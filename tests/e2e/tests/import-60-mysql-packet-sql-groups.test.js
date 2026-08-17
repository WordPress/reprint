/**
 * db-apply sends each statement separately when one saved exporter SQL group
 * is larger than the MySQL target's packet limit.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { createReadStream } from 'node:fs';
import { join } from 'node:path';
import { createInterface } from 'node:readline';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, getDbName,
    createMysqlConnection,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const describeWithHostPhpProcess = process.env.PHP_BINARY?.endsWith('/playground-php.sh')
    ? describe.skip
    : describe;

describeWithHostPhpProcess('Import: MySQL packet-sized SQL commands', { timeout: 600000 }, () => {
    const site = 'mysql-packet-sql-groups';
    const sourceTable = 'aa_packet_group_rows';
    const rowCount = 1024;
    const targetDb = `${getDbName(site)}_import`;
    const sqlGroupMarker = '-- REPRINT SQL GROUP 82d10e87-ec1b-4aa2-a522-963dc82b6bb1 ';
    let maxAllowedPacket;
    let rowByteLength;
    let tempDir;

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    beforeAll(async () => {
        const connection = await createMysqlConnection();
        try {
            const [[packet]] = await connection.query(
                'SELECT @@SESSION.max_allowed_packet AS max_allowed_packet'
            );
            maxAllowedPacket = Number(packet.max_allowed_packet);
            assert.ok(maxAllowedPacket >= 1024 * 1024, 'Expected a usable MySQL packet limit');

            // The first complete prefix contains 750 rows because the table
            // comment takes one of the 1,000 exporter fragment slots. Its
            // base64 SQL is about 10% larger than the target packet limit.
            rowByteLength = Math.ceil(maxAllowedPacket * 1.1 / 1000);
        } finally {
            await connection.end();
        }

        await ensureSite(site, {
            files: 'none',
            customDb: async (_database, sourceConnection) => {
                await sourceConnection.query(
                    `CREATE TABLE \`${sourceTable}\` (`
                    + '`id` INT NOT NULL PRIMARY KEY, `payload` MEDIUMBLOB NOT NULL) ENGINE=InnoDB'
                );
                await sourceConnection.query(
                    `INSERT INTO \`${sourceTable}\` (id, payload) VALUES (1, '')`
                );
                for (let rows = 1; rows < rowCount; rows *= 2) {
                    await sourceConnection.query(
                        `INSERT INTO \`${sourceTable}\` (id, payload) `
                        + `SELECT id + ?, payload FROM \`${sourceTable}\``,
                        [rows],
                    );
                }
                await sourceConnection.query(
                    `UPDATE \`${sourceTable}\` `
                    + 'SET payload = REPEAT(CHAR(65 + MOD(id, 26)), ?)',
                    [rowByteLength],
                );
            },
        });

        tempDir = createTempDir('e2e-mysql-packet-sql-groups');
        const targetConnection = await createMysqlConnection();
        try {
            await targetConnection.query(`DROP DATABASE IF EXISTS \`${targetDb}\``);
            await targetConnection.query(`CREATE DATABASE \`${targetDb}\``);
        } finally {
            await targetConnection.end();
        }
    }, 300000);

    afterAll(async () => {
        if (tempDir) {
            cleanupTempDir(tempDir);
        }
        const connection = await createMysqlConnection();
        try {
            await connection.query(`DROP DATABASE IF EXISTS \`${targetDb}\``);
        } finally {
            await connection.end();
        }
    });

    it('writes a marker-delimited group larger than one target packet', async () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            timeout: 300000,
            wallTimeout: 600000,
            extraArgs: [
                `--max-allowed-packet=${maxAllowedPacket}`,
                '--sql-fragments-start=1000',
                '--sql-fragments-min=1000',
                '--sql-fragments-max=1000',
            ],
        });
        assert.equal(
            result.exitCode,
            0,
            `db-pull failed:\n${result.stderr}\n${result.stdout}`,
        );

        const targetInsert = `INSERT INTO \`${sourceTable}\``;
        const targetGroups = [];
        let groupByteLength = 0;
        let statementByteLength = null;
        let statementByteLengths = [];
        const lines = createInterface({
            input: createReadStream(join(tempDir, 'db.sql')),
            crlfDelay: Infinity,
        });

        for await (const line of lines) {
            if (line.startsWith(sqlGroupMarker)) {
                assert.equal(statementByteLength, null, 'The marker split an INSERT statement');
                if (statementByteLengths.length > 0) {
                    targetGroups.push({ groupByteLength, statementByteLengths });
                }
                groupByteLength = 0;
                statementByteLengths = [];
                continue;
            }

            const text = `${line}\n`;
            groupByteLength += Buffer.byteLength(text);
            let offset = 0;
            while (offset < text.length) {
                if (statementByteLength === null) {
                    const insertOffset = text.indexOf(targetInsert, offset);
                    if (insertOffset === -1) {
                        break;
                    }
                    statementByteLength = 0;
                    offset = insertOffset;
                }

                const statementEnd = text.indexOf(';', offset);
                if (statementEnd === -1) {
                    statementByteLength += Buffer.byteLength(text.slice(offset));
                    break;
                }
                statementByteLength += Buffer.byteLength(text.slice(offset, statementEnd + 1));
                statementByteLengths.push(statementByteLength);
                statementByteLength = null;
                offset = statementEnd + 1;
            }
        }

        const oversizedGroup = targetGroups.find(
            group => group.groupByteLength > maxAllowedPacket
        );
        assert.ok(
            oversizedGroup,
            `Expected a saved SQL group larger than ${maxAllowedPacket} bytes`,
        );
        assert.ok(
            oversizedGroup.statementByteLengths.length > 1,
            'Expected the large SQL group to contain several INSERT statements',
        );
        assert.ok(
            oversizedGroup.statementByteLengths.every(bytes => bytes < maxAllowedPacket),
            'Expected every INSERT statement to fit within the target packet limit',
        );
    });

    it('applies the large group through statement-sized MySQL commands', () => {
        const result = runImporter(importUrl(), tempDir, 'db-apply', {
            secret: getSiteSecret(site),
            timeout: 300000,
            wallTimeout: 600000,
            extraArgs: [
                '--target-engine=mysql',
                '--target-host=127.0.0.1',
                '--target-user=e2e_admin',
                '--target-pass=e2e_password',
                `--target-db=${targetDb}`,
            ],
        });
        assert.equal(
            result.exitCode,
            0,
            `db-apply failed:\n${result.stderr}\n${result.stdout}`,
        );
    });

    it('keeps every source row byte-for-byte', async () => {
        const sourceConnection = await createMysqlConnection(getDbName(site));
        const targetConnection = await createMysqlConnection(targetDb);
        try {
            const sql = `SELECT id, OCTET_LENGTH(payload) AS byte_length, `
                + `SHA2(payload, 256) AS sha256 FROM \`${sourceTable}\` ORDER BY id`;
            const [sourceRows] = await sourceConnection.query(sql);
            const [targetRows] = await targetConnection.query(sql);

            assert.equal(sourceRows.length, rowCount);
            assert.deepEqual(targetRows, sourceRows);
        } finally {
            await sourceConnection.end();
            await targetConnection.end();
        }
    });
});
