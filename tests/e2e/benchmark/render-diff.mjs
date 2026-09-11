/**
 * Compare PR and baseline timings, with changes of 5% or less collapsed.
 * Reads bench-pr.json and bench-trunk.json, writes bench-results.md.
 * The baseline label defaults
 * to "trunk" but can be overridden with BASELINE_LABEL — that's how stacked
 * PRs report their incremental impact against the parent branch.
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

function fmtMs(ms) {
    if (ms < 1000) return `${ms.toFixed(0)} ms`;
    return `${(ms / 1000).toFixed(2)} s`;
}

function fmtDelta(prMs, baseMs) {
    if (baseMs <= 0) return '—';
    const diff = prMs - baseMs;
    const pct = (diff / baseMs) * 100;
    const sign = diff >= 0 ? '+' : '';
    const abs = `${sign}${fmtMs(Math.abs(diff)).replace(/^/, diff < 0 ? '-' : '')}`;
    // Match the table split: 🟢 more than 5% faster, 🔴 more than 5% slower.
    let marker = '⚪';
    if (Math.abs(pct) > 5) {
        marker = diff < 0 ? '🟢' : '🔴';
    }
    return `${marker} ${abs} (${sign}${pct.toFixed(1)}%)`;
}

/** Keep both builds' metrics in one closed disclosure without breaking the table cell. */
function fmtDetails(details, baselineDetails) {
    const text = [['PR', details], [baselineLabel, baselineDetails]].map(([label, values]) => {
        if (!values || typeof values !== 'object') return '';
        const metrics = Object.entries(values)
            .filter(([, value]) => value !== null && value !== undefined && value !== '')
            .map(([key, value]) => `${key}=${String(value)}`)
            .join('\n');
        return metrics ? `${label}:\n${metrics}` : '';
    }).filter(Boolean).join('\n');
    if (!text) return '';
    const html = text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('|', '&#124;').replace(/\r\n|\r|\n/g, '<br>');
    return `<details><summary>Metrics</summary>${html}</details>`;
}

const prPath = process.env.PR_JSON || 'bench-pr.json';
const basePath = process.env.TRUNK_JSON || 'bench-trunk.json';
const outPath = process.env.OUT_MD || 'bench-results.md';
const baselineLabel = process.env.BASELINE_LABEL || 'trunk';

if (!existsSync(prPath)) {
    console.error(`Missing PR results: ${prPath}`);
    process.exit(1);
}

const pr = JSON.parse(readFileSync(prPath, 'utf-8'));
const base = existsSync(basePath) ? JSON.parse(readFileSync(basePath, 'utf-8')) : null;

const lines = [];
lines.push(`## Pull pipeline performance — \`${pr.meta.site}\``);
lines.push('');
{
    const seedSuffix = (pr.meta.seedPosts && pr.meta.seedPostmeta)
        ? ` · ${Number(pr.meta.seedPosts).toLocaleString('en-US')} posts · ${Number(pr.meta.seedPostmeta).toLocaleString('en-US')} postmeta`
        : '';
    lines.push(`Site: \`${pr.meta.site}\` · ${pr.meta.fileCount} files${seedSuffix} · PHP \`${pr.meta.phpVersion}\``);
}
lines.push('');

if (base) {
    const tableHeader = [
        `| Stage | PR | ${baselineLabel} | Δ | Status | Details |`,
        '|---|---:|---:|---:|---|---|',
    ];
    const visibleRows = [];
    const smallChangeRows = [];
    const baseByStage = Object.fromEntries(base.results.map((r) => [r.stage, r]));
    let prTotal = 0;
    let baseTotal = 0;
    for (const r of pr.results) {
        const t = baseByStage[r.stage];
        prTotal += r.elapsedMs;
        if (t) baseTotal += t.elapsedMs;
        const comparable = t && r.ok && t.ok && t.elapsedMs > 0;
        const delta = comparable ? fmtDelta(r.elapsedMs, t.elapsedMs) : '—';
        const status = !r.ok ? '✗ PR exit ' + r.exitCode
            : !t ? '— No ' + baselineLabel + ' result'
            : !t.ok ? '✗ ' + baselineLabel + ' exit ' + t.exitCode
            : t.elapsedMs <= 0 ? '— Zero ' + baselineLabel + ' time' : '✓';
        const row = `| \`${r.stage}\` | ${fmtMs(r.elapsedMs)} | ${t ? fmtMs(t.elapsedMs) : '—'} | ${delta} | ${status} | ${fmtDetails(r.details, t?.details)} |`;
        // Failed or missing comparisons need attention even when their recorded time is zero.
        if (comparable && Math.abs((r.elapsedMs - t.elapsedMs) / t.elapsedMs * 100) <= 5) {
            smallChangeRows.push(row);
        } else {
            visibleRows.push(row);
        }
    }
    if (visibleRows.length) {
        lines.push('### Changes above 5% or unavailable comparisons', '', ...tableHeader, ...visibleRows);
    } else {
        lines.push('No changes above 5%.');
    }
    if (smallChangeRows.length) {
        // Blank lines let GitHub parse the Markdown table inside the HTML disclosure.
        lines.push('', '<details>', `<summary>Changes of 5% or less (${smallChangeRows.length})</summary>`,
            '', ...tableHeader, ...smallChangeRows, '', '</details>');
    }
    const comparable = pr.results.every(result => result.ok && baseByStage[result.stage]?.ok && baseByStage[result.stage].elapsedMs > 0);
    lines.push('', `**Total:** PR ${fmtMs(prTotal)} · ${baselineLabel} ${fmtMs(baseTotal)} · ${comparable ? fmtDelta(prTotal, baseTotal) : '—'}`);
} else {
    lines.push(`_${baselineLabel} baseline unavailable — showing PR numbers only._`);
    lines.push('');
    lines.push('| Stage | Wall time | Resume attempts | Status | Details |');
    lines.push('|---|---:|---:|---|---|');
    let total = 0;
    for (const r of pr.results) {
        total += r.elapsedMs;
        lines.push(`| \`${r.stage}\` | ${fmtMs(r.elapsedMs)} | ${r.attempts} | ${r.ok ? '✓' : '✗ exit ' + r.exitCode} | ${fmtDetails(r.details)} |`);
    }
    lines.push(`| **Total** | **${fmtMs(total)}** | | | |`);
}

lines.push('');
lines.push('<sub>Numbers carry runner noise; treat single-run deltas as directional, not authoritative.</sub>');
if (pr.results.some(result => result.stage.startsWith('url-rewrite-'))) {
    lines.push('');
    lines.push('URL rows show the median of five samples, using the same inputs and PHP binary for both builds. Each sample starts with fresh caches and reuses one rewriter across 128 distinct values. Input generation and output checks are outside the timer. Every output must pass before a speed is reported.');
}

const historyUrl = (() => {
    if (process.env.PERF_HISTORY_URL) return process.env.PERF_HISTORY_URL;
    const slug = process.env.GITHUB_REPOSITORY;
    if (!slug) return null;
    const [owner, repo] = slug.split('/');
    if (!owner || !repo) return null;
    return `https://${owner}.github.io/${repo}/`;
})();
if (historyUrl) {
    lines.push('');
    lines.push(`<sub>📈 [Trunk performance history](${historyUrl}) — commit-by-commit timeline.</sub>`);
}
lines.push('');

const md = lines.join('\n');
writeFileSync(outPath, md);
console.log(md);
