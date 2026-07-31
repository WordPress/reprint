/**
 * E2E test helpers for Reprint.
 */
import assert from 'node:assert/strict';
import { execSync, execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync, existsSync, mkdirSync, lstatSync } from 'node:fs';
import { join, relative } from 'node:path';
import { tmpdir } from 'node:os';
import { createConnection } from 'mysql2/promise';
import { HmacClient } from './hmac-client.js';
import { gunzipSync } from 'node:zlib';
import { createRequire } from 'node:module';

const REGISTRY = createRequire(import.meta.url)('../site-registry.json');

const SITE_ROOT = REGISTRY.siteRoot;
const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const IMPORTER_PATH = process.env.IMPORTER_PATH || join(PROJECT_ROOT, 'importer', 'import.php');
const PHP_BINARY = process.env.PHP_BINARY || 'php';
const DB_HOST = REGISTRY.dbHost;
const DB_USER = REGISTRY.dbUser;
const DB_PASS = REGISTRY.dbPass;

/**
 * Get the base URL for a test site.
 */
export function getSiteUrl(siteName, port = null) {
    const p = port || REGISTRY.sites[siteName]?.port;
    if (!p) throw new Error(`Unknown site: ${siteName}`);
    return `http://127.0.0.1:${p}/?reprint-api`;
}

/**
 * Get the HMAC secret for a test site.
 */
export function getSiteSecret(siteName) {
    return `test-secret-${siteName}`;
}

/**
 * Get site directory path.
 */
export function getSiteDir(siteName) {
    return join(SITE_ROOT, siteName);
}

/**
 * Create HMAC client for a site.
 */
export function createHmacClient(siteName) {
    return new HmacClient(getSiteSecret(siteName));
}

/**
 * Make an authenticated HTTP request to the export API.
 * @param {string} siteName - Site name
 * @param {string} endpoint - API endpoint
 * @param {Object} params - Query parameters
 * @param {Object} options - Additional options (method, body, rawResponse, followRedirects)
 * @returns {Promise<Object>} Parsed response or raw response
 */
export async function apiRequest(siteName, endpoint, params = {}, options = {}) {
    const client = createHmacClient(siteName);
    const url = new URL(options.url || getSiteUrl(siteName));
    url.searchParams.set('endpoint', endpoint);
    for (const [k, v] of Object.entries(params)) {
        url.searchParams.set(k, String(v));
    }

    const body = options.body || '';
    const method = options.method || 'GET';
    const headers = client.getAuthHeaders(body);
    headers['Accept-Encoding'] = 'gzip';

    const fetchOptions = {
        method,
        headers,
        redirect: options.followRedirects === false ? 'manual' : 'follow',
    };
    if (body && method !== 'GET') {
        fetchOptions.body = body;
        fetchOptions.headers['Content-Type'] = options.contentType || 'application/json';
    }

    const response = await fetch(url.toString(), fetchOptions);

    if (options.rawResponse) {
        return response;
    }

    const contentType = response.headers.get('content-type') || '';

    if (contentType.includes('application/json')) {
        return {
            status: response.status,
            json: await response.json(),
            headers: Object.fromEntries(response.headers.entries()),
        };
    }

    if (contentType.includes('multipart/mixed')) {
        const arrayBuf = await response.arrayBuffer();
        let bodyBuf = Buffer.from(arrayBuf);

        // Node.js fetch auto-decompresses gzip when Content-Encoding: gzip.
        // Only manually decompress if the data is still gzipped (starts with 0x1f8b).
        if (bodyBuf.length >= 2 && bodyBuf[0] === 0x1f && bodyBuf[1] === 0x8b) {
            try {
                bodyBuf = gunzipSync(bodyBuf);
            } catch (e) {
                // Partial gzip stream - return what we can
                return {
                    status: response.status,
                    headers: Object.fromEntries(response.headers.entries()),
                    chunks: [],
                    raw: bodyBuf,
                    gzipError: e.message,
                };
            }
        }

        const boundary = extractBoundary(contentType);
        const chunks = parseMultipart(bodyBuf.toString('binary'), boundary);
        return {
            status: response.status,
            headers: Object.fromEntries(response.headers.entries()),
            chunks,
            raw: bodyBuf,
        };
    }

    // Plain text / error response
    const text = await response.text();
    return {
        status: response.status,
        text,
        headers: Object.fromEntries(response.headers.entries()),
    };
}

