/**
 * Show one build's stage times for the Windows and Linux clients side by side,
 * collapsed. Both clients ran on the same Windows runner against the same
 * source in WSL, so the ratio shows what native Windows costs per stage.
 *
 * Reads WINDOWS_JSON and LINUX_JSON, writes OUT_MD.
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const windowsPath = process.env.WINDOWS_JSON || 'bench-windows-pr.json';
const linuxPath = process.env.LINUX_JSON || 'bench-wsl-linux-pr.json';
const outPath = process.env.OUT_MD || 'bench-platforms.md';

function fmtMs(ms) {
    if (ms < 1000) return `${ms.toFixed(0)} ms`;
    return `${(ms / 1000).toFixed(2)} s`;
}

/** A failed stage has no time worth comparing. */
function fmtResult(result) {
    if (!result) return '—';
    return result.ok ? fmtMs(result.elapsedMs) : `✗ exit ${result.exitCode}`;
}

if (!existsSync(windowsPath) || !existsSync(linuxPath)) {
    console.error(`Missing results: ${existsSync(windowsPath) ? linuxPath : windowsPath}`);
    process.exit(1);
}

const windows = JSON.parse(readFileSync(windowsPath, 'utf-8'));
const linux = JSON.parse(readFileSync(linuxPath, 'utf-8'));
const linuxByStage = Object.fromEntries(linux.results.map((result) => [result.stage, result]));

// Blank lines let GitHub parse the Markdown table inside the HTML disclosure.
const lines = [
    '<details>',
    '<summary>Windows and Linux clients on the same runner</summary>',
    '',
    'Both clients pulled this PR\'s build from the same source in WSL. Windows PHP reaches the database through WSL\'s localhost relay, which adds time to `db-apply`.',
    '',
    '| Stage | Windows | Linux | Windows ÷ Linux |',
    '|---|---:|---:|---:|',
];
for (const windowsResult of windows.results) {
    const linuxResult = linuxByStage[windowsResult.stage];
    const comparable = windowsResult.ok && linuxResult?.ok && linuxResult.elapsedMs > 0;
    const ratio = comparable ? `${(windowsResult.elapsedMs / linuxResult.elapsedMs).toFixed(1)}×` : '—';
    lines.push(`| \`${windowsResult.stage}\` | ${fmtResult(windowsResult)} | ${fmtResult(linuxResult)} | ${ratio} |`);
}
lines.push('', '</details>', '');

const md = lines.join('\n');
writeFileSync(outPath, md);
console.log(md);
