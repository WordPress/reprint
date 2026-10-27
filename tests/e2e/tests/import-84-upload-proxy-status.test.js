import { it } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createNodeFsMountHandler, loadNodeRuntime } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';

it('keeps pull checkpoints unchanged when imported PHP writes the mounted proxy status', async () => {
    const root = realpathSync(mkdtempSync(join(tmpdir(), 'upload-proxy-status-')));
    const php = new PHP(await loadNodeRuntime('8.3'));
    try {
        const script = join(root, 'runtime.php');
        const projectRoot = join(import.meta.dirname, '../../..');
        writeFileSync(script, `<?php
require ${JSON.stringify(join(projectRoot, 'packages/reprint-client/src/import.php'))};
$root = $argv[1];
$client = new ImportClient('https://source.example/export.php', $root . '/state', $root . '/site');
if ($argv[2] === 'setup') {
    file_put_contents($root . '/site/index.php', '<?php echo "site";');
    $state = $client->get_state();
    $state->active_resumable_command->command_name = 'files-pull';
    $state->active_resumable_command->completion_state = 'partial';
    $state->active_resumable_command->current_stage = 'fetch';
    $state->set_preflight_record(['http_code' => 200, 'data' => [
        'ok' => true,
        'runtime' => ['document_root' => ''],
        'database' => ['wp' => ['paths_urls' => [
            'abspath' => $root . '/site',
            'uploads' => ['baseurl' => 'https://source.example/wp-content/uploads'],
        ]]],
    ]]);
    $client->save_state();
    $client->run_apply_runtime([
        'runtime' => 'playground-cli',
        'output_dir' => $root . '/runtime',
        'flat_document_root' => $root . '/site',
    ]);
    file_put_contents($root . '/state-path.txt', $client->pull_state_directory . '/state.json');
} else {
    $reflection = new ReflectionClass($client);
    $reflection->getProperty('state')->setValue($client, $reflection->getMethod('load_state')->invoke($client));
    $client->get_state()->active_resumable_command->completion_state = 'complete';
    $client->save_state();
}
`);
        const runClient = (operation) => execFileSync(process.env.PHP_BINARY || 'php', [script, root, operation]);
        runClient('setup');
        const stateFile = readFileSync(join(root, 'state-path.txt'), 'utf8');
        const originalState = readFileSync(stateFile, 'utf8');
        const config = JSON.parse(readFileSync(join(root, 'runtime/start.json'), 'utf8'));
        const statusMount = config.mounts.find(({ target }) => target === '/tmp/reprint/upload-proxy.json');
        assert.ok(statusMount, 'The runtime must mount only the published proxy status');
        assert.deepEqual(JSON.parse(readFileSync(statusMount.source, 'utf8')), { proxy_enabled: true });
        for (const { source, target } of [...config.mounts_before_install, ...config.mounts]) {
            const resolvedSource = realpathSync(source);
            assert.ok(stateFile !== resolvedSource && !stateFile.startsWith(`${resolvedSource}/`),
                'No mount may expose the pull checkpoints');
            await php.mount(target, createNodeFsMountHandler(source));
        }

        const forgedState = JSON.parse(originalState);
        forgedState.fetch.batch_file = `base64:${Buffer.from(join(root, 'unrelated.txt')).toString('base64')}`;
        forgedState.apply.target_sqlite_path = join(root, 'unrelated.sqlite');
        forgedState.proxy_enabled = false;
        const payload = Buffer.from(JSON.stringify(forgedState)).toString('base64');
        mkdirSync(join(root, 'site/wp-content/mu-plugins'), { recursive: true });
        writeFileSync(join(root, 'site/wp-content/mu-plugins/source-plugin.php'), `<?php
$payload = base64_decode('${payload}');
file_put_contents('/tmp/reprint/state.json', $payload);
file_put_contents('/tmp/reprint/upload-proxy.json', $payload);
`);
        await php.run({ code: "<?php require '/wordpress/wp-content/mu-plugins/source-plugin.php';" });
        assert.equal(readFileSync(stateFile, 'utf8'), originalState);
        assert.equal(JSON.parse(readFileSync(statusMount.source, 'utf8')).proxy_enabled, false);

        // A later host process reads the private checkpoint and refreshes the
        // same mount. Atomic publication must remain visible to an open VM.
        runClient('complete');
        const completedState = JSON.parse(readFileSync(stateFile, 'utf8'));
        assert.equal(completedState.fetch.batch_file, null);
        assert.equal(completedState.apply.target_sqlite_path, null);
        assert.equal(completedState.active_resumable_command.completion_state, 'complete');
        const response = await php.run({ code: "<?php echo file_get_contents('/tmp/reprint/upload-proxy.json');" });
        assert.deepEqual(JSON.parse(response.text), { proxy_enabled: false });
    } finally {
        php.exit();
        rmSync(root, { recursive: true, force: true });
    }
});
