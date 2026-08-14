/**
 * Test 54b: Database pull index response interruption.
 *
 * The source exits after sending its table-stat parts but before sending the
 * completion part. The first db-pull invocation must return exit code 2 and
 * retain partial state; a later process must continue that db-pull lifecycle
 * instead of silently replacing it with a new one.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    writeTestHooks, removeTestHooks,
    readHookState, clearHookState, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Database Pull Response Interruption', () => {
    const site = 'db-pull-index-interruption';
    const hookState = `/srv/e2e-sites/.e2e-hook-state-${site}`;
    let tempDir;
    let savedDbIndexCursor;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-db-pull-index-interruption');
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

    it('first db-pull process exits partial when the db-index completion part is interrupted', () => {
        writeTestHooks(site, [
            'function test_hook_before_completion($status, $gz, $boundary) {',
            "    if (($_GET['endpoint'] ?? '') !== 'db_index') { return; }",
            `    $state = file_exists('${hookState}')`,
            `        ? json_decode(file_get_contents('${hookState}'), true)`,
            '        : [];',
            "    $state['requests'] = ($state['requests'] ?? 0) + 1;",
            "    $state['request_cursor'][] = $_GET['cursor'] ?? null;",
            `    file_put_contents('${hookState}', json_encode($state));`,
            "    if ($state['requests'] === 1) {",
            "        $progress = json_encode(['phase' => 'tables']);",
            '        $gz->write(',
            '            "--{$boundary}\\r\\n" .',
            '            "Content-Type: application/json\\r\\n" .',
            '            "Content-Length: " . strlen($progress) . "\\r\\n" .',
            '            "X-Chunk-Type: progress\\r\\n" .',
            '            "\\r\\n" .',
            '            $progress . "\\r\\n"',
            '        );',
            '        $gz->write("--{$boundary}--\\r\\n");',
            '        $gz->finish();',
            '        exit(1);',
            '    }',
            '}',
        ].join('\n'));

        const result = runImporter(importUrl(), tempDir, 'db-pull', {
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
            { requests: 1, request_cursor: [null] },
            'Expected the first db-index request to start without a cursor',
        );

        const state = JSON.parse(
            readFileSync(join(pullStateDirectory(tempDir, importUrl()), 'state.json'), 'utf-8'),
        );
        assert.equal(
            state.active_resumable_command.completion_state,
            'partial',
            'Expected db-pull to retain a partial checkpoint',
        );
        assert.equal(
            state.active_resumable_command.current_stage,
            'db-index',
            'Expected db-pull to stop in its db-index stage',
        );
        savedDbIndexCursor = state.active_resumable_command.remote_cursor;
        assert.ok(
            savedDbIndexCursor,
            'Expected the interrupted db-index response to save its table cursor',
        );
        assert.equal(state.sql_output, 'file');
        assert.equal(
            existsSync(join(pullStateDirectory(tempDir, importUrl()), 'database-dump.intent')),
            true,
            'Expected current file output to retain its download intent',
        );
    });

    it('the next db-pull process continues the partial lifecycle and completes', () => {
        const changedMode = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            autoResume: false,
            extraArgs: ['--sql-output=stdout'],
        });
        assert.equal(
            changedMode.exitCode,
            1,
            `Expected output-mode drift to fail:\n${changedMode.stderr}\n${changedMode.stdout}`,
        );
        assert.match(
            `${changedMode.stderr}\n${changedMode.stdout}`,
            /Cannot change --sql-output/,
        );
        assert.equal(
            readHookState(site).requests,
            1,
            'Output-mode drift requested another database-index response',
        );

        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(
            result.exitCode,
            0,
            `Expected db-pull resume to complete\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );

        const hookState = readHookState(site);
        assert.equal(hookState.requests, 2, 'Expected a second db-index request');
        assert.equal(
            hookState.request_cursor[1],
            savedDbIndexCursor,
            'Expected the second db-index request to carry the first process cursor',
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

        const state = JSON.parse(
            readFileSync(join(pullStateDirectory(tempDir, importUrl()), 'state.json'), 'utf-8'),
        );
        assert.equal(
            state.active_resumable_command.completion_state,
            'complete',
            'Expected db-pull to complete after resuming db-index',
        );
        assert.match(
            result.stdout,
            /"event":"resuming".*"command":"db-pull"/,
            'Expected the second process to report that it continued db-pull',
        );
    });
});
