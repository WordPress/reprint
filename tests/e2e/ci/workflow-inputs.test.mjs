import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { test } from 'node:test';

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
