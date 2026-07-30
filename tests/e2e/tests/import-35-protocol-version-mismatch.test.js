/**
 * Test 35: Protocol Version Mismatch Detection
 *
 * Verifies that preflight-assert correctly detects incompatible protocol
 * versions between the export plugin (remote) and the importer (client).
 * Tests all three failure modes: remote too old, client too old, and
 * remote not reporting a version at all.
 */
import { describe, it, beforeAll, afterAll, beforeEach } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, copyFileSync } from 'node:fs';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    getPullStatePath,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Protocol Version Mismatch', () => {
    const site = 'basic';
    let tempDir;

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function stateFilePath() {
        return getPullStatePath(tempDir);
    }

    function readState() {
        return JSON.parse(readFileSync(stateFilePath(), 'utf-8'));
    }

    function writeState(state) {
        writeFileSync(stateFilePath(), JSON.stringify(state, null, 2));
    }

    beforeAll(async () => {
        await ensureSite(site);
    });

    beforeEach(() => {
        // Fresh temp dir for each test so state doesn't leak between tests
        tempDir = createTempDir('e2e-protocol-version');

        // Run preflight to populate state
        const result = runImporter(importUrl(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Preflight failed:\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    afterAll(() => {
        if (tempDir) cleanupTempDir(tempDir);
    });

    it('passes when versions are compatible', () => {
        const result = runImporter(importUrl(), tempDir, 'preflight-assert', {
            secret: getSiteSecret(site),
        });

        assert.equal(result.exitCode, 0, `Expected exit 0:\nstdout: ${result.stdout}`);
        assert.ok(result.stdout.includes('[PASS] Protocol compatible'), `Expected PASS for protocol check:\n${result.stdout}`);
        assert.match(result.stdout, /remote v\d+, client v\d+/);
    });

    it('fails when remote protocol version is too old', () => {
        const state = readState();
        state.remote_protocol_version = 0;
        writeState(state);

        const result = runImporter(importUrl(), tempDir, 'preflight-assert', {
            secret: getSiteSecret(site),
        });

        assert.equal(result.exitCode, 1, `Expected exit 1:\nstdout: ${result.stdout}`);
        assert.ok(result.stdout.includes('[FAIL] Protocol compatible'), `Expected FAIL for protocol check:\n${result.stdout}`);
        assert.ok(result.stdout.includes('too old'), `Expected "too old" message:\n${result.stdout}`);
        assert.ok(result.stdout.includes('Update the export plugin'), `Expected update instruction:\n${result.stdout}`);
    });

    it('fails when client protocol version is too old for remote', () => {
        const state = readState();
        state.remote_protocol_min_version = 999;
        writeState(state);

        const result = runImporter(importUrl(), tempDir, 'preflight-assert', {
            secret: getSiteSecret(site),
        });

        assert.equal(result.exitCode, 1, `Expected exit 1:\nstdout: ${result.stdout}`);
        assert.ok(result.stdout.includes('[FAIL] Protocol compatible'), `Expected FAIL for protocol check:\n${result.stdout}`);
        assert.ok(result.stdout.includes('too old'), `Expected "too old" message:\n${result.stdout}`);
        assert.ok(result.stdout.includes('Update the importer'), `Expected update instruction:\n${result.stdout}`);
    });

    it('fails when remote does not report a protocol version', () => {
        const state = readState();
        delete state.remote_protocol_version;
        delete state.remote_protocol_min_version;
        writeState(state);

        const result = runImporter(importUrl(), tempDir, 'preflight-assert', {
            secret: getSiteSecret(site),
        });

        assert.equal(result.exitCode, 1, `Expected exit 1:\nstdout: ${result.stdout}`);
        assert.ok(result.stdout.includes('[FAIL] Protocol compatible'), `Expected FAIL for protocol check:\n${result.stdout}`);
        assert.ok(result.stdout.includes('does not report a protocol version'), `Expected missing version message:\n${result.stdout}`);
        assert.ok(result.stdout.includes('Update the export plugin'), `Expected update instruction:\n${result.stdout}`);
    });
});
