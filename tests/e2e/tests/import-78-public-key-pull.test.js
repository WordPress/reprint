/**
 * Test 76: the precise path end to end. keygen, enroll the printed key on
 * the site, pull with no credential flag — the key is found automatically.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { mkdtempSync, existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import {
    runImporter, getSiteUrl, getSiteDir, fsRootDir, exportedKeyPath,
    assertTreesMatch, assertSiteMirror, assertPullPipelineComplete,
    pullStateDirectory, createMysqlConnection,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: public-key pull', { timeout: 180000 }, () => {
    const site = 'public-key-pull';
    const importDb = 'e2e_public_key_pull_76';
    let stateDir;

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    beforeAll(async () => {
        stateDir = mkdtempSync(join(tmpdir(), 'reprint-pk-pull-'));
        // keygen sends nothing, so it runs before the site exists and
        // without the helper's automatic preflight.
        const keygen = runImporter(importUrl(), stateDir, 'keygen', { autoResume: false, skipPreflight: true });
        assert.equal(keygen.exitCode, 0, keygen.stdout + keygen.stderr);
        const publicKey = keygen.stdout.match(/^(MII[A-Za-z0-9+/]+=*)$/m)[1];
        await ensureSite(site, { publicKeys: [publicKey] });

        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await connection.query(`CREATE DATABASE \`${importDb}\``);
        await connection.end();
    });

    afterAll(async () => {
        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await connection.end();
    });

    it('pulls the site with the key found in the state directory', () => {
        const result = runImporter(importUrl(), stateDir, 'pull', {
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: [
                '--target-user=e2e_admin',
                '--target-pass=e2e_password',
                `--target-db=${importDb}`,
                '--new-site-url=http://localhost:9999',
                '--runtime=none',
            ],
        });
        assert.equal(result.exitCode, 0, result.stdout + result.stderr);

        const importedRoot = join(fsRootDir(stateDir), getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);
        assertTreesMatch(getSiteDir(site), importedRoot);
        assertSiteMirror(importedRoot);
        assertPullPipelineComplete(JSON.parse(readFileSync(
            join(pullStateDirectory(stateDir, importUrl()), 'state.json'), 'utf-8',
        )));

        assert.match(result.stdout, /Key for this site:/);
        assert.match(result.stdout, /Deleting the state directory revokes it/);
        assert.ok(existsSync(exportedKeyPath(importUrl(), stateDir)), 'the key stays where pull found it');
    });
});
