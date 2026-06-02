<?php
declare(strict_types=1);

/**
 * Uninstall routine for Sellmo POS Integration.
 *
 * Runs when the plugin is fully deleted via the WordPress admin.
 * Drops the sync log table and removes all sellmo_wc_* options.
 * This file is NOT loaded on deactivation — only on full uninstall.
 *
 * @package Sellmo_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 1. Drop the sync log table.
$table = $wpdb->prefix . 'sellmo_sync_log';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name constructed from trusted $wpdb->prefix.
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

// Drop the product mapping table.
$mapping_table = $wpdb->prefix . 'sellmo_product_mapping';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name constructed from trusted $wpdb->prefix.
$wpdb->query( "DROP TABLE IF EXISTS `{$mapping_table}`" );

// 2. Delete all sellmo_wc_* options.
// Underscores in the LIKE value are escaped with a backslash so they match
// literally rather than as single-character wildcards.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'sellmo\_wc\_%'
	)
);

// 3. Delete all plugin transients (products, inventory, and category page caches).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_sellmo_wc_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_sellmo_wc_' ) . '%'
	)
);

// 4. Remove any remaining scheduled cron events.
wp_clear_scheduled_hook( 'sellmo_wc_stock_sync' );
wp_clear_scheduled_hook( 'sellmo_wc_product_sync' );
