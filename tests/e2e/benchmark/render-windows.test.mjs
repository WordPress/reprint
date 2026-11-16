import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

/** Render the Windows report from saved measurements; a null set leaves its file out. */
function renderWindows(context, windowsResults, trunkResults, linuxResults) {
    const directory = mkdtempSync(join(tmpdir(), 'reprint-benchmark-windows-'));
    context.after(() => rmSync(directory, { recursive: true, force: true }));
    const meta = { site: 'large-directory' };
    const files = { 'windows.json': windowsResults, 'trunk.json': trunkResults, 'linux.json': linuxResults };
    for (const [name, results] of Object.entries(files)) {
        if (results) writeFileSync(join(directory, name), JSON.stringify({ meta, results }));
    }
    execFileSync(process.execPath, [fileURLToPath(new URL('./render-windows.mjs', import.meta.url))], {
        env: {
            ...process.env, WINDOWS_JSON: join(directory, 'windows.json'),
            WINDOWS_TRUNK_JSON: join(directory, 'trunk.json'), LINUX_JSON: join(directory, 'linux.json'),
            OUT_MD: join(directory, 'report.md'),
        },
    });
    return readFileSync(join(directory, 'report.md'), 'utf8');
}

test('each stage shows the trunk change and how many times longer Windows took than Linux', context => {
    const report = renderWindows(context,
        [{ stage: 'files-pull', elapsedMs: 52_800, ok: true }],
        [{ stage: 'files-pull', elapsedMs: 52_000, ok: true }],
        [{ stage: 'files-pull', elapsedMs: 10_300, ok: true }]);
    assert.match(report, /\| `files-pull` \| 52\.80 s \| 52\.00 s \| ⚪ \+1\.5% \| 10\.30 s \(5\.1×\) \|/);
    assert.match(report, /<summary><b>Native Windows client<\/b>: PR 52\.80 s · trunk 52\.00 s · ⚪ \+1\.5% · no stage changed more than 5%<\/summary>/);
});

test('the summary counts stages that changed more than 5% or could not be compared', context => {
    const report = renderWindows(context,
        [
            { stage: 'preflight', elapsedMs: 350, ok: true },
            { stage: 'files-pull', elapsedMs: 44_000, ok: true },
            { stage: 'db-pull', elapsedMs: 1_700, ok: true },
        ],
        [
            { stage: 'preflight', elapsedMs: 200, ok: true },
            { stage: 'files-pull', elapsedMs: 3_000, ok: false, exitCode: 1 },
            { stage: 'db-pull', elapsedMs: 1_700, ok: true },
        ],
        null);
    assert.match(report, /\| `preflight` \| 350 ms \| 200 ms \| 🔴 \+75\.0% \| — \|/);
    assert.match(report, /\| `files-pull` \| 44\.00 s \| ✗ exit 1 \| — \| — \|/);
    assert.match(report, /<summary><b>Native Windows client<\/b>: PR 46\.05 s · 2 stages changed more than 5% or could not be compared<\/summary>/);
});

test('a failed or missing stage gets no ratio', context => {
    const report = renderWindows(context,
        [
            { stage: 'db-apply', elapsedMs: 200, ok: false, exitCode: 1 },
            { stage: 'apply-runtime', elapsedMs: 200, ok: true },
        ],
        null,
        [{ stage: 'db-apply', elapsedMs: 5_600, ok: true }]);
    assert.match(report, /\| `db-apply` \| ✗ exit 1 \| — \| — \| 5\.60 s \|/);
    assert.match(report, /\| `apply-runtime` \| 200 ms \| — \| — \| — \|/);
});
