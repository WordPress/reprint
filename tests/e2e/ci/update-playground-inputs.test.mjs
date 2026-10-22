import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdir, mkdtemp, readFile, rm, writeFile, access } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { test } from 'node:test';

const run = promisify(execFile);

test('Playground updates keep packages, generated startup, and parser hashes together', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'reprint-playground-update-'));
    const commit = 'a'.repeat(40);
    const releaseBytes = Buffer.from('extension bytes; do not execute');
    const hash = createHash('sha256').update(releaseBytes).digest('hex');
    const files = [
        'tests/e2e/package.json',
        'tests/e2e/package-lock.json',
        'packages/reprint-client/src/lib/target-runtime/class-playground-cli-applier.php',
        'tests/e2e/ci/wp-mysql-parser-extension.json',
    ];
    const packages = ['@wp-playground/cli', '@php-wasm/node', '@php-wasm/universal'];
    let missingArtifact = true;
    let downloadFails = false;
    let packageVersion = '99.0.0';
    const server = createServer((request, response) => {
        const path = decodeURIComponent(request.url);
        response.setHeader('Content-Type', 'application/json');
        if (path.startsWith('/api/')) {
            assert.equal(request.headers.authorization, 'Bearer test-only-token');
            response.end(JSON.stringify([{ sha: commit }]));
        } else if (path.endsWith('/manifest.json')) {
            assert.equal(request.headers.authorization, undefined);
            response.end(JSON.stringify({ version: 'new-parser-build', artifacts: (missingArtifact ? ['8.3'] : ['8.3', '8.4']).map((phpVersion) => ({
                phpVersion,
                sourcePath: `wp_mysql_parser-php${phpVersion}-jspi.so`,
            })) }));
        } else if (path.endsWith('.so')) {
            assert.equal(request.headers.authorization, undefined);
            response.statusCode = downloadFails ? 404 : 200;
            response.end(releaseBytes);
        } else if (path.startsWith('/npm/')) {
            assert.equal(request.headers.authorization, undefined);
            const name = path.slice('/npm/'.length).replace(/\/latest$/, '');
            assert.ok(packages.includes(name), `Unexpected npm lookup: ${path}`);
            const metadata = {
                name,
                version: packageVersion,
                dependencies: name === '@wp-playground/cli' ? { '@php-wasm/node': packageVersion, '@php-wasm/universal': packageVersion } : {},
                dist: { tarball: `${url}/never-download.tgz`, integrity: `sha512-${Buffer.alloc(64).toString('base64')}` },
            };
            response.end(JSON.stringify(path.endsWith('/latest') ? metadata : {
                name, 'dist-tags': { latest: packageVersion }, versions: { [packageVersion]: metadata },
            }));
        } else {
            response.statusCode = 500;
            response.end('The updater must resolve metadata without downloading or executing packages.');
        }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}`;
    try {
        for (const file of [...files, 'bin/update-playground-inputs.mjs']) {
            await mkdir(join(directory, file, '..'), { recursive: true });
        }
        await writeFile(join(directory, files[0]), JSON.stringify({
            name: 'update-fixture', private: true,
            scripts: { preinstall: 'node -e "require(\'fs\').writeFileSync(\'executed\', \'wrong\')"' },
            dependencies: Object.fromEntries(packages.map((name) => [name, '1.0.0'])),
        }, null, 2) + '\n');
        await writeFile(join(directory, files[1]), JSON.stringify({
            name: 'update-fixture', lockfileVersion: 3, packages: {
                '': { name: 'update-fixture', dependencies: Object.fromEntries(packages.map((name) => [name, '1.0.0'])) },
                ...Object.fromEntries(packages.map((name) => [`node_modules/${name}`, { version: '1.0.0' }])),
            },
        }, null, 2) + '\n');
        const applier = await readFile(new URL(`../../../${files[2]}`, import.meta.url), 'utf8');
        await writeFile(join(directory, files[2]), applier.replace(/private const CLI_VERSION = '[^']+';/, "private const CLI_VERSION = '1.0.0';"));
        await writeFile(join(directory, files[3]), JSON.stringify({
            sourceCommit: 'b'.repeat(40), version: 'old-parser-build',
            artifacts: { '8.3': { url: 'old', sha256: 'old' }, '8.4': { url: 'old', sha256: 'old' } },
        }, null, 2) + '\n');
        const source = await readFile(new URL('../../../bin/update-playground-inputs.mjs', import.meta.url), 'utf8');
        await writeFile(join(directory, 'bin/update-playground-inputs.mjs'), source
            .replaceAll('https://api.github.com', `${url}/api`)
            .replaceAll('https://raw.githubusercontent.com', `${url}/raw`)
            .replaceAll('https://registry.npmjs.org', `${url}/npm`));
        const update = () => run(process.execPath, [join(directory, 'bin/update-playground-inputs.mjs')], {
            env: { ...process.env, GH_TOKEN: 'test-only-token', npm_config_registry: `${url}/npm/`, npm_config_cache: join(directory, 'npm-cache') },
        });
        const original = await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8')));
        await assert.rejects(update(), /8\.4.*artifact/);
        assert.deepEqual(await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8'))), original);
        missingArtifact = false;
        downloadFails = true;
        await assert.rejects(update(), /HTTP 404/);
        assert.deepEqual(await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8'))), original);
        downloadFails = false;
        await update();
        const updated = await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8')));
        for (const name of packages) {
            assert.equal(JSON.parse(updated[0]).dependencies[name], '99.0.0');
            assert.equal(JSON.parse(updated[1]).packages[`node_modules/${name}`].version, '99.0.0');
        }
        assert.ok(updated[2].includes("private const CLI_VERSION = '99.0.0';"));
        const parser = JSON.parse(updated[3]);
        assert.equal(parser.sourceCommit, commit);
        assert.equal(parser.version, 'new-parser-build');
        for (const [version, artifact] of Object.entries(parser.artifacts)) {
            assert.equal(artifact.url, `${url}/raw/WordPress/sqlite-database-integration/${commit}/wp_mysql_parser-wasm-extension/latest/wp_mysql_parser-php${version}-jspi.so`);
            assert.equal(artifact.sha256, hash);
        }
        await assert.rejects(access(join(directory, 'tests/e2e/executed')), /ENOENT/);
        await update();
        assert.deepEqual(await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8'))), updated);
        packageVersion = '1.0.0';
        await update();
        assert.deepEqual(await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8'))), updated);
    } finally {
        server.closeAllConnections();
        await new Promise((resolve) => server.close(resolve));
        await rm(directory, { recursive: true, force: true });
    }
});
