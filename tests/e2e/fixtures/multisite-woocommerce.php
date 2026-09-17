<?php
// Creates orders through WooCommerce on each source site, with HPOS enabled
// and post-table synchronization disabled. A posts-only export loses the order.
if (!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
    || !wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class)->create_database_tables()) {
    throw new RuntimeException('The fixture requires WooCommerce custom order tables.');
}
$product = new WC_Product_Simple();
$product->set_name('Product from site ' . get_current_blog_id());
$product->set_sku('same-sku-on-each-site');
$product->set_regular_price('12.34');
$product->set_status('publish');
$product->save();

$order = wc_create_order(array('customer_id' => get_user_by('login', 'shop-member')->ID));
if (is_wp_error($order)) {
    throw new RuntimeException($order->get_error_message());
}
$order->add_product($product, 2);
$order->set_address(array('first_name' => 'Site ' . get_current_blog_id(), 'email' => 'customer@example.test', 'city' => 'Warsaw'), 'billing');
$order->add_meta_data('migration_marker', 'site-' . get_current_blog_id());
$order->calculate_totals();
$order->save();
global $wpdb;
if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders") !== 1) {
    throw new RuntimeException('The fixture must save one real HPOS order before migration.');
}
update_option('reprint_fixture_order_id', $order->get_id());
update_option('reprint_fixture_product_id', $product->get_id());
