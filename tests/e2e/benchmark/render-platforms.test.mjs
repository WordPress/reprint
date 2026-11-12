import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

/** Render the platform table from saved measurements. */
function renderPlatforms(context, windowsResults, linuxResults) {
    const directory = mkdtempSync(join(tmpdir(), 'reprint-benchmark-platforms-'));
    context.after(() => rmSync(directory, { recursive: true, force: true }));
    const meta = { site: 'large-directory' };
    writeFileSync(join(directory, 'windows.json'), JSON.stringify({ meta, results: windowsResults }));
    writeFileSync(join(directory, 'linux.json'), JSON.stringify({ meta, results: linuxResults }));
    execFileSync(process.execPath, [fileURLToPath(new URL('./render-platforms.mjs', import.meta.url))], {
        env: {
            ...process.env, WINDOWS_JSON: join(directory, 'windows.json'),
            LINUX_JSON: join(directory, 'linux.json'), OUT_MD: join(directory, 'report.md'),
        },
    });
    return readFileSync(join(directory, 'report.md'), 'utf8');
}

test('each stage shows both times and how many times longer Windows took', context => {
    const report = renderPlatforms(context,
        [{ stage: 'files-pull', elapsedMs: 52_800, ok: true }],
        [{ stage: 'files-pull', elapsedMs: 10_300, ok: true }]);
    assert.match(report, /\| `files-pull` \| 52\.80 s \| 10\.30 s \| 5\.1× \|/);
    assert.match(report, /<details>/);
});

test('a failed or missing stage gets no ratio', context => {
    const report = renderPlatforms(context,
        [
            { stage: 'db-apply', elapsedMs: 200, ok: false, exitCode: 1 },
            { stage: 'apply-runtime', elapsedMs: 200, ok: true },
        ],
        [{ stage: 'db-apply', elapsedMs: 5_600, ok: true }]);
    assert.match(report, /\| `db-apply` \| ✗ exit 1 \| 5\.60 s \| — \|/);
    assert.match(report, /\| `apply-runtime` \| 200 ms \| — \| — \|/);
});