/**
 * Extract boundary from Content-Type header.
 */
function extractBoundary(contentType) {
    const match = contentType.match(/boundary="?([^";\s]+)"?/);
    if (!match) throw new Error(`No boundary in Content-Type: ${contentType}`);
    return match[1];
}

/**
 * Parse multipart/mixed response body into chunks.
 */
function parseMultipart(body, boundary) {
    const chunks = [];
    const delimiter = `--${boundary}`;
    const parts = body.split(delimiter);

    for (const part of parts) {
        if (!part || part.trim() === '' || part.trim() === '--') continue;
        if (part.startsWith('--')) continue; // closing boundary

        const headerEnd = part.indexOf('\r\n\r\n');
        if (headerEnd === -1) continue;

        const headerSection = part.substring(0, headerEnd);
        const bodySection = part.substring(headerEnd + 4);

        const headers = {};
        for (const line of headerSection.split('\r\n')) {
            const colonIdx = line.indexOf(':');
            if (colonIdx === -1) continue;
            const key = line.substring(0, colonIdx).trim().toLowerCase();
            const value = line.substring(colonIdx + 1).trim();
            headers[key] = value;
        }

        const chunkType = headers['x-chunk-type'] || 'unknown';
        const contentLength = parseInt(headers['content-length'] || '0', 10);

        let chunkBody = bodySection;
        if (contentLength > 0) {
            chunkBody = bodySection.substring(0, contentLength);
        }

        const chunk = {
            type: chunkType,
            headers,
            body: chunkBody,
        };

        // Parse JSON bodies
        if (headers['content-type']?.includes('application/json') && chunkBody) {
            try {
                chunk.json = JSON.parse(chunkBody);
            } catch (e) {
                chunk.jsonError = e.message;
            }
        }

        chunks.push(chunk);
    }

    return chunks;
}

/**
 * Return the fs-root path for a given output directory.
 * By convention, E2E tests place downloaded files in outputDir/fs-root.
 */
export function fsRootDir(outputDir) {
    return join(outputDir, 'fs-root');
}

/**
 * Run the importer CLI.
 * @param {string} url - Export URL
 * @param {string} outputDir - Local output directory (state files live here; fs-root is outputDir/fs-root)
 * @param {string} command - Import command (files-pull, db-pull, etc.)
 * @param {Object} options - Additional options
 * @returns {Object} { stdout, stderr, exitCode }
 */
