import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { mkdir, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { test } from 'node:test';

const run = promisify(execFile);
const workflows = new URL('../../../.github/workflows/', import.meta.url);

test('external CI actions use recorded commit IDs', async () => {
    for (const filename of await readdir(workflows)) {
        if (!filename.endsWith('.yml')) continue;
        const source = await readFile(new URL(filename, workflows), 'utf8');
        for (const match of source.matchAll(/^\s*(?:-\s*)?uses:\s+([^\s#]+)/gm)) {
            if (match[1].startsWith('./')) continue;
            assert.match(match[1], /^[^@]+@[a-f0-9]{40}$/, `${filename}: ${match[1]}`);
        }
    }
});

test('build and benchmark jobs leave publishing to separate jobs', async () => {
    for (const filename of ['release.yml', 'perf-history.yml', 'perf-history-backfill.yml']) {
        const source = await readFile(new URL(filename, workflows), 'utf8');
        assert.match(source, /^permissions:\n  contents: read$/m, filename);
        const section = source.slice(source.indexOf('\njobs:\n'));
        const jobs = [...section.matchAll(/^  ([\w-]+):\s*$/gm)];
        for (let index = 0; index < jobs.length; ++index) {
            const name = jobs[index][1];
            const body = section.slice(jobs[index].index, jobs[index + 1]?.index);
            if (name === 'publish') {
                assert.match(body, /^      contents: write$/m, filename);
                assert.match(body, /needs: /, filename);
            } else {
                assert.doesNotMatch(body, /^      contents: write$/m, `${filename}: ${name}`);
                assert.doesNotMatch(body, /GH_TOKEN:/, `${filename}: ${name}`);
            }
        }
        for (const step of source.split(/(?=^      - )/m)) {
            if (step.includes('uses: actions/checkout@')) {
                assert.match(step, /persist-credentials: false/, filename);
            }
        }
    }
});

test('WordPress.org rebuilds the tagged downloads-only plugin after GitHub publishing', async () => {
    const release = await readFile(new URL('release.yml', workflows), 'utf8');
    const deploy = release.slice(release.indexOf('\n  deploy-wporg:'));
    assert.match(deploy, /needs: \[release, publish\]/);
    assert.match(deploy, /uses: \.\/\.github\/workflows\/release-wporg\.yml/);
    assert.match(deploy, /tag: \$\{\{ needs\.release\.outputs\.tag \}\}/);
    const source = await readFile(new URL('release-wporg.yml', workflows), 'utf8');
    assert.match(source, /^  workflow_call:/m);
    assert.match(source, /^  workflow_dispatch:/m);
    assert.match(source, /if: github\.repository == 'WordPress\/reprint'/);
    assert.match(source, /^    environment: svn$/m);
    for (const name of ['SVN_USERNAME', 'SVN_PASSWORD']) {
        assert.ok(deploy.includes(name + ': ${{ secrets.' + name + ' }}'));
    }
    assert.match(source, /group: release-wporg\n  cancel-in-progress: false/);
    assert.match(source, /ref: refs\/tags\/\$\{\{ steps\.resolve\.outputs\.tag \}\}/);
    assert.match(source, /persist-credentials: false/);
    assert.match(source, /--json isDraft,isPrerelease/);
    assert.doesNotMatch(source, /gh release download|browser_download_url|sparse-checkout/);
    assert.match(source, /tools: composer:2\.9\.7/);
    const stamp = source.indexOf('run: ./bin/stamp-plugin-version.sh "$VERSION"');
    const build = source.indexOf('./bin/build-server-plugin.sh reprint-wordpress-org.zip wordpress-org');
    const extract = source.indexOf('unzip -q reprint-wordpress-org.zip');
    const verify = source.indexOf('run: php tests/server-plugin-package.php');
    const publish = source.indexOf('uses: 10up/action-wordpress-plugin-deploy@');
    assert.ok(stamp > 0 && stamp < build && build < extract && extract < verify && verify < publish);
    assert.doesNotMatch(source, /reprint-exporter-wp\.zip/);
    assert.match(source, /php tests\/server-plugin-package\.php reprint "\$VERSION" wordpress-org/);
    assert.match(source, /SLUG: reprint\n          BUILD_DIR: reprint/);
    assert.match(source, /SVN_USERNAME: \$\{\{ secrets\.SVN_USERNAME \}\}/);
    assert.match(source, /SVN_PASSWORD: \$\{\{ secrets\.SVN_PASSWORD \}\}/);
});

test('WordPress.org permits only adamziel for publishing and upstream reruns before release code runs', async () => {
    const source = await readFile(new URL('release-wporg.yml', workflows), 'utf8');
    const step = source.split(/(?=^      - )/m)[1];
    assert.match(step, /name: Check publishing user/);
    assert.match(step, /TRIGGERING_ACTOR: \$\{\{ github\.triggering_actor \}\}/);
    assert.match(step, /UPSTREAM_TRIGGERING_ACTOR: \$\{\{ github\.event\.workflow_run\.triggering_actor\.login \}\}/);
    const command = step.match(/run: \|\n((?: {10}[^\n]+\n?)+)/)[1].replace(/^ {10}/gm, '');
    for (const [actor, upstreamActor, allowed] of [
        ['akirk', '', false],
        ['brandonpayton', '', false],
        ['WPprodigy', '', false],
        ['adamziel', '', true],
        ['adamziel', 'adamziel', true],
        ['akirk', 'adamziel', false],
        ['adamziel', 'akirk', false],
        ['other-user', '', false],
        ['other-user', 'adamziel', false],
        ['adamziel', 'other-user', false],
        ['github-actions[bot]', '', false],
        ['adamziel-other-user', '', false],
        ['', '', false],
    ]) {
        const check = run('bash', ['-e', '-o', 'pipefail', '-c', command], {
            env: { ...process.env, TRIGGERING_ACTOR: actor, UPSTREAM_TRIGGERING_ACTOR: upstreamActor },
        });
        if (allowed) {
            await check;
        } else {
            await assert.rejects(check, /is not allowed to publish Reprint/);
        }
    }
});

test('WordPress.org accepts only stable version tags', async () => {
    const source = await readFile(new URL('release-wporg.yml', workflows), 'utf8');
    const step = source.split(/(?=^      - )/m).find((part) => part.includes('name: Resolve release tag'));
    const command = step.match(/run: \|\n((?: {10}[^\n]+\n?)+)/)[1].replace(/^ {10}/gm, '');
    const directory = await mkdtemp(join(tmpdir(), 'reprint-release-tag-'));
    try {
        for (const tag of ['v0.12.0', '0.12.0', 'v0.12.0-beta.1', 'v0.12.0-dev', 'trunk', '../../v0.12.0', 'v0.12.0\nother=value']) {
            const output = join(directory, encodeURIComponent(tag));
            const check = run('bash', ['-e', '-o', 'pipefail', '-c', command], {
                env: { ...process.env, RELEASE_TAG: tag, GITHUB_OUTPUT: output },
            });
            if (tag === 'v0.12.0' || tag === '0.12.0') {
                await check;
                assert.equal(await readFile(output, 'utf8'), 'tag=v0.12.0\nversion=0.12.0\n');
            } else {
                await assert.rejects(check, /must be a stable version/, tag);
            }
        }
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('WordPress.org screenshot updates use the release queue and cannot publish plugin code', async () => {
    const source = await readFile(new URL('update-wporg-screenshots.yml', workflows), 'utf8');
    const release = await readFile(new URL('release-wporg.yml', workflows), 'utf8');
    assert.match(source, /^  workflow_dispatch:$/m);
    assert.doesNotMatch(source, /^  (push|pull_request|workflow_call):/m);
    assert.match(source, /github\.repository == 'WordPress\/reprint' && github\.triggering_actor == 'adamziel' && github\.ref == 'refs\/heads\/trunk'/);
    assert.match(source, /^    environment: svn$/m);
    assert.equal(source.match(/^  group: (.+)$/m)[1], release.match(/^  group: (.+)$/m)[1]);
    assert.match(source, /cancel-in-progress: false/);
    assert.match(source, /persist-credentials: false/);
    assert.match(source, /IGNORE_OTHER_FILES: 'true'/);
    assert.match(source, /ASSETS_DIR: \.wordpress-org/);
    assert.match(source, /SVN_USERNAME: \$\{\{ secrets\.SVN_USERNAME \}\}/);
    assert.match(source, /SVN_PASSWORD: \$\{\{ secrets\.SVN_PASSWORD \}\}/);
});

test('WordPress.org screenshot captions preserve the published readme and replace earlier captions', async () => {
    const source = await readFile(new URL('update-wporg-screenshots.yml', workflows), 'utf8');
    const step = source.split(/(?=^      - )/m).find((part) => part.includes('name: Update screenshot captions'));
    const command = step.split('        run: |\n')[1].replace(/^ {10}/gm, '');
    const directory = await mkdtemp(join(tmpdir(), 'reprint-screenshot-readme-'));
    const published = '=== Reprint ===\nStable tag: 0.11.3\nRequires PHP: 7.2\n\n== Description ==\nPublished description.\n\n== Changelog ==\nPublished changelog.\n';
    const screenshots = '== Screenshots ==\n\n1. Authorize a tool to copy your site using a Migration Token.\n';
    try {
        await mkdir(join(directory, 'reprint-server-wp'));
        await writeFile(join(directory, 'reprint-server-wp/readme.txt'), 'Stable tag: 0.11.4-dev\n\n== Description ==\nUnreleased description.\n\n' + screenshots + '\n== Changelog ==\nUnreleased changelog.\n');
        const output = join(directory, 'readme.txt');
        for (const input of [published, published + '\n== Screenshots ==\n\n1. Old caption.\n', published.replace('== Changelog ==', '== Screenshots ==\n\n1. Old caption.\n\n== Changelog ==')]) {
            await writeFile(output, input);
            await run('bash', ['-e', '-o', 'pipefail', '-c', command], { cwd: directory });
            const updated = await readFile(output, 'utf8');
            assert.ok(updated.includes(screenshots));
            assert.equal(updated.replace(/^== Screenshots ==\n(?:(?!^== ).)*/ms, '').trim(), published.trim());
            await run('bash', ['-e', '-o', 'pipefail', '-c', command], { cwd: directory });
            assert.equal(await readFile(output, 'utf8'), updated);
        }
        const before = await readFile(output, 'utf8');
        await writeFile(join(directory, 'reprint-server-wp/readme.txt'), '== Description ==\nNo screenshot captions.\n');
        await assert.rejects(run('bash', ['-e', '-o', 'pipefail', '-c', command], { cwd: directory }), /no Screenshots section/);
        assert.equal(await readFile(output, 'utf8'), before);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('the first WordPress.org screenshot has a matching caption and is a PNG', async () => {
    const readme = await readFile(new URL('../../../reprint-server-wp/readme.txt', import.meta.url), 'utf8');
    assert.match(readme, /== Screenshots ==\n\n1\. Authorize a tool to copy your site using a Migration Token\./);
    const image = await readFile(new URL('../../../.wordpress-org/screenshot-1.png', import.meta.url));
    assert.equal(image.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
    assert.ok(image.length < 10 * 1024 * 1024);
});

test('dependency updates enable auto-merge for the generated PR and commit', async () => {
    for (const filename of ['update-build-inputs.yml', 'update-playground-inputs.yml']) {
        const source = await readFile(new URL(filename, workflows), 'utf8');
        const step = source.split(/(?=^      - )/m).find((part) => part.includes('name: Enable auto-merge'));
        assert.ok(step, `${filename} must enable auto-merge after opening the update PR.`);
        assert.match(source, /id: update_pr/, filename);
        assert.match(step, /if: steps\.update_pr\.outputs\.pull-request-number != ''/, filename);
        assert.match(step, /gh pr merge --squash --auto --match-head-commit "\$EXPECTED_COMMIT" "\$PR_NUMBER"/, filename);
        assert.match(step, /PR_NUMBER: \$\{\{ steps\.update_pr\.outputs\.pull-request-number \}\}/, filename);
        assert.match(step, /EXPECTED_COMMIT: \$\{\{ steps\.update_pr\.outputs\.pull-request-head-sha \}\}/, filename);
        assert.match(step, /GH_TOKEN: \$\{\{ secrets\.GH_TOKEN \}\}/, filename);
        assert.doesNotMatch(step, /--admin/, filename);
    }
});

test('every PR workflow reports a final result covering all of its PR jobs', async () => {
    for (const filename of await readdir(workflows)) {
        if (!filename.endsWith('.yml')) continue;
        const source = await readFile(new URL(filename, workflows), 'utf8');
        if (!/^  pull_request:/m.test(source)) continue;
        assert.match(source, /^  pull_request:[ \t]*$/m, filename);
        assert.doesNotMatch(source, /^  pull_request:[ \t]*\n(?:[ \t]*\n)* {4}\S/m, `${filename} must report its required result on every PR.`);
        const section = source.slice(source.indexOf('\njobs:\n'));
        const jobs = [...section.matchAll(/^  ([\w-]+):\s*$/gm)].map((match, index, matches) => ({
            name: match[1],
            source: section.slice(match.index, matches[index + 1]?.index),
        }));
        const result = jobs.find((job) => job.name === 'ci-result');
        assert.ok(result, `${filename} must report its final CI result.`);
        assert.match(result.source, /if: \$\{\{ always\(\) \}\}/, filename);
        assert.match(result.source, /JOB_RESULTS: \$\{\{ toJSON\(needs\) \}\}/, filename);
        assert.match(result.source, /all\(\.\[\]; \.result == "success"\)/, filename);
        const needed = result.source.match(/needs: \[([^\]]+)\]/)?.[1].split(',').map((name) => name.trim()).sort();
        const expected = jobs.filter((job) => job.name !== 'ci-result' && !job.source.includes("if: github.event_name != 'pull_request'"))
            .map((job) => job.name).sort();
        assert.deepEqual(needed, expected, `${filename}: a new PR job must be included in the final result.`);
        const command = result.source.match(/run: \|\n((?: {10}[^\n]+\n?)+)/)[1].replace(/^ {10}/gm, '');
        for (const status of ['success', 'failure', 'skipped', 'cancelled']) {
            const check = run('bash', ['-e', '-o', 'pipefail', '-c', command], {
                env: { ...process.env, JOB_RESULTS: JSON.stringify({ build: { result: 'success' }, tests: { result: status } }) },
            });
            if (status === 'success') {
                await check;
            } else {
                await assert.rejects(check, `${filename} must reject a ${status} dependency.`);
            }
        }
    }
});

test('failed PR workflows have bounded reruns without executing PR code', async () => {
    const retry = await readFile(new URL('retry-failed-ci.yml', workflows), 'utf8');
    for (const filename of await readdir(workflows)) {
        if (!filename.endsWith('.yml')) continue;
        const source = await readFile(new URL(filename, workflows), 'utf8');
        if (/^  pull_request:/m.test(source)) {
            const name = source.match(/^name: (.+)$/m)[1];
            assert.ok(retry.includes(`      - '${name}'`), `${filename} must be included in failed-job reruns.`);
        }
    }
    assert.match(retry, /github\.event\.workflow_run\.event == 'pull_request'/);
    assert.match(retry, /github\.event\.workflow_run\.head_repository\.full_name == github\.repository/);
    assert.match(retry, /github\.event\.workflow_run\.run_attempt < 3/);
    assert.match(retry, /actions\/runs\/\$RUN_ID\/rerun-failed-jobs/);
    assert.doesNotMatch(retry, /actions\/checkout|gh pr merge|secrets\./);
});
