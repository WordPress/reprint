import { beforeEach, afterEach, describe, expect, it } from 'vitest';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, rmSync, statSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomUUID, createHash } from 'node:crypto';
import { createMysqlConnection } from '../lib/test-helpers.js';
import { createManyRowData, createBoundedPayloadData } from '../lib/large-database-data.js';

const project = join(import.meta.dirname, '../../..');
const client = process.env.CLIENT_PATH || join(project, 'packages/reprint-client/bin/reprint-client');
const php = process.env.PHP_BINARY || 'php';
const describeNative = php.endsWith('/playground-php.sh') ? describe.skip : describe;
// Deliberately unreachable: these commands must work without contacting production.
const remote = 'https://127.0.0.1:1/?reprint-api';

describeNative('Local database baseline and column diff', () => {
    let connection, database, state;
    const args = (command, options = []) => [
        '-d', 'memory_limit=64M', client, command, remote,
        `--state-dir=${state}`, '--progress=jsonl',
        `--source-dsn=mysql:host=127.0.0.1;dbname=${database};charset=utf8mb4`,
        '--source-user=e2e_admin', '--source-pass=e2e_password', ...options,
    ];
    function run(command, options = []) {
        return execFileSync(php, args(command, options), { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] });
    }
    function changes() {
        return run('db-diff').trim().split('\n').map(JSON.parse).filter(record => record.type === 'database_change');
    }
    function baselinePath() {
        return join(state, 'remotes', createHash('md5').update(remote).digest('hex'), 'database-baseline');
    }
    function baselineDigest() {
        return readdirSync(baselinePath()).sort().map(name => [name,
            createHash('sha256').update(readFileSync(join(baselinePath(), name))).digest('hex')]);
    }
    const b64 = value => value === null ? null : Buffer.from(String(value)).toString('base64');
    const values = row => Object.fromEntries(Object.entries(row).map(([key, value]) => [key, b64(value)]));

    beforeEach(async () => {
        database = `baseline_${randomUUID().replaceAll('-', '')}`;
        state = mkdtempSync(join(tmpdir(), 'reprint-baseline-e2e-'));
        connection = await createMysqlConnection();
        await connection.query(`CREATE DATABASE \`${database}\``);
        await connection.changeUser({ database });
    });
    afterEach(async () => {
        await connection?.query(`DROP DATABASE IF EXISTS \`${database}\``);
        await connection?.end();
        rmSync(state, { recursive: true, force: true });
    });

    it('keeps old values across repeated diffs; distinguishes edits, inserts, deletes and reverts', async () => {
        await connection.query('CREATE TABLE wp_posts (id BIGINT PRIMARY KEY, title TEXT, body TEXT)');
        await connection.query("INSERT INTO wp_posts VALUES (1,'Old title','Keep body'),(2,'Delete me',NULL)");
        run('db-baseline', ['--table=wp_posts']);
        const saved = baselineDigest();
        await connection.query("UPDATE wp_posts SET title='New title' WHERE id=1");
        await connection.query('DELETE FROM wp_posts WHERE id=2');
        await connection.query("INSERT INTO wp_posts VALUES (3,'New post','New body')");
        const diff = changes();
        expect(diff).toHaveLength(3);
        expect(diff).toContainEqual({ type: 'database_change', table: 'wp_posts', action: 'update',
            key: values({ id: 1 }), before: values({ title: 'Old title' }), after: values({ title: 'New title' }) });
        expect(diff).toContainEqual({ type: 'database_change', table: 'wp_posts', action: 'delete',
            key: values({ id: 2 }), before: values({ id: 2, title: 'Delete me', body: null }), after: null });
        expect(diff).toContainEqual({ type: 'database_change', table: 'wp_posts', action: 'insert',
            key: values({ id: 3 }), before: null, after: values({ id: 3, title: 'New post', body: 'New body' }) });
        expect(changes()).toEqual(diff);
        expect(baselineDigest()).toEqual(saved);
        expect(() => run('db-baseline', ['--table=wp_posts'])).toThrow();
        expect(baselineDigest()).toEqual(saved);
        await connection.query("UPDATE wp_posts SET title='Old title' WHERE id=1");
        expect(changes().some(change => change.action === 'update')).toBe(false);
    });

    it('never invents deletions for rows omitted before capture, or changes in unselected tables', async () => {
        await connection.query('CREATE TABLE wp_posts (id INT PRIMARY KEY, url TEXT)');
        await connection.query("INSERT INTO wp_posts VALUES (1,'http://local.test/?p=1')");
        await connection.query('CREATE TABLE wp_orders (id INT PRIMARY KEY)');
        await connection.query('INSERT INTO wp_orders VALUES (900)');
        // Production rows 2 and 3 were not pulled. Capture only what exists locally,
        // after local URL rewriting, not a reconstructed production snapshot.
        run('db-baseline', ['--table=wp_posts']);
        expect(changes()).toEqual([]);
        await connection.query('DELETE FROM wp_orders');
        await connection.query("UPDATE wp_posts SET url='http://local.test/new' WHERE id=1");
        expect(changes()).toEqual([{ type: 'database_change', action: 'update', table: 'wp_posts',
            key: values({ id: 1 }), before: values({ url: 'http://local.test/?p=1' }), after: values({ url: 'http://local.test/new' }) }]);
    });

    it('matches binary composite keys, large IDs and keys ordered differently by MySQL collations', async () => {
        await connection.query('CREATE TABLE plugin_data (segment VARBINARY(8), id BIGINT UNSIGNED, payload BLOB, PRIMARY KEY(id,segment))');
        await connection.query("INSERT INTO plugin_data VALUES (X'00FF',18446744073709551615,X'FF00'),(X'0000',2,NULL)");
        await connection.query('CREATE TABLE names (name VARCHAR(30) COLLATE utf8mb4_unicode_ci PRIMARY KEY, value TEXT)');
        await connection.query("INSERT INTO names VALUES ('é','accent'),('Z','last'),('a','first'),('10','ten'),('2','two')");
        run('db-baseline', ['--table=plugin_data', '--table=names']);
        expect(changes()).toEqual([]);
        await connection.query("UPDATE plugin_data SET payload=X'FF01' WHERE id=18446744073709551615");
        await connection.query("UPDATE plugin_data SET payload='' WHERE id=2");
        await connection.query("DELETE FROM names WHERE name='é'");
        await connection.query("UPDATE names SET value='changed' WHERE name='Z'");
        const diff = changes();
        expect(diff).toHaveLength(4);
        expect(diff).toContainEqual({ type: 'database_change', table: 'plugin_data', action: 'update',
            key: { id: b64('18446744073709551615'), segment: 'AP8=' }, before: { payload: '/wA=' }, after: { payload: '/wE=' } });
        expect(diff).toContainEqual({ type: 'database_change', table: 'plugin_data', action: 'update',
            key: { id: b64(2), segment: 'AAA=' }, before: { payload: null }, after: { payload: '' } });
    });

    it('preserves FLOAT changes and distinguishes SET masks and ENUM index zero from an empty label', async () => {
        await connection.query("SET SESSION sql_mode=''");
        await connection.query("CREATE TABLE special_values (id INT PRIMARY KEY, amount FLOAT, flags SET('','a'), choice ENUM('','a'))");
        await connection.query("INSERT INTO special_values VALUES (1,1.234567,0,0)");
        run('db-baseline', ['--table=special_values']);
        expect(changes()).toEqual([]);
        // Both SET masks display as an empty string; both ENUM indexes display as an empty string.
        await connection.query("UPDATE special_values SET amount=1.234568, flags=1, choice=1");
        const [diff] = changes();
        expect(Object.keys(diff.after).sort()).toEqual(['amount', 'choice', 'flags']);
        expect(diff.before.amount).not.toBe(diff.after.amount);
        expect(diff.before.flags).not.toBe(diff.after.flags);
        expect(diff.before.choice).not.toBe(diff.after.choice);
    });

    it('keeps original text bytes and treats serialized options as one changed column', async () => {
        await connection.query('CREATE TABLE wp_options (name VARCHAR(40) PRIMARY KEY, value LONGBLOB, label VARCHAR(40) CHARACTER SET latin1)');
        const oldValue = 'a:1:{i:0;s:9:"hello.php";}';
        const newValue = 'a:2:{i:0;s:9:"hello.php";i:1;s:8:"shop.php";}';
        await connection.query("INSERT INTO wp_options VALUES ('active_plugins',?,X'E9')", [oldValue]);
        run('db-baseline', ['--table=wp_options']);
        await connection.query("UPDATE wp_options SET value=?, label=X'F1'", [newValue]);
        expect(changes()).toEqual([{ type: 'database_change', action: 'update', table: 'wp_options',
            key: values({ name: 'active_plugins' }),
            before: { value: b64(oldValue), label: '6Q==' }, after: { value: b64(newValue), label: '8Q==' } }]);
    });

    it('records the available hash algorithm and rejects a different database or a changed table selection', async () => {
        await connection.query('CREATE TABLE posts (id INT PRIMARY KEY)');
        run('db-baseline', ['--table=posts']);
        const manifest = JSON.parse(readFileSync(join(baselinePath(), 'manifest.json')));
        const algorithm = execFileSync(php, ['-r', 'echo in_array("xxh128", hash_algos(), true) ? "xxh128" : "sha256";'], { encoding: 'utf8' });
        expect(manifest.algorithm).toBe(algorithm);
        expect(changes()).toEqual([]);
        const saved = baselineDigest();
        expect(() => run('db-diff', ['--source-dsn=mysql:host=127.0.0.1;dbname=mysql'])).toThrow();
        expect(() => run('db-diff', ['--table=posts'])).toThrow();
        expect(baselineDigest()).toEqual(saved);
    });

    it('rejects missing keys and column changes without publishing or advancing a baseline', async () => {
        await connection.query('CREATE TABLE no_key (value TEXT)');
        expect(() => run('db-baseline', ['--table=no_key'])).toThrow();
        expect(existsSync(baselinePath())).toBe(false);
        await connection.query('CREATE TABLE keyed (id INT PRIMARY KEY, value TEXT)');
        run('db-baseline', ['--table=keyed']);
        const saved = baselineDigest();
        await connection.query('ALTER TABLE keyed ADD extra INT');
        expect(() => run('db-diff')).toThrow();
        expect(baselineDigest()).toEqual(saved);
        await connection.query('DROP TABLE keyed');
        expect(() => run('db-diff')).toThrow();
        expect(baselineDigest()).toEqual(saved);
    });

    it('compares 70,000 rows with a disk-sorted index under a 64 MiB PHP limit', async () => {
        await createManyRowData(connection, 'many_rows', 70000);
        run('db-baseline', ['--table=many_rows']);
        const index = join(baselinePath(), createHash('sha256').update('many_rows').digest('hex') + '.index');
        expect(statSync(index).size).toBeGreaterThan(4 * 1024 * 1024);
        expect(readFileSync(index, 'utf8').trim().split('\n')).toHaveLength(70000);
        expect(statSync(index).mode & 0o077).toBe(0);
        await connection.query("UPDATE many_rows SET value='changed' WHERE MOD(id,10000)=0");
        await connection.query('DELETE FROM many_rows WHERE id=123');
        await connection.query("INSERT INTO many_rows VALUES (70001,'new')");
        const diff = changes();
        expect(diff).toHaveLength(9);
        expect(diff.filter(row => row.action === 'update')).toHaveLength(7);
    }, 120000);

    it('streams the pull suite’s 200 × 80 KiB payload fixture under a 64 MiB PHP limit', async () => {
        await createBoundedPayloadData(connection, 'payloads');
        run('db-baseline', ['--table=payloads']);
        expect(changes()).toEqual([]);
        await connection.query("UPDATE payloads SET payload=CONCAT('!',SUBSTRING(payload,2)) WHERE id=199");
        const [diff] = changes();
        expect(diff.key).toEqual(values({ id: 199 }));
        expect(Buffer.from(diff.before.payload, 'base64')).toHaveLength(80 * 1024);
        expect(Buffer.from(diff.after.payload, 'base64')[0]).toBe(33);
        expect(statSync(baselinePath()).mode & 0o077).toBe(0);
        for (const name of readdirSync(baselinePath())) {
            expect(statSync(join(baselinePath(), name)).mode & 0o077).toBe(0);
        }
    }, 120000);

    it('holds local read locks and discards rows from an interrupted scan before restarting', async () => {
        await createManyRowData(connection, 'posts', 60000);
        await connection.query('ALTER TABLE posts ENGINE=MyISAM');
        await connection.query('CREATE TABLE z_options (id INT PRIMARY KEY, value TEXT) ENGINE=InnoDB');
        await connection.query("INSERT INTO z_options VALUES (1,'old')");
        const child = spawn(php, args('db-baseline', ['--table=posts', '--table=z_options']), { stdio: 'ignore' });
        const exited = new Promise(resolve => child.once('exit', resolve));
        const observer = await createMysqlConnection();
        const writer = await createMysqlConnection(database);
        let write;
        try {
            const rowFile = join(baselinePath() + '.scan', createHash('sha256').update('posts').digest('hex') + '.rows');
            const deadline = Date.now() + 10000;
            while (Date.now() < deadline && (!existsSync(rowFile) || statSync(rowFile).size < 20000)) {
                await new Promise(resolve => setTimeout(resolve, 5));
            }
            expect(existsSync(rowFile)).toBe(true);
            expect(statSync(rowFile).size).toBeGreaterThanOrEqual(20000);
            expect(child.kill('SIGSTOP')).toBe(true);
            await writer.query('SET SESSION lock_wait_timeout=10');
            // z_options has not been scanned yet, but is already read-locked.
            write = writer.query("UPDATE z_options SET value='after interruption'");
            // Handle rejection immediately; await and assert completion after killing the reader.
            write.catch(() => {});
            let blocked = false;
            while (Date.now() < deadline) {
                const [processes] = await observer.query('SHOW FULL PROCESSLIST');
                blocked = processes.some(process => process.db === database
                    && process.Info?.startsWith('UPDATE z_options') && /Waiting for table/.test(process.State));
                if (blocked) break;
                await new Promise(resolve => setTimeout(resolve, 10));
            }
            expect(blocked).toBe(true);
            child.kill('SIGKILL');
            await exited;
            await write;
            expect(existsSync(baselinePath())).toBe(false);
            expect(statSync(rowFile).size).toBeGreaterThan(0);
        } finally {
            child.kill('SIGKILL');
            await exited;
            if (write) await write;
            await writer.end();
            await observer.end();
        }
        run('db-baseline', ['--table=posts', '--table=z_options']);
        expect(changes()).toEqual([]);
        expect(existsSync(baselinePath() + '.scan')).toBe(false);
        await connection.query("UPDATE z_options SET value='next edit'");
        expect(changes()).toEqual([{ type: 'database_change', table: 'z_options', action: 'update',
            key: values({ id: 1 }), before: values({ value: 'after interruption' }), after: values({ value: 'next edit' }) }]);
    }, 120000);
});
