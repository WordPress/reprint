/**
 * Render the native Windows client's report as one collapsed table. Each stage
 * shows the PR and trunk times on Windows, and the Linux client's time for the
 * same PR build. Both clients ran on the same Windows runner against the same
 * source in WSL, so the last column shows what native Windows costs per stage.
 *
 * Reads WINDOWS_JSON, WINDOWS_TRUNK_JSON and LINUX_JSON, writes OUT_MD. Only
 * WINDOWS_JSON is required.
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const windowsPath = process.env.WINDOWS_JSON || 'bench-windows-pr.json';
const windowsTrunkPath = process.env.WINDOWS_TRUNK_JSON || 'bench-windows-trunk.json';
const linuxPath = process.env.LINUX_JSON || 'bench-wsl-linux-pr.json';
const outPath = process.env.OUT_MD || 'bench-windows.md';

function fmtMs(ms) {
    if (ms < 1000) return `${ms.toFixed(0)} ms`;
    return `${(ms / 1000).toFixed(2)} s`;
}

/** A failed stage has no time worth comparing. */
function fmtResult(result) {
    if (!result) return '—';
    return result.ok ? fmtMs(result.elapsedMs) : `✗ exit ${result.exitCode}`;
}

function comparable(result, baseline) {
    return Boolean(result?.ok && baseline?.ok && baseline.elapsedMs > 0);
}

/** Same markers as render-diff.mjs: 🟢 more than 5% faster, 🔴 more than 5% slower. */
function fmtPercent(prMs, baseMs) {
    const percent = (prMs - baseMs) / baseMs * 100;
    const marker = Math.abs(percent) <= 5 ? '⚪' : percent < 0 ? '🟢' : '🔴';
    return `${marker} ${percent >= 0 ? '+' : ''}${percent.toFixed(1)}%`;
}

if (!existsSync(windowsPath)) {
    console.error(`Missing Windows results: ${windowsPath}`);
    process.exit(1);
}
const readResults = path => existsSync(path) ? JSON.parse(readFileSync(path, 'utf-8')).results : [];
const windows = readResults(windowsPath);
const trunkByStage = Object.fromEntries(readResults(windowsTrunkPath).map(result => [result.stage, result]));
const linuxByStage = Object.fromEntries(readResults(linuxPath).map(result => [result.stage, result]));

const rows = [];
let attentionCount = 0;
let prTotal = 0;
let trunkTotal = 0;
for (const result of windows) {
    const trunk = trunkByStage[result.stage];
    const linux = linuxByStage[result.stage];
    prTotal += result.elapsedMs;
    trunkTotal += trunk?.elapsedMs ?? 0;
    const change = comparable(result, trunk) ? fmtPercent(result.elapsedMs, trunk.elapsedMs) : '—';
    if (!change.startsWith('⚪')) attentionCount++;
    const linuxCell = comparable(result, linux)
        ? `${fmtMs(linux.elapsedMs)} (${(result.elapsedMs / linux.elapsedMs).toFixed(1)}×)`
        : fmtResult(linux);
    rows.push(`| \`${result.stage}\` | ${fmtResult(result)} | ${fmtResult(trunk)} | ${change} | ${linuxCell} |`);
}

const allComparable = windows.every(result => comparable(result, trunkByStage[result.stage]));
const total = allComparable
    ? `PR ${fmtMs(prTotal)} · trunk ${fmtMs(trunkTotal)} · ${fmtPercent(prTotal, trunkTotal)}`
    : `PR ${fmtMs(prTotal)}`;
const attention = attentionCount === 0 ? 'no stage changed more than 5%'
    : `${attentionCount} ${attentionCount === 1 ? 'stage' : 'stages'} changed more than 5% or could not be compared`;

// Blank lines let GitHub parse the Markdown table inside the HTML disclosure.
const md = [
    '<details>',
    `<summary><b>Native Windows client</b>: ${total} · ${attention}</summary>`,
    '',
    '| Stage | PR | trunk | Δ | Linux client (Windows ÷ Linux) |',
    '|---|---:|---:|---:|---:|',
    ...rows,
    '',
    'Both clients pulled from the same source in WSL on one Windows runner. The Linux client ran this PR\'s build. Windows PHP reaches the database through WSL\'s localhost relay, which adds time to `db-apply`.',
    '',
    '</details>',
    '',
].join('\n');
writeFileSync(outPath, md);
console.log(md);
