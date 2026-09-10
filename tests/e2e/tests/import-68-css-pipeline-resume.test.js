/** Checks that resuming after file download cannot give the database a different target URL from the CSS. */
import { describe, it, beforeAll, afterEach } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { ensureSite } from '../lib/site-setup.js';
import {
    runImporter, createTempDir, cleanupTempDir, getSiteDir, getSiteUrl, getSiteSecret,
    fsRootDir, pullStateDirectory, writeTestHooks, removeTestHooks, queryMysqlOnSqlite,
} from '../lib/test-helpers.js';

describe('Import: CSS mappings across full-pull resume', () => {
    const site = 'css-pipeline-resume';
    const sourceUrl = new URL(getSiteUrl(site)).origin;
    const sourcePath = join(getSiteDir(site), 'wp-content/uploads/generated.css');
    const sourceCss = `a{background:url("${sourceUrl}/photo.png")}`;
    const importUrl = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    let temporaryDirectory;

    beforeAll(async () => {
        await ensureSite(site, {
            db: 'standard', files: 'none',
            afterCreate: async () => {
                mkdirSync(dirname(sourcePath), { recursive: true });
                writeFileSync(sourcePath, sourceCss);
            },
        });
    });

    afterEach(() => {
        removeTestHooks(site);
        if (temporaryDirectory) cleanupTempDir(temporaryDirectory);
    });

    it.each(['--new-site-url', '--rewrite-url'])('checks %s even when resume skips file download', option => {
        temporaryDirectory = createTempDir('e2e-css-pipeline-resume');
        const targetUrl = 'https://first.example.test';
        const differentTargetUrl = 'https://second.example.test';
        const sqlitePath = join(temporaryDirectory, 'site.sqlite');
        const mappingArguments = option === '--rewrite-url' ? [option, sourceUrl, targetUrl] : [option, targetUrl];
        const argumentsForPull = ['--runtime=none', '--start-runtime=none', '--target-engine=sqlite',
            `--target-sqlite-path=${sqlitePath}`, '--only', sourcePath, ...mappingArguments];

        // Cut off the real database response after the file stage has saved
        // its normal completion checkpoint. No local state is altered by the test.
        writeTestHooks(site, `
/** End the response before its first SQL batch, leaving db-pull incomplete. */
function test_hook_before_sql_batch(&$sql, $cursor) { exit; }
`);
        const interrupted = runImporter(importUrl, temporaryDirectory, 'pull', {
            secret: getSiteSecret(site), autoResume: false, skipPreflight: true, extraArgs: argumentsForPull,
        });
        assert.equal(interrupted.exitCode, 3, interrupted.stdout + interrupted.stderr);
        assert.match(interrupted.stdout + interrupted.stderr, /missing completion chunk/);
        const statePath = join(pullStateDirectory(temporaryDirectory, importUrl), 'state.json');
        assert.equal(JSON.parse(readFileSync(statePath, 'utf8')).pull_pipeline.last_completed_stage, 'files-pull');
        const localCssPath = join(fsRootDir(temporaryDirectory), sourcePath);
        const expectedCss = `a{background:url("${targetUrl}/photo.png")}`;
        assert.equal(readFileSync(localCssPath, 'utf8'), expectedCss);
        assert.equal(existsSync(sqlitePath), false);
        removeTestHooks(site);

        const rejected = runImporter(importUrl, temporaryDirectory, 'pull', {
            secret: getSiteSecret(site), autoResume: false, skipPreflight: true,
            extraArgs: argumentsForPull.map(argument => argument === targetUrl ? differentTargetUrl : argument),
        });
        assert.equal(rejected.exitCode, 1, rejected.stdout + rejected.stderr);
        assert.match(rejected.stdout + rejected.stderr, /Cannot change CSS URL mappings/);
        assert.equal(readFileSync(localCssPath, 'utf8'), expectedCss);
        assert.equal(existsSync(sqlitePath), false, 'Reject the new URL before applying any database records');
        assert.equal(JSON.parse(readFileSync(statePath, 'utf8')).pull_pipeline.last_completed_stage, 'files-pull');

        const resumed = runImporter(importUrl, temporaryDirectory, 'pull', {
            secret: getSiteSecret(site), autoResume: false, skipPreflight: true, extraArgs: argumentsForPull,
        });
        assert.equal(resumed.exitCode, 0, resumed.stdout + resumed.stderr);
        assert.deepEqual(queryMysqlOnSqlite(sqlitePath,
            "SELECT option_value FROM wp_options WHERE option_name = 'home'"), [{ option_value: targetUrl }]);
        assert.equal(readFileSync(localCssPath, 'utf8'), expectedCss);
        assert.equal(readFileSync(sourcePath, 'utf8'), sourceCss);
    }, 180000);
});
