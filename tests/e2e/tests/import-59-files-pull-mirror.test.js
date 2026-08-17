/**
 * Test 59: files-pull mirror and catch-up modes
 *
 * Uses the real CLI and HTTP endpoint to check the difference between the two
 * modes. Mirror restores the current remote tree after local changes. Catch-up
 * keeps those same local changes when the remote index did not change.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import {
    existsSync,
    lstatSync,
    mkdirSync,
    readdirSync,
    readlinkSync,
    readFileSync,
    rmSync,
    statSync,
    unlinkSync,
    writeFileSync,
} from 'node:fs';
import { join } from 'node:path';
import {
    assertTreesMatch,
    cleanupTempDir,
    clearHookState,
    createTempDir,
    fsRootDir,
    getSiteDir,
    getSiteSecret,
    getSiteUrl,
    pullStateDirectory,
    removeTestHooks,
    runImporter,
    writeHookState,
    writeTestHooks,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: files-pull mirror and catch-up modes', { timeout: 300000 }, () => {
    const site = 'files-pull-mirror';
    let mirrorTempDir;
    let catchUpTempDir;
    let defaultMirrorTempDir;
    let emptyRemoteMirrorTempDir;
    let nonemptyMirrorTempDir;
    let remappedMirrorTempDir;
    let typeChangeMirrorTempDir;

    const remoteConflictFile = join(
        getSiteDir(site),
        'test-data',
        'local-and-remote-change.txt',
    );
    const remoteDeletedFile = join(
        getSiteDir(site),
        'test-data',
        'deleted-remotely.txt',
    );
    const remoteAddedFile = join(
        getSiteDir(site),
        'test-data',
        'added-remotely.txt',
    );
    const remoteEmptyDirectory = join(
        getSiteDir(site),
        'test-data',
        'mirror-empty-directory',
    );
    const remoteSymlink = join(
        getSiteDir(site),
        'test-data',
        'mirror-link',
    );
    const remoteSymlinkTarget = join(
        getSiteDir(site),
        'test-data',
        'mirror-link-target.txt',
    );

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                writeFileSync(
                    join(siteDir, 'test-data', 'local-and-remote-change.txt'),
                    'initial remote content\n',
                );
                writeFileSync(
                    join(siteDir, 'test-data', 'deleted-remotely.txt'),
                    'present before remote deletion\n',
                );
            },
        });
        resetRemoteMirrorFixtures();
        mirrorTempDir = createTempDir('e2e-files-pull-mirror');
        catchUpTempDir = createTempDir('e2e-files-pull-catch-up');
        defaultMirrorTempDir = createTempDir('e2e-files-pull-default-mirror');
        emptyRemoteMirrorTempDir = createTempDir('e2e-files-pull-empty-remote');
        nonemptyMirrorTempDir = createTempDir('e2e-files-pull-mirror-nonempty');
        remappedMirrorTempDir = createTempDir('e2e-files-pull-mirror-remapped');
        typeChangeMirrorTempDir = createTempDir('e2e-files-pull-mirror-types');
        clearHookState(site);
        removeTestHooks(site);
    });

    afterAll(() => {
        removeTestHooks(site);
        clearHookState(site);
        cleanupTempDir(mirrorTempDir);
        cleanupTempDir(catchUpTempDir);
        cleanupTempDir(defaultMirrorTempDir);
        cleanupTempDir(emptyRemoteMirrorTempDir);
        cleanupTempDir(nonemptyMirrorTempDir);
        cleanupTempDir(remappedMirrorTempDir);
        cleanupTempDir(typeChangeMirrorTempDir);
        writeRemoteFile(remoteConflictFile, 'initial remote content\n');
        writeRemoteFile(remoteDeletedFile, 'present before remote deletion\n');
        removeRemotePath(remoteAddedFile);
        resetRemoteMirrorFixtures();
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function localSiteRoot(tempDir) {
        return join(fsRootDir(tempDir), getSiteDir(site));
    }

    function emptyRemoteImportUrl() {
        return `${getSiteUrl(site)}&directory=${remoteEmptyDirectory}`;
    }

    function changeLocalTree(localRoot) {
        writeFileSync(join(localRoot, 'test-data', 'hello.txt'), 'local edit\n');
        unlinkSync(join(localRoot, 'test-data', 'subdir', 'nested', 'deep.txt'));
        mkdirSync(join(localRoot, 'test-data', 'local-only'), { recursive: true });
        writeFileSync(
            join(localRoot, 'test-data', 'local-only', 'debug.log'),
            'local only\n',
        );
    }

    function writeRemoteFile(path, contents) {
        execFileSync('sudo', ['tee', path], {
            input: contents,
            stdio: ['pipe', 'ignore', 'pipe'],
        });
    }

    function removeRemotePath(path) {
        execFileSync('sudo', ['rm', '-rf', path]);
    }

    function resetRemoteMirrorFixtures() {
        removeRemotePath(remoteEmptyDirectory);
        removeRemotePath(remoteSymlink);
        execFileSync('sudo', ['mkdir', '-p', remoteEmptyDirectory]);
        writeRemoteFile(remoteSymlinkTarget, 'remote symlink target\n');
        execFileSync('sudo', ['ln', '-s', 'mirror-link-target.txt', remoteSymlink]);
    }

    function runFilesPull(tempDir, mode, options = {}, url = importUrl()) {
        const modeArgument = mode === null ? [] : [`--mode=${mode}`];
        return runImporter(url, tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            timeout: 180000,
            wallTimeout: 240000,
            ...options,
            extraArgs: [...modeArgument, ...(options.extraArgs || [])],
        });
    }

    function resetCompletedFilesPull(tempDir) {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
        });
        assert.equal(
            result.exitCode,
            0,
            `Failed to start the next files-pull\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );
    }

    it('downloads the same remote tree in both modes', () => {
        for (const [tempDir, mode] of [
            [mirrorTempDir, 'mirror'],
            [catchUpTempDir, 'catch-up'],
        ]) {
            const result = runFilesPull(tempDir, mode);
            assert.equal(
                result.exitCode,
                0,
                `${mode} initial pull failed\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
            );
            assertTreesMatch(getSiteDir(site), localSiteRoot(tempDir));
        }
    });

    it('replaces an existing local tree during the first mirror', () => {
        const localRoot = localSiteRoot(nonemptyMirrorTempDir);
        mkdirSync(join(localRoot, 'test-data', 'local-only'), { recursive: true });
        writeFileSync(join(localRoot, 'test-data', 'hello.txt'), 'old local content\n');
        writeFileSync(
            join(localRoot, 'test-data', 'local-only', 'debug.log'),
            'local only\n',
        );

        const result = runFilesPull(nonemptyMirrorTempDir, 'mirror');
        assert.equal(
            result.exitCode,
            0,
            `Initial nonempty mirror failed\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );
        assertTreesMatch(getSiteDir(site), localRoot);
    });

    it('keeps catch-up as the default and rejects a mode change without abort', () => {
        const initial = runFilesPull(defaultMirrorTempDir, null);
        assert.equal(
            initial.exitCode,
            0,
            `Default files-pull failed\nstderr: ${initial.stderr}\nstdout: ${initial.stdout}`,
        );
        assertTreesMatch(getSiteDir(site), localSiteRoot(defaultMirrorTempDir));

        const changedMode = runFilesPull(defaultMirrorTempDir, 'mirror', {
            autoResume: false,
        });
        assert.equal(changedMode.exitCode, 1);
        assert.match(
            `${changedMode.stderr}\n${changedMode.stdout}`,
            /Cannot change --mode after files-pull starts/,
        );
    });

    it('restores a same-size local edit when the remote index did not change', () => {
        resetCompletedFilesPull(defaultMirrorTempDir);
        const localPath = join(
            localSiteRoot(defaultMirrorTempDir),
            'test-data',
            'hello.txt',
        );
        const remoteContents = readFileSync(
            join(getSiteDir(site), 'test-data', 'hello.txt'),
        );
        const retainedCtimeSeconds = Math.floor(statSync(localPath).ctimeMs / 1000);

        execFileSync('sleep', ['1']);
        const localEdit = Buffer.alloc(remoteContents.length, 0x78);
        assert.notDeepEqual(localEdit, remoteContents);
        writeFileSync(localPath, localEdit);
        assert.equal(statSync(localPath).size, remoteContents.length);
        assert.ok(
            Math.floor(statSync(localPath).ctimeMs / 1000) > retainedCtimeSeconds,
            'The local ctime must advance so the retained index detects the edit',
        );

        const result = runFilesPull(defaultMirrorTempDir, 'mirror');
        assert.equal(
            result.exitCode,
            0,
            `Same-size mirror failed\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );
        assert.deepEqual(readFileSync(localPath), remoteContents);
    });

    it('restores local file, directory, empty-directory, and symlink type changes', () => {
        const initial = runFilesPull(typeChangeMirrorTempDir, 'mirror');
        assert.equal(
            initial.exitCode,
            0,
            `Type-change setup failed\nstderr: ${initial.stderr}\nstdout: ${initial.stdout}`,
        );
        resetCompletedFilesPull(typeChangeMirrorTempDir);

        const localRoot = localSiteRoot(typeChangeMirrorTempDir);
        const localFile = join(localRoot, 'test-data', 'hello.txt');
        rmSync(localFile);
        mkdirSync(localFile);
        writeFileSync(join(localFile, 'local-child.txt'), 'local directory\n');

        const localDirectory = join(localRoot, 'test-data', 'subdir', 'nested');
        rmSync(localDirectory, { recursive: true });
        writeFileSync(localDirectory, 'local file\n');

        const localEmptyDirectory = join(
            localRoot,
            'test-data',
            'mirror-empty-directory',
        );
        rmSync(localEmptyDirectory, { recursive: true });
        writeFileSync(localEmptyDirectory, 'local file\n');

        const localSymlink = join(localRoot, 'test-data', 'mirror-link');
        rmSync(localSymlink);
        writeFileSync(localSymlink, 'local file\n');

        const result = runFilesPull(typeChangeMirrorTempDir, 'mirror');
        assert.equal(
            result.exitCode,
            0,
            `Type-change mirror failed\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );

        assert.ok(lstatSync(localFile).isFile());
        assert.ok(lstatSync(localDirectory).isDirectory());
        assert.ok(lstatSync(localEmptyDirectory).isDirectory());
        assert.deepEqual(readdirSync(localEmptyDirectory), []);
        assert.ok(lstatSync(localSymlink).isSymbolicLink());
        assert.equal(readlinkSync(localSymlink), 'mirror-link-target.txt');
        assertTreesMatch(getSiteDir(site), localRoot);
    });

    it('removes local paths when the selected remote directory is empty', () => {
        const localRoot = join(fsRootDir(emptyRemoteMirrorTempDir), remoteEmptyDirectory);
        const localOnlyPath = join(localRoot, 'local-only', 'nested.txt');
        mkdirSync(join(localRoot, 'local-only'), { recursive: true });
        writeFileSync(localOnlyPath, 'local only\n');

        const result = runFilesPull(
            emptyRemoteMirrorTempDir,
            'mirror',
            {},
            emptyRemoteImportUrl(),
        );
        assert.equal(
            result.exitCode,
            0,
            `Empty-remote mirror failed\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );
        assert.ok(!existsSync(localOnlyPath));
    });

    it('resumes an interrupted mirror and restores the current remote tree', () => {
        resetCompletedFilesPull(mirrorTempDir);
        changeLocalTree(localSiteRoot(mirrorTempDir));

        writeTestHooks(site, [
            'function test_hook_during_dir_scan($dir, &$entries) {',
            "    $state_file = '/srv/e2e-sites/.e2e-hook-state-files-pull-mirror';",
            "    $state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];",
            "    $count = ($state['scan_count'] ?? 0) + 1;",
            "    $state['scan_count'] = $count;",
            '    file_put_contents($state_file, json_encode($state));',
            '    if ($count === 5) {',
            '        exit(1);',
            '    }',
            '}',
        ].join('\n'));
        writeHookState(site, { scan_count: 0 });

        const interrupted = runFilesPull(mirrorTempDir, 'mirror', {
            autoResume: false,
        });
        assert.equal(
            interrupted.exitCode,
            2,
            `Expected an interrupted mirror pull\nstderr: ${interrupted.stderr}\nstdout: ${interrupted.stdout}`,
        );

        const interruptedState = JSON.parse(readFileSync(
            join(pullStateDirectory(mirrorTempDir, importUrl()), 'state.json'),
            'utf-8',
        ));
        assert.equal(interruptedState.active_resumable_command.current_stage, 'index');
        assert.equal(interruptedState.active_resumable_command.completion_state, 'partial');

        removeTestHooks(site);
        const resumed = runFilesPull(mirrorTempDir, 'mirror');
        assert.equal(
            resumed.exitCode,
            0,
            `Mirror resume failed\nstderr: ${resumed.stderr}\nstdout: ${resumed.stdout}`,
        );

        assertTreesMatch(getSiteDir(site), localSiteRoot(mirrorTempDir));
        assert.ok(
            !existsSync(join(localSiteRoot(mirrorTempDir), 'test-data', 'local-only')),
            'Mirror should remove the local-only directory',
        );
    });

    it('catch-up keeps local changes when the remote index did not change', () => {
        resetCompletedFilesPull(catchUpTempDir);
        changeLocalTree(localSiteRoot(catchUpTempDir));

        const result = runFilesPull(catchUpTempDir, 'catch-up');
        assert.equal(
            result.exitCode,
            0,
            `Catch-up pull failed\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );

        const localRoot = localSiteRoot(catchUpTempDir);
        assert.equal(
            readFileSync(join(localRoot, 'test-data', 'hello.txt'), 'utf-8'),
            'local edit\n',
        );
        assert.ok(
            !existsSync(join(localRoot, 'test-data', 'subdir', 'nested', 'deep.txt')),
            'Catch-up should keep the local deletion',
        );
        assert.equal(
            readFileSync(
                join(localRoot, 'test-data', 'local-only', 'debug.log'),
                'utf-8',
            ),
            'local only\n',
        );
    });

    it('mirrors remote paths into a remapped local root', () => {
        const remapArgs = [
            '--remap',
            ':abspath:',
            ':fs-root:/site',
        ];
        const remappedLocalRoot = join(fsRootDir(remappedMirrorTempDir), 'site');

        const initial = runFilesPull(remappedMirrorTempDir, 'mirror', {
            extraArgs: remapArgs,
        });
        assert.equal(
            initial.exitCode,
            0,
            `Initial remapped mirror failed\nstderr: ${initial.stderr}\nstdout: ${initial.stdout}`,
        );
        assertTreesMatch(getSiteDir(site), remappedLocalRoot);

        resetCompletedFilesPull(remappedMirrorTempDir);
        changeLocalTree(remappedLocalRoot);
        const repeated = runFilesPull(remappedMirrorTempDir, 'mirror', {
            extraArgs: remapArgs,
        });
        assert.equal(
            repeated.exitCode,
            0,
            `Repeated remapped mirror failed\nstderr: ${repeated.stderr}\nstdout: ${repeated.stdout}`,
        );
        assertTreesMatch(getSiteDir(site), remappedLocalRoot);
    });

    it('applies remote additions, deletions, and conflicts on both modes', () => {
        resetCompletedFilesPull(mirrorTempDir);
        resetCompletedFilesPull(catchUpTempDir);

        const mirrorLocalRoot = localSiteRoot(mirrorTempDir);
        const catchUpLocalRoot = localSiteRoot(catchUpTempDir);
        const mirrorConflictPath = join(
            mirrorLocalRoot,
            'test-data',
            'local-and-remote-change.txt',
        );
        rmSync(mirrorConflictPath);
        mkdirSync(mirrorConflictPath);
        writeFileSync(join(mirrorConflictPath, 'local-child.txt'), 'local directory\n');
        writeFileSync(
            join(catchUpLocalRoot, 'test-data', 'local-and-remote-change.txt'),
            'conflicting local content\n',
        );
        writeFileSync(
            join(mirrorLocalRoot, 'test-data', 'deleted-remotely.txt'),
            'locally edited before remote deletion\n',
        );
        writeFileSync(
            join(catchUpLocalRoot, 'test-data', 'deleted-remotely.txt'),
            'locally edited before remote deletion\n',
        );

        writeRemoteFile(remoteConflictFile, 'new content from remote\n');
        removeRemotePath(remoteDeletedFile);
        writeRemoteFile(remoteAddedFile, 'new remote file\n');

        for (const [tempDir, mode] of [
            [mirrorTempDir, 'mirror'],
            [catchUpTempDir, 'catch-up'],
        ]) {
            const result = runFilesPull(tempDir, mode);
            assert.equal(
                result.exitCode,
                0,
                `${mode} remote-delta pull failed\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
            );

            const localRoot = localSiteRoot(tempDir);
            const conflictPath = join(
                localRoot,
                'test-data',
                'local-and-remote-change.txt',
            );
            assert.ok(lstatSync(conflictPath).isFile());
            assert.equal(readFileSync(conflictPath, 'utf-8'), 'new content from remote\n');
            assert.ok(
                !existsSync(join(localRoot, 'test-data', 'deleted-remotely.txt')),
                `${mode} should apply the remote deletion`,
            );
            assert.equal(
                readFileSync(join(localRoot, 'test-data', 'added-remotely.txt'), 'utf-8'),
                'new remote file\n',
            );
        }

        assertTreesMatch(getSiteDir(site), mirrorLocalRoot);
        assert.equal(
            readFileSync(
                join(catchUpLocalRoot, 'test-data', 'local-only', 'debug.log'),
                'utf-8',
            ),
            'local only\n',
            'Catch-up should still keep a local-only path after applying remote changes',
        );
    });
});
