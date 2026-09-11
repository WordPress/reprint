/** Run the same URL corpus against the selected build, independently of stage filters. */
import { execFileSync } from 'node:child_process';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

/** Each case gets a new PHP process so its peak memory does not include other cases. */
export function runUrlRewriteBenchmarks(build, phpBinary = process.env.PHP_BINARY || 'php') {
    const script = resolve(dirname(fileURLToPath(import.meta.url)), 'bench-url-rewrite.php');
    const urlRewriteCases = JSON.parse(execFileSync(phpBinary, [script, '--list'], { encoding: 'utf8' }));
    return urlRewriteCases.map(scenario => {
        try {
            const output = execFileSync(phpBinary, ['-d', 'memory_limit=256M', script, resolve(build), scenario], {
                encoding: 'utf8', timeout: 120_000, maxBuffer: 1024 * 1024,
            });
            return JSON.parse(output);
        } catch (error) {
            // A broken result is never a speed improvement. Keep the other cases
            // in the report, but let the caller fail the benchmark job.
            return {
                stage: 'url-rewrite-' + scenario, elapsedMs: 0, attempts: 1,
                ok: false, exitCode: error.status ?? 1,
                stderr: String(error.stderr || error.message).slice(-2000),
            };
        }
    });
}

// This entry point needs no WordPress, database or network setup.
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const results = runUrlRewriteBenchmarks(process.argv[2] || resolve(dirname(fileURLToPath(import.meta.url)), '../../..'));
    const meta = { site: 'URL corpus', fileCount: 0, phpVersion: results.find(result => result.ok)?.phpVersion || 'unknown' };
    console.log(JSON.stringify({ meta, results }, null, 2));
    if (results.some(result => !result.ok)) process.exitCode = 1;
}