export function runImporter(url, outputDir, command, options = {}) {
    const secret = options.secret || '';
    const maxResumeAttempts = options.maxResumeAttempts || 100;
    const runWithResume = options.autoResume !== false;

    function runImporterOnce(cmd, extraArgs = []) {
        const args = [
            IMPORTER_PATH,
            cmd,
            url,
            `--state-dir=${outputDir}`,
            `--fs-root=${fsRootDir(outputDir)}`,
        ];
        if (secret) {
            args.push(`--secret=${secret}`);
        }
        if (extraArgs.length > 0) {
            args.push(...extraArgs);
        }

        try {
            const result = execFileSync(PHP_BINARY, args, {
                // WASM PHP startup overhead is ~12s per invocation, so the per-call
                // budget needs to comfortably cover boot + the actual work.
                timeout: options.timeout || 120000,
                encoding: 'utf-8',
                env: { ...process.env },
                maxBuffer: 50 * 1024 * 1024,
            });
            return { stdout: result, stderr: '', exitCode: 0, signal: null };
        } catch (e) {
            // Capture all available crash diagnostics
            return {
                stdout: e.stdout || '',
                stderr: e.stderr || '',
                exitCode: e.status === null ? -1 : (e.status || 1),
                signal: e.signal || null,
                errorCode: e.code || null,
                killed: e.killed || false,
            };
        }
    }

    // Non-preflight commands require a prior preflight run.
    // Automatically run one if the state file doesn't already have preflight data.
    if (command !== 'preflight' && command !== 'preflight-assert' && options.skipPreflight !== true) {
        const stateFile = join(outputDir, 'pull/state.json');
        let needsPreflight = true;
        try {
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            if (state.preflight && state.preflight.data) {
                needsPreflight = false;
            }
        } catch {
            // No state file or invalid JSON — need preflight
        }
        if (needsPreflight) {
            const preflightResult = runImporterOnce('preflight');
            if (preflightResult.exitCode !== 0) {
                return preflightResult;
            }
        }
    }

    const commandExtraArgs = options.extraArgs || [];
    // Wall-clock budget across all resume attempts. Set high enough to cover several
    // WASM PHP invocations (~12s startup each) plus their actual work.
    const wallTimeout = options.wallTimeout || 240000;
    const wallStart = Date.now();
    let result = runImporterOnce(command, commandExtraArgs);
    if (
        runWithResume &&
        command !== 'preflight' &&
        command !== 'preflight-assert'
    ) {
        let attempts = 0;
        while (result.exitCode === 2 && attempts < maxResumeAttempts) {
            if (Date.now() - wallStart > wallTimeout) {
                result = {
                    ...result,
                    exitCode: 1,
                    stderr: `${result.stderr}\nWall-clock timeout (${wallTimeout}ms) after ${attempts} resume attempts.`,
                };
                break;
            }
            attempts += 1;
            const next = runImporterOnce(command, commandExtraArgs);
            result = {
                stdout: `${result.stdout}${next.stdout}`,
                stderr: `${result.stderr}${next.stderr}`,
                exitCode: next.exitCode,
            };
        }

        if (result.exitCode === 2) {
            result = {
                ...result,
                exitCode: 1,
                stderr: `${result.stderr}\nExceeded max resume attempts (${maxResumeAttempts}) while command remained partial.`,
            };
        }
    }

    return result;
}

/**
 * Create a temporary directory for import output.
 */
export function createTempDir(prefix = 'e2e-import') {
    const dir = join(tmpdir(), `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2)}`);
    mkdirSync(dir, { recursive: true });
    return dir;
}

/**
 * Clean up a temporary directory.
 */
export function cleanupTempDir(dir) {
    try {
        execSync(`rm -rf ${JSON.stringify(dir)}`, { timeout: 10000 });
    } catch {
        // Ignore cleanup errors
    }
}

/**
 * Compute SHA1 hash of a file.
 */
export function sha1File(filePath) {
    const content = readFileSync(filePath);
    return createHash('sha1').update(content).digest('hex');
}

/**
 * Recursively list all files in a directory with their SHA1 hashes.
 * @returns {Map<string, string>} Map of relative path -> SHA1 hash
 */
export function hashDirectory(dirPath) {
    const hashes = new Map();

    function walk(currentDir) {
        let entries;
        try {
            entries = readdirSync(currentDir);
        } catch {
            return; // Skip unreadable directories
        }

        for (const entry of entries) {
            const fullPath = join(currentDir, entry);
            let stat;
            try {
                stat = lstatSync(fullPath);
            } catch {
                continue;
            }

            if (stat.isSymbolicLink()) {
                continue; // Skip symlinks for hash comparison
            }

            if (stat.isDirectory()) {
                walk(fullPath);
            } else if (stat.isFile()) {
                try {
                    const relPath = relative(dirPath, fullPath);
                    const hash = sha1File(fullPath);
                    hashes.set(relPath, hash);
                } catch {
                    // Skip unreadable files
                }
            }
        }
    }

    walk(dirPath);
    return hashes;
}

