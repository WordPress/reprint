/**
 * Test 75: precise commands never generate a key. Without a credential they
 * stop before any request and name keygen.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { runImporter, getSiteUrl, readAuditLog } from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: granular commands refuse without a credential', () => {
    const site = 'granular-refuse';

    beforeAll(async () => {
        await ensureSite(site);
    });

    for (const command of ['preflight', 'pull-files', 'pull-db', 'files-index', 'db-index']) {
        it(`${command} exits non-zero, names keygen, and writes no key`, () => {
            const stateDir = mkdtempSync(join(tmpdir(), 'reprint-refuse-'));
            // skipPreflight makes the named command meet the missing
            // credential itself rather than the helper's preflight.
            const result = runImporter(getSiteUrl(site), stateDir, command, { autoResume: false, skipPreflight: true });

            assert.notEqual(result.exitCode, 0);
            assert.notEqual(result.exitCode, 4);
            assert.match(result.stdout + result.stderr, /reprint keygen/);
            assert.doesNotMatch(readAuditLog(stateDir), /HTTP_REQUEST \|/, 'no request was sent');
            const remotes = join(stateDir, 'remotes');
            const keyFiles = existsSync(remotes)
                ? readdirSync(remotes, { recursive: true }).filter((name) => String(name).endsWith('key.pem'))
                : [];
            assert.deepEqual(keyFiles, []);
        });
    }
});
