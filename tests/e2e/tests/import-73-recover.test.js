import { describe, it, beforeAll, beforeEach, afterEach } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { ensureSite } from '../lib/site-setup.js';
import { getSiteDir } from '../lib/test-helpers.js';

describe('Recover: load WordPress and deactivate fatal plugins', () => {
    const siteDirectory = getSiteDir('recover');
    const pluginsDirectory = join(siteDirectory, 'wp-content/plugins');
    const mustUseDirectory = join(siteDirectory, 'wp-content/mu-plugins');
    const entry = process.env.CLIENT_PATH || join(import.meta.dirname, '../../../packages/reprint-client/bin/reprint-client');
    const sourceUrl = 'https://source.example/?reprint-api';
    let stateDirectory;

    beforeAll(async () => {
        await ensureSite('recover', { files: 'none' });
        // ensureSite prepares files for nginx; these tests edit the destination directly.
        execFileSync('sudo', ['chown', '-R', `${process.getuid()}:${process.getgid()}`, join(siteDirectory, 'wp-content')]);
    });

    beforeEach(() => {
        stateDirectory = mkdtempSync(join(tmpdir(), 'post-process-'));
        plugin('recover-healthy/main.php', "add_action('wp_loaded', function () { update_option('recover_loaded', 'yes'); });");
        runWp(['option', 'update', 'recover_loaded', 'no']);
        activate(['recover-healthy/main.php']);
    });

    afterEach(() => {
        rmSync(join(mustUseDirectory, 'recover-fatal.php'), { force: true });
        rmSync(join(mustUseDirectory, 'hostinger-mu-plugin.php'), { force: true });
        rmSync(join(siteDirectory, 'wp-content/object-cache.php'), { force: true });
        activate([]);
        for (const name of ['recover-healthy', 'recover-first', 'recover-second', 'recover-first-extra', 'recover-single.php', 'hostinger']) {
            rmSync(join(pluginsDirectory, name), { recursive: true, force: true });
        }
        rmSync(stateDirectory, { recursive: true, force: true });
    });

    it('loads a healthy site without changing its active plugins', () => {
        const result = recover();
        assert.equal(result.exitCode, 0, result.stderr);
        assert.equal(result.report.status, 'complete');
        assert.deepEqual(result.report.disabled_plugins, []);
        assert.deepEqual(activePlugins(), ['recover-healthy/main.php']);
        assert.equal(runWp(['option', 'get', 'recover_loaded']).trim(), 'yes');
    });

    it('deactivates successive runtime and parse failures, then loads WordPress', () => {
        plugin('recover-first/main.php', 'recover_missing_function();');
        plugin('recover-second/main.php', 'this is not valid PHP;');
        activate(['recover-first/main.php', 'recover-second/main.php', 'recover-healthy/main.php']);

        const result = recover();
        assert.equal(result.exitCode, 0, result.stderr);
        assert.equal(result.report.status, 'complete');
        assert.deepEqual(result.report.disabled_plugins.map(item => item.plugin), ['recover-first/main.php', 'recover-second/main.php']);
        assert.match(result.report.disabled_plugins[0].error.message, /recover_missing_function/);
        assert.equal(result.report.disabled_plugins[0].error.line, 3);
        assert.deepEqual(activePlugins(), ['recover-healthy/main.php']);
        assert.equal(runWp(['option', 'get', 'recover_loaded']).trim(), 'yes');
        assert.ok(existsSync(join(pluginsDirectory, 'recover-first/main.php')), 'Deactivation must preserve plugin files');
        assert.deepEqual(recover().report.disabled_plugins, []);
    });

    it('matches nested plugin files without matching a neighboring directory', () => {
        plugin('recover-first/main.php', '');
        plugin('recover-first-extra/main.php', "require __DIR__ . '/nested/fatal.php';");
        plugin('recover-first-extra/nested/fatal.php', 'recover_missing_function();');
        activate(['recover-first/main.php', 'recover-first-extra/main.php', 'recover-healthy/main.php']);

        const result = recover();
        assert.equal(result.exitCode, 0, result.stderr);
        assert.deepEqual(result.report.disabled_plugins.map(item => item.plugin), ['recover-first-extra/main.php']);
        assert.deepEqual(activePlugins(), ['recover-first/main.php', 'recover-healthy/main.php']);
    });

    it('deactivates a single-file plugin without running its deactivation hook', () => {
        plugin('recover-single.php', "register_deactivation_hook(__FILE__, function () { update_option('recover_deactivation_hook', 'ran'); }); recover_missing_function();");
        activate(['recover-single.php', 'recover-healthy/main.php']);

        const result = recover();
        assert.equal(result.exitCode, 0, result.stderr);
        assert.deepEqual(result.report.disabled_plugins.map(item => item.plugin), ['recover-single.php']);
        assert.deepEqual(activePlugins(), ['recover-healthy/main.php']);
        const hook = spawnSync('php', ['/tmp/wp-cli.phar', '--allow-root', `--path=${siteDirectory}`, '--skip-plugins', 'option', 'get', 'recover_deactivation_hook'], { encoding: 'utf8' });
        assert.notEqual(hook.status, 0, 'The deactivation hook must not run');
    });

    it('stops on a must-use plugin fatal without disabling ordinary plugins', () => {
        mkdirSync(mustUseDirectory, { recursive: true });
        writeFileSync(join(mustUseDirectory, 'recover-fatal.php'), '<?php recover_missing_function();');

        const result = recover();
        assert.equal(result.exitCode, 1);
        assert.equal(result.report.status, 'failed');
        assert.deepEqual(result.report.disabled_plugins, []);
        assert.match(result.report.error.message, /recover_missing_function/);
        rmSync(join(mustUseDirectory, 'recover-fatal.php'));
        assert.deepEqual(activePlugins(), ['recover-healthy/main.php']);
    });

    it('does not treat exit before wp-load returns as a successful load', () => {
        plugin('recover-first/main.php', 'exit(0);');
        activate(['recover-first/main.php', 'recover-healthy/main.php']);

        const result = recover();
        assert.equal(result.exitCode, 1);
        assert.equal(result.report.status, 'failed');
        assert.deepEqual(result.report.disabled_plugins, []);
        assert.deepEqual(activePlugins(), ['recover-first/main.php', 'recover-healthy/main.php']);
    });

    it('stops when WordPress refuses to save the plugin deactivation', () => {
        plugin('recover-first/main.php', "add_filter('pre_update_option_active_plugins', function ($value, $old) { return $old; }, 10, 2); recover_missing_function();");
        activate(['recover-first/main.php', 'recover-healthy/main.php']);

        const result = recover();
        assert.equal(result.exitCode, 1);
        assert.equal(result.report.status, 'failed');
        assert.deepEqual(result.report.disabled_plugins, []);
        assert.match(result.report.message, /deactivat/i);
        assert.deepEqual(activePlugins(), ['recover-first/main.php', 'recover-healthy/main.php']);
    });

    it.each([
        { options: [] },
        { options: ['--tasks=disable-failing-plugins,disable-hosting-plugins'] },
    ])('runs hosting cleanup before fatal-plugin recovery with options $options', ({ options }) => {
        const stateFile = savePreflight();
        plugin('recover-first/main.php', 'recover_missing_function();');
        activate(['recover-first/main.php', 'recover-healthy/main.php']);
        mkdirSync(mustUseDirectory, { recursive: true });
        writeFileSync(join(mustUseDirectory, 'hostinger-mu-plugin.php'), '<?php recover_missing_host_function();');

        const result = postProcess([`--state-dir=${stateDirectory}`, ...options]);
        assert.equal(result.exitCode, 0, result.stderr);
        assert.equal(result.report.status, 'complete');
        assert.deepEqual(result.report.results.map(item => item.task), ['disable-hosting-plugins', 'disable-failing-plugins']);
        assert.deepEqual(result.report.results[0].removed_paths, ['wp-content/mu-plugins/hostinger-mu-plugin.php']);
        assert.deepEqual(result.report.results[1].disabled_plugins.map(item => item.plugin), ['recover-first/main.php']);
        assert.equal(existsSync(join(mustUseDirectory, 'hostinger-mu-plugin.php')), false);
        assert.deepEqual(activePlugins(), ['recover-healthy/main.php']);
        const state = JSON.parse(readFileSync(stateFile, 'utf8'));
        assert.equal(state.include_host_plugins, true, 'Post-processing must not change the saved pull selection');
        assert.ok(state.apply.remote_paths_removed_from_local_site.includes('wp-content/mu-plugins/hostinger-mu-plugin.php'));
        assert.ok(state.apply.remote_paths_removed_from_local_site.includes('wp-content/mu-plugins/previous-host.php'));

        const repeated = postProcess([`--state-dir=${stateDirectory}`, '--tasks=all']);
        assert.equal(repeated.exitCode, 0, repeated.stderr);
        assert.deepEqual(repeated.report.results[0].removed_paths, []);
        assert.deepEqual(repeated.report.results[1].disabled_plugins, []);
    });

    it('runs only failing-plugin recovery without requiring migration state', () => {
        plugin('hostinger/main.php', '');
        plugin('recover-first/main.php', 'recover_missing_function();');
        activate(['hostinger/main.php', 'recover-first/main.php', 'recover-healthy/main.php']);

        const result = postProcess(['--tasks=disable-failing-plugins']);
        assert.equal(result.exitCode, 0, result.stderr);
        assert.deepEqual(result.report.results.map(item => item.task), ['disable-failing-plugins']);
        assert.deepEqual(activePlugins(), ['hostinger/main.php', 'recover-healthy/main.php']);
        assert.ok(existsSync(join(pluginsDirectory, 'hostinger/main.php')));
    });

    it('runs only hosting cleanup without loading or deactivating an ordinary failing plugin', () => {
        savePreflight();
        plugin('hostinger/main.php', '');
        plugin('recover-first/main.php', 'recover_missing_function();');
        activate(['hostinger/main.php', 'recover-first/main.php', 'recover-healthy/main.php']);

        const result = postProcess([`--state-dir=${stateDirectory}`, '--tasks=disable-hosting-plugins']);
        assert.equal(result.exitCode, 0, result.stderr);
        assert.deepEqual(result.report.results.map(item => item.task), ['disable-hosting-plugins']);
        assert.deepEqual(result.report.results[0].removed_paths, ['wp-content/plugins/hostinger']);
        assert.equal(existsSync(join(pluginsDirectory, 'hostinger')), false);
        assert.deepEqual(activePlugins(), ['hostinger/main.php', 'recover-first/main.php', 'recover-healthy/main.php']);
        assert.equal(runWp(['option', 'get', 'recover_loaded']).trim(), 'no');
    });

    it('uses saved source-host data before removing a generic cache drop-in', () => {
        savePreflight(sourceUrl, '/var/www/source');
        const dropin = join(siteDirectory, 'wp-content/object-cache.php');
        writeFileSync(dropin, '<?php // A portable cache drop-in.');
        let result = postProcess([`--state-dir=${stateDirectory}`, '--tasks=disable-hosting-plugins']);
        assert.equal(result.exitCode, 0, result.stderr);
        assert.ok(existsSync(dropin));

        savePreflight(sourceUrl, '/nas/content/live/source');
        result = postProcess([`--state-dir=${stateDirectory}`, '--tasks=disable-hosting-plugins']);
        assert.equal(result.exitCode, 0, result.stderr);
        assert.deepEqual(result.report.results[0].removed_paths, ['wp-content/object-cache.php']);
        assert.equal(existsSync(dropin), false);
    });

    it('requires an explicit source when migration state contains multiple remotes', () => {
        savePreflight();
        savePreflight('https://other-source.example/?reprint-api');
        plugin('hostinger/main.php', '');

        const ambiguous = postProcess([`--state-dir=${stateDirectory}`]);
        assert.equal(ambiguous.exitCode, 1);
        assert.match(ambiguous.report.message, /more than one saved remote/);
        assert.ok(existsSync(join(pluginsDirectory, 'hostinger/main.php')));

        const selected = postProcess([sourceUrl, `--state-dir=${stateDirectory}`]);
        assert.equal(selected.exitCode, 0, selected.stderr);
        assert.equal(existsSync(join(pluginsDirectory, 'hostinger')), false);
    });

    it('retains completed task results when WordPress cannot be recovered', () => {
        savePreflight();
        plugin('hostinger/main.php', '');
        mkdirSync(mustUseDirectory, { recursive: true });
        writeFileSync(join(mustUseDirectory, 'recover-fatal.php'), '<?php recover_missing_function();');

        const result = postProcess([`--state-dir=${stateDirectory}`]);
        assert.equal(result.exitCode, 1);
        assert.equal(result.report.status, 'failed');
        assert.deepEqual(result.report.results.map(item => item.status), ['complete', 'failed']);
        assert.deepEqual(result.report.results[0].removed_paths, ['wp-content/plugins/hostinger']);
        assert.match(result.report.results[1].error.message, /recover_missing_function/);
        assert.deepEqual(result.report.results[1].disabled_plugins, []);
    });

    it.each([null, { error: 'The saved source preflight was rejected.' }])('refuses hosting cleanup with missing or rejected preflight: %j', (preflight) => {
        const stateFile = savePreflight();
        const state = JSON.parse(readFileSync(stateFile, 'utf8'));
        state.preflight = preflight === null ? null : { ...state.preflight, ...preflight };
        writeFileSync(stateFile, JSON.stringify(state));
        plugin('hostinger/main.php', '');

        const result = postProcess([`--state-dir=${stateDirectory}`]);
        assert.equal(result.exitCode, 1);
        assert.equal(result.report.status, 'failed');
        assert.deepEqual(result.report.results.map(item => item.task), ['disable-hosting-plugins']);
        assert.match(result.report.message, preflight === null ? /No preflight data/ : /preflight was rejected/);
        assert.ok(existsSync(join(pluginsDirectory, 'hostinger/main.php')));
        assert.equal(runWp(['option', 'get', 'recover_loaded']).trim(), 'no');
    });

    it('rejects missing state and invalid task lists before changing the site', () => {
        savePreflight();
        plugin('hostinger/main.php', '');
        plugin('recover-first/main.php', 'recover_missing_function();');
        activate(['recover-first/main.php', 'recover-healthy/main.php']);

        for (const arguments_ of [[], [`--state-dir=${stateDirectory}/missing`], [`--state-dir=${stateDirectory}`, '--tasks=disable-hosting-plugins,unknown'], [`--state-dir=${stateDirectory}`, '--tasks='], [`--state-dir=${stateDirectory}`, '--tasks=all,disable-failing-plugins']]) {
            const result = postProcess(arguments_);
            assert.equal(result.exitCode, 1);
            assert.equal(result.report.status, 'failed');
            assert.ok(existsSync(join(pluginsDirectory, 'hostinger/main.php')));
            assert.deepEqual(activePlugins(), ['recover-first/main.php', 'recover-healthy/main.php']);
        }
        assert.equal(existsSync(join(stateDirectory, 'missing')), false);
    });

    it.each(['pull/index.wal', 'push/sender.json'])('refuses hosting cleanup while %s exists', (pendingFile) => {
        const stateFile = savePreflight();
        const pendingPath = join(stateFile, '../..', pendingFile);
        mkdirSync(join(pendingPath, '..'), { recursive: true });
        writeFileSync(pendingPath, '');
        plugin('hostinger/main.php', '');

        const result = postProcess([`--state-dir=${stateDirectory}`]);
        assert.equal(result.exitCode, 1);
        assert.match(result.report.message, /Finish.*interrupted files-(pull|push)/);
        assert.deepEqual(result.report.results.map(item => item.task), ['disable-hosting-plugins']);
        assert.ok(existsSync(join(pluginsDirectory, 'hostinger/main.php')));
        assert.equal(runWp(['option', 'get', 'recover_loaded']).trim(), 'no');
    });

    function postProcess(arguments_) {
        const result = spawnSync('php', [entry, 'post-process', ...arguments_, `--fs-root=${siteDirectory}`], { encoding: 'utf8', timeout: 15000 });
        assert.equal(result.error, undefined, String(result.error));
        assert.ok(result.stdout.trim().startsWith('{'), `Post-process must return a JSON report.\n${result.stdout}\n${result.stderr}`);
        return { exitCode: result.status, report: JSON.parse(result.stdout), stderr: result.stderr };
    }

    function savePreflight(url = sourceUrl, documentRoot = '/nas/content/live/source') {
        execFileSync('php', ['-r', `
            require $argv[1];
            $client = new ImportClient($argv[2], $argv[3], $argv[4]);
            $client->get_state()->set_preflight_record(['http_code' => 200, 'data' => [
                'runtime' => ['document_root' => $argv[5]],
                'database' => ['wp' => ['paths_urls' => [
                    'abspath' => $argv[5] . '/',
                    'content_dir' => $argv[5] . '/wp-content',
                ]]],
            ]]);
            $client->get_state()->apply->remote_paths_removed_from_local_site = ['wp-content/mu-plugins/previous-host.php'];
            $client->save_state();
        `, join(import.meta.dirname, '../../../packages/reprint-client/src/import.php'), url, stateDirectory, siteDirectory, documentRoot], { encoding: 'utf8' });
        return join(stateDirectory, 'remotes', createHash('md5').update(url).digest('hex'), 'pull/state.json');
    }

    function recover() {
        const result = spawnSync('php', [entry, 'recover', `--fs-root=${siteDirectory}`], { encoding: 'utf8', timeout: 15000 });
        assert.equal(result.error, undefined, String(result.error));
        assert.ok(result.stdout.trim().startsWith('{'), `Recover must return a JSON report.\n${result.stdout}\n${result.stderr}`);
        return { exitCode: result.status, report: JSON.parse(result.stdout), stderr: result.stderr };
    }

    function plugin(basename, code) {
        const filename = join(pluginsDirectory, basename);
        mkdirSync(join(filename, '..'), { recursive: true });
        writeFileSync(filename, `<?php\n/* Plugin Name: Recover fixture */\n${code}\n`);
    }

    function activate(plugins) {
        runWp(['option', 'update', 'active_plugins', JSON.stringify(plugins), '--format=json']);
    }

    function activePlugins() {
        return JSON.parse(runWp(['option', 'get', 'active_plugins', '--format=json']));
    }

    function runWp(arguments_) {
        return execFileSync('php', ['/tmp/wp-cli.phar', '--allow-root', `--path=${siteDirectory}`, '--skip-plugins', '--skip-themes', ...arguments_], { encoding: 'utf8' });
    }
});
