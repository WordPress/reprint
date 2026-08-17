/**
 * Direct MySQL output keeps the exporter's existing response budgets while
 * ending a SQL group before the next table replacement starts.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    apiRequest, getSiteDir, createMysqlConnection, getDbName,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: budgeted MySQL SQL groups', { timeout: 120000 }, () => {
    const site = 'budgeted-mysql-sql-groups';
    const firstTable = 'aa_budgeted_rows';
    const secondTable = 'bb_budgeted_rows';
    let skippedTables;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            customDb: async (_database, connection) => {
                await connection.query(
                    `CREATE TABLE \`${firstTable}\` (`
                    + '`id` INT NOT NULL PRIMARY KEY, `value` VARCHAR(64) NOT NULL) ENGINE=InnoDB'
                );
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
        } finally {
            await connection.end();
        }
    });

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

    it('emits a complete SQL part before an unfinished batch suffix', async () => {
        const parts = await sqlParts(1000);
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
            'The complete prefix should be exposed as its own SQL part',
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
        assert.equal(
            resumedParts.map(part => part.body).join(''),
            parts.slice(firstTableInsertPartIndex + 1).map(part => part.body).join(''),
            'The complete prefix cursor should resume at the unfinished suffix',
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
});
