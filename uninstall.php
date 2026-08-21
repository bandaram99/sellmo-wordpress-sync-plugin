<?php
declare(strict_types=1);

/**
 * Uninstall routine for the POS Integration plugin.
 *
 * Runs when the plugin is fully deleted via the WordPress admin.
 * Drops the plugin's custom tables and removes all of its options and
 * transients. This file is NOT loaded on deactivation — only on full uninstall.
 *
 * All identifiers are derived from the configured brand slug (POS_Brand) so
 * uninstall targets the SAME keys the running plugin created, whatever brand
 * it was configured as.
 *
 * @package Sellmo_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Plugin constants are not defined during uninstall — load the brand engine
// directly so table/option/cron names resolve to the configured slug.
require_once __DIR__ . '/includes/class-pos-brand.php';
require_once __DIR__ . '/includes/class-cleanup.php';

global $wpdb;

// 0. Delete every product the plugin created (with variations, imported images,
// and created categories) and unlink any merchant products it only mapped.
// Runs before the tables are dropped — it reads post/term meta, not the tables.
Sellmo_Cleanup::purge_catalogue();

// 1. Drop the plugin's custom tables.
foreach ( [ 'sync_log', 'product_mapping', 'product_variants', 'product_mapper' ] as $suffix ) {
	$table = POS_Brand::table( $suffix );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name derived from sanitised brand slug and trusted $wpdb->prefix.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

// 2. Delete all {slug}_wc_* options. esc_like() keeps underscores literal
// (including any in the slug itself) rather than treating them as wildcards.
$option_like = $wpdb->esc_like( POS_Brand::option( '' ) ) . '%';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$option_like
	)
);

// 3. Delete all plugin transients (products, inventory, and category caches).
$transient_like = $wpdb->esc_like( POS_Brand::transient( '' ) );
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_' ) . $transient_like . '%',
		$wpdb->esc_like( '_transient_timeout_' ) . $transient_like . '%'
	)
);

// 4. Remove any remaining scheduled cron events.
wp_clear_scheduled_hook( POS_Brand::cron_hook( 'stock_sync' ) );
wp_clear_scheduled_hook( POS_Brand::cron_hook( 'product_sync' ) );
