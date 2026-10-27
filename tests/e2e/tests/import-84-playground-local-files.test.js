import { it } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createNodeFsMountHandler, loadNodeRuntime } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';

it('keeps pull state outside the generated Playground mounts', async () => {
    const root = realpathSync(mkdtempSync(join(tmpdir(), 'playground-local-files-')));
    const php = new PHP(await loadNodeRuntime('8.3'));
    try {
        mkdirSync(join(root, 'site'));
        mkdirSync(join(root, 'state'));
        const stateFile = join(root, 'state/state.json');
        const originalState = '{"command":"files-pull","status":"partial"}\n';
        writeFileSync(stateFile, originalState);
        writeFileSync(join(root, 'site/index.php'), `<?php
if (!is_dir('/tmp/reprint')) mkdir('/tmp/reprint', 0700, true);
file_put_contents('/tmp/reprint/state.json', 'changed by the imported site');
echo 'local site';
`);
        const projectRoot = join(import.meta.dirname, '../../..');
        const script = join(root, 'generate-runtime.php');
        writeFileSync(script, `<?php
require ${JSON.stringify(join(projectRoot, 'vendor/autoload.php'))};
require ${JSON.stringify(join(projectRoot, 'packages/reprint-client/src/lib/host/class-runtime-manifest.php'))};
require ${JSON.stringify(join(projectRoot, 'packages/reprint-client/src/lib/target-runtime/load.php'))};
$manifest = new RuntimeManifest('other');
$manifest->constants['REPRINT_PULL_STATE_FILE'] = $argv[1] . '/state/state.json';
$manifest->constants['REPRINT_REMOTE_UPLOAD_PROXY_BASE_URL'] = 'https://source.example/wp-content/uploads';
$manifest->routes[] = [
    'handler' => 'remote-upload-proxy',
    'path_pattern' => '/wp-content/uploads/.*',
    'condition' => 'file_not_found',
    'description' => 'Proxy missing uploads',
];
(new PlaygroundCliApplier())->apply($manifest, $argv[1] . '/site', $argv[1] . '/runtime');
`);
        execFileSync(process.env.PHP_BINARY || 'php', [script, root]);
        const runtime = readFileSync(join(root, 'runtime/runtime.php'), 'utf8');
        assert.ok(!runtime.includes('REPRINT_PULL_STATE_FILE'));
        assert.ok(!runtime.includes('Remote upload proxy failed.'));
        const config = JSON.parse(readFileSync(join(root, 'runtime/start.json'), 'utf8'));
        for (const { source, target } of [...config.mounts_before_install, ...config.mounts]) {
            const resolvedSource = realpathSync(source);
            assert.ok(stateFile !== resolvedSource && !stateFile.startsWith(`${resolvedSource}/`),
                'No generated mount may expose the pull state');
            await php.mount(target, createNodeFsMountHandler(source));
        }
        assert.equal(php.fileExists('/tmp/reprint/state.json'), false);
        const response = await php.run({ code: "<?php require '/wordpress/index.php';" });
        assert.equal(response.text, 'local site');
        assert.equal(readFileSync(stateFile, 'utf8'), originalState);
        assert.equal(php.readFileAsText('/tmp/reprint/state.json'), 'changed by the imported site');
    } finally {
        php.exit();
        rmSync(root, { recursive: true, force: true });
    }
});
