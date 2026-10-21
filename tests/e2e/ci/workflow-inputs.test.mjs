import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { readFile, readdir } from 'node:fs/promises';
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
        assert.match(step, /GH_TOKEN: \$\{\{ secrets\.DEPENDENCY_UPDATES_TOKEN \}\}/, filename);
        assert.doesNotMatch(step, /--admin/, filename);
        assert.match(source, /token: \$\{\{ secrets\.DEPENDENCY_UPDATES_TOKEN \}\}/, filename);
        assert.doesNotMatch(source, /secrets\.GH_TOKEN/, filename);
        assert.match(source, /gh api user --silent/, filename);
        assert.ok(source.includes("${{ github.event.pull_request.number || 'updates' }}"), `${filename}: PR tests must not share the publisher's pending slot.`);
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
