/**
 * Checks CSS download recovery through the CLI and real source HTTP responses.
 * Interrupted downloads must produce exactly one rewrite of each source URL.
 * Rejected mapping changes and preserve-local pulls must leave local CSS intact.
 */
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
    const largePath = join(getSiteDir(site), 'test-data/long-escaped-url.css');
    const largeCss = 'a{src:url("h' + '\\\n'.repeat(550000) + sourceUrl.slice(1) + '/photo.png")}';
    const limitPath = join(getSiteDir(site), 'test-data/image-set-nesting-limit.css');
    const limitCss = '/*' + 'a'.repeat(2 * chunkBytes) + '*/a{src:' + 'image-set('.repeat(129)
        + `"${sourceUrl}/photo.png"` + ')'.repeat(129) + '}';
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
                // Put a URL across every multipart boundary so any saved part
                // leaves an incomplete match to resume. Random CSS comments
                // make skipped or repeated bytes detectable and resist compression,
                // allowing body bytes to reach the importer before the source pauses.
                for (let part = 1; part <= 8; ++part) {
                    const beforeUrl = `*/\n.item-${part}{background:url("`;
                    const paddingBytes = part * chunkBytes - 10 - css.length - 2 - beforeUrl.length;
                    css += '/*' + randomBytes(Math.ceil(paddingBytes / 2)).toString('hex').slice(0, paddingBytes)
                        + beforeUrl + `${sourceUrl}/photo-${part}.png")}\n`;
                }
                writeFileSync(sourcePath, css);
                writeFileSync(limitPath, limitCss);
                writeFileSync(largePath, largeCss);
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
        // A paused source request may still be reading the hook-state file.
        // Keep release=true available until it exits; beforeEach replaces the
        // file before the next importer starts.
        cleanupTempDir(temporaryDirectory);
    });

    it('loads pre-CSS pull state and keeps raw downloads unchanged when new mappings are rejected', () => {
        // PullState at 6a0746c640f950fbb81df4e976acbbad6a1b3474 wrote this fixture
        // before CSS fields existed. Installing it before the first CLI call
        // exercises loading an older client's state, without altering an active download.
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
        assert.match(
            readFileSync(join(temporaryDirectory, 'audit.log'), 'utf8'),
            /TEMPORARY REQUEST FAILURE \| file_fetch.*missing completion chunk/,
        );
        assert.equal(readFileSync(join(fsRootDir(temporaryDirectory), sourcePath), 'utf8'), sourceCss.replaceAll(sourceUrl, targetUrl));
        assert.equal(readFileSync(sourcePath, 'utf8'), sourceCss, 'Source bytes must not change');
    }, 180000);

    // SIGKILL reaches the native PHP importer directly. With Playground it
    // would kill the shell wrapper instead, so this case is native-only.
    // The HTTP response-cutoff case also runs with the Playground importer.
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
        assert.equal(typeof savedPart.current_css_cursor.parser_cursor, 'string');
        assert.ok(Buffer.from(savedPart.current_css_cursor.pending_input_b64, 'base64').toString().includes(sourceUrl.slice(0, 10)));
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

    it('buffers a long escaped URL and rewrites it once its token is complete', () => {
        const result = runImporter(importUrl, temporaryDirectory, 'files-pull', {
            secret: getSiteSecret(site), autoResume: false,
            extraArgs: downloadArguments().map(argument => argument === sourcePath ? largePath : argument),
        });
        assert.equal(result.exitCode, 0, result.stdout + result.stderr);
        assert.equal(readFileSync(join(fsRootDir(temporaryDirectory), largePath), 'utf8'),
            `a{src:url("${targetUrl}/photo.png")}`);
        assert.equal(readFileSync(largePath, 'utf8'), largeCss, 'Source bytes must not change');
    }, 180000);

    it('reports excessive image-set nesting without completing a partial stylesheet', () => {
        // Completed comment bytes precede the failing token, so both the first
        // attempt and resume must leave the stylesheet incomplete.
        const argumentsForLimit = downloadArguments().map(argument => argument === sourcePath ? limitPath : argument);
        for (let attempt = 0; attempt < 2; ++attempt) {
            const result = runImporter(importUrl, temporaryDirectory, 'files-pull', {
                secret: getSiteSecret(site), autoResume: false, extraArgs: argumentsForLimit,
            });
            assert.equal(result.exitCode, 1, result.stdout + result.stderr);
            assert.match(result.stdout + result.stderr, /Cannot rewrite CSS file .*image-set-nesting-limit\.css/);
            assert.match(result.stdout + result.stderr, /nesting exceeds 128/);
            assert.notEqual(readFileSync(join(fsRootDir(temporaryDirectory), limitPath), 'utf8'), limitCss);
            assert.equal(readFileSync(limitPath, 'utf8'), limitCss, 'Source bytes must not change');
        }
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

    /** Pins the source file, part size, and URL mapping so resumed attempts use the same download settings. */
    function downloadArguments() {
        return ['--only', sourcePath, `--file-chunk-start=${chunkBytes}`, `--file-chunk-min=${chunkBytes}`, `--file-chunk-max=${chunkBytes}`,
            '--rewrite-url', sourceUrl, targetUrl];
    }
});
