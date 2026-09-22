/**
 * Test 74: pull with no credential generates a key, reports it, and stops
 * with the enrollment-needed exit code before sending any request. A second
 * run finds the key; since it is not enrolled, the site says so.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { runImporter, getSiteUrl, exportedKeyPath, readAuditLog } from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const EXIT_ENROLLMENT_NEEDED = 4;

// The helper's automatic preflight is skipped so the pull command itself
// meets the missing credential: a preflight run first would refuse instead.
const pullOptions = { autoResume: false, skipPreflight: true };

describe('Import: pull generates a key when no credential exists', () => {
    const site = 'pull-generates-key';

    beforeAll(async () => {
        await ensureSite(site);
    });

    it('writes key.pem, reports the public key, exits 4, and sends nothing', () => {
        const url = getSiteUrl(site);
        const stateDir = mkdtempSync(join(tmpdir(), 'reprint-pull-gen-'));
        const result = runImporter(url, stateDir, 'pull', pullOptions);

        assert.equal(result.exitCode, EXIT_ENROLLMENT_NEEDED, result.stdout + result.stderr);
        assert.ok(existsSync(exportedKeyPath(url, stateDir)));
        // The harness runs the importer without a TTY, so stdout is JSONL and
        // the key travels in the command report rather than as printed text.
        const records = result.stdout.trim().split('\n').map(line => JSON.parse(line));
        const report = records.at(-1);
        assert.equal(report.type, 'reprint_report');
        assert.equal(report.status, 'enrollment_needed');
        assert.equal(report.exit_code, EXIT_ENROLLMENT_NEEDED);
        assert.equal(report.key_path, exportedKeyPath(url, stateDir));
        assert.match(report.key_id, /^[0-9a-f]{16}$/);
        assert.match(report.public_key, /^MII[A-Za-z0-9+/]+=*$/, 'one-line public key');
        assert.match(report.message, /No credential found for this site/);
        assert.match(report.message, /run the same command again/);
        assert.match(report.message, /^MII[A-Za-z0-9+/]+=*$/m, 'one-line public key at column 0 of the message');
        // The audit log records every request the client sends.
        assert.doesNotMatch(readAuditLog(stateDir), /HTTP_REQUEST \|/,
            'no request was attempted before enrollment');
    });

    it('prints the enrollment instructions under the terminal presentation', () => {
        const url = getSiteUrl(site);
        const stateDir = mkdtempSync(join(tmpdir(), 'reprint-pull-gen-'));
        const result = runImporter(url, stateDir, 'pull', { ...pullOptions, extraArgs: ['--progress=tty'] });

        assert.equal(result.exitCode, EXIT_ENROLLMENT_NEEDED, result.stdout + result.stderr);
        assert.match(result.stdout, /No credential found for this site/);
        assert.match(result.stdout, /run the same command again/);
        assert.match(result.stdout, /^MII[A-Za-z0-9+/]+=*$/m, 'one-line public key at column 0');
    });

    it('a second run finds the key, sends a request, and reports it is not enrolled', () => {
        const url = getSiteUrl(site);
        const stateDir = mkdtempSync(join(tmpdir(), 'reprint-pull-gen-'));
        runImporter(url, stateDir, 'pull', pullOptions);
        const second = runImporter(url, stateDir, 'pull', pullOptions);

        assert.notEqual(second.exitCode, EXIT_ENROLLMENT_NEEDED);
        assert.notEqual(second.exitCode, 0);
        assert.match(second.stdout + second.stderr, /AUTH_UNKNOWN_KEY|not enrolled/);
        assert.match(second.stdout + second.stderr, /MII[A-Za-z0-9+/]+=*/, 'the public key is reprinted for enrollment');
        assert.match(readAuditLog(stateDir), /HTTP_REQUEST \|/, 'the second run sent a signed request');
    });
});
