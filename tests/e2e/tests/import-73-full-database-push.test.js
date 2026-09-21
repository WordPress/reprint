/** Reuse pull fixtures against the real push endpoint and a bootable WordPress target. */
import { describe, it, beforeAll, beforeEach, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { writeFileSync, readFileSync, mkdirSync, chmodSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import {
    createMysqlConnection, createTempDir, getDbName,
    getSiteDir, getSiteUrl, getSiteSecret, runImporter,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { createSqlFidelityData } from '../lib/sql-fidelity-data.js';
import { createBinaryKeyData } from '../lib/binary-key-data.js';
import { createSqlModeData } from '../lib/mysql-session-data.js';
import { createManyRowData, createBoundedPayloadData, createLargeSingleRowData } from '../lib/large-database-data.js';
import { SITE_BUILDER_CASES, installSiteBuilderShortcodes, createUrlRewritingData } from '../lib/url-rewriting-data.js';

const sourceSite = 'database-push-source';
const targetSite = 'database-push-target';
const sourceDatabase = getDbName(sourceSite);
const targetDatabase = getDbName(targetSite);
const referenceDatabase = `${sourceDatabase}_reference`;
const sourceOrigin = 'http://local-source.example.test';
const targetOrigin = new URL(getSiteUrl(targetSite)).origin;
const targetApi = `${targetOrigin}/database-push.php`;
const projectRoot = join(import.meta.dirname, '..', '..', '..');
const clientPath = process.env.CLIENT_PATH || join(projectRoot, 'packages/reprint-client/bin/reprint-client');
const phpBinary = process.env.PHP_BINARY || 'php';
const secret = 'database-push-e2e-independent-token';
const mysqlArguments = ['-h127.0.0.1', '-ue2e_admin', '-pe2e_password'];
let directory;
let sourceBaseline;
let targetBaseline;
let sequence = 0;

// The streamed push client requires native PHP 8.1+, not PHP.wasm.
const nativePhpVersion = phpBinary.endsWith('/playground-php.sh')
    ? 0 : Number(execFileSync(phpBinary, ['-r', 'echo PHP_VERSION_ID;'], { encoding: 'utf8' }));
const describeNative = nativePhpVersion >= 80100 ? describe : describe.skip;
describeNative('Full database push: pull fixtures and live WordPress', { timeout: 600000 }, () => {
    beforeAll(async () => {
        directory = createTempDir('e2e-full-database-push');
        // Host configuration lives outside WordPress. Replacing options and
        // deactivating the regular plugin must not remove the push API.
        const privateDirectory = join(directory, 'host');
        mkdirSync(privateDirectory, { recursive: true });
        writeFileSync(join(privateDirectory, 'requests'), '');
        writeFileSync(join(privateDirectory, 'secret.php'), `<?php return '${secret}';`);
        // PHP-FPM reads the installed bundle, not the runner's private checkout.
        const targetPluginDirectory = join(getSiteDir(targetSite), 'wp-content/plugins/reprint-server');
        const targetRoute = `<?php
require '${targetPluginDirectory}/vendor/autoload.php';
define('ABSPATH', '${getSiteDir(targetSite)}/');
define('DB_HOST', '127.0.0.1');
define('DB_NAME', '${targetDatabase}');
define('DB_USER', 'e2e_admin');
define('DB_PASSWORD', 'e2e_password');
$GLOBALS['table_prefix'] = 'wp_';
define('WordPress\\\\Reprint\\\\Server\\\\Plugin\\\\PLUGIN_DIR', '${targetPluginDirectory}/');
define('WordPress\\\\Reprint\\\\Server\\\\Plugin\\\\CONNECTION_TOKEN_FILE', '${privateDirectory}/secret.php');
define('REPRINT_SERVER_PUSH_ENABLED', true);
register_shutdown_function(static function () {
    file_put_contents('${privateDirectory}/requests', ($_GET['endpoint'] ?? '') . "\\n", FILE_APPEND);
});
require '${targetPluginDirectory}/lib.php';
\\WordPress\\Reprint\\Server\\Plugin\\handle_api_request([
    'docroot' => ABSPATH, 'reprint_directory' => '${privateDirectory}', 'database_push' => true,
]);
`;
        execFileSync('chmod', ['-R', '777', directory]);
        for (const site of [sourceSite, targetSite]) {
            execFileSync('sudo', ['rm', '-rf', getSiteDir(site)]);
            await ensureSite(site, {
                files: 'none',
                afterCreate: async siteDirectory => {
                    await installSiteBuilderShortcodes(siteDirectory);
                    if (site === targetSite) {
                        writeFileSync(join(siteDirectory, 'database-push.php'), targetRoute);
                    }
                },
            });
        }
        const source = await createMysqlConnection(sourceDatabase);
        const target = await createMysqlConnection(targetDatabase);
        try {
            await source.query("UPDATE wp_options SET option_value=? WHERE option_name IN ('home','siteurl')", [sourceOrigin]);
            await source.query("UPDATE wp_posts SET guid=REPLACE(guid, ?, ?)", [new URL(getSiteUrl(sourceSite)).origin, sourceOrigin]);
            await target.query('CREATE TABLE wp_production_orders (id int PRIMARY KEY, value text) ENGINE=InnoDB');
            await target.query("INSERT INTO wp_production_orders VALUES (827, 'production-only order')");
            await target.query('CREATE TABLE unrelated_records (id int PRIMARY KEY, value text) ENGINE=InnoDB');
            await target.query("INSERT INTO unrelated_records VALUES (1, 'keep this site')");
            // Pull must authenticate even though the copied plugin is disabled
            // in the snapshot that push will install.
            await source.query("UPDATE wp_options SET option_value=? WHERE option_name='active_plugins'", ['a:1:{i:0;s:24:"reprint-server/index.php";}']);
        } finally {
            await source.end();
            await target.end();
        }
        sourceBaseline = execFileSync('mysqldump', [...mysqlArguments, '--skip-comments', sourceDatabase]);
        targetBaseline = execFileSync('mysqldump', [...mysqlArguments, '--skip-comments', targetDatabase]);
    });

    beforeEach(async () => {
        const connection = await createMysqlConnection();
        try {
            for (const database of [sourceDatabase, targetDatabase, referenceDatabase]) {
                await connection.query(`DROP DATABASE IF EXISTS \`${database}\``);
                await connection.query(`CREATE DATABASE \`${database}\``);
            }
        } finally {
            await connection.end();
        }
        execFileSync('mysql', [...mysqlArguments, sourceDatabase], { input: sourceBaseline });
        execFileSync('mysql', [...mysqlArguments, targetDatabase], { input: targetBaseline });
    });

    afterAll(async () => {
        // The PHP-FPM user creates private upload files the runner cannot unlink.
        if (directory) execFileSync('sudo', ['rm', '-rf', directory]);
        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${referenceDatabase}\``);
        await connection.end();
    });

    it('preserves SQL edge values, Unicode, zero dates and extreme numbers', async () => {
        await exercisePush(createSqlFidelityData);
    });

    it('rewrites serialized options, blocks and nested builder markup that still renders', async () => {
        await exercisePush(createUrlRewritingData, { builder: true });
    });

    it('preserves arbitrary-byte primary keys and composite keys', async () => {
        await exercisePush(async connection => {
            await createBinaryKeyData(connection);
            // Pull covers arbitrary table names; push selects the site prefix.
            await connection.query('RENAME TABLE aa_binary_primary_keys TO wp_binary_primary_keys, ab_composite_binary_primary_key TO wp_composite_binary_primary_key');
        });
    });

    it('preserves the ENUM index-zero value created under permissive SQL mode', async () => {
        await exercisePush(async connection => {
            await createSqlModeData(connection, 'wp_session_sql_mode');
            // Numeric index zero is not the label '0', a valid empty label, or NULL.
            await connection.query("CREATE TABLE wp_enum_distinctions (id int PRIMARY KEY, value ENUM('0','') NULL) ENGINE=InnoDB");
            await connection.query("INSERT IGNORE INTO wp_enum_distinctions VALUES (1, 'invalid'), (2, '0'), (3, ''), (4, NULL)");
        });
        const source = await createMysqlConnection(sourceDatabase);
        const target = await createMysqlConnection(targetDatabase);
        try {
            const query = 'SELECT id, value, value+0 AS member FROM wp_enum_distinctions ORDER BY id';
            assert.deepEqual((await target.query(query))[0], (await source.query(query))[0]);
        } finally {
            await source.end();
            await target.end();
        }
    });

    it('preserves all 200 payloads of 80 KiB', async () => {
        await exercisePush(connection => createBoundedPayloadData(connection, 'wp_bounded_payloads'), { archiveBytes: 20 * 1024 * 1024 });
    });

    it('preserves 50,050 rows across hundreds of import requests', async () => {
        // Fifty copies of the pull suite's 1,001-row batch-boundary dataset.
        await exercisePush(connection => createManyRowData(connection, 'wp_many_rows', 50050), { imports: 390 });
    });

    it('rejects the pull suite’s 13 MiB row without changing the live site', async () => {
        const connection = await createMysqlConnection(sourceDatabase);
        await createLargeSingleRowData(connection, 'wp_large_single_row');
        await connection.end();
        const before = await snapshot(targetDatabase);
        const stateDirectory = join(directory, `push-${++sequence}`);
        const result = push(stateDirectory, sourceArguments());
        assert.notEqual(result.status, 0, 'The documented row limit must reject this dataset');
        assert.match(result.stdout + result.stderr, /1 MiB/);
        assert.deepEqual(await snapshot(targetDatabase), before);
        assert.equal(push(stateDirectory, ['--abort']).status, 0);
        await assertSiteWorks('E2E: ' + targetSite);
    });
});

async function exercisePush(createData, { builder = false, archiveBytes = 0, imports = 1 } = {}) {
    const source = await createMysqlConnection(sourceDatabase);
    try {
        await createData(source);
    } finally {
        await source.end();
    }
    const stateDirectory = join(directory, `push-${++sequence}`);
    const pullDirectory = join(directory, `pull-${sequence}`);
    // A real pull/apply of the same data is the reference, not another push.
    const pulled = runImporter(getSiteUrl(sourceSite), pullDirectory, 'pull-db', {
        secret: getSiteSecret(sourceSite), timeout: 300000, wallTimeout: 600000,
        extraArgs: ['--target-engine=mysql', '--target-host=127.0.0.1', '--target-user=e2e_admin',
            '--target-pass=e2e_password', `--target-db=${referenceDatabase}`, ...rewriteArguments()],
    });
    assert.equal(pulled.exitCode, 0, pulled.stderr + pulled.stdout.slice(-4000));
    // Independent host credentials must keep working after the copied plugin
    // and its option-backed authentication are replaced.
    for (const database of [sourceDatabase, referenceDatabase]) {
        const connection = await createMysqlConnection(database);
        await connection.query("UPDATE wp_options SET option_value='a:0:{}' WHERE option_name='active_plugins'");
        await connection.end();
    }
    const sourceBefore = await snapshot(sourceDatabase);
    const targetBefore = await snapshot(targetDatabase);
    const logPath = join(directory, 'host/requests');
    writeFileSync(logPath, '');
    chmodSync(logPath, 0o666);
    const staged = push(stateDirectory, sourceArguments());
    assert.equal(staged.status, 0, staged.stderr + staged.stdout.slice(-4000));
    const ready = resultJson(staged);
    assert.equal(ready.phase, 'ready');
    assert.ok(ready.replace_tables.includes('wp_production_orders'));
    assert.deepEqual(await snapshot(targetDatabase), targetBefore, 'Staging must leave every live value unchanged');
    assert.deepEqual(await snapshot(sourceDatabase), sourceBefore, 'Push must not modify the local source');
    const noConfirmation = push(stateDirectory, [`--commit=${ready.review}`]);
    assert.notEqual(noConfirmation.status, 0);
    assert.deepEqual(await snapshot(targetDatabase), targetBefore);
    const committed = push(stateDirectory, [`--commit=${ready.review}`, '--writers-stopped']);
    assert.equal(committed.status, 0, committed.stderr + committed.stdout);
    assert.equal(resultJson(committed).phase, 'committed');
    const expected = await snapshot(referenceDatabase);
    const actual = await snapshot(targetDatabase);
    delete actual.unrelated_records;
    assert.deepEqual(actual, expected, 'Every site schema and row must match the pull/apply result');
    assert.deepEqual(await snapshot(sourceDatabase), sourceBefore, 'Commit must not change the local source');
    const retained = resultJson(committed).old_tables;
    assert.ok(retained.length > 0, 'Old tables must remain until explicit cleanup');
    const target = await createMysqlConnection(targetDatabase);
    try {
        if (builder) {
            for (const testCase of SITE_BUILDER_CASES) {
                const [[post]] = await target.query('SELECT post_content FROM wp_posts WHERE post_name=?', [testCase.slug]);
                assert.equal(post.post_content, testCase.expected, testCase.name);
            }
        }
        assert.deepEqual((await target.query('SELECT * FROM unrelated_records'))[0], [{ id: 1, value: 'keep this site' }]);
        for (const table of retained) {
            await target.query(`SELECT 1 FROM \`${table}\` LIMIT 1`);
        }
    } finally {
        await target.end();
    }
    const requests = readFileSync(logPath, 'utf8').trim().split('\n');
    assert.ok(requests.includes('push_db_upload'));
    const archive = join(stateDirectory, 'remotes', createHash('md5').update(targetApi).digest('hex'), 'push/database/database.jsonl');
    assert.ok(statSync(archive).size >= archiveBytes);
    assert.ok(requests.filter(endpoint => endpoint === 'push_db_import').length >= imports);
    await assertSiteWorks('E2E: ' + sourceSite, builder);
    const cleanup = push(stateDirectory, ['--cleanup']);
    assert.equal(cleanup.status, 0, cleanup.stderr + cleanup.stdout);
    const afterCleanup = await createMysqlConnection(targetDatabase);
    const [tables] = await afterCleanup.query('SHOW TABLES');
    await afterCleanup.end();
    assert.ok(retained.every(table => !tables.some(row => Object.values(row)[0] === table)));
    await assertSiteWorks('E2E: ' + sourceSite, builder);
    console.log(`Verified ${Object.keys(expected).length} site tables; ${requests.filter(e => e === 'push_db_upload').length} uploads, ${requests.filter(e => e === 'push_db_import').length} imports`);
}

function push(stateDirectory, arguments_) {
    return spawnSync(phpBinary, ['-d', 'memory_limit=128M', clientPath, 'db-push', targetApi,
        `--state-dir=${stateDirectory}`, `--secret=${secret}`, '--force-http', '--progress=jsonl', ...arguments_],
    { encoding: 'utf8', timeout: 600000, maxBuffer: 4 * 1024 * 1024 });
}

function sourceArguments() {
    return [`--source-dsn=mysql:host=127.0.0.1;dbname=${sourceDatabase};charset=utf8mb4`,
        '--source-user=e2e_admin', '--source-pass=e2e_password', ...rewriteArguments()];
}

function rewriteArguments() {
    return ['--rewrite-url', sourceOrigin, targetOrigin, '--rewrite-url', 'http://127.0.0.1:8108', 'https://target.example.com'];
}

function resultJson(result) {
    const records = result.stdout.trim().split('\n').map(line => JSON.parse(line));
    const result_ = records.findLast(record => record.phase);
    assert.ok(result_, result.stdout);
    return result_;
}

async function snapshot(database) {
    const connection = await createMysqlConnection(database);
    try {
        await connection.query("SET time_zone='+00:00'");
        const [tables] = await connection.query('SHOW TABLES');
        const result = {};
        for (const row of tables) {
            const table = Object.values(row)[0];
            if (table.startsWith('__reprint_')) continue;
            const [[schema]] = await connection.query(`SHOW CREATE TABLE \`${table}\``);
            const [columns] = await connection.query(`SHOW COLUMNS FROM \`${table}\``);
            // Hash every complete row, including NULL markers and duplicate
            // multiplicity. No row-count-only or sampled data comparison.
            const fields = columns.map(column => `HEX(CAST(\`${column.Field}\` AS BINARY))`).join(',');
            const [hashes] = await connection.query(`SELECT SHA2(JSON_ARRAY(${fields}),256) AS digest FROM \`${table}\` ORDER BY digest`);
            result[table] = { schema: schema['Create Table'], rows: hashes.map(row_ => row_.digest) };
        }
        return result;
    } finally {
        await connection.end();
    }
}

async function assertSiteWorks(title, builder = false) {
    const response = await fetch(targetOrigin, { signal: AbortSignal.timeout(30000) });
    assert.equal(response.status, 200);
    const html = await response.text();
    assert.ok(html.includes(title), `Target home page did not render ${title}`);
    const php = `require $argv[1] . '/wp-load.php';
$user = wp_authenticate('admin', 'password');
if (is_wp_error($user)) { fwrite(STDERR, $user->get_error_message()); exit(1); }
$posts = get_posts(['numberposts' => -1]);
$result = ['login' => $user->user_login, 'home' => get_option('home'), 'posts' => []];
foreach ($posts as $post) { $result['posts'][$post->post_name] = do_shortcode(do_blocks($post->post_content)); }
$result['serialized'] = get_option('serialized_option');
echo json_encode($result);`;
    const state = JSON.parse(execFileSync(phpBinary, ['-r', php, getSiteDir(targetSite)], { encoding: 'utf8' }));
    assert.equal(state.login, 'admin');
    assert.equal(state.home, targetOrigin);
    assert.ok(Object.hasOwn(state.posts, 'hello-world'));
    if (builder) {
        assert.deepEqual(state.serialized, { siteurl: 'https://target.example.com', home: 'https://target.example.com' });
        for (const testCase of SITE_BUILDER_CASES) {
            assert.equal(state.posts[testCase.slug], testCase.rendered, testCase.name);
        }
    }
}
