/**
 * Test 55: apply-runtime database target options
 *
 * Covers the case where the database never travels through Reprint: a caller
 * pulls the files only, keeps its own SQLite database, and names it on the
 * apply-runtime command line. No db-pull, no db-apply, so nothing has written
 * a database target into state.
 *
 * The SQLite file is prepared in a separate state directory, so the state the
 * runtime is generated from holds no target at all.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir, pullStateDirectory, PHP_BINARY,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: apply-runtime database target', { timeout: 300000 }, () => {
    const site = 'basic';
    const port = 9491;
    const targetUrl = `http://127.0.0.1:${port}`;
    let tempDir;
    let databasePrepDir;
    let runtimeDir;
    let sqlitePath;
    let server = null;

    beforeAll(async () => {
        await ensureSite(site, {
            db: 'sample',
            files: 'sample',
        });

        tempDir = createTempDir('e2e-apply-runtime-db-target');
        databasePrepDir = createTempDir('e2e-apply-runtime-db-source');
        runtimeDir = join(tempDir, 'runtime');
        sqlitePath = join(fsRootDir(tempDir), getSiteDir(site), 'wp-content', 'database', '.ht.sqlite');
    }, 120000);

    afterAll(() => {
        if (server && server.exitCode === null) {
            server.kill('SIGTERM');
        }
        cleanupTempDir(tempDir);
        cleanupTempDir(databasePrepDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function runApplyRuntime(extraArgs) {
        return runImporter(importUrl(), tempDir, 'apply-runtime', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--runtime=php-builtin',
                `--output-dir=${runtimeDir}`,
                `--port=${port}`,
                ...extraArgs,
            ],
        });
    }

    it('files-pull downloads the site', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0,
            `files-pull failed (exit ${result.exitCode})\nstderr: ${result.stderr}`);
    });

    it('a SQLite database is prepared outside the pull state', () => {
        const sourceDomain = new URL(getSiteUrl(site)).origin;
        const result = runImporter(importUrl(), databasePrepDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0,
            `db-pull failed (exit ${result.exitCode})\nstderr: ${result.stderr}`);

        const applyResult = runImporter(importUrl(), databasePrepDir, 'db-apply', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--target-engine=sqlite',
                `--target-sqlite-path=${sqlitePath}`,
                '--rewrite-url', sourceDomain, targetUrl,
            ],
        });
        assert.equal(applyResult.exitCode, 0,
            `db-apply failed (exit ${applyResult.exitCode})\nstderr: ${applyResult.stderr}`);
        assert.ok(existsSync(sqlitePath), `Expected SQLite database at ${sqlitePath}`);

        // The state apply-runtime will read holds no database target.
        const state = JSON.parse(readFileSync(
            join(pullStateDirectory(tempDir, importUrl()), 'state.json'),
            'utf-8',
        ));
        assert.equal(state.apply.target_engine, null,
            'files-only pull state should not name a database target');
    });

    it('rejects --target-sqlite-path without --target-engine', () => {
        const result = runApplyRuntime([`--target-sqlite-path=${sqlitePath}`]);

        assert.notEqual(result.exitCode, 0, 'Expected apply-runtime to fail');
        const output = `${result.stderr}${result.stdout}`;
        assert.ok(output.includes('--target-sqlite-path'), `Expected the given option named: ${output}`);
        assert.ok(output.includes('--target-engine'), `Expected the missing option named: ${output}`);
    });

    it('generates a runtime from the target options alone', () => {
        const result = runApplyRuntime([
            '--target-engine=sqlite',
            `--target-sqlite-path=${sqlitePath}`,
        ]);

        assert.equal(result.exitCode, 0,
            `apply-runtime failed (exit ${result.exitCode})\nstderr: ${result.stderr}`);

        const runtime = readFileSync(join(runtimeDir, 'runtime.php'), 'utf-8');
        assert.ok(runtime.includes(`define('DB_FILE', '.ht.sqlite')`),
            'runtime.php should define DB_FILE from the given path');
        assert.ok(runtime.includes(join(fsRootDir(tempDir), getSiteDir(site), 'wp-content', 'database') + '/'),
            'runtime.php should define DB_DIR from the given path');
        assert.ok(existsSync(join(runtimeDir, 'sqlite-database-integration')),
            'the SQLite integration plugin should be copied into the output directory');
    });

    it('the generated runtime serves the site', async () => {
        server = spawn(PHP_BINARY, [
            '-S', `127.0.0.1:${port}`,
            '-t', join(fsRootDir(tempDir), getSiteDir(site)),
            join(runtimeDir, 'runtime.php'),
        ], { stdio: 'ignore' });

        let body = null;
        const deadline = Date.now() + 60000;
        while (Date.now() < deadline) {
            if (server.exitCode !== null) {
                throw new Error(`Runtime server exited early with code ${server.exitCode}`);
            }
            try {
                const response = await fetch(targetUrl, { redirect: 'manual' });
                if (response.status === 200) {
                    body = await response.text();
                    break;
                }
            } catch {
                // Server not listening yet.
            }
            await sleep(200);
        }

        assert.ok(body !== null, 'Expected the runtime to answer with HTTP 200');
        assert.ok(body.includes('E2E: basic'),
            `Expected the source site's blogname in the rendered page: ${body.slice(0, 500)}`);
    });

    it('does not record the target options in state', () => {
        const state = JSON.parse(readFileSync(
            join(pullStateDirectory(tempDir, importUrl()), 'state.json'),
            'utf-8',
        ));

        assert.equal(state.apply.target_engine, null,
            'apply-runtime options configure one run and must not be persisted');
        assert.equal(state.apply.target_sqlite_path, null,
            'apply-runtime options configure one run and must not be persisted');
    });
});
