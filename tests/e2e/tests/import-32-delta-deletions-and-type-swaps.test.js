/**
 * Test 32: Delta sync deletions + type swaps (file/dir/symlink)
 *
 * Covers:
 * - Deleting file, deep directory tree, symlink to file, symlink to directory
 * - Replacing paths across types: file<->dir<->symlink
 * - Ensuring local directory deletion does not follow symlink targets
 * - State path persistence uses base64-encoded values
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, lstatSync, readlinkSync, readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Delta deletions and type swaps', () => {
    const site = 'file-type-swaps';
    const scenarioName = 'delta-rigorous';
    const preserveName = 'delta-rigorous-preserve';
    const remoteScenarioRoot = join(getSiteDir(site), 'test-data', scenarioName);
    const remotePreserveRoot = join(getSiteDir(site), 'test-data', preserveName);

    let tempDir;

    const sh = (v) => JSON.stringify(v);
    const sudoRun = (script) => {
        const oneLine = script
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
            .join('; ');
        execSync(`sudo bash -lc ${JSON.stringify(`set -euo pipefail; ${oneLine}`)}`);
    };

    const writeText = (path, content) => `printf %b ${sh(content)} > ${sh(path)}`;

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function localScenarioRoot() {
        return join(fsRootDir(tempDir), getSiteDir(site), 'test-data', scenarioName);
    }

    function localPreserveFile() {
        return join(fsRootDir(tempDir), getSiteDir(site), 'test-data', preserveName, 'keep.txt');
    }

    function lstatIfExists(path) {
        try {
            return lstatSync(path);
        } catch (e) {
            if (e && e.code === 'ENOENT') return null;
            throw e;
        }
    }

    function setupInitialRemoteLayout() {
        sudoRun(`
rm -rf ${sh(remoteScenarioRoot)} ${sh(remotePreserveRoot)}
mkdir -p ${sh(remoteScenarioRoot)} ${sh(remotePreserveRoot)}

mkdir -p ${sh(join(remoteScenarioRoot, 'targets', 'delete-dir'))}
mkdir -p ${sh(join(remoteScenarioRoot, 'targets', 'start-dir', 'deep'))}
mkdir -p ${sh(join(remoteScenarioRoot, 'targets', 'new-link-dir', 'nested'))}
mkdir -p ${sh(join(remoteScenarioRoot, 'dir-delete', 'level1', 'level2', 'level3'))}
mkdir -p ${sh(join(remoteScenarioRoot, 'dir-to-file', 'n1', 'n2'))}
mkdir -p ${sh(join(remoteScenarioRoot, 'dir-to-symlink', 'deep'))}
mkdir -p ${sh(join(remoteScenarioRoot, 'delete-dir-with-symlink', 'sub'))}

${writeText(join(remoteScenarioRoot, 'file-delete.txt'), 'delete-me\n')}
${writeText(join(remoteScenarioRoot, 'file-to-dir'), 'initial-file-to-dir\n')}
${writeText(join(remoteScenarioRoot, 'file-to-symlink'), 'initial-file-to-symlink\n')}
${writeText(join(remoteScenarioRoot, 'targets', 'delete-file.txt'), 'delete-file-target\n')}
${writeText(join(remoteScenarioRoot, 'targets', 'delete-dir', 'inside.txt'), 'delete-dir-target\n')}
${writeText(join(remoteScenarioRoot, 'targets', 'start-dir', 'deep', 'start.txt'), 'start-dir\n')}
${writeText(join(remoteScenarioRoot, 'targets', 'start-file.txt'), 'start-file\n')}
${writeText(join(remoteScenarioRoot, 'targets', 'new-link-file.txt'), 'new-link-file\n')}
${writeText(join(remoteScenarioRoot, 'targets', 'new-link-dir', 'nested', 'linked.txt'), 'linked-dir-content\n')}
${writeText(join(remoteScenarioRoot, 'dir-delete', 'level1', 'level2', 'level3', 'deep.txt'), 'deep-delete\n')}
${writeText(join(remoteScenarioRoot, 'dir-delete', 'level1', 'level2', 'sibling.txt'), 'deep-delete-sibling\n')}
${writeText(join(remoteScenarioRoot, 'dir-to-file', 'n1', 'n2', 'original.txt'), 'dir-to-file-initial\n')}
${writeText(join(remoteScenarioRoot, 'dir-to-symlink', 'deep', 'original.txt'), 'dir-to-symlink-initial\n')}
${writeText(join(remoteScenarioRoot, 'delete-dir-with-symlink', 'sub', 'nested.txt'), 'remove-dir-with-link\n')}
${writeText(join(remotePreserveRoot, 'keep.txt'), 'must-survive-local-delete\n')}

ln -s ${sh('targets/delete-file.txt')} ${sh(join(remoteScenarioRoot, 'symlink-delete-file'))}
ln -s ${sh('targets/delete-dir')} ${sh(join(remoteScenarioRoot, 'symlink-delete-dir'))}
ln -s ${sh('targets/start-dir')} ${sh(join(remoteScenarioRoot, 'symlink-to-dir-to-file'))}
ln -s ${sh('targets/start-file.txt')} ${sh(join(remoteScenarioRoot, 'symlink-to-file-to-dir'))}
ln -s ${sh('../' + preserveName)} ${sh(join(remoteScenarioRoot, 'delete-dir-with-symlink', 'escape-link'))}

chown -R nginx:nginx ${sh(remoteScenarioRoot)} ${sh(remotePreserveRoot)}
`);
    }

    function applyDeltaRemoteChanges() {
        sudoRun(`
rm -f ${sh(join(remoteScenarioRoot, 'file-delete.txt'))}
rm -rf ${sh(join(remoteScenarioRoot, 'dir-delete'))}
rm -f ${sh(join(remoteScenarioRoot, 'symlink-delete-file'))}
rm -f ${sh(join(remoteScenarioRoot, 'symlink-delete-dir'))}
rm -rf ${sh(join(remoteScenarioRoot, 'delete-dir-with-symlink'))}

rm -f ${sh(join(remoteScenarioRoot, 'file-to-dir'))}
mkdir -p ${sh(join(remoteScenarioRoot, 'file-to-dir', 'nested', 'deeper'))}
${writeText(join(remoteScenarioRoot, 'file-to-dir', 'nested', 'deeper', 'value.txt'), 'file-became-dir\n')}

rm -f ${sh(join(remoteScenarioRoot, 'file-to-symlink'))}
ln -s ${sh('targets/new-link-file.txt')} ${sh(join(remoteScenarioRoot, 'file-to-symlink'))}

rm -rf ${sh(join(remoteScenarioRoot, 'dir-to-file'))}
${writeText(join(remoteScenarioRoot, 'dir-to-file'), 'dir-became-file\n')}

rm -rf ${sh(join(remoteScenarioRoot, 'dir-to-symlink'))}
ln -s ${sh('targets/new-link-dir')} ${sh(join(remoteScenarioRoot, 'dir-to-symlink'))}

rm -f ${sh(join(remoteScenarioRoot, 'symlink-to-dir-to-file'))}
${writeText(join(remoteScenarioRoot, 'symlink-to-dir-to-file'), 'symlink-dir-became-file\n')}

rm -f ${sh(join(remoteScenarioRoot, 'symlink-to-file-to-dir'))}
mkdir -p ${sh(join(remoteScenarioRoot, 'symlink-to-file-to-dir', 'x', 'y', 'z'))}
${writeText(join(remoteScenarioRoot, 'symlink-to-file-to-dir', 'x', 'y', 'z', 'value.txt'), 'symlink-file-became-dir\n')}

chown -R nginx:nginx ${sh(remoteScenarioRoot)} ${sh(remotePreserveRoot)}
`);
    }

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-delta-rigorous');
        setupInitialRemoteLayout();
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
        execSync(`sudo rm -rf ${sh(remoteScenarioRoot)} ${sh(remotePreserveRoot)} 2>/dev/null || true`);
    });

    it('initial files-sync completes', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('delta sync applies deletions and type swaps', () => {
        applyDeltaRemoteChanges();

        // Abort previous completion so we can run a delta
        const abort = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
        });
        assert.equal(abort.exitCode, 0);

        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('deleted file, deep directory tree, and symlinks are removed locally', () => {
        const base = localScenarioRoot();

        assert.equal(existsSync(join(base, 'file-delete.txt')), false, 'Expected deleted file to be removed');
        assert.equal(existsSync(join(base, 'dir-delete')), false, 'Expected deleted deep directory tree to be removed');
        assert.equal(lstatIfExists(join(base, 'symlink-delete-file')), null, 'Expected deleted file symlink to be removed');
        assert.equal(lstatIfExists(join(base, 'symlink-delete-dir')), null, 'Expected deleted directory symlink to be removed');
        assert.equal(existsSync(join(base, 'delete-dir-with-symlink')), false, 'Expected deleted directory with symlink to be removed');
    });

    it('directory deletion does not follow symlinks outside the deleted directory', () => {
        const preserveFile = localPreserveFile();
        assert.equal(existsSync(preserveFile), true, `Expected preserve file to remain: ${preserveFile}`);
        assert.equal(readFileSync(preserveFile, 'utf-8'), 'must-survive-local-delete\n');
    });

    it('type swaps file<->dir<->symlink are applied with correct final types', () => {
        const base = localScenarioRoot();

        const fileToDir = join(base, 'file-to-dir');
        assert.ok(lstatSync(fileToDir).isDirectory(), 'Expected file-to-dir to be a directory');
        assert.equal(
            readFileSync(join(fileToDir, 'nested', 'deeper', 'value.txt'), 'utf-8'),
            'file-became-dir\n',
        );

        const fileToSymlink = join(base, 'file-to-symlink');
        assert.ok(lstatSync(fileToSymlink).isSymbolicLink(), 'Expected file-to-symlink to be a symlink');
        assert.equal(readlinkSync(fileToSymlink), 'targets/new-link-file.txt');

        const dirToFile = join(base, 'dir-to-file');
        assert.ok(lstatSync(dirToFile).isFile(), 'Expected dir-to-file to be a regular file');
        assert.equal(readFileSync(dirToFile, 'utf-8'), 'dir-became-file\n');

        const dirToSymlink = join(base, 'dir-to-symlink');
        assert.ok(lstatSync(dirToSymlink).isSymbolicLink(), 'Expected dir-to-symlink to be a symlink');
        assert.equal(readlinkSync(dirToSymlink), 'targets/new-link-dir');
        assert.equal(
            readFileSync(join(dirToSymlink, 'nested', 'linked.txt'), 'utf-8'),
            'linked-dir-content\n',
        );

        const symlinkDirToFile = join(base, 'symlink-to-dir-to-file');
        assert.ok(lstatSync(symlinkDirToFile).isFile(), 'Expected symlink-to-dir-to-file to be a regular file');
        assert.equal(readFileSync(symlinkDirToFile, 'utf-8'), 'symlink-dir-became-file\n');

        const symlinkFileToDir = join(base, 'symlink-to-file-to-dir');
        assert.ok(lstatSync(symlinkFileToDir).isDirectory(), 'Expected symlink-to-file-to-dir to be a directory');
        assert.equal(
            readFileSync(join(symlinkFileToDir, 'x', 'y', 'z', 'value.txt'), 'utf-8'),
            'symlink-file-became-dir\n',
        );
    });

    it('state stores path fields in base64 form', () => {
        const statePath = join(tempDir, '.import-state.json');
        const state = JSON.parse(readFileSync(statePath, 'utf-8'));

        assert.equal(typeof state.diff.local_after, 'string', 'Expected diff.local_after to be persisted');
        assert.ok(
            state.diff.local_after.startsWith('base64:'),
            `Expected base64-encoded diff.local_after, got: ${state.diff.local_after}`,
        );

        if (typeof state.fetch.batch_file === 'string') {
            assert.ok(state.fetch.batch_file.startsWith('base64:'), 'Expected fetch.batch_file to use base64');
        }
        if (typeof state.current_file === 'string') {
            assert.ok(state.current_file.startsWith('base64:'), 'Expected current_file to use base64');
        }
        if (state.db_index && typeof state.db_index.file === 'string') {
            assert.ok(state.db_index.file.startsWith('base64:'), 'Expected db_index.file to use base64');
        }
    });

    it('subsequent files-sync still works with encoded state paths', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });
});
