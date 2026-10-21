import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

async function main() {
    const packagePath = new URL('../tests/e2e/package.json', import.meta.url);
    const applierPath = new URL('../packages/reprint-client/src/lib/target-runtime/class-playground-cli-applier.php', import.meta.url);
    const parserPath = new URL('../tests/e2e/ci/wp-mysql-parser-extension.json', import.meta.url);
    const packages = ['@wp-playground/cli', '@php-wasm/node', '@php-wasm/universal'];
    const packageJson = JSON.parse(await readFile(packagePath, 'utf8'));
    const applier = await readFile(applierPath, 'utf8');
    const currentVersion = packageJson.dependencies['@wp-playground/cli'];
    const currentConstant = `private const CLI_VERSION = '${currentVersion}';`;
    if (!/^\d+\.\d+\.\d+$/.test(currentVersion) || !applier.includes(currentConstant) || packages.some((name) => packageJson.dependencies[name] !== currentVersion)) {
        throw new Error('The installed Playground packages and generated runtime must record the same exact version before updating.');
    }
    const latest = await readJson('https://registry.npmjs.org/@wp-playground%2fcli/latest');
    if (!/^\d+\.\d+\.\d+$/.test(latest.version) || packages.slice(1).some((name) => latest.dependencies?.[name] !== latest.version)) {
        throw new Error(`Playground CLI ${latest.version} must use matching @php-wasm/node and @php-wasm/universal releases.`);
    }
    const currentParts = currentVersion.split('.').map(Number);
    const difference = latest.version.split('.').map((part, index) => Number(part) - currentParts[index]).find((part) => part !== 0) ?? 0;

    const currentParser = JSON.parse(await readFile(parserPath, 'utf8'));
    const commits = await readJson('https://api.github.com/repos/WordPress/sqlite-database-integration/commits?sha=gh-pages&path=wp_mysql_parser-wasm-extension/latest/manifest.json&per_page=1',
        process.env.GH_TOKEN ? { Authorization: `Bearer ${process.env.GH_TOKEN}` } : {});
    const sourceCommit = commits[0]?.sha;
    if (!/^[a-f0-9]{40}$/.test(sourceCommit)) {
        throw new Error(`The parser manifest lookup returned an invalid commit: ${sourceCommit}.`);
    }
    let nextParser;
    if (sourceCommit !== currentParser.sourceCommit) {
        // Resolve the branch once. The manifest and every binary then come
        // from that same commit, even if upstream publishes during this run.
        const manifestUrl = `https://raw.githubusercontent.com/WordPress/sqlite-database-integration/${sourceCommit}/wp_mysql_parser-wasm-extension/latest/manifest.json`;
        const manifest = await readJson(manifestUrl);
        if (typeof manifest.version !== 'string' || !manifest.version) {
            throw new Error(`The parser manifest at ${sourceCommit} has no build version.`);
        }
        nextParser = { sourceCommit, version: manifest.version, artifacts: {} };
        for (const phpVersion of Object.keys(currentParser.artifacts)) {
            const sourcePath = `wp_mysql_parser-php${phpVersion}-jspi.so`;
            if (!manifest.artifacts.some((artifact) => artifact.phpVersion === phpVersion && artifact.sourcePath === sourcePath)) {
                throw new Error(`PHP ${phpVersion} has no ${sourcePath} artifact in the parser manifest at ${sourceCommit}.`);
            }
            const url = new URL(sourcePath, manifestUrl).href;
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`${url} returned HTTP ${response.status}.`);
            }
            const hash = createHash('sha256');
            for await (const chunk of response.body) {
                hash.update(chunk);
            }
            nextParser.artifacts[phpVersion] = { url, sha256: hash.digest('hex') };
        }
    }
    // Download and validate parser metadata before npm changes the package
    // files. Resolving a new lockfile must not run package install scripts.
    if (difference > 0) {
        execFileSync('npm', ['install', '--package-lock-only', '--ignore-scripts', '--save-exact', '--no-audit', '--no-fund', '--no-update-notifier',
            ...packages.map((name) => `${name}@${latest.version}`)], {
            cwd: fileURLToPath(new URL('../tests/e2e/', import.meta.url)),
            stdio: 'inherit',
        });
        await writeFile(applierPath, applier.replace(currentConstant, `private const CLI_VERSION = '${latest.version}';`));
        console.log(`Playground packages and generated runtime: ${currentVersion} -> ${latest.version}`);
    }
    if (nextParser) {
        await writeFile(parserPath, JSON.stringify(nextParser, null, 2) + '\n');
        console.log(`Parser build: ${currentParser.version} -> ${nextParser.version}`);
    }
}

async function readJson(url, headers = {}) {
    const response = await fetch(url, { headers });
    if (!response.ok) {
        throw new Error(`${url} returned HTTP ${response.status}.`);
    }
    return response.json();
}

main().catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
