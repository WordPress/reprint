/** Exercise response cutoff, process death, mapping conflicts, and preserved local CSS through the CLI. */
import { describe, it, beforeAll, beforeEach, afterEach } from 'vitest';
import assert from 'node:assert/strict';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { setTimeout as sleep } from 'node:timers/promises';
import { ensureSite } from '../lib/site-setup.js';
import {
    runImporter, createTempDir, cleanupTempDir, fsRootDir, getSiteDir, getSiteUrl, getSiteSecret,
    pullStateDirectory, writeTestHooks, removeTestHooks, writeHookState, readHookState, clearHookState,
} from '../lib/test-helpers.js';

describe('Import: generated CSS download failures', () => {
    const site = 'css-download-failures';
    const sourceUrl = new URL(getSiteUrl(site)).origin;
    const targetUrl = 'https://target.example.test/moved';
    const chunkBytes = 65536;
    const sourcePath = join(getSiteDir(site), 'test-data/generated.css');
    const importUrl = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    const projectRoot = join(import.meta.dirname, '../../..');
    const clientPath = process.env.CLIENT_PATH || join(projectRoot, 'packages/reprint-client/bin/reprint-client');
    const phpBinary = process.env.PHP_BINARY || 'php';
    let temporaryDirectory;
    let sourceCss;
    let child;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            afterCreate: async () => {
                let css = '';
                // A URL crosses every part boundary, including whichever part
                // the importer saves before the pause. Distinct random comment
                // bytes also expose skipped/duplicated data and pass through
                // compression buffers before the source waits for the test.
                for (let part = 1; part <= 8; ++part) {
                    const beforeUrl = `*/\n.item-${part}{background:url("`;
                    const paddingBytes = part * chunkBytes - 10 - css.length - 2 - beforeUrl.length;
                    css += '/*' + randomBytes(Math.ceil(paddingBytes / 2)).toString('hex').slice(0, paddingBytes)
                        + beforeUrl + `${sourceUrl}/photo-${part}.png")}\n`;
                }
                writeFileSync(sourcePath, css);
            },
        });
        sourceCss = readFileSync(sourcePath, 'utf8');
    });

    beforeEach(() => {
        temporaryDirectory = createTempDir('e2e-css-download-failure');
        clearHookState(site);
        writeHookState(site, { action: null, fired: false, release: false });
        writeTestHooks(site, `
/** Stop the selected CSS response after two parts; other files are unaffected. */
function test_hook_before_file_chunk($path, $offset, &$data) {
    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';
    $state = json_decode(file_get_contents($state_file), true);
    if ($path !== '${sourcePath}' || $offset < ${2 * chunkBytes} || !$state['action'] || $state['fired']) { return; }
    $state['fired'] = true;
    $state['offset'] = $offset;
    e2e_write_hook_state($state_file, $state);
    if ($state['action'] === 'pause') {
        // Hold the real response open until Node kills the importer after a
        // saved CSS part. The deadline only prevents a failed test leaving a worker stuck.
        $deadline = microtime(true) + 60;
        do {
            usleep(10000);
            $state = json_decode(file_get_contents($state_file), true);
        } while (!$state['release'] && microtime(true) < $deadline);
    }
    exit;
}
`);
    });

    afterEach(async () => {
        writeHookState(site, { action: null, fired: true, release: true });
        if (child && child.exitCode === null && child.signalCode === null) {
            const exited = once(child, 'exit');
            child.kill('SIGKILL');
            await exited;
        }
        child = null;
        removeTestHooks(site);
        // Leave the released barrier readable until any old source request ends.
        // The next case replaces it before starting another importer.
        cleanupTempDir(temporaryDirectory);
    });

    it('loads pre-CSS pull state and keeps raw downloads unchanged when new mappings are rejected', () => {
        // This fixture was serialized by PullState at 6a0746c640f950fbb81df4e976acbbad6a1b3474.
        // Seed an old checkpoint before starting the CLI; do not edit current saved state.
        const oldState = readFileSync(join(projectRoot, 'tests/fixtures/pull-state-before-css-rewriting.json'));
        const stateDirectory = pullStateDirectory(temporaryDirectory, importUrl);
        mkdirSync(stateDirectory, { recursive: true });
        writeFileSync(join(stateDirectory, 'state.json'), oldState);
        const result = runImporter(importUrl, temporaryDirectory, 'files-pull', {
            secret: getSiteSecret(site), autoResume: false, extraArgs: ['--only', sourcePath],
        });
        assert.equal(result.exitCode, 0, result.stdout + result.stderr);
        const localPath = join(fsRootDir(temporaryDirectory), sourcePath);
        assert.equal(readFileSync(localPath, 'utf8'), sourceCss);
        const mapped = runImporter(importUrl, temporaryDirectory, 'files-pull', {
            secret: getSiteSecret(site), autoResume: false, extraArgs: downloadArguments(),
        });
        assert.equal(mapped.exitCode, 1, mapped.stdout + mapped.stderr);
        assert.match(mapped.stdout + mapped.stderr, /Cannot change CSS URL mappings/);
        assert.equal(readFileSync(localPath, 'utf8'), sourceCss);
        assert.equal(readFileSync(sourcePath, 'utf8'), sourceCss);
    }, 180000);

    it('replays a cut-off CSS response without losing or rewriting the boundary URL twice', () => {
        writeHookState(site, { action: 'cutoff', fired: false, release: false });
        const result = runImporter(importUrl, temporaryDirectory, 'files-pull', {
            secret: getSiteSecret(site), autoResume: false, extraArgs: downloadArguments(),
        });
        assert.equal(result.exitCode, 0, result.stdout + result.stderr);
        assert.equal(readHookState(site).fired, true, 'The real source response must be cut off');
        assert.equal(readHookState(site).offset, 2 * chunkBytes, 'The cutoff must follow two source parts');
        assert.equal(readFileSync(join(fsRootDir(temporaryDirectory), sourcePath), 'utf8'), sourceCss.replaceAll(sourceUrl, targetUrl));
        assert.equal(readFileSync(sourcePath, 'utf8'), sourceCss, 'Source bytes must not change');
    }, 180000);

    // A Playground shell wrapper is not the PHP process whose death this case
    // checks. Its response-cutoff case above still runs in the Playground matrix.
    it.skipIf(phpBinary.endsWith('/playground-php.sh'))('a new CLI process resumes a saved CSS part after SIGKILL', async () => {
        const preflight = runImporter(importUrl, temporaryDirectory, 'preflight', { secret: getSiteSecret(site) });
        assert.equal(preflight.exitCode, 0, preflight.stdout + preflight.stderr);
        writeHookState(site, { action: 'pause', fired: false, release: false });
        child = spawn(phpBinary, [clientPath, 'files-pull', importUrl,
            `--state-dir=${temporaryDirectory}`, `--fs-root=${fsRootDir(temporaryDirectory)}`,
            `--secret=${getSiteSecret(site)}`, ...downloadArguments(),
        ], { stdio: ['ignore', 'pipe', 'pipe'] });
        let output = '';
        child.stdout.on('data', bytes => { output += bytes; });
        child.stderr.on('data', bytes => { output += bytes; });
        const exited = once(child, 'exit');
        const statePath = join(pullStateDirectory(temporaryDirectory, importUrl), 'state.json');
        let savedPart;
        const deadline = Date.now() + 45000;
        while (Date.now() < deadline && child.exitCode === null && child.signalCode === null) {
            const state = JSON.parse(readFileSync(statePath, 'utf8'));
            if (readHookState(site).fired && state.current_css_cursor && state.current_file_bytes > 0) {
                savedPart = state;
                break;
            }
            await sleep(20);
        }
        assert.ok(savedPart, `The importer must save a CSS part while the source request is open:\n${output}`);
        assert.equal(readHookState(site).offset, 2 * chunkBytes);
        assert.ok(Buffer.from(savedPart.current_css_cursor.pending_b64, 'base64').toString().includes(sourceUrl.slice(0, 10)));
        assert.equal(child.kill('SIGKILL'), true);
        assert.deepEqual(await exited, [null, 'SIGKILL']);
        writeHookState(site, { action: null, fired: true, release: true });
        const resumed = runImporter(importUrl, temporaryDirectory, 'files-pull', {
            secret: getSiteSecret(site), autoResume: false, extraArgs: downloadArguments(),
        });
        assert.equal(resumed.exitCode, 0, resumed.stdout + resumed.stderr);
        assert.equal(readFileSync(join(fsRootDir(temporaryDirectory), sourcePath), 'utf8'), sourceCss.replaceAll(sourceUrl, targetUrl));
        assert.equal(readFileSync(sourcePath, 'utf8'), sourceCss);
    }, 180000);

    it('rejects changed mappings on a repeat pull without changing the downloaded stylesheet', () => {
        const first = runImporter(importUrl, temporaryDirectory, 'files-pull', {
            secret: getSiteSecret(site), autoResume: false, extraArgs: downloadArguments(),
        });
        assert.equal(first.exitCode, 0, first.stdout + first.stderr);
        const localPath = join(fsRootDir(temporaryDirectory), sourcePath);
        const before = readFileSync(localPath, 'utf8');
        const changed = runImporter(importUrl, temporaryDirectory, 'files-pull', {
            secret: getSiteSecret(site), autoResume: false,
            extraArgs: downloadArguments().map(argument => argument === targetUrl ? 'https://different.example.test' : argument),
        });
        assert.equal(changed.exitCode, 1, changed.stdout + changed.stderr);
        assert.match(changed.stdout + changed.stderr, /Cannot change CSS URL mappings/);
        assert.equal(readFileSync(localPath, 'utf8'), before);
        assert.equal(before, sourceCss.replaceAll(sourceUrl, targetUrl));
    }, 180000);

    it('leaves an existing local stylesheet untouched in preserve-local mode', () => {
        const localPath = join(fsRootDir(temporaryDirectory), sourcePath);
        const localCss = `.customer{background:url("${sourceUrl}/my-local-image.png")}\n`;
        mkdirSync(dirname(localPath), { recursive: true });
        writeFileSync(localPath, localCss);
        const result = runImporter(importUrl, temporaryDirectory, 'files-pull', {
            secret: getSiteSecret(site), autoResume: false,
            extraArgs: [...downloadArguments(), '--on-fs-root-nonempty=preserve-local'],
        });
        assert.equal(result.exitCode, 0, result.stdout + result.stderr);
        assert.equal(readFileSync(localPath, 'utf8'), localCss);
        assert.equal(readFileSync(sourcePath, 'utf8'), sourceCss);
    }, 180000);

    /** Keep the CLI's real file selection and multipart size identical across attempts. */
    function downloadArguments() {
        return ['--only', sourcePath, `--file-chunk-start=${chunkBytes}`, `--file-chunk-min=${chunkBytes}`, `--file-chunk-max=${chunkBytes}`,
            '--rewrite-url', sourceUrl, targetUrl];
    }
});
