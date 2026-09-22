/**
 * Test 79: --private-key wins over key.pem in the state directory.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { mkdtempSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { runImporter, getSiteUrl } from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const keygenOptions = { autoResume: false, skipPreflight: true };

describe('Import: --private-key overrides the state directory key', () => {
    const site = 'private-key-override';
    let stateDir;
    let enrolledPath;

    beforeAll(async () => {
        const url = getSiteUrl(site);
        stateDir = mkdtempSync(join(tmpdir(), 'ov-'));
        enrolledPath = join(mkdtempSync(join(tmpdir(), 'ov-out-')), 'enrolled.pem');
        const enrolled = runImporter(url, mkdtempSync(join(tmpdir(), 'ov-tmp-')), 'keygen', {
            ...keygenOptions, extraArgs: [`--out=${enrolledPath}`],
        });
        assert.equal(enrolled.exitCode, 0, enrolled.stdout + enrolled.stderr);
        const publicKey = enrolled.stdout.match(/^(MII[A-Za-z0-9+/]+=*)$/m)[1];
        // A different, un-enrolled key in the state directory.
        const stateKey = runImporter(url, stateDir, 'keygen', keygenOptions);
        assert.equal(stateKey.exitCode, 0, stateKey.stdout + stateKey.stderr);
        await ensureSite(site, { publicKeys: [publicKey] });
    });

    it('signs with the flag key, not the state key', () => {
        const url = getSiteUrl(site);
        const withFlag = runImporter(url, stateDir, 'preflight', { autoResume: false, extraArgs: [`--private-key=${enrolledPath}`] });
        assert.equal(withFlag.exitCode, 0, withFlag.stdout + withFlag.stderr);

        const withoutFlag = runImporter(url, stateDir, 'preflight', { autoResume: false });
        assert.notEqual(withoutFlag.exitCode, 0);
        assert.match(withoutFlag.stdout + withoutFlag.stderr, /AUTH_UNKNOWN_KEY|not enrolled/);
    });
});
