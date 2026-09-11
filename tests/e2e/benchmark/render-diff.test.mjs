import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

/** Exercise the report CLI with saved measurements, without timing a fake rewriter. */
function renderReport(context, prResults, baselineResults) {
    const directory = mkdtempSync(join(tmpdir(), 'reprint-benchmark-report-'));
    context.after(() => rmSync(directory, { recursive: true, force: true }));
    const meta = { site: 'large-directory', fileCount: 2000, phpVersion: '8.5' };
    writeFileSync(join(directory, 'pr.json'), JSON.stringify({ meta, results: prResults }));
    if (baselineResults) {
        writeFileSync(join(directory, 'baseline.json'), JSON.stringify({ meta, results: baselineResults }));
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
        [{ stage: 'url-rewrite-blocks-nested-html', elapsedMs: 900, ok: true }],
        [{ stage: 'url-rewrite-blocks-nested-html', elapsedMs: 60, ok: true }]);
    assert.match(report, /url-rewrite-blocks-nested-html.*900 ms.*60 ms.*\+840 ms \(\+1400\.0%\)/);
    assert.match(report, /median of five samples/);
});

for (const failedSide of ['pr', 'baseline']) {
    test(`${failedSide} output failure cannot appear as a speed improvement`, context => {
        const passed = { stage: 'url-rewrite-html', elapsedMs: 1000, ok: true };
        const failed = { stage: 'url-rewrite-html', elapsedMs: 0, ok: false, exitCode: 1 };
        const report = renderReport(context,
            [failedSide === 'pr' ? failed : passed], [failedSide === 'baseline' ? failed : passed]);
        assert.match(report, /✗ (PR|trunk) exit 1/);
        assert.doesNotMatch(report, /🟢|🔴|⚪/);
        assert.doesNotMatch(report, /<summary>Changes of 5% or less/);
    });
}

test('a missing baseline is reported without inventing a zero time', context => {
    const report = renderReport(context, [{ stage: 'url-rewrite-html', elapsedMs: 1000, attempts: 1, ok: true }]);
    assert.match(report, /trunk baseline unavailable/);
    assert.doesNotMatch(report, /🟢|🔴|⚪/);
    assert.doesNotMatch(report, /<summary>Changes of 5% or less/);
});

test('only changes above 5% stay visible, including small absolute time changes', context => {
    const prResults = [
        { stage: 'slower', elapsedMs: 106, ok: true },
        { stage: 'faster', elapsedMs: 94, ok: true },
        { stage: 'plus-five', elapsedMs: 105, ok: true },
        { stage: 'minus-five', elapsedMs: 95, ok: true },
        { stage: 'unchanged', elapsedMs: 100, ok: true },
        { stage: 'below-five', elapsedMs: 104, ok: true },
    ];
    const report = renderReport(context, prResults, prResults.map(result => ({ ...result, elapsedMs: 100 })));
    const [visible, collapsed] = report.split('<details>\n<summary>Changes of 5% or less (4)</summary>');

    assert.ok(collapsed, 'The small changes must have a closed details section.');
    assert.match(visible, /`slower`.*🔴 \+6 ms \(\+6\.0%\)/);
    assert.match(visible, /`faster`.*🟢 -6 ms \(-6\.0%\)/);
    for (const stage of ['plus-five', 'minus-five', 'unchanged', 'below-five']) {
        assert.ok(!visible.includes(`\`${stage}\``), `${stage} must not stay visible.`);
        assert.ok(collapsed.includes(`\`${stage}\``), `${stage} must stay available in the collapsed table.`);
    }
    for (const { stage } of prResults) {
        assert.equal(report.split(`\`${stage}\``).length - 1, 1, `${stage} must appear exactly once.`);
    }
    assert.equal(report.match(/\| Stage \|/g).length, 2);
});

test('an all-small-change report has no empty visible table', context => {
    const results = [{ stage: 'url-rewrite-html', elapsedMs: 1000, ok: true }];
    const report = renderReport(context, results, results);
    assert.match(report, /No changes above 5%\./);
    assert.match(report, /<details>\n<summary>Changes of 5% or less \(1\)<\/summary>\n\n\| Stage/);
    assert.equal(report.match(/\| Stage \|/g).length, 1);
});

test('a missing or zero-time baseline row stays visible beside collapsed comparisons', context => {
    const report = renderReport(context, [
        { stage: 'missing', elapsedMs: 100, ok: true },
        { stage: 'zero-time', elapsedMs: 100, ok: true },
        { stage: 'unchanged', elapsedMs: 100, ok: true },
    ], [
        { stage: 'zero-time', elapsedMs: 0, ok: true },
        { stage: 'unchanged', elapsedMs: 100, ok: true },
    ]);
    const [visible, collapsed] = report.split('<details>\n<summary>Changes of 5% or less (1)</summary>');
    assert.ok(collapsed);
    assert.match(visible, /`missing`.*—.*No trunk result/);
    assert.match(visible, /`zero-time`.*—.*Zero trunk time/);
    assert.doesNotMatch(visible, /🟢|🔴|⚪/);
    assert.match(report, /\*\*Total:\*\* PR 300 ms · trunk 100 ms · —/);
});

for (const withBaseline of [true, false]) {
    test(`metrics stay in one closed table cell, ${withBaseline ? 'with' : 'without'} a baseline`, context => {
        const pr = {
            stage: 'url-rewrite-html', elapsedMs: 200, ok: true, attempts: 1,
            details: { rows: 128, sample_ms: '200, 201, 199', note: '<value>|line\nnext & last', omitted: null },
        };
        const baseline = { ...pr, elapsedMs: 100, details: { rows: 128, sample_ms: '100, 101, 99' } };
        const report = renderReport(context, [pr], withBaseline ? [baseline] : undefined);
        const row = report.split('\n').find(line => line.startsWith('| `url-rewrite-html`'));

        assert.match(row, /\| <details><summary>Metrics<\/summary>PR:<br>rows=128<br>sample_ms=200, 201, 199/);
        assert.match(row, /note=&lt;value&gt;&#124;line<br>next &amp; last/);
        if (withBaseline) {
            assert.match(row, /trunk:<br>rows=128<br>sample_ms=100, 101, 99/);
        }
        assert.match(row, /<\/details> \|$/);
        assert.equal(row.match(/<details>/g).length, 1);
        assert.doesNotMatch(row, /omitted=/);
    });
}