/**
 * Compare two directory hashes and return differences.
 */
function compareDirectoryHashes(source, imported) {
    const missing = [];
    const extra = [];
    const different = [];

    for (const [path, hash] of source) {
        if (!imported.has(path)) {
            missing.push(path);
        } else if (imported.get(path) !== hash) {
            different.push({ path, sourceHash: hash, importedHash: imported.get(path) });
        }
    }

    for (const [path] of imported) {
        if (!source.has(path)) {
            extra.push(path);
        }
    }

    return { missing, extra, different, match: missing.length === 0 && different.length === 0 };
}

/**
 * Create a MySQL connection.
 */
export async function createMysqlConnection(dbName = null) {
    const config = {
        host: DB_HOST,
        user: DB_USER,
        password: DB_PASS,
    };
    if (dbName) {
        config.database = dbName;
    }
    return createConnection(config);
}

/**
 * Query a SQLite database through the upstream MySQL-on-SQLite PDO driver.
 */
export function queryMysqlOnSqlite(sqlitePath, sql, dbName = 'sqlite_database') {
    const script = `
require_once $argv[1] . '/lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';
$pdo = new WP_PDO_MySQL_On_SQLite(
    'mysql-on-sqlite:path=' . str_replace(';', ';;', $argv[2]) . ';dbname=' . str_replace(';', ';;', $argv[3]),
    null,
    null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$stmt = $pdo->query($argv[4]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES);
`;

    const output = execFileSync(PHP_BINARY, ['-r', script, PROJECT_ROOT, sqlitePath, dbName, sql], {
        encoding: 'utf-8',
        env: { ...process.env },
    });

    return JSON.parse(output);
}

/**
 * Get database name for a site.
 */
export function getDbName(siteName) {
    return `e2e_${siteName.replace(/-/g, '_')}`;
}

/**
 * Compare two databases (table list and row counts).
 */
export async function compareDatabases(sourceDb, importDb) {
    const sourceConn = await createMysqlConnection(sourceDb);
    const importConn = await createMysqlConnection(importDb);

    try {
        const [sourceTables] = await sourceConn.query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME",
            [sourceDb]
        );
        const [importTables] = await importConn.query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME",
            [importDb]
        );

        const sourceNames = sourceTables.map(r => r.TABLE_NAME);
        const importNames = importTables.map(r => r.TABLE_NAME);

        const missingTables = sourceNames.filter(t => !importNames.includes(t));
        const extraTables = importNames.filter(t => !sourceNames.includes(t));

        const rowCounts = {};
        for (const table of sourceNames) {
            if (importNames.includes(table)) {
                const [[srcRow]] = await sourceConn.query(`SELECT COUNT(*) as cnt FROM \`${table}\``);
                const [[impRow]] = await importConn.query(`SELECT COUNT(*) as cnt FROM \`${table}\``);
                rowCounts[table] = {
                    source: Number(srcRow.cnt),
                    imported: Number(impRow.cnt),
                    match: Number(srcRow.cnt) === Number(impRow.cnt),
                };
            }
        }

        return {
            sourceTableCount: sourceNames.length,
            importTableCount: importNames.length,
            missingTables,
            extraTables,
            rowCounts,
            match: missingTables.length === 0 && Object.values(rowCounts).every(r => r.match),
        };
    } finally {
        await sourceConn.end();
        await importConn.end();
    }
}

/**
 * Write a test-hooks.php file for a site.
 */
export function writeTestHooks(siteName, phpCode) {
    const hookPath = join(SITE_ROOT, siteName, 'wp-content', 'plugins', 'site-export', 'test-hooks.php');
    const code = `<?php\n${phpCode}\n`;
    execSync(`sudo tee ${JSON.stringify(hookPath)} > /dev/null <<'HOOKEOF'\n${code}\nHOOKEOF`);
    execSync(`sudo chown nginx:nginx ${JSON.stringify(hookPath)}`);
}

