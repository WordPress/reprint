/**
 * Test 41: Playground CLI runtime target
 *
 * Verifies the apply-runtime --runtime=playground-cli flow:
 * 1. files-pull downloads the remote site
 * 2. db-pull + db-apply import the database into SQLite
 * 3. apply-runtime --runtime=playground-cli generates runtime.php,
 *    blueprint.json, and start.sh with the correct structure
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir, PHP_BINARY, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const CLIENT_PATH = process.env.CLIENT_PATH || join(PROJECT_ROOT, 'packages', 'reprint-client', 'bin', 'reprint-client');
const itWhenRustExtensionConfigured = process.env.WP_MYSQL_PARSER_EXTENSION_MANIFEST ? it : it.skip;

describe('Import: Playground CLI runtime', () => {
    const site = 'basic';
    const port = 9487;
    let tempDir;
    let runtimeDir;

    beforeAll(async () => {
        await ensureSite(site, {
            db: 'sample',
            files: 'sample',
        });

        tempDir = createTempDir('e2e-playground-cli');
        runtimeDir = join(tempDir, 'runtime');
    }, 120000);

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    itWhenRustExtensionConfigured('loads and selects the Rust wp_mysql_parser extension in Playground PHP', () => {
        const output = execFileSync(PHP_BINARY, [
            join(PROJECT_ROOT, 'tests', 'e2e', 'ci', 'verify-wp-mysql-parser.php'),
            PROJECT_ROOT,
            'parser',
        ], {
            encoding: 'utf-8',
            timeout: 120000,
        }).trim();
        const details = JSON.parse(output);

        assert.equal(details.wp_mysql_parser, 'enabled');
        assert.equal(details.native_lexer, 'verified');
        assert.equal(details.native_parser, 'verified');
        assert.equal(details.sqlite_driver_parser, 'verified');
    });

    it('files-pull downloads the site', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0,
            `files-pull failed (exit ${result.exitCode})\nstderr: ${result.stderr}`);

        const state = JSON.parse(readFileSync(
            join(pullStateDirectory(tempDir, importUrl()), 'state.json'),
            'utf-8',
        ));
        assert.equal(state.active_resumable_command.completion_state, 'complete');
    });

    it('db-pull downloads the SQL dump', () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0,
            `db-pull failed (exit ${result.exitCode})\nstderr: ${result.stderr}`);

        assert.ok(existsSync(join(tempDir, 'db.sql')), 'db.sql should exist');
    });

    it('db-apply imports into SQLite', () => {
        const sourceDomain = new URL(getSiteUrl(site)).origin;
        const result = runImporter(importUrl(), tempDir, 'db-apply', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--target-engine=sqlite',
                `--target-sqlite-path=${join(fsRootDir(tempDir), getSiteDir(site), 'wp-content', 'database', '.ht.sqlite')}`,
                '--rewrite-url', sourceDomain, `http://127.0.0.1:${port}`,
            ],
        });
        assert.equal(result.exitCode, 0,
            `db-apply failed (exit ${result.exitCode})\nstderr: ${result.stderr}`);
    });

    it('apply-runtime generates playground-cli files', () => {
        execFileSync(PHP_BINARY, [
            CLIENT_PATH,
            'apply-runtime',
            importUrl(),
            `--state-dir=${tempDir}`,
            `--fs-root=${fsRootDir(tempDir)}`,
            `--runtime=playground-cli`,
            `--output-dir=${runtimeDir}`,
            `--port=${port}`,
        ], {
            encoding: 'utf-8',
            timeout: 30000,
        });

        assert.ok(existsSync(join(runtimeDir, 'runtime.php')), 'runtime.php should exist');
        assert.ok(existsSync(join(runtimeDir, 'blueprint.json')), 'blueprint.json should exist');
        assert.ok(existsSync(join(runtimeDir, 'start.sh')), 'start.sh should exist');
    });

    it('blueprint.json is valid and has the correct schema', () => {
        const blueprint = JSON.parse(readFileSync(join(runtimeDir, 'blueprint.json'), 'utf-8'));
        assert.equal(blueprint.$schema, 'https://playground.wordpress.net/blueprint-schema.json');
        assert.equal(blueprint.landingPage, '/');
    });

    it('runtime.php suppresses display_errors and uses VFS paths', () => {
        const runtime = readFileSync(join(runtimeDir, 'runtime.php'), 'utf-8');
        assert.ok(runtime.includes("ini_set('display_errors', '0')"),
            'runtime.php should suppress display_errors');
        // Should NOT contain the SQLite lazy-loader — Playground handles
        // SQLite natively.
        assert.ok(!runtime.includes('Streaming_SQLite_Loader'),
            'runtime.php should not contain the custom SQLite loader');
    });

    it('start.sh uses the required CLI flags', () => {
        const startSh = readFileSync(join(runtimeDir, 'start.sh'), 'utf-8');
        assert.ok(startSh.includes('--wordpress-install-mode=do-not-attempt-installing'),
            'start.sh should use do-not-attempt-installing');
        assert.ok(startSh.includes('--follow-symlinks'),
            'start.sh should enable follow-symlinks');
        assert.ok(startSh.includes('--mount-before-install='),
            'start.sh should have mount-before-install flags');
        assert.ok(startSh.includes(`--port=${port}`),
            'start.sh should use the configured port');
        assert.ok(startSh.includes('npx @wp-playground/cli'),
            'start.sh should invoke Playground CLI');
    });

    it('start.sh mounts runtime.php as a mu-plugin', () => {
        const startSh = readFileSync(join(runtimeDir, 'start.sh'), 'utf-8');
        assert.ok(
            startSh.includes('0-playground-runtime.php'),
            'start.sh should mount runtime.php as 0-playground-runtime.php mu-plugin',
        );
    });
});
