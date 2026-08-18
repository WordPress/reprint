/**
 * Test 60: Pull with an open_basedir boundary above WordPress
 *
 * The site itself is allowed, but its parent is not. WordPress root
 * discovery must keep the root it finds when its parent scan reaches the
 * open_basedir boundary. A selected file pull and the database pull must
 * then finish through the normal high-level pipelines.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import {
    apiRequest,
    assertPullPipelineComplete,
    cleanupTempDir,
    compareDatabases,
    createMysqlConnection,
    createTempDir,
    fsRootDir,
    getDbName,
    getSiteDir,
    getSiteSecret,
    getSiteUrl,
    pullStateDirectory,
    runImporter,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Pull with an open_basedir boundary', { timeout: 180000 }, () => {
    const site = 'open-basedir';
    const importDb = 'e2e_open_basedir_import_60';
    let tempDir;
    let preflightResponse;

    beforeAll(async () => {
        await ensureSite(site);
        preflightResponse = await apiRequest(site, 'preflight');

        tempDir = createTempDir('e2e-open-basedir');
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.query(`CREATE DATABASE \`${importDb}\``);
        await conn.end();
    });

    afterAll(async () => {
        cleanupTempDir(tempDir);
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    it('keeps the detected WordPress root when its parent cannot be inspected', () => {
        const siteDir = getSiteDir(site);
        const expectedOpenBasedir = `${siteDir}:/tmp`;

        assert.equal(
            preflightResponse.status,
            200,
            `Expected HTTP 200, got ${preflightResponse.status}: ` +
            JSON.stringify(preflightResponse.json || preflightResponse.text),
        );
        assert.equal(preflightResponse.json.limits.open_basedir, expectedOpenBasedir);
        assert.equal(
            preflightResponse.json.ok,
            true,
            `Preflight failed: ${preflightResponse.json.error}`,
        );
        assert.ok(preflightResponse.json.wp_detect.found, 'Expected WordPress to be found');
        assert.ok(
            preflightResponse.json.wp_detect.roots.some(
                (root) => root.path === siteDir && root.wp_load && root.wp_config,
            ),
            `Expected a detected WordPress root at ${siteDir}`,
        );
        assert.deepEqual(preflightResponse.json.wp_detect.searched, [siteDir, dirname(siteDir)]);
    });

    it('migrates wp-content and the database while open_basedir is active', async () => {
        const siteDir = getSiteDir(site);
        const remoteUrl = getSiteUrl(site);
        const expectedOpenBasedir = `${siteDir}:/tmp`;

        // `pull` does not expose --include. Run the supported high-level file
        // and database pipelines separately.
        const filesResult = runImporter(remoteUrl, tempDir, 'pull-files', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: ['--include=:wp-content:'],
        });
        assert.equal(
            filesResult.exitCode,
            0,
            `pull-files failed:\nstdout:\n${filesResult.stdout}\nstderr:\n${filesResult.stderr}`,
        );

        let state = JSON.parse(readFileSync(
            join(pullStateDirectory(tempDir, remoteUrl), 'state.json'),
            'utf-8',
        ));
        assertPullPipelineComplete(state, 'pull-files');
        assert.deepEqual(state.pull_pipeline.stage_sequence, ['preflight', 'files-pull']);
        assert.equal(state.preflight.data.limits.open_basedir, expectedOpenBasedir);

        const importedSiteDir = join(fsRootDir(tempDir), siteDir);
        const importedWpContent = join(importedSiteDir, 'wp-content');
        assert.ok(existsSync(importedWpContent), `Expected ${importedWpContent} to exist`);
        assert.equal(
            readFileSync(join(importedWpContent, 'plugins', 'site-export', 'secret.php'), 'utf-8'),
            `<?php return '${getSiteSecret(site)}';\n`,
        );
        assert.ok(!existsSync(join(importedSiteDir, 'wp-load.php')),
            'wp-load.php is outside the selected wp-content directory');

        const databaseResult = runImporter(remoteUrl, tempDir, 'pull-db', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: [
                '--target-user=e2e_admin',
                '--target-pass=e2e_password',
                `--target-db=${importDb}`,
            ],
        });
        assert.equal(
            databaseResult.exitCode,
            0,
            `pull-db failed:\nstdout:\n${databaseResult.stdout}\nstderr:\n${databaseResult.stderr}`,
        );

        state = JSON.parse(readFileSync(
            join(pullStateDirectory(tempDir, remoteUrl), 'state.json'),
            'utf-8',
        ));
        assertPullPipelineComplete(state, 'pull-db');
        assert.deepEqual(
            state.pull_pipeline.stage_sequence,
            ['preflight', 'db-pull', 'db-apply'],
        );
        assert.equal(state.preflight.data.limits.open_basedir, expectedOpenBasedir);

        const databaseComparison = await compareDatabases(getDbName(site), importDb);
        assert.deepEqual(databaseComparison.extraTables, []);
        assert.ok(
            databaseComparison.match,
            `Database mismatch: missing=${JSON.stringify(databaseComparison.missingTables)}, ` +
            `counts=${JSON.stringify(databaseComparison.rowCounts)}`,
        );

        const importedDatabase = await createMysqlConnection(importDb);
        const [[blogName]] = await importedDatabase.query(
            "SELECT option_value FROM wp_options WHERE option_name = 'blogname'",
        );
        await importedDatabase.end();
        assert.equal(blogName.option_value, `E2E: ${site}`);
    });
});