/**
 * Remove test-hooks.php for a site.
 */
export function removeTestHooks(siteName) {
    const hookPath = join(SITE_ROOT, siteName, 'wp-content', 'plugins', 'site-export', 'test-hooks.php');
    try {
        execSync(`sudo rm -f ${JSON.stringify(hookPath)}`);
    } catch {
        // Ignore
    }
}

/**
 * Get hook state file path.
 * Uses /srv/e2e-sites/ instead of /tmp because PHP-FPM has PrivateTmp=yes,
 * so PHP and Node.js see different /tmp directories.
 */
function hookStatePath(siteName) {
    return `${SITE_ROOT}/.e2e-hook-state-${siteName}`;
}

/**
 * Write a state file that test hooks can read/write.
 */
export function writeHookState(siteName, data) {
    const statePath = hookStatePath(siteName);
    execSync(`sudo tee ${JSON.stringify(statePath)} > /dev/null <<'STATEEOF'\n${JSON.stringify(data)}\nSTATEEOF`);
    execSync(`sudo chmod 666 ${JSON.stringify(statePath)}`);
}

/**
 * Read a state file written by test hooks.
 */
export function readHookState(siteName) {
    const statePath = hookStatePath(siteName);
    try {
        return JSON.parse(readFileSync(statePath, 'utf-8'));
    } catch {
        return null;
    }
}

/**
 * Clear hook state file.
 */
export function clearHookState(siteName) {
    const statePath = hookStatePath(siteName);
    try {
        execSync(`sudo rm -f ${JSON.stringify(statePath)}`);
    } catch {
        // Ignore
    }
}

/**
 * Make a file_fetch request with a file_list upload.
 * Uses multipart/form-data to upload the file list as a file.
 */
export async function apiRequestWithFileList(siteName, filePaths, params = {}) {
    const client = createHmacClient(siteName);
    const url = new URL(getSiteUrl(siteName));
    url.searchParams.set('endpoint', 'file_fetch');
    for (const [k, v] of Object.entries(params)) {
        url.searchParams.set(k, String(v));
    }

    const fileListJson = JSON.stringify(filePaths);

    // Create form data with file upload
    const formData = new FormData();
    const blob = new Blob([fileListJson], { type: 'application/json' });
    formData.append('file_list', blob, 'file_list.json');

    // For HMAC: hash the file content (what the server will hash from $_FILES)
    const headers = client.getAuthHeaders(fileListJson);

    const response = await fetch(url.toString(), {
        method: 'POST',
        headers,
        body: formData,
    });

    const contentType = response.headers.get('content-type') || '';

    if (contentType.includes('application/json')) {
        return {
            status: response.status,
            json: await response.json(),
            headers: Object.fromEntries(response.headers.entries()),
        };
    }

    if (contentType.includes('multipart/mixed')) {
        const arrayBuf = await response.arrayBuffer();
        let bodyBuf = Buffer.from(arrayBuf);

        if (bodyBuf.length >= 2 && bodyBuf[0] === 0x1f && bodyBuf[1] === 0x8b) {
            try {
                bodyBuf = gunzipSync(bodyBuf);
            } catch (e) {
                return {
                    status: response.status,
                    headers: Object.fromEntries(response.headers.entries()),
                    chunks: [],
                    raw: bodyBuf,
                    gzipError: e.message,
                };
            }
        }

        const boundary = extractBoundary(contentType);
        const chunks = parseMultipart(bodyBuf.toString('binary'), boundary);
        return {
            status: response.status,
            headers: Object.fromEntries(response.headers.entries()),
            chunks,
            raw: bodyBuf,
        };
    }

    return {
        status: response.status,
        text: await response.text(),
        headers: Object.fromEntries(response.headers.entries()),
    };
}

/**
 * Count non-empty lines in a JSONL file.
 */
export function countJsonlLines(filePath) {
    if (!existsSync(filePath)) return 0;
    const content = readFileSync(filePath, 'utf-8');
    return content.split('\n').filter(l => l.trim()).length;
}

