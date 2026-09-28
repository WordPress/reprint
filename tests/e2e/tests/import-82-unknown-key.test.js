/**
 * Test 80: a key on disk that is not enrolled gets unknown_key, and the
 * client reprints the public key so the user never digs it out of state.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { mkdtempSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { runImporter, getSiteUrl } from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: unknown key', () => {
    const site = 'unknown-key';
    let stateDir;
    let publicKey;

    beforeAll(async () => {
        await ensureSite(site);   // enrolls the harness key, not ours
        stateDir = mkdtempSync(join(tmpdir(), 'uk-'));
        const mine = runImporter(getSiteUrl(site), stateDir, 'keygen', { autoResume: false, skipPreflight: true });
        assert.equal(mine.exitCode, 0, mine.stdout + mine.stderr);
        publicKey = mine.stdout.match(/^(MII[A-Za-z0-9+/]+=*)$/m)[1];
    });

    it('reports unknown_key and reprints the public key', () => {
        const result = runImporter(getSiteUrl(site), stateDir, 'preflight', { autoResume: false });
        assert.notEqual(result.exitCode, 0);
        assert.match(result.stdout + result.stderr, /AUTH_UNKNOWN_KEY|not enrolled/);
        assert.ok((result.stdout + result.stderr).includes(publicKey), 'public key is reprinted');
    });
});
