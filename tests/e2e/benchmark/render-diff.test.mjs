import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

/** Exercise the report CLI with saved measurements, without timing a fake rewriter. */
function renderReport(context, prResult, baselineResult) {
    const directory = mkdtempSync(join(tmpdir(), 'reprint-benchmark-report-'));
    context.after(() => rmSync(directory, { recursive: true, force: true }));
    const meta = { site: 'large-directory', fileCount: 2000, phpVersion: '8.5' };
    writeFileSync(join(directory, 'pr.json'), JSON.stringify({ meta, results: [prResult] }));
    if (baselineResult) {
        writeFileSync(join(directory, 'baseline.json'), JSON.stringify({ meta, results: [baselineResult] }));
    }
    execFileSync(process.execPath, [fileURLToPath(new URL('./render-diff.mjs', import.meta.url))], {
        env: {
            ...process.env, PR_JSON: join(directory, 'pr.json'), TRUNK_JSON: join(directory, 'baseline.json'),
            OUT_MD: join(directory, 'report.md'), BASELINE_LABEL: 'trunk',
        },
    });
    return readFileSync(join(directory, 'report.md'), 'utf8');
}

test('URL cases display their own slowdown, not just the combined total', context => {
    const report = renderReport(context,
        { stage: 'url-rewrite-blocks-nested-html', elapsedMs: 900, ok: true },
        { stage: 'url-rewrite-blocks-nested-html', elapsedMs: 60, ok: true });
    assert.match(report, /url-rewrite-blocks-nested-html.*900 ms.*60 ms.*\+840 ms \(\+1400\.0%\)/);
    assert.match(report, /median of five samples/);
});

for (const failedSide of ['pr', 'baseline']) {
    test(`${failedSide} output failure cannot appear as a speed improvement`, context => {
        const passed = { stage: 'url-rewrite-html', elapsedMs: 1000, ok: true };
        const failed = { stage: 'url-rewrite-html', elapsedMs: 0, ok: false, exitCode: 1 };
        const report = renderReport(context,
            failedSide === 'pr' ? failed : passed, failedSide === 'baseline' ? failed : passed);
        assert.match(report, /✗ (PR|trunk) exit 1/);
        assert.doesNotMatch(report, /🟢|🔴|⚪/);
    });
}

test('a missing baseline is reported without inventing a zero time', context => {
    const report = renderReport(context, { stage: 'url-rewrite-html', elapsedMs: 1000, attempts: 1, ok: true });
    assert.match(report, /trunk baseline unavailable/);
    assert.doesNotMatch(report, /🟢|🔴|⚪/);
});
