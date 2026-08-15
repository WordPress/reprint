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
                const rows = Array.from({ length: 600 }, (_, index) => [
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

    async function sqlParts(fragmentsPerBatch) {
        const response = await apiRequest(site, 'sql_chunk', {}, {
            method: 'POST',
            body: JSON.stringify({
                directory: getSiteDir(site),
                fragments_per_batch: fragmentsPerBatch,
                skip_tables: skippedTables,
            }),
        });
        assert.equal(
            response.status,
            200,
            response.json?.error ?? response.text ?? 'The SQL endpoint rejected the request',
        );
        return response.chunks.filter(chunk => chunk.headers['x-chunk-type'] === 'sql');
    }

    it('allows the existing fragment budget to group complete INSERT statements', async () => {
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
