/**
 * SQL groups expose every usable statement checkpoint while keeping both
 * multipart bodies and the bytes between checkpoints bounded.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    apiRequest, cleanupTempDir, createMysqlConnection, createTempDir,
    clearHookState, getDbName, getSiteDir, getSiteSecret, getSiteUrl,
    readAuditLog, readHookState, runImporter,
    removeTestHooks, writeTestHooks,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: budgeted MySQL SQL groups', { timeout: 120000 }, () => {
    const site = 'budgeted-mysql-sql-groups';
    const firstTable = 'aa_budgeted_rows';
    const secondTable = 'bb_budgeted_rows';
    const boundedPayloadTable = 'cc_bounded_payload_rows';
    const splitPayloadTable = 'dd_split_payload_rows';
    const unsplittablePayloadTable = 'ee_unsplittable_payload_rows';
    const maximumSqlPartBodyBytes = 16 * 1024 * 1024;
    const hookState = `/srv/e2e-sites/.e2e-hook-state-${site}`;
    let skippedTables;
    let boundedPayloadSkippedTables;
    let splitAndUnsplittableSkippedTables;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            customDb: async (_database, connection) => {
                await connection.query(
                    `CREATE TABLE \`${firstTable}\` (`
                    + '`id` INT NOT NULL PRIMARY KEY, `value` VARCHAR(64) NOT NULL) ENGINE=InnoDB'
                );
                // One table comment plus three complete 250-row INSERTs uses
                // 751 fragments. A 1,000-fragment batch then ends after 249
                // rows of the fourth INSERT, leaving an unfinished suffix.
                const rows = Array.from({ length: 1001 }, (_, index) => [
                    index + 1,
                    `row-${index + 1}`,
                ]);
                for (let offset = 0; offset < rows.length; offset += 100) {
                    await connection.query(
                        `INSERT INTO \`${firstTable}\` (id, value) VALUES ?`,
                        [rows.slice(offset, offset + 100)],
                    );
                }
                await connection.query(
                    `CREATE TABLE \`${secondTable}\` (`
                    + '`id` INT NOT NULL PRIMARY KEY) ENGINE=InnoDB'
                );
                await connection.query(`INSERT INTO \`${secondTable}\` VALUES (1)`);

                await connection.query(
                    `CREATE TABLE \`${boundedPayloadTable}\` (`
                    + '`id` INT NOT NULL PRIMARY KEY, `payload` MEDIUMBLOB NOT NULL) ENGINE=InnoDB'
                );
                // These 200 rows remain inside one 250-row query batch, but
                // their formatted SQL is about 21 MiB. The producer must close
                // the INSERT on bytes before the query batch ends.
                for (let id = 1; id <= 200; id++) {
                    await connection.query(
                        `INSERT INTO \`${boundedPayloadTable}\` (id, payload) `
                        + 'VALUES (?, REPEAT(CHAR(65 + MOD(?, 26)), 80 * 1024))',
                        [id, id],
                    );
                }

                await connection.query(
                    `CREATE TABLE \`${splitPayloadTable}\` (`
                    + '`id` INT NOT NULL PRIMARY KEY, '
                    + '`payload` MEDIUMBLOB NOT NULL) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT INTO \`${splitPayloadTable}\` `
                    + '(id, payload) VALUES ('
                    + '1, REPEAT(CHAR(65), 13 * 1024 * 1024))'
                );

                await connection.query(
                    `CREATE TABLE \`${unsplittablePayloadTable}\` (`
                    + '`payload` MEDIUMBLOB NOT NULL) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT INTO \`${unsplittablePayloadTable}\` (payload) `
                    + 'VALUES (REPEAT(CHAR(67), 13 * 1024 * 1024))'
                );
            },
        });

        const connection = await createMysqlConnection(getDbName(site));
        try {
            const [tables] = await connection.query(
                'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES '
                + 'WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\'',
                [getDbName(site)],
            );
            skippedTables = tables
                .map(row => row.TABLE_NAME)
                .filter(table => ![firstTable, secondTable].includes(table));
            boundedPayloadSkippedTables = tables
                .map(row => row.TABLE_NAME)
                .filter(table => table !== boundedPayloadTable);
            splitAndUnsplittableSkippedTables = tables
                .map(row => row.TABLE_NAME)
                .filter(
                    table => ![splitPayloadTable, unsplittablePayloadTable].includes(table)
                );
        } finally {
            await connection.end();
        }
    }, 300000);

    async function sqlParts(fragmentsPerBatch, cursor = null) {
        const request = {
            directory: getSiteDir(site),
            fragments_per_batch: fragmentsPerBatch,
            skip_tables: skippedTables,
        };
        if (cursor !== null) {
            request.cursor = cursor;
        }
        const response = await apiRequest(site, 'sql_chunk', {}, {
            method: 'POST',
            body: JSON.stringify(request),
        });
        assert.equal(
            response.status,
            200,
            response.json?.error ?? response.text ?? 'The SQL endpoint rejected the request',
        );
        return response.chunks.filter(chunk => chunk.headers['x-chunk-type'] === 'sql');
    }

    function sqlPartBodyLengths(response) {
        const contentType = response.headers['content-type'];
        const boundary = contentType.match(/boundary="?([^";\s]+)"?/i)?.[1];
        assert.ok(boundary, `Expected a multipart boundary in ${contentType}`);

        const rawBody = response.raw.toString('binary');
        const lengths = [];
        for (const section of rawBody.split(`--${boundary}`).slice(1)) {
            const headerEnd = section.indexOf('\r\n\r\n');
            if (headerEnd === -1) {
                continue;
            }
            const headers = section.substring(0, headerEnd).toLowerCase();
            if (!headers.includes('\r\nx-chunk-type: sql\r\n')) {
                continue;
            }
            const declared = Number(headers.match(/\r\ncontent-length: (\d+)\r\n/)?.[1]);
            const bodyStart = headerEnd + 4;
            const actual = section.length - bodyStart - 2;
            lengths.push({ actual, declared });
        }
        return lengths;
    }

    function assertBoundedSqlPartBodies(response) {
        const lengths = sqlPartBodyLengths(response);
        assert.ok(lengths.length > 0, 'Expected SQL multipart parts');
        for (const { actual, declared } of lengths) {
            assert.equal(actual, declared, 'SQL part body must match its Content-Length');
            assert.ok(
                actual <= maximumSqlPartBodyBytes,
                `SQL part body was ${actual} bytes`,
            );
        }
    }

    function assertBoundedBytesBetweenCheckpoints(parts) {
        let bytesSinceCheckpoint = 0;
        for (const part of parts) {
            bytesSinceCheckpoint += Buffer.byteLength(part.body);
            assert.ok(
                bytesSinceCheckpoint <= 2 * maximumSqlPartBodyBytes,
                `SQL group reached ${bytesSinceCheckpoint} bytes before its next checkpoint`,
            );
            if (part.headers['x-query-complete'] === '1') {
                bytesSinceCheckpoint = 0;
            }
        }
        assert.equal(
            bytesSinceCheckpoint,
            0,
            'A complete SQL response must end at a statement checkpoint',
        );
    }

    it('checkpoints a complete SQL prefix before its unfinished batch suffix', async () => {
        const parts = await sqlParts(1000);
        assertBoundedBytesBetweenCheckpoints(parts);
        const firstTableInsertPart = parts.find(
            part => part.body.includes(`INSERT INTO \`${firstTable}\``)
        );
        assert.ok(firstTableInsertPart, 'Expected an INSERT part for the first table');
        assert.equal(
            firstTableInsertPart.body.match(new RegExp('INSERT INTO `' + firstTable + '`', 'g'))?.length,
            3,
            'Expected the three producer INSERT statements in one budgeted SQL part',
        );
        assert.equal(
            firstTableInsertPart.headers['x-query-complete'],
            '1',
            'The complete prefix should expose a cursor before the unfinished suffix',
        );
        const firstTableInsertPartIndex = parts.indexOf(firstTableInsertPart);
        const incompleteSuffixPart = parts[firstTableInsertPartIndex + 1];
        assert.equal(
            incompleteSuffixPart?.headers['x-query-complete'],
            '0',
            'The unfinished INSERT suffix should follow the complete prefix',
        );
        assert.ok(
            incompleteSuffixPart.body.includes(`INSERT INTO \`${firstTable}\``),
            'The unfinished suffix should contain the next INSERT statement',
        );
        assert.ok(
            !incompleteSuffixPart.body.includes(';'),
            'The unfinished suffix must not retain a complete statement',
        );
        const resumedParts = await sqlParts(
            1000,
            firstTableInsertPart.headers['x-cursor'],
        );
        assertBoundedBytesBetweenCheckpoints(resumedParts);
        assert.equal(
            resumedParts.map(part => part.body).join(''),
            parts.slice(firstTableInsertPartIndex + 1).map(part => part.body).join(''),
            'The complete-prefix cursor must resume at the unfinished suffix',
        );
        assert.ok(
            !firstTableInsertPart.body.includes(`DROP TABLE IF EXISTS \`${secondTable}\``),
            'The next table replacement must start in another SQL part',
        );
    });

    it('still ends parts at the configured fragment limit', async () => {
        const parts = await sqlParts(1);
        assert.ok(
            parts.filter(part => part.body.includes(`INSERT INTO \`${firstTable}\``)).length > 1,
            'A one-fragment budget should split the large INSERT stream across parts',
        );
    });

    it('bounds INSERT statements and resumes at their byte checkpoint', async () => {
        const requestBody = {
            directory: getSiteDir(site),
            fragments_per_batch: 1000,
            max_execution_time: 60,
            skip_tables: boundedPayloadSkippedTables,
        };
        const response = await apiRequest(site, 'sql_chunk', {}, {
            method: 'POST',
            body: JSON.stringify(requestBody),
        });
        assert.equal(
            response.status,
            200,
            response.json?.error ?? response.text ?? 'The SQL endpoint rejected the request',
        );

        assertBoundedSqlPartBodies(response);
        const parts = response.chunks.filter(
            chunk => chunk.headers['x-chunk-type'] === 'sql'
        );
        assert.ok(
            parts.reduce((bytes, part) => bytes + Buffer.byteLength(part.body), 0)
                > maximumSqlPartBodyBytes,
            'The fixture must exceed one SQL part body',
        );
        assertBoundedBytesBetweenCheckpoints(parts);
        const completion = response.chunks.find(
            chunk => chunk.headers['x-chunk-type'] === 'completion'
        );
        assert.equal(completion?.headers['x-status'], 'complete');

        const firstInsertPartIndex = parts.findIndex(
            part => part.body.includes(`INSERT INTO \`${boundedPayloadTable}\``)
        );
        assert.notEqual(firstInsertPartIndex, -1, 'Expected the bounded table INSERT');
        const firstInsertPart = parts[firstInsertPartIndex];
        assert.equal(
            firstInsertPart.headers['x-query-complete'],
            '1',
            'The INSERT byte limit should expose a checkpoint before the 250-row batch ends',
        );
        const expectedResumedSql = parts
            .slice(firstInsertPartIndex + 1)
            .map(part => part.body)
            .join('');
        assert.ok(
            expectedResumedSql.includes(`INSERT INTO \`${boundedPayloadTable}\``),
            'Rows after the byte checkpoint should begin another INSERT',
        );

        const encodedCursor = firstInsertPart.headers['x-cursor'];
        assert.ok(encodedCursor, 'Expected a cursor on the bounded SQL part');
        const resumedResponse = await apiRequest(site, 'sql_chunk', {}, {
            method: 'POST',
            body: JSON.stringify({ ...requestBody, cursor: encodedCursor }),
        });
        assert.equal(
            resumedResponse.status,
            200,
            resumedResponse.json?.error ?? resumedResponse.text ?? 'The resumed SQL request failed',
        );
        assertBoundedSqlPartBodies(resumedResponse);
        const resumedCompletion = resumedResponse.chunks.find(
            chunk => chunk.headers['x-chunk-type'] === 'completion'
        );
        assert.equal(resumedCompletion?.headers['x-status'], 'complete');

        const resumedSql = resumedResponse.chunks
            .filter(chunk => chunk.headers['x-chunk-type'] === 'sql')
            .map(part => part.body)
            .join('');
        assertBoundedBytesBetweenCheckpoints(
            resumedResponse.chunks.filter(
                chunk => chunk.headers['x-chunk-type'] === 'sql'
            ),
        );
        assert.equal(
            resumedSql,
            expectedResumedSql,
            'Resume must begin with the unfinished INSERT suffix after the byte checkpoint',
        );
    }, 300000);

    it('splits a keyed value and stops when a row cannot fit the SQL part limit', async () => {
        const response = await apiRequest(site, 'sql_chunk', {}, {
            method: 'POST',
            body: JSON.stringify({
                directory: getSiteDir(site),
                fragments_per_batch: 1000,
                max_execution_time: 60,
                skip_tables: splitAndUnsplittableSkippedTables,
            }),
        });
        assert.equal(response.status, 200);

        assertBoundedSqlPartBodies(response);
        const sqlParts = response.chunks.filter(
            chunk => chunk.headers['x-chunk-type'] === 'sql'
        );
        assert.ok(
            sqlParts.some(part => part.body.includes(' = CONCAT(')),
            'A large keyed value should use bounded UPDATE statements',
        );
        const errorPart = response.chunks.find(
            chunk => chunk.headers['x-chunk-type'] === 'error'
        );
        assert.ok(errorPart, 'Expected an error multipart part');
        const error = JSON.parse(errorPart.body);
        const observedSize = error.message.match(/SQL fragment size of (\d+) bytes/);
        assert.ok(
            observedSize,
            'The error should name the estimated SQL row size',
        );
        assert.ok(Number(observedSize[1]) > maximumSqlPartBodyBytes);
        assert.match(error.message, /no primary key/);
        const statementLimit = error.message.match(
            /max_statement_size \((\d+) bytes\)/,
        );
        assert.ok(statementLimit, 'The error should report the SQL statement limit');
        const partBodyLimit = error.message.match(
            /SQL part body limit \((\d+) bytes\)/,
        );
        assert.ok(partBodyLimit, 'The error should report the SQL part body limit');
        assert.equal(Number(partBodyLimit[1]), maximumSqlPartBodyBytes);

        const completion = response.chunks.find(
            chunk => chunk.headers['x-chunk-type'] === 'completion'
        );
        assert.equal(completion?.headers['x-status'], 'partial');

        const outputDirectory = createTempDir('e2e-bounded-sql-error');
        try {
            const result = runImporter(
                `${getSiteUrl(site)}&directory=${getSiteDir(site)}`,
                outputDirectory,
                'db-pull',
                {
                    secret: getSiteSecret(site),
                    autoResume: false,
                    timeout: 300000,
                },
            );
            const output = `${result.stdout}\n${result.stderr}`;
            assert.equal(result.exitCode, 1, output);
            assert.match(
                output,
                /The source could not export the database: .*no primary key/,
            );
            const audit = readAuditLog(outputDirectory);
            assert.equal(
                audit.match(/REMOTE ERROR \| phase=sql/g)?.length,
                1,
                'The importer must stop after the first SQL export error',
            );
        } finally {
            cleanupTempDir(outputDirectory);
        }
    }, 300000);

    it('keeps the source error when the response ends before completion', () => {
        clearHookState(site);
        writeTestHooks(site, [
            'function test_hook_before_completion($status, $gz, $boundary) {',
            "    if ($status !== 'partial') { return; }",
            `    e2e_write_hook_state('${hookState}', array('completion_interrupted' => true));`,
            '    $gz->finish();',
            '    exit(1);',
            '}',
        ].join('\n'));

        const outputDirectory = createTempDir('e2e-bounded-sql-truncated-error');
        try {
            const result = runImporter(
                `${getSiteUrl(site)}&directory=${getSiteDir(site)}`,
                outputDirectory,
                'db-pull',
                {
                    secret: getSiteSecret(site),
                    autoResume: false,
                    timeout: 300000,
                    extraArgs: ['--max-exec=60'],
                },
            );
            const output = `${result.stdout}\n${result.stderr}`;
            assert.equal(result.exitCode, 1, output);
            assert.match(
                output,
                /The source could not export the database: .*no primary key/,
            );
            assert.deepEqual(
                readHookState(site),
                { completion_interrupted: true },
                'The response must end before its completion part',
            );

            const audit = readAuditLog(outputDirectory);
            assert.equal(
                audit.match(/REMOTE ERROR \| phase=sql/g)?.length,
                1,
                'The importer must keep the source error from the truncated response',
            );
            assert.doesNotMatch(audit, /SQL RETRY/);
        } finally {
            removeTestHooks(site);
            clearHookState(site);
            cleanupTempDir(outputDirectory);
        }
    }, 300000);
});
