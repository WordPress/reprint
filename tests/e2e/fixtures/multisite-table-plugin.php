<?php
/**
 * Plugin Name: Multisite table migration fixture
 * Description: Site-local tables with deliberately different keys and value types.
 */

// Fixture orders must never send mail to the addresses in their billing data.
add_filter('pre_wp_mail', '__return_true');
add_action('before_woocommerce_init', static function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

register_activation_hook(__FILE__, 'reprint_install_fixture_tables');

/** Activation creates real plugin tables; normal boot never repairs missing data. */
function reprint_install_fixture_tables() {
    global $wpdb;
    $prefix = $wpdb->prefix;
    $marker = 'site-' . get_current_blog_id();
    $schemas = array(
        'fixture_z_parents' => '(id bigint unsigned NOT NULL PRIMARY KEY, marker varchar(30) NOT NULL)',
        // The child sorts before its parent in the export. Keep the constraint,
        // even though the parent table does not exist when this DDL is applied.
        'fixture_a_children' => '(parent_id bigint unsigned NOT NULL, sequence_id int NOT NULL,
            `select` text, PRIMARY KEY (parent_id, sequence_id),
            FOREIGN KEY (parent_id) REFERENCES `' . $prefix . 'fixture_z_parents` (id))',
        'fixture_heap' => '(marker varchar(30), payload blob, optional_value text NULL)',
        'fixture_unique' => '(token varbinary(32) NOT NULL UNIQUE, marker varchar(30) NOT NULL)',
        'fixture_types' => '(id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            amount decimal(30,10), flags bit(9), status enum(\'new\',\'done\'), tags set(\'a\',\'b\'),
            document json, payload longblob, note longtext, `雪` varchar(50),
            doubled decimal(31,10) GENERATED ALWAYS AS (amount * 2) STORED)',
        'fixture_empty' => '(id int NOT NULL PRIMARY KEY, marker varchar(30))',
        'fixture_quote`tick' => '(id int NOT NULL PRIMARY KEY, marker varchar(30))',
    );
    foreach ($schemas as $suffix => $schema) {
        reprint_fixture_query('CREATE TABLE `' . str_replace('`', '``', $prefix . $suffix) . '` ' . $schema . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    reprint_fixture_query($wpdb->prepare("INSERT INTO `{$prefix}fixture_z_parents` VALUES (9007199254740993, %s)", $marker));
    // More than the dump reader's 250-row default batch, with a composite key.
    for ($sequence = 0; $sequence < 601; ++$sequence) {
        reprint_fixture_query($wpdb->prepare("INSERT INTO `{$prefix}fixture_a_children` VALUES (9007199254740993, %d, %s)", $sequence, $marker . '-' . $sequence));
    }
    for ($copy = 0; $copy < 3; ++$copy) {
        reprint_fixture_query($wpdb->prepare("INSERT INTO `{$prefix}fixture_heap` VALUES (%s, UNHEX('00ff275c00'), NULL)", $marker));
    }
    reprint_fixture_query($wpdb->prepare("INSERT INTO `{$prefix}fixture_heap` VALUES (%s, '', '')", $marker));
    reprint_fixture_query($wpdb->prepare("INSERT INTO `{$prefix}fixture_unique` VALUES (UNHEX('00ff'), %s), (UNHEX('00ff00'), %s)", $marker, $marker));
    reprint_fixture_query($wpdb->prepare("INSERT INTO `{$prefix}fixture_types`
        (id, amount, flags, status, tags, document, payload, note, `雪`)
        VALUES (9007199254740993, 12345678901234567890.1234567890, b'100000001', 'done', 'a,b',
            %s, REPEAT(UNHEX('00ff275c'), 300000), REPEAT(%s, 100000), %s)",
        wp_json_encode(array('marker' => $marker, 'nested' => array('quotes' => "\"\\雪"))),
        $marker . " 雪\n", '雪-' . $marker));
    reprint_fixture_query($wpdb->prepare('INSERT INTO `' . $prefix . 'fixture_quote``tick` VALUES (1, %s)', $marker));
}

/** Report the SQL failure rather than letting a half-built fixture pass. */
function reprint_fixture_query($sql) {
    global $wpdb;
    if ($wpdb->query($sql) === false) {
        throw new RuntimeException($wpdb->last_error);
    }
}
