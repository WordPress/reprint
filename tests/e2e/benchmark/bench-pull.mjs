/**
 * Per-stage performance benchmark for the `pull` pipeline.
 *
 * Provisions the `large-directory` e2e site (2k+ files) and seeds its DB
 * with hundreds of thousands of posts/postmeta rows so the DB stages
 * dominate wall-clock — see Automattic/studio#3248 for the dataset shape.
 * Then runs each pipeline stage as an individual CLI invocation and times
 * the wall-clock.
 * Emits a markdown table to stdout, appends to $GITHUB_STEP_SUMMARY, and
 * writes a JSON artifact to bench-results.json.
 *
 * Stages timed: preflight, files-pull, db-pull, db-apply, apply-runtime.
 *
 * Note: each stage runs in a fresh PHP process to keep timings comparable
 * with how the importer is run in production (resumable, single-stage).
 * That means each measurement includes one PHP startup cost, which is
 * roughly constant across stages and across PRs — so deltas remain
 * meaningful even though absolute numbers carry that overhead.
 */
import { execFileSync, execSync } from 'node:child_process';
import { mkdirSync, writeFileSync, readFileSync, appendFileSync, existsSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { tmpdir } from 'node:os';
import { performance } from 'node:perf_hooks';
import { createConnection } from 'mysql2/promise';
import { ensureSite } from '../lib/site-setup.js';
import { HmacClient } from '../lib/hmac-client.js';
import {
    getSiteUrl, getSiteSecret, getSiteDir, fsRootDir,
} from '../lib/test-helpers.js';

const SITE = 'large-directory';
const FILE_BENCH_SITE = 'large-single-file';
const FILE_BENCH_SIZE_MB = Number(process.env.BENCH_FILE_SIZE_MB || 24);
const FILE_BENCH_SIZE = FILE_BENCH_SIZE_MB * 1024 * 1024;
const FILE_BENCH_TUNED_CHUNK_SIZE = 16 * 1024 * 1024;
const FILE_BENCH_RELATIVE_PATH = `test-data/bench-random-${FILE_BENCH_SIZE_MB}mb.bin`;
const IMPORT_DB = 'e2e_bench_pull';
// Seed enough posts/postmeta to make db-pull and db-apply dominate wall-clock
// (the default WP install has ~1 post). Mirrors the dataset shape used in
// Automattic/studio#3248: ~320k posts and ~720k postmeta rows.
const SEED_POSTS = Number(process.env.BENCH_SEED_POSTS || 320_007);
const SEED_POSTMETA = Number(process.env.BENCH_SEED_POSTMETA || 720_015);
const PHP_BINARY = process.env.PHP_BINARY || 'php';
const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const IMPORTER_PATH = process.env.IMPORTER_PATH || join(PROJECT_ROOT, 'packages', 'reprint-client', 'bin', 'reprint-client');
const PREFLIGHT_IMPORTER_PATH = process.env.BENCH_PREFLIGHT_IMPORTER_PATH || IMPORTER_PATH;
const PLAYGROUND_PHP_BINARY = process.env.BENCH_PLAYGROUND_PHP_BINARY || join(PROJECT_ROOT, 'tests', 'e2e', 'ci', 'playground-php.sh');
const PLAYGROUND_PHP_VERSION = process.env.PLAYGROUND_PHP_VERSION || '8.3';
const REGISTRY = JSON.parse(readFileSync(join(import.meta.dirname, '..', 'site-registry.json'), 'utf-8'));
const MYSQL_PARSER_MANIFEST = process.env.WP_MYSQL_PARSER_EXTENSION_MANIFEST || '';

async function seedSourceDb() {
    const dbName = `e2e_${SITE.replace(/-/g, '_')}`;
    const conn = await createConnection({
        host: REGISTRY.dbHost,
        user: REGISTRY.dbUser,
        password: REGISTRY.dbPass,
        database: dbName,
        multipleStatements: true,
    });
    try {
        const [rows] = await conn.query('SELECT COUNT(*) AS c FROM wp_posts');
        if (rows[0].c >= SEED_POSTS) {
            console.log(`Source DB already seeded with ${rows[0].c} posts; skipping seed`);
            return;
        }

        console.log(`Seeding source DB: ${SEED_POSTS} posts, ${SEED_POSTMETA} postmeta...`);
        // Speed up bulk inserts: skip per-row durability, defer index updates.
        // Drop STRICT mode so TEXT columns without defaults (to_ping,
        // pinged, post_content_filtered, etc.) accept implicit ''.
        await conn.query("SET SESSION sql_mode='';");
        await conn.query('SET autocommit=0; SET unique_checks=0; SET foreign_key_checks=0;');
        await conn.query('ALTER TABLE wp_posts DISABLE KEYS; ALTER TABLE wp_postmeta DISABLE KEYS;');

        const BATCH = 2000;
        const now = '2024-01-01 00:00:00';
        for (let start = 1; start <= SEED_POSTS; start += BATCH) {
            const end = Math.min(start + BATCH - 1, SEED_POSTS);
            const values = [];
            const params = [];
            for (let i = start; i <= end; i++) {
                values.push('(?,?,?,?,?,?,?,?,?,?,?,?)');
                params.push(
                    1, now, now,
                    `Bench post body ${i} — lorem ipsum dolor sit amet, consectetur adipiscing elit. http://localhost:9999/post/${i}`,
                    `Bench post ${i}`,
                    '', 'publish', 'open', 'open', `bench-post-${i}`,
                    'post', 0,
                );
            }
            await conn.query(
                `INSERT INTO wp_posts
                    (post_author, post_date, post_date_gmt, post_content, post_title,
                     post_excerpt, post_status, comment_status, ping_status, post_name,
                     post_type, comment_count)
                 VALUES ${values.join(',')}`,
                params,
            );
        }

        for (let start = 1; start <= SEED_POSTMETA; start += BATCH) {
            const end = Math.min(start + BATCH - 1, SEED_POSTMETA);
            const values = [];
            const params = [];
            for (let i = start; i <= end; i++) {
                const postId = ((i - 1) % SEED_POSTS) + 1;
                values.push('(?,?,?)');
                params.push(postId, `bench_meta_${i % 3}`, `value-${i}`);
            }
            await conn.query(
                `INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ${values.join(',')}`,
                params,
            );
        }

        await conn.query('COMMIT');
        await conn.query('ALTER TABLE wp_posts ENABLE KEYS; ALTER TABLE wp_postmeta ENABLE KEYS;');
        await conn.query('SET autocommit=1; SET unique_checks=1; SET foreign_key_checks=1;');
        console.log('Seed complete');
    } finally {
        await conn.end();
    }
}

async function provisionDatabase() {
    const conn = await createConnection({
        host: REGISTRY.dbHost,
        user: REGISTRY.dbUser,
        password: REGISTRY.dbPass,
        multipleStatements: true,
    });
    await conn.query(`DROP DATABASE IF EXISTS \`${IMPORT_DB}\``);
    await conn.query(`CREATE DATABASE \`${IMPORT_DB}\``);
    await conn.end();
}

function runStage(stage, stateDir, extraArgs = [], { phpBinary = PHP_BINARY, env = {} } = {}) {
    const url = `${getSiteUrl(SITE)}&directory=${getSiteDir(SITE)}`;
    const importerPath = stage === 'preflight' ? PREFLIGHT_IMPORTER_PATH : IMPORTER_PATH;
    const args = [
        importerPath,
        stage,
        url,
        `--state-dir=${stateDir}`,
        `--fs-root=${fsRootDir(stateDir)}`,
        `--secret=${getSiteSecret(SITE)}`,
        ...extraArgs,
    ];

    const start = performance.now();
    let attempts = 0;

    // Stages other than preflight may exit 2 to request resumption. Loop
    // until the command runs to completion (exit 0) or fatally fails.
    while (true) {
        attempts += 1;
        try {
            execFileSync(phpBinary, args, {
                timeout: 900_000,
                encoding: 'utf-8',
                maxBuffer: 64 * 1024 * 1024,
                stdio: ['ignore', 'pipe', 'pipe'],
                env: { ...process.env, ...env },
            });
            const elapsedMs = performance.now() - start;
            return { stage, elapsedMs, attempts, ok: true };
        } catch (e) {
            const exitCode = e.status === null ? -1 : (e.status || 1);
            if (exitCode === 2 && attempts < 50) {
                continue;
            }
            const elapsedMs = performance.now() - start;
            return {
                stage, elapsedMs, attempts, ok: false, exitCode,
                stderr: (e.stderr || '').toString().slice(-2000),
                stdout: (e.stdout || '').toString().slice(-2000),
            };
        }
    }
}

function requireBenchStageOk(result, context) {
    if (result.ok) {
        return;
    }

    throw new Error(
        `${context} failed with exit ${result.exitCode}\n` +
        `stderr:\n${result.stderr || ''}\nstdout:\n${result.stdout || ''}`,
    );
}

function playgroundPhpEnv() {
    return {
        PLAYGROUND_PHP_USE_WASM_RUNNER: '1',
        PLAYGROUND_PHP_VERSION,
    };
}

function runNativeMysqlParserProof({ requireParser }) {
    if (!MYSQL_PARSER_MANIFEST) {
        return {
            ok: true,
            details: {
                wp_mysql_parser: 'disabled',
                native_lexer: 'not requested',
                native_parser: 'not requested',
            },
        };
    }

    const mode = requireParser ? 'parser' : 'lexer';
    const verifierPath = join(PROJECT_ROOT, 'tests', 'e2e', 'ci', 'verify-wp-mysql-parser.php');

    try {
        const stdout = execFileSync(PLAYGROUND_PHP_BINARY, [verifierPath, PROJECT_ROOT, mode], {
            cwd: PROJECT_ROOT,
            timeout: 120_000,
            encoding: 'utf-8',
            maxBuffer: 16 * 1024 * 1024,
            stdio: ['ignore', 'pipe', 'pipe'],
            env: { ...process.env, ...playgroundPhpEnv() },
        }).trim();
        return {
            ok: true,
            details: stdout ? JSON.parse(stdout) : {},
        };
    } catch (e) {
        return {
            ok: false,
            exitCode: e.status === null ? -1 : (e.status || 1),
            stderr: (e.stderr || '').toString().slice(-4000),
            stdout: (e.stdout || '').toString().slice(-4000),
        };
    }
}

function proofFailureResult(stage, start, proof, details) {
    return {
        stage,
        elapsedMs: performance.now() - start,
        attempts: 0,
        ok: false,
        exitCode: proof.exitCode || 1,
        stderr: proof.stderr || '',
        stdout: proof.stdout || '',
        details: {
            ...details,
            wp_mysql_parser: MYSQL_PARSER_MANIFEST ? 'enabled' : 'disabled',
            native_parser_proof: 'failed',
        },
    };
}

function runPlaygroundSqliteDbPullBenchmark() {
    const start = performance.now();
    const stateDir = join(tmpdir(), `bench-playground-sqlite-db-pull-${Date.now()}`);
    mkdirSync(stateDir, { recursive: true });
    mkdirSync(fsRootDir(stateDir), { recursive: true });

    requireBenchStageOk(
        runStage('preflight', stateDir),
        'playground sqlite benchmark preflight',
    );
    const proof = runNativeMysqlParserProof({ requireParser: false });
    const baseDetails = {
        condition: 'db-pull in PHP.wasm',
        runtime: `php.wasm ${PLAYGROUND_PHP_VERSION}`,
        wp_mysql_parser: MYSQL_PARSER_MANIFEST ? 'enabled' : 'disabled',
    };
    if (!proof.ok) {
        return proofFailureResult('playground-sqlite-db-pull', start, proof, baseDetails);
    }

    const result = runStage('db-pull', stateDir, [], {
        phpBinary: PLAYGROUND_PHP_BINARY,
        env: playgroundPhpEnv(),
    });

    return {
        ...result,
        stage: 'playground-sqlite-db-pull',
        details: {
            ...baseDetails,
            ...proof.details,
        },
    };
}

function runPlaygroundSqliteDbApplyBenchmark() {
    const start = performance.now();
    const stateDir = join(tmpdir(), `bench-playground-sqlite-db-apply-${Date.now()}`);
    mkdirSync(stateDir, { recursive: true });
    mkdirSync(fsRootDir(stateDir), { recursive: true });

    requireBenchStageOk(
        runStage('preflight', stateDir),
        'playground sqlite benchmark preflight',
    );
    requireBenchStageOk(
        runStage('db-pull', stateDir),
        'playground sqlite benchmark db-pull',
    );
    const proof = runNativeMysqlParserProof({ requireParser: true });
    const baseDetails = {
        condition: 'db-apply to SQLite in PHP.wasm',
        runtime: `php.wasm ${PLAYGROUND_PHP_VERSION}`,
        wp_mysql_parser: MYSQL_PARSER_MANIFEST ? 'enabled' : 'disabled',
    };
    if (!proof.ok) {
        return proofFailureResult('playground-sqlite-db-apply', start, proof, baseDetails);
    }

    const sqlitePath = join(
        fsRootDir(stateDir),
        getSiteDir(SITE),
        'wp-content',
        'database',
        'bench-playground.sqlite',
    );
    const result = runStage('db-apply', stateDir, [
        '--target-engine=sqlite',
        `--target-sqlite-path=${sqlitePath}`,
        '--target-db=playground_sqlite_bench',
        '--new-site-url=http://localhost:9999',
    ], {
        phpBinary: PLAYGROUND_PHP_BINARY,
        env: playgroundPhpEnv(),
    });

    return {
        ...result,
        stage: 'playground-sqlite-db-apply',
        details: {
            ...baseDetails,
            ...proof.details,
        },
    };
}

function fmtMs(ms) {
    if (ms < 1000) return `${ms.toFixed(0)} ms`;
    return `${(ms / 1000).toFixed(2)} s`;
}

function fmtBytes(bytes) {
    if (!Number.isFinite(bytes)) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KiB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MiB`;
}

function fmtDetails(details) {
    if (!details || typeof details !== 'object') return '';
    return Object.entries(details)
        .filter(([, value]) => value !== null && value !== undefined && value !== '')
        .map(([key, value]) => `${key}=${String(value).replaceAll('|', '/')}`)
        .join('<br>');
}

function summarizeMultipart(body, contentType) {
    const match = contentType.match(/boundary="?([^";\s]+)"?/);
    if (!match) {
        return { multipart_parts: 0, file_parts: 0 };
    }
    const delimiter = `--${match[1]}`;
    const parts = body.toString('binary')
        .split(delimiter)
        .filter((part) => part.includes('\r\n\r\n'));

    return {
        multipart_parts: parts.length,
        file_parts: parts.filter((part) => /x-chunk-type:\s*file/i.test(part)).length,
    };
}

async function ensureFileBenchSite() {
    await ensureSite(FILE_BENCH_SITE, { files: 'none' });

    const siteDir = getSiteDir(FILE_BENCH_SITE);
    const filePath = join(siteDir, FILE_BENCH_RELATIVE_PATH);
    const needsFile = !existsSync(filePath) || statSync(filePath).size !== FILE_BENCH_SIZE;
    if (needsFile) {
        console.log(`Creating ${fmtBytes(FILE_BENCH_SIZE)} random file for file-transfer benchmark...`);
        execSync(`sudo mkdir -p ${JSON.stringify(dirname(filePath))}`);
        execSync(`sudo rm -f ${JSON.stringify(filePath)}`);
        execSync(`sudo dd if=/dev/urandom of=${JSON.stringify(filePath)} bs=1M count=${FILE_BENCH_SIZE_MB} status=none`);
        execSync(`sudo chown nginx:nginx ${JSON.stringify(filePath)}`);
    }

    return { site: FILE_BENCH_SITE, siteDir, filePath };
}

async function runFileFetchScenario({ stage, site, filePath, params = {}, details = {} }) {
    const fileListJson = JSON.stringify([{
        path: Buffer.from(filePath).toString('base64'),
    }]);
    const formData = new FormData();
    formData.append(
        'file_list',
        new Blob([fileListJson], { type: 'application/json' }),
        'file_list.json',
    );

    const url = new URL(getSiteUrl(site));
    url.searchParams.set('endpoint', 'file_fetch');
    url.searchParams.set('directory', getSiteDir(site));
    for (const [key, value] of Object.entries(params)) {
        url.searchParams.set(key, String(value));
    }

    const client = new HmacClient(getSiteSecret(site));
    const headers = client.getAuthHeaders(fileListJson);

    const start = performance.now();
    const response = await fetch(url.toString(), {
        method: 'POST',
        headers,
        body: formData,
    });
    const body = Buffer.from(await response.arrayBuffer());
    const elapsedMs = performance.now() - start;
    const contentType = response.headers.get('content-type') || '';
    const ok = response.ok && contentType.includes('multipart/mixed');
    const multipart = summarizeMultipart(body, contentType);

    return {
        stage,
        elapsedMs,
        attempts: 1,
        ok,
        exitCode: ok ? null : response.status,
        details: {
            ...details,
            file: fmtBytes(FILE_BENCH_SIZE),
            chunk_size: params.chunk_size ? fmtBytes(Number(params.chunk_size)) : 'omitted',
            encoding: response.headers.get('content-encoding') || 'identity',
            response: fmtBytes(body.length),
            file_parts: multipart.file_parts,
            multipart_parts: multipart.multipart_parts,
        },
    };
}

function runFilePullWithPeakMemory({ site, stateDir, filePath }) {
    const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    const peakProbe = join(tmpdir(), `reprint-bench-peak-${process.pid}-${Date.now()}.php`);
    const peakFile = join(tmpdir(), `reprint-bench-peak-${process.pid}-${Date.now()}.txt`);
    writeFileSync(peakProbe, `<?php
register_shutdown_function(function () {
    $path = getenv('REPRINT_BENCH_PEAK_FILE');
    if ($path) {
        file_put_contents($path, (string) memory_get_peak_usage(true));
    }
});
`);

    const baseArgs = [
        IMPORTER_PATH,
        'files-pull',
        url,
        `--state-dir=${stateDir}`,
        `--fs-root=${fsRootDir(stateDir)}`,
        `--secret=${getSiteSecret(site)}`,
        `--file-chunk-start=${FILE_BENCH_TUNED_CHUNK_SIZE}`,
        `--file-chunk-max=${FILE_BENCH_TUNED_CHUNK_SIZE}`,
        '--duty=1',
        '--max-exec=60',
    ];
    const args = [
        '-d',
        `auto_prepend_file=${peakProbe}`,
        ...baseArgs,
    ];

    const start = performance.now();
    let attempts = 0;
    let peakMemory = 0;
    let lastErr = null;

    while (true) {
        attempts += 1;
        try {
            execFileSync(PHP_BINARY, args, {
                timeout: 900_000,
                encoding: 'utf-8',
                maxBuffer: 64 * 1024 * 1024,
                stdio: ['ignore', 'pipe', 'pipe'],
                env: { ...process.env, REPRINT_BENCH_PEAK_FILE: peakFile },
            });
            if (existsSync(peakFile)) {
                peakMemory = Math.max(peakMemory, Number(readFileSync(peakFile, 'utf-8')) || 0);
            }
            return {
                stage: 'files-pull-large-part-peak-memory',
                elapsedMs: performance.now() - start,
                attempts,
                ok: true,
                details: {
                    condition: 'files-pull large multipart file part',
                    file: fmtBytes(statSync(filePath).size),
                    chunk_size: fmtBytes(FILE_BENCH_TUNED_CHUNK_SIZE),
                    peak_memory: fmtBytes(peakMemory),
                },
            };
        } catch (e) {
            lastErr = e;
            if (existsSync(peakFile)) {
                peakMemory = Math.max(peakMemory, Number(readFileSync(peakFile, 'utf-8')) || 0);
            }
            const exitCode = e.status === null ? -1 : (e.status || 1);
            if (exitCode === 2 && attempts < 50) {
                continue;
            }
            return {
                stage: 'files-pull-large-part-peak-memory',
                elapsedMs: performance.now() - start,
                attempts,
                ok: false,
                exitCode,
                stderr: (lastErr.stderr || '').toString().slice(-2000),
                stdout: (lastErr.stdout || '').toString().slice(-2000),
                details: {
                    condition: 'files-pull large multipart file part',
                    file: fmtBytes(statSync(filePath).size),
                    chunk_size: fmtBytes(FILE_BENCH_TUNED_CHUNK_SIZE),
                    peak_memory: fmtBytes(peakMemory),
                },
            };
        }
    }
}

function runPreflightForSite(site, stateDir) {
    const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    const args = [
        IMPORTER_PATH,
        'preflight',
        url,
        `--state-dir=${stateDir}`,
        `--fs-root=${fsRootDir(stateDir)}`,
        `--secret=${getSiteSecret(site)}`,
    ];
    let lastErr = null;
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            execFileSync(PHP_BINARY, args, {
                timeout: 120_000,
                encoding: 'utf-8',
                maxBuffer: 16 * 1024 * 1024,
                stdio: ['ignore', 'pipe', 'pipe'],
            });
            return;
        } catch (e) {
            lastErr = e;
            const output = `${e.stdout || ''}\n${e.stderr || ''}`;
            if (!/Operation timed out|Could not connect|Connection refused|Empty reply/i.test(output) || attempt === 3) {
                throw e;
            }
            console.log(`   preflight retry ${attempt}/3 after transient failure`);
        }
    }
    throw lastErr;
}

function renderMarkdown(results, meta) {
    const total = results.reduce((s, r) => s + r.elapsedMs, 0);
    const lines = [];
    lines.push(`## Pull pipeline performance — \`${SITE}\``);
    lines.push('');
    lines.push(`Site: \`${SITE}\` · ${meta.fileCount} files · ${meta.seedPosts.toLocaleString('en-US')} posts · ${meta.seedPostmeta.toLocaleString('en-US')} postmeta · PHP \`${meta.phpVersion}\``);
    lines.push('');
    lines.push('| Stage | Wall time | Resume attempts | Status | Details |');
    lines.push('|---|---:|---:|---|---|');
    for (const r of results) {
        lines.push(`| \`${r.stage}\` | ${fmtMs(r.elapsedMs)} | ${r.attempts} | ${r.ok ? '✓' : '✗ exit ' + r.exitCode} | ${fmtDetails(r.details)} |`);
    }
    lines.push(`| **Total** | **${fmtMs(total)}** | | | |`);
    lines.push('');
    return lines.join('\n');
}

async function main() {
    console.log(`Provisioning site: ${SITE}`);
    await ensureSite(SITE, {
        files: 'none',
        afterCreate: async (siteDir) => {
            const manyDir = join(siteDir, 'test-data', 'many-files');
            mkdirSync(manyDir, { recursive: true });
            for (let i = 1; i <= 2000; i++) {
                const num = String(i).padStart(4, '0');
                writeFileSync(join(manyDir, `file-${num}.txt`), `content-${num}`);
            }
        },
    });

    await seedSourceDb();
    await provisionDatabase();

    const stateDir = join(tmpdir(), `bench-pull-${Date.now()}`);
    mkdirSync(stateDir, { recursive: true });
    mkdirSync(fsRootDir(stateDir), { recursive: true });

    const dbApplyArgs = [
        `--target-user=${REGISTRY.dbUser}`,
        `--target-pass=${REGISTRY.dbPass}`,
        `--target-db=${IMPORT_DB}`,
        `--new-site-url=http://localhost:9999`,
    ];
    const runtimeOutDir = join(stateDir, 'runtime-out');
    mkdirSync(runtimeOutDir, { recursive: true });
    const runtimeArgs = [
        '--runtime=php-builtin',
        `--output-dir=${runtimeOutDir}`,
    ];

    // Optional stage filter — when BENCH_STAGES is set (comma-separated
    // list of stage names), only those stages run on both sides of the
    // PR-vs-trunk comparison. This is how each PR limits its perf comment
    // to the single scenario it actually changes.
    const stageFilter = (process.env.BENCH_STAGES || '')
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);
    const shouldRun = (name) => stageFilter.length === 0 || stageFilter.includes(name);

    const stages = [
        { name: 'preflight', extra: [] },
        { name: 'files-pull', extra: [] },
        { name: 'db-pull', extra: [] },
        { name: 'db-apply', extra: dbApplyArgs },
        { name: 'apply-runtime', extra: runtimeArgs },
    ];

    const results = [];
    for (const { name, extra } of stages) {
        if (!shouldRun(name)) continue;
        console.log(`-> ${name}`);
        const r = runStage(name, stateDir, extra);
        results.push(r);
        console.log(`   ${r.ok ? 'ok' : 'FAIL'} in ${fmtMs(r.elapsedMs)} (attempts=${r.attempts})`);
        if (!r.ok) {
            console.error(`   stderr (tail):\n${r.stderr}`);
            console.error(`   stdout (tail):\n${r.stdout}`);
        }
    }

    if (shouldRun('playground-sqlite-db-pull')) {
        console.log('-> playground-sqlite-db-pull');
        const playgroundSqlitePull = runPlaygroundSqliteDbPullBenchmark();
        results.push(playgroundSqlitePull);
        console.log(`   ${playgroundSqlitePull.ok ? 'ok' : 'FAIL'} in ${fmtMs(playgroundSqlitePull.elapsedMs)} (${fmtDetails(playgroundSqlitePull.details)})`);
        if (!playgroundSqlitePull.ok) {
            console.error(`   stderr (tail):\n${playgroundSqlitePull.stderr}`);
            console.error(`   stdout (tail):\n${playgroundSqlitePull.stdout}`);
        }
    }

    if (shouldRun('playground-sqlite-db-apply')) {
        console.log('-> playground-sqlite-db-apply');
        const playgroundSqliteApply = runPlaygroundSqliteDbApplyBenchmark();
        results.push(playgroundSqliteApply);
        console.log(`   ${playgroundSqliteApply.ok ? 'ok' : 'FAIL'} in ${fmtMs(playgroundSqliteApply.elapsedMs)} (${fmtDetails(playgroundSqliteApply.details)})`);
        if (!playgroundSqliteApply.ok) {
            console.error(`   stderr (tail):\n${playgroundSqliteApply.stderr}`);
            console.error(`   stdout (tail):\n${playgroundSqliteApply.stdout}`);
        }
    }

    const fileFetchScenarios = ['file-fetch-untuned-random', 'file-fetch-binary-compression'];
    let fileBench = null;
    if (fileFetchScenarios.some(shouldRun)) {
        console.log(`Provisioning site: ${FILE_BENCH_SITE}`);
        fileBench = await ensureFileBenchSite();
    }

    if (shouldRun('file-fetch-untuned-random')) {
        console.log('-> file-fetch-untuned-random');
        const untunedFetch = await runFileFetchScenario({
            stage: 'file-fetch-untuned-random',
            site: fileBench.site,
            filePath: fileBench.filePath,
            details: {
                condition: 'file_fetch without chunk_size',
            },
        });
        results.push(untunedFetch);
        console.log(`   ${untunedFetch.ok ? 'ok' : 'FAIL'} in ${fmtMs(untunedFetch.elapsedMs)} (${fmtDetails(untunedFetch.details)})`);
    }

    if (shouldRun('file-fetch-binary-compression')) {
        console.log('-> file-fetch-binary-compression');
        const binaryCompressionFetch = await runFileFetchScenario({
            stage: 'file-fetch-binary-compression',
            site: fileBench.site,
            filePath: fileBench.filePath,
            params: {
                chunk_size: FILE_BENCH_TUNED_CHUNK_SIZE,
            },
            details: {
                condition: 'random binary file_fetch with tuned chunk_size',
            },
        });
        results.push(binaryCompressionFetch);
        console.log(`   ${binaryCompressionFetch.ok ? 'ok' : 'FAIL'} in ${fmtMs(binaryCompressionFetch.elapsedMs)} (${fmtDetails(binaryCompressionFetch.details)})`);
    }

    if (shouldRun('files-pull-large-part-peak-memory')) {
        if (!fileBench) {
            console.log(`Provisioning site: ${FILE_BENCH_SITE}`);
            fileBench = await ensureFileBenchSite();
        }
        const filePullStateDir = join(tmpdir(), `bench-file-pull-${Date.now()}`);
        mkdirSync(filePullStateDir, { recursive: true });
        mkdirSync(fsRootDir(filePullStateDir), { recursive: true });
        runPreflightForSite(fileBench.site, filePullStateDir);
        console.log('-> files-pull-large-part-peak-memory');
        const largePartPull = runFilePullWithPeakMemory({
            site: fileBench.site,
            stateDir: filePullStateDir,
            filePath: fileBench.filePath,
        });
        results.push(largePartPull);
        console.log(`   ${largePartPull.ok ? 'ok' : 'FAIL'} in ${fmtMs(largePartPull.elapsedMs)} (${fmtDetails(largePartPull.details)})`);
    }

    const phpVersion = execFileSync(PHP_BINARY, ['-r', 'echo PHP_VERSION;'], { encoding: 'utf-8' }).trim();
    const meta = {
        site: SITE,
        fileCount: '2,000+ plus targeted file-transfer scenarios',
        seedPosts: SEED_POSTS,
        seedPostmeta: SEED_POSTMETA,
        phpVersion,
        importer: IMPORTER_PATH,
        preflightImporter: PREFLIGHT_IMPORTER_PATH,
    };
    const md = renderMarkdown(results, meta);
    console.log('\n' + md);

    const label = process.env.BENCH_LABEL || 'pr';
    const jsonOut = process.env.BENCH_JSON_OUT || 'bench-results.json';
    const mdOut = process.env.BENCH_MD_OUT || 'bench-results.md';
    writeFileSync(jsonOut, JSON.stringify({ meta: { ...meta, label }, results }, null, 2));
    writeFileSync(mdOut, md);

    if (process.env.GITHUB_STEP_SUMMARY) {
        appendFileSync(process.env.GITHUB_STEP_SUMMARY, md + '\n');
    }

    if (results.some((r) => !r.ok)) {
        process.exit(1);
    }
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});
