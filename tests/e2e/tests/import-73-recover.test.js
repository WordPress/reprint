import { describe, it, beforeAll, beforeEach, afterEach } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { ensureSite } from '../lib/site-setup.js';
import { getSiteDir } from '../lib/test-helpers.js';

describe('Recover: load WordPress and deactivate fatal plugins', () => {
    const siteDirectory = getSiteDir('recover');
    const pluginsDirectory = join(siteDirectory, 'wp-content/plugins');
    const mustUseDirectory = join(siteDirectory, 'wp-content/mu-plugins');
    const entry = join(import.meta.dirname, '../../../packages/reprint-client/bin/reprint-client');

    beforeAll(async () => {
        await ensureSite('recover', { files: 'none' });
    });

    beforeEach(() => {
        plugin('recover-healthy/main.php', "add_action('wp_loaded', function () { update_option('recover_loaded', 'yes'); });");
        runWp(['option', 'update', 'recover_loaded', 'no']);
        activate(['recover-healthy/main.php']);
    });

    afterEach(() => {
        rmSync(join(mustUseDirectory, 'recover-fatal.php'), { force: true });
        activate([]);
        for (const name of ['recover-healthy', 'recover-first', 'recover-second', 'recover-first-extra', 'recover-single.php']) {
            rmSync(join(pluginsDirectory, name), { recursive: true, force: true });
        }
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
