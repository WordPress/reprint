import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';

async function main() {
    const changes = new Map();
    for (const [repository, file] of [
        ['box-project/box', 'bin/build-reprint-phar.sh'],
        ['wp-cli/wp-cli', 'tests/e2e/ci/download-wp-cli.sh'],
    ]) {
        const path = new URL(`../${file}`, import.meta.url);
        const source = await readFile(path, 'utf8');
        // The shell call keeps the release URL and its hash on adjacent lines.
        const current = source.match(/'([^']+\/releases\/download\/v?(\d+\.\d+\.\d+)\/[^']+)'\s*\\\s*'([a-f0-9]{64})'/);
        if (!current) {
            throw new Error(`Cannot find the recorded release URL and SHA-256 in ${file}.`);
        }
        const response = await fetch(`https://api.github.com/repos/${repository}/releases/latest`, {
            headers: process.env.GH_TOKEN ? { Authorization: `Bearer ${process.env.GH_TOKEN}` } : {},
        });
        if (!response.ok) {
            throw new Error(`${repository} release lookup returned HTTP ${response.status}.`);
        }
        const release = await response.json();
        const version = release.tag_name.match(/^v?(\d+\.\d+\.\d+)$/)?.[1];
        if (!version) {
            throw new Error(`${repository} returned an unsupported release tag: ${release.tag_name}.`);
        }
        const currentParts = current[2].split('.').map(Number);
        const difference = version.split('.').map((part, index) => Number(part) - currentParts[index]).find((part) => part !== 0) ?? 0;
        if (difference <= 0) {
            continue;
        }
        const assetName = repository === 'box-project/box' ? 'box.phar' : `wp-cli-${version}.phar`;
        const url = `https://github.com/${repository}/releases/download/${release.tag_name}/${assetName}`;
        const asset = release.assets.find((candidate) => candidate.name === assetName && candidate.browser_download_url === url);
        if (!asset) {
            throw new Error(`${repository} release ${release.tag_name} has no ${assetName} asset at ${url}.`);
        }
        const download = await fetch(url);
        if (!download.ok) {
            throw new Error(`${url} returned HTTP ${download.status}.`);
        }
        const hash = createHash('sha256');
        for await (const chunk of download.body) {
            hash.update(chunk);
        }
        const checksum = hash.digest('hex');
        if (asset.digest && asset.digest !== `sha256:${checksum}`) {
            throw new Error(`SHA-256 mismatch for ${url}: GitHub reports ${asset.digest}, downloaded sha256:${checksum}.`);
        }
        changes.set(path, source.replace(current[1], url).replace(current[3], checksum));
        console.log(`${repository}: ${current[2]} -> ${version}`);
    }
    // Finish both lookups before changing files, so a failed lookup cannot
    // leave a partial update ready for the PR-opening step.
    for (const [path, source] of changes) {
        await writeFile(path, source);
    }
}

main().catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