/**
 * Read the audit log as a string.
 */
export function readAuditLog(outputDir) {
    const logPath = join(outputDir, 'audit.log');
    if (!existsSync(logPath)) return '';
    return readFileSync(logPath, 'utf-8');
}

/**
 * Assert that the import indexed at least minCount files.
 * Checks pull/remote-index.jsonl line count.
 */
export function assertFileCount(outputDir, minCount = 3000) {
    const indexPath = join(outputDir, 'pull/remote-index.jsonl');
    assert.ok(existsSync(indexPath), `Expected ${indexPath} to exist`);
    const count = countJsonlLines(indexPath);
    assert.ok(count >= minCount,
        `Expected at least ${minCount} files in index, got ${count}`);
}

/**
 * Assert that the imported site root looks like a real WordPress installation.
 * Checks for key WP paths.
 */
export function assertSiteMirror(importedSiteRoot) {
    const requiredPaths = [
        'wp-includes/version.php',
        'wp-admin/index.php',
        'wp-content/themes',
        'index.php',
        'wp-load.php',
    ];
    for (const p of requiredPaths) {
        const fullPath = join(importedSiteRoot, p);
        assert.ok(existsSync(fullPath),
            `Expected WordPress path to exist: ${p} (checked ${fullPath})`);
    }
}

/**
 * Assert that two directory trees are identical: no missing, no extra, no different files.
 * Symlinks and unreadable files are skipped on both sides (by hashDirectory).
 * @param {string} sourceDir - Source directory path
 * @param {string} importedDir - Imported directory path
 * @param {Object} options
 * @param {string[]} options.exclude - Substrings to exclude from comparison (e.g. ['large-volatile.bin'])
 * @param {boolean} options.allowMissing - If true, allow files present in source but missing from import (for tests where hooks cause incomplete syncs)
 */
export function assertTreesMatch(sourceDir, importedDir, options = {}) {
    const exclude = options.exclude || [];
    const sourceHashes = hashDirectory(sourceDir);
    const importedHashes = hashDirectory(importedDir);

    // Remove excluded paths from both maps
    for (const substr of exclude) {
        for (const path of sourceHashes.keys()) {
            if (path.includes(substr)) sourceHashes.delete(path);
        }
        for (const path of importedHashes.keys()) {
            if (path.includes(substr)) importedHashes.delete(path);
        }
    }

    const comparison = compareDirectoryHashes(sourceHashes, importedHashes);
    const problems = [];
    if (!options.allowMissing && comparison.missing.length > 0) {
        problems.push(`missing=${comparison.missing.length} (${comparison.missing.slice(0, 5).join(', ')})`);
    }
    if (comparison.extra.length > 0) {
        problems.push(`extra=${comparison.extra.length} (${comparison.extra.slice(0, 5).join(', ')})`);
    }
    if (comparison.different.length > 0) {
        problems.push(`different=${comparison.different.length} (${comparison.different.slice(0, 5).map(d => d.path).join(', ')})`);
    }
    assert.equal(problems.length, 0, `Trees differ: ${problems.join('; ')}`);
}

/**
 * Assert that a high-level pull pipeline finished all stages it recorded.
 */
export function assertPullPipelineComplete(state, command = 'pull') {
    const pipeline = state.pull_pipeline;
    assert.ok(pipeline, 'Expected pull_pipeline state to exist');
    assert.equal(pipeline.started_by_command, command);
    assert.equal(pipeline.has_completed_once, true);
    assert.ok(Array.isArray(pipeline.stage_sequence),
        'Expected pull_pipeline.stage_sequence to be an array');
    assert.ok(pipeline.stage_sequence.length > 0,
        'Expected pull_pipeline.stage_sequence to record at least one stage');
    assert.equal(
        pipeline.last_completed_stage,
        pipeline.stage_sequence[pipeline.stage_sequence.length - 1],
    );
}

export { PHP_BINARY };
