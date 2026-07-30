/**
 * Test 45: Runtime file download — completion chunk handling
 *
 * Verifies that when auto_prepend_file points to a real file, preflight
 * downloads it into runtime_files/ without a spurious "Fetch failed" error.
 *
 * The bug: fetch_files_into()'s on_chunk callback didn't handle the
 * "completion" chunk type, so fetch_streaming() always threw
 * "missing completion chunk from server" even though the file was
 * already saved.  The exception was caught and logged as non-fatal,
 * so the download still counted — but the error log was misleading.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, readAuditLog,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { writeFileSync, mkdirSync } from 'node:fs';
import { execSync } from 'node:child_process';

describe('Import: Runtime file download', () => {
    const site = 'runtime-download';
    let tempDir;
    let preflightResult;

    function importUrlWithDirectory() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    beforeAll(async () => {
        const siteDir = getSiteDir(site);
        const prependPath = join(siteDir, 'scripts', 'env.php');

        await ensureSite(site, {
            afterCreate: async (dir) => {
                // Create the prepend script inside the site directory.
                mkdirSync(join(dir, 'scripts'), { recursive: true });
                writeFileSync(
                    join(dir, 'scripts', 'env.php'),
                    '<?php // auto_prepend stub for e2e test\n',
                );

                // Configure PHP to use this as auto_prepend_file via .user.ini.
                // PHP-FPM reads .user.ini per-directory for PHP_INI_PERDIR settings.
                writeFileSync(
                    join(dir, '.user.ini'),
                    `auto_prepend_file = ${prependPath}\n`,
                );
            },
        });

        tempDir = createTempDir('e2e-runtime-download');

        // PHP-FPM can briefly miss a freshly-written .user.ini when a worker
        // has a stale realpath/stat cache for the site directory (e.g. from
        // a parent-directory lookup that predates this site's creation).
        // The pool sets user_ini.cache_ttl=0 and realpath_cache_ttl=0, but
        // caches survive until the current request ends, so retry preflight
        // a few times until auto_prepend_file shows up. Each preflight wipes
        // tempDir state, so retries are safe.
        const maxAttempts = 5;
        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
            preflightResult = runImporter(importUrlWithDirectory(), tempDir, 'preflight', {
                secret: getSiteSecret(site),
            });
            assert.equal(preflightResult.exitCode, 0,
                `Expected exit 0\nstderr: ${preflightResult.stderr}\nstdout: ${preflightResult.stdout}`);

            const state = JSON.parse(readFileSync(join(tempDir, 'pull/state.json'), 'utf-8'));
            const prepend = state.preflight?.data?.runtime?.ini_get_all?.auto_prepend_file ?? '';
            if (prepend.includes('scripts/env.php')) {
                break;
            }
            if (attempt === maxAttempts) {
                break;
            }
            // Touch .user.ini so any worker that cached a missing-file stat
            // re-checks on the next request, then brief pause for FPM workers
            // to cycle.
            try {
                execSync(`sudo touch ${JSON.stringify(join(siteDir, '.user.ini'))}`);
            } catch {}
            await new Promise((r) => setTimeout(r, 500));
        }
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    it('preflight state reports auto_prepend_file path', () => {
        const state = JSON.parse(readFileSync(join(tempDir, 'pull/state.json'), 'utf-8'));
        const iniAll = state.preflight?.data?.runtime?.ini_get_all;
        const prepend = iniAll?.auto_prepend_file ?? '';
        assert.ok(
            prepend.includes('scripts/env.php'),
            `auto_prepend_file should point to scripts/env.php, got: ${prepend}`,
        );
    });

    it('downloads the prepend script into runtime_files/', () => {
        // Glob for any file under runtime_files/scripts/ if exact path differs
        assert.ok(
            existsSync(join(tempDir, 'runtime_files')),
            'runtime_files/ directory should exist',
        );

        // The path inside runtime_files mirrors the absolute server path.
        // Find it by checking the audit log for the saved path.
        const log = readAuditLog(tempDir);
        const saveMatch = log.match(/Saved .+ → (.+)/);
        assert.ok(saveMatch, 'Audit log should contain a "Saved" line for the prepend script');

        const savedPath = saveMatch[1];
        assert.ok(
            savedPath.includes('env.php'),
            `Saved path should contain env.php, got: ${savedPath}`,
        );
    });

    it('preflight stdout is single-line JSON (not pretty-printed)', () => {
        const lines = preflightResult.stdout.trim().split('\n');
        const lastLine = lines[lines.length - 1];
        let parsed;
        try {
            parsed = JSON.parse(lastLine);
        } catch {
            assert.fail(
                `Last stdout line is not valid JSON: ${lastLine.substring(0, 200)}`,
            );
        }
        assert.ok(parsed.timestamp, 'Parsed result should have a timestamp field');
        assert.ok(
            !lastLine.includes('\n'),
            'Preflight result must be a single line so last-line trackers can capture it',
        );
    });

    it('audit log does NOT contain "Fetch failed"', () => {
        const log = readAuditLog(tempDir);
        assert.ok(
            !log.includes('Fetch failed'),
            'Audit log should not contain "Fetch failed" — the completion chunk should be handled.\n' +
            'Relevant log lines:\n' +
            log.split('\n').filter(l => l.includes('RUNTIME') || l.includes('Fetch') || l.includes('Saved')).join('\n'),
        );
    });
});
