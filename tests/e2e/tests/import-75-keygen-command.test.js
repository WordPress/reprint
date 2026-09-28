/**
 * Test 73: reprint keygen writes a private key beside the remote's state
 * and prints a one-line public key ready to paste into the site.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, statSync, mkdtempSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { runImporter, getSiteUrl, exportedKeyPath } from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

// keygen never sends a request, so the helper's automatic preflight is
// skipped: without a credential that preflight would refuse before keygen ran.
const keygenOptions = { autoResume: false, skipPreflight: true };

describe('Import: keygen command', () => {
    const site = 'keygen-command';

    beforeAll(async () => {
        await ensureSite(site);
    });

    it('writes key.pem with mode 0600 into the remote state directory', () => {
        const url = getSiteUrl(site);
        const stateDir = mkdtempSync(join(tmpdir(), 'reprint-keygen-'));
        const result = runImporter(url, stateDir, 'keygen', keygenOptions);
        assert.equal(result.exitCode, 0, result.stdout + result.stderr);

        const path = exportedKeyPath(url, stateDir);
        assert.ok(existsSync(path), `expected ${path}`);
        if (process.platform !== 'win32') {
            assert.equal(statSync(path).mode & 0o777, 0o600);
        }
    });

    it('prints the key id and a one-line public key', () => {
        const result = runImporter(getSiteUrl(site), mkdtempSync(join(tmpdir(), 'reprint-keygen-')), 'keygen', keygenOptions);
        assert.equal(result.exitCode, 0, result.stdout + result.stderr);
        assert.match(result.stdout, /Key id:\s+[0-9a-f]{16}/);
        // The key sits at column 0 on its own line so a whole-line copy
        // carries nothing but the key into the enrollment form.
        assert.match(result.stdout, /^MII[A-Za-z0-9+/]+=*$/m, 'one-line public key at column 0');
        assert.match(result.stdout, /Tools .* Reprint Server/);
    });

    it('refuses to overwrite without --force, and obeys --force', () => {
        const url = getSiteUrl(site);
        const dir = mkdtempSync(join(tmpdir(), 'reprint-keygen-'));
        assert.equal(runImporter(url, dir, 'keygen', keygenOptions).exitCode, 0);
        const second = runImporter(url, dir, 'keygen', keygenOptions);
        assert.notEqual(second.exitCode, 0);
        assert.match(second.stdout + second.stderr, /--force/);
        const forced = runImporter(url, dir, 'keygen', { ...keygenOptions, extraArgs: ['--force'] });
        assert.equal(forced.exitCode, 0, forced.stdout + forced.stderr);
    });
});
