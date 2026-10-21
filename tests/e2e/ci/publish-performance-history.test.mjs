import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { copyFile, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { test } from 'node:test';

const run = promisify(execFile);

test('a fresh publisher sends only the history file and its own static site', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'reprint-publish-'));
    const origin = join(directory, 'origin.git');
    const source = join(directory, 'source');
    const artifact = join(directory, 'history.json');
    const environment = { ...process.env, GH_TOKEN: 'test-token', GITHUB_SHA: 'test-commit' };
    const git = (args, cwd = source) => run('git', args, { cwd, env: environment });
    try {
        await run('git', ['init', '--bare', '--initial-branch=trunk', origin]);
        await run('git', ['init', '--initial-branch=trunk', source]);
        await git(['config', 'user.name', 'Test']);
        await git(['config', 'user.email', 'test@example.test']);
        await git(['config', 'commit.gpgsign', 'false']);
        await mkdir(join(source, 'bin'), { recursive: true });
        await mkdir(join(source, '.github/perf-site'), { recursive: true });
        await copyFile(new URL('../../../bin/publish-performance-history.sh', import.meta.url), join(source, 'bin/publish-performance-history.sh'));
        await writeFile(join(source, '.github/perf-site/index.html'), 'static site from publishing checkout');
        await git(['add', '.']);
        await git(['commit', '-m', 'Publishing files']);
        await git(['remote', 'add', 'origin', origin]);
        await git(['push', 'origin', 'trunk']);
        let history = '{"entries":[{"sha":"first"}]}\n';
        await writeFile(artifact, history);
        let lastCommit;
        for (const [index, change] of ['create', 'update', 'unchanged'].entries()) {
            const checkout = join(directory, `publisher-${index}`);
            await run('git', ['clone', origin, checkout]);
            await git(['config', 'commit.gpgsign', 'false'], checkout);
            if (change === 'update') {
                history = '{"entries":[{"sha":"first"},{"sha":"second"}]}\n';
                await writeFile(artifact, history);
            }
            await run('bash', [join(checkout, 'bin/publish-performance-history.sh'), artifact], {
                cwd: checkout, env: environment,
            });
            const commit = (await git(['--git-dir=' + origin, 'rev-parse', 'gh-pages'])).stdout.trim();
            const published = (await git(['--git-dir=' + origin, 'show', 'gh-pages:data/history.json'])).stdout;
            assert.equal(published, history);
            assert.equal((await git(['--git-dir=' + origin, 'show', 'gh-pages:index.html'])).stdout, 'static site from publishing checkout');
            assert.ok(!(await readFile(join(checkout, '.git/config'), 'utf8')).includes('test-token'));
            if (change === 'unchanged') assert.equal(commit, lastCommit);
            lastCommit = commit;
        }
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});
