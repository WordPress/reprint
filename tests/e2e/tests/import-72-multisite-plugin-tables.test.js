import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { cpSync, readFileSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import {
    apiRequest, runImporter, createTempDir, cleanupTempDir, createMysqlConnection,
    getSiteDir, getSiteUrl, getSiteSecret,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { convertToMultisite, runWp } from '../lib/multisite-setup.js';

const site = 'multisite-plugin-tables';
const sourceDatabase = 'e2e_multisite_plugin_tables';
const targetDatabase = 'e2e_multisite_plugin_tables_target';
const fixtures = join(import.meta.dirname, '../fixtures');
const mode = { multisite_mode: 'one-site-network-v1' };
const sourcePhp = process.env.E2E_EXPECTED_SERVER_PHP_VERSION || execFileSync(
    process.env.E2E_WP_CLI_PHP_BINARY || 'php', ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8' }
);

// WooCommerce 10.0.4 requires PHP 7.4 and WordPress 6.7, unlike Reprint itself.
// The pinned release keeps its schema and APIs stable across CI runs.
describe.skipIf(parseFloat(sourcePhp) < 7.4)('Migrate WooCommerce and site-local plugin tables', () => {
    let fixture;
    let directory;

    beforeAll(async () => {
        await ensureSite(site, {
            tablePrefix: 'network_', files: 'none',
            afterCreate: async (source) => {
                convertToMultisite(source, getSiteUrl(site));
                cpSync(join(fixtures, 'multisite-table-plugin.php'), join(source, 'wp-content/plugins/multisite-table-plugin.php'));
                runWp(source, ['plugin', 'install', 'woocommerce', '--version=10.0.4']);
                for (const path of ['/shop/', '/sibling/']) {
                    const url = new URL(path, getSiteUrl(site)).href;
                    runWp(source, ['plugin', 'activate', 'multisite-table-plugin.php', 'woocommerce'], url);
                    runWp(source, ['option', 'update', 'woocommerce_custom_orders_table_enabled', 'yes'], url);
                    runWp(source, ['option', 'update', 'woocommerce_custom_orders_table_data_sync_enabled', 'no'], url);
                    runWp(source, ['eval-file', join(fixtures, 'multisite-woocommerce.php')], url);
                }
                const connection = await createMysqlConnection(sourceDatabase);
                try {
                    // These names overlap site 7 without being inside its prefix.
                    await connection.query('CREATE TABLE network_70_fixture_heap (secret text)');
                    await connection.query("INSERT INTO network_70_fixture_heap VALUES ('site-70-private')");
                } finally { await connection.end(); }
            },
        });
        fixture = JSON.parse(readFileSync(join(getSiteDir(site), '.multisite-layer.json'), 'utf8'));
    });

    afterAll(async () => {
        const connection = await createMysqlConnection();
        try { await connection.query(`DROP DATABASE IF EXISTS \`${targetDatabase}\``); }
        finally { await connection.end(); }
        if (directory) cleanupTempDir(directory);
    });

    it('keeps custom schemas, rows and HPOS orders while leaving sibling tables behind', async () => {
        const source = await createMysqlConnection(sourceDatabase);
        const before = await inspectFixtureTables(source);
        directory = createTempDir('e2e-multisite-plugin-tables');
        const documentRoot = join(directory, 'site');
        const targetUrl = 'http://localhost:9181';
        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${targetDatabase}\``);
        await connection.query(`CREATE DATABASE \`${targetDatabase}\``);
        await connection.end();
        try {
            const result = runImporter(`${fixture.sites[7].url}/?reprint-api`, directory, 'pull', {
                secret: getSiteSecret(site), skipPreflight: true, autoResume: false,
                timeout: 240000, wallTimeout: 300000,
                extraArgs: [
                    '--target-engine=mysql', '--target-host=127.0.0.1',
                    '--target-user=e2e_admin', '--target-pass=e2e_password', `--target-db=${targetDatabase}`,
                    `--new-site-url=${targetUrl}`, '--site-admin=shop-member',
                    '--runtime=php-builtin', '--start-runtime=none', `--flatten-to=${documentRoot}`,
                ],
            });
            assert.equal(result.exitCode, 0, result.stderr + '\n' + result.stdout);

            const target = await createMysqlConnection(targetDatabase);
            try {
                assert.deepEqual(await inspectFixtureTables(target), before, 'Preserve table names, schemas, duplicate rows and every value');
                // mysql2 normally returns BIGINT as a JS number. Read these
                // IDs as strings so a value above 2^53 cannot be rounded away.
                for (const table of ['network_7_fixture_z_parents', 'network_7_fixture_types']) {
                    const [[row]] = await target.query(`SELECT CAST(id AS CHAR) AS id FROM ${table}`);
                    assert.equal(row.id, '9007199254740993');
                }
                const [tables] = await target.query('SHOW TABLES');
                const names = tables.map(row => Object.values(row)[0]);
                assert.ok(names.includes('network_7_wc_orders'));
                assert.ok(names.includes('network_7_woocommerce_order_items'));
                assert.ok(names.includes('network_7_actionscheduler_actions'));
                assert.ok(names.every(name => !name.startsWith('network_8_') && !name.startsWith('network_70_')));
                assert.ok(names.every(name => !name.endsWith('reprint_users')));
                const [[order]] = await target.query('SELECT total_amount, billing_email FROM network_7_wc_orders');
                assert.equal(Number(order.total_amount), 24.68);
                assert.equal(order.billing_email, 'customer@example.test');
                const [[placeholder]] = await target.query("SELECT post_type FROM network_7_posts WHERE ID=(SELECT id FROM network_7_wc_orders LIMIT 1)");
                assert.equal(placeholder.post_type, 'shop_order_placehold', 'Order data must come from HPOS, not a synchronized post');
                await assert.rejects(
                    target.query("INSERT INTO network_7_fixture_a_children VALUES (123, 0, 'missing parent')"),
                    error => error.code === 'ER_NO_REFERENCED_ROW_2',
                    'The imported foreign key must still reject a missing parent'
                );
            } finally { await target.end(); }

            assert.ok(existsSync(join(documentRoot, 'wp-content/plugins/woocommerce/woocommerce.php')));
            assert.ok(existsSync(join(documentRoot, 'wp-content/plugins/multisite-table-plugin.php')));
            // Boot the copied WordPress and plugins. Read the migrated order via
            // WooCommerce, then create a new one to check the adopted table prefix.
            const inspection = JSON.parse(runWp(documentRoot, ['eval', `
                $order = wc_get_order(get_option('reprint_fixture_order_id'));
                $product = wc_get_product(get_option('reprint_fixture_product_id'));
                $items = array_values($order->get_items());
                $new = wc_create_order();
                $new->add_product($product, 1);
                $new->calculate_totals();
                $new->save();
                echo wp_json_encode(array(
                    'multisite' => is_multisite(), 'prefix' => $GLOBALS['wpdb']->prefix,
                    'hpos' => \\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled(),
                    'marker' => $order->get_meta('migration_marker'), 'total' => $order->get_total(),
                    'city' => $order->get_billing_city(), 'name' => $product->get_name(),
                    'item_count' => count($items), 'quantity' => $items[0]->get_quantity(),
                    'item_product_id' => $items[0]->get_product_id(), 'product_id' => $product->get_id(),
                    'customer' => get_userdata($order->get_customer_id())->user_login,
                    'new_order_id' => $new->get_id(), 'old_order_id' => $order->get_id(),
                    'new_total' => $new->get_total(), 'fixture_loaded' => function_exists('reprint_install_fixture_tables'),
                ));
            `], targetUrl));
            assert.equal(inspection.multisite, false);
            assert.equal(inspection.prefix, 'network_7_');
            assert.equal(inspection.hpos, true);
            assert.equal(inspection.marker, 'site-7');
            assert.equal(inspection.total, '24.68');
            assert.equal(inspection.city, 'Warsaw');
            assert.equal(inspection.name, 'Product from site 7');
            assert.equal(inspection.customer, 'shop-member');
            assert.equal(inspection.item_count, 1);
            assert.equal(inspection.quantity, 2);
            assert.equal(inspection.item_product_id, inspection.product_id);
            assert.ok(inspection.new_order_id > inspection.old_order_id);
            assert.equal(inspection.new_total, '12.34');
            assert.equal(inspection.fixture_loaded, true);
            const targetAfterBoot = await createMysqlConnection(targetDatabase);
            try {
                const [[saved]] = await targetAfterBoot.query('SELECT total_amount FROM network_7_wc_orders WHERE id=?', [inspection.new_order_id]);
                assert.equal(Number(saved.total_amount), 12.34, 'The new order must reach the copied HPOS table');
            } finally { await targetAfterBoot.end(); }
            assert.deepEqual(await inspectFixtureTables(source), before, 'The pull must not change source plugin tables');
        } finally { await source.end(); }
    }, 300000);

    it('still rejects an unknown unnumbered table instead of guessing its shared rows', async () => {
        const connection = await createMysqlConnection(sourceDatabase);
        try {
            await connection.query('CREATE TABLE network_plugin_shared (site_id int, secret text)');
            await connection.query("INSERT INTO network_plugin_shared VALUES (7, 'selected'), (8, 'sibling-private')");
            const response = await apiRequest(site, 'preflight', mode, { url: `${fixture.sites[7].url}/?reprint-api` });
            assert.equal(response.status, 400);
            assert.ok(JSON.stringify(response.json).includes('No multisite migration rule exists for table network_plugin_shared.'), JSON.stringify(response.json));
        } finally {
            await connection.query('DROP TABLE IF EXISTS network_plugin_shared');
            await connection.end();
        }
    });
});

/** Small fixture only: compare full schemas and values, including BLOB bytes and duplicate rows. */
async function inspectFixtureTables(connection) {
    const [tables] = await connection.query('SHOW TABLES');
    const result = {};
    for (const name of tables.map(row => Object.values(row)[0]).filter(name => name.startsWith('network_7_fixture_')).sort()) {
        const identifier = '`' + name.replaceAll('`', '``') + '`';
        const [[schema]] = await connection.query(`SHOW CREATE TABLE ${identifier}`);
        const [rows] = await connection.query(`SELECT * FROM ${identifier}`);
        result[name] = { schema: schema['Create Table'], rows: rows.map(row => JSON.stringify(row)).sort() };
    }
    assert.equal(Object.keys(result).length, 7, 'The fixture must contain all seven table layouts');
    return result;
}
