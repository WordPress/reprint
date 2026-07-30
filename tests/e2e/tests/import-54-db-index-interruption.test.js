/**
 * Test 54: Database index response interruption.
 *
 * The source exits after sending its table-stat parts but before sending the
 * completion part. The first db-index invocation must retain partial state;
 * a later invocation resumes from the last complete table-stat part.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    writeTestHooks, removeTestHooks,
    readHookState, clearHookState,
    getPullStatePath,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Database Index Response Interruption', () => {
    const site = 'db-index-interruption';
    const hookState = `/srv/e2e-sites/.e2e-hook-state-${site}`;
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-db-index-interruption');
        clearHookState(site);
    });

    afterAll(() => {
        removeTestHooks(site);
        clearHookState(site);
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('first run exits partial when the completion part is interrupted', () => {
        writeTestHooks(site, [
            'function test_hook_before_completion($status, $gz, $boundary) {',
            `    if (file_exists('${hookState}')) { return; }`,
            `    file_put_contents('${hookState}', '{"fired":true}');`,
            '    exit(1);',
            '}',
        ].join('\n'));

        const result = runImporter(importUrl(), tempDir, 'db-index', {
            secret: getSiteSecret(site),
            autoResume: false,
        });

        assert.equal(
            result.exitCode,
            2,
            `Expected exit 2 after the interrupted db-index response\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );
        assert.deepEqual(
            readHookState(site),
            { fired: true },
            'Expected the completion hook to run',
        );

        const state = JSON.parse(
            readFileSync(getPullStatePath(tempDir), 'utf-8'),
        );
        assert.equal(
            state.active_resumable_command.completion_state,
            'partial',
            'Expected db-index to retain a partial checkpoint',
        );
    });

    it('resume completes without duplicating table rows', () => {
        removeTestHooks(site);

        const result = runImporter(importUrl(), tempDir, 'db-index', {
            secret: getSiteSecret(site),
        });
        assert.equal(
            result.exitCode,
            0,
            `Expected db-index resume to complete\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );

        const lines = readFileSync(
            join(tempDir, 'db-tables.jsonl'),
            'utf-8',
        ).trim().split('\n').filter(Boolean);
        const tableNames = lines.map((line) => JSON.parse(line).name);

        assert.ok(tableNames.length > 0, 'Expected table rows after resume');
        assert.equal(
            new Set(tableNames).size,
            tableNames.length,
            'Expected each table exactly once after resume',
        );
    });
});
