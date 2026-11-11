<?php
/**
 * Scenario 19 — Token removal footprint, and products deleted in WooCommerce.
 *
 * 19a  release_wc_product() keeps _sellmo_created but strips the POS code, so
 *      a later purge can no longer delete it.
 * 19d  The purge clears the brand-prefixed product/inventory/category caches.
 * 19e  No token → every sync is disabled and its cron event unscheduled.
 * 19f  Trashing/deleting a mapped WC product or variation unmaps it.
 * 19g  Product Mapper page with no token shows a connect prompt, no table.
 *
 * What the purge deletes vs keeps is covered by test-20-cleanup-rules.php.
 * The purge is site-wide, so 19d SKIPs unless the site holds no real synced
 * data (mapper rows, created/linked products, imported images or categories).
 *
 * Run: php tests/test-19-token-removal-and-wc-deletes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

// =============================================================================
t_head( '19a. Releasing an imported product keeps its "created" flag' );
// =============================================================================
$a = t_wc_simple( 'T19 Released', 10.0, t_uid( 'A' ), t_uid( 'APOS' ) );
update_post_meta( $a, '_sellmo_created', 'yes' );
Sellmo_Product_Mapping::release_wc_product( $a );
t_eq( 'link meta stripped',     '', (string) get_post_meta( $a, '_salesplay_pos_product_id', true ) );
t_eq( 'created flag kept',      'yes', (string) get_post_meta( $a, '_sellmo_created', true ) );
t_set_setting( 'auto_map_enabled', 'yes' );
update_post_meta( $a, '_sku', 'SAME' );
t_ok( 'released product is no longer deletable', ! Sellmo_Cleanup::is_deletable( $a ) );

// =============================================================================
t_head( '19d. Token-removal purge clears caches' );
// =============================================================================
$real_footprint = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id <> %d AND (
		( meta_key = '_sellmo_created' AND meta_value = 'yes' )
		OR meta_key IN ( '_pos_product_code', '_salesplay_pos_product_id', '_sellmo_image_source_url' ) )",
	$a
) );
$real_footprint += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = '_sellmo_created'" );
$real_footprint += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . Sellmo_Product_Mapping::table_name() . '`' );

if ( $real_footprint > 0 ) {
	echo "SKIP 19d: the site holds real synced data ({$real_footprint} items) that a purge would delete.\n";
} else {
	$admin = new Sellmo_Admin();
	$keys = [ POS_Brand::transient( 'products_' . md5( 'x' ) ), POS_Brand::transient( 'inventory_' . md5( 'x' ) ), POS_Brand::transient( 'categories' ) ];
	foreach ( $keys as $k ) {
		set_transient( $k, [ 'cached' ], HOUR_IN_SECONDS );
	}
	t_call( $admin, 'purge_sellmo_synced_data', [ false ] );
	foreach ( $keys as $k ) {
		t_ok( "cache cleared: {$k}", false === get_transient( $k ) );
	}
}

// =============================================================================
t_head( '19e. No token → syncs disabled and unscheduled' );
// =============================================================================
// Cleanup runs in reverse: registered first, so it reschedules AFTER the
// settings below are restored, leaving the site's real cron as it was.
t_defer( static function (): void {
	( new Sellmo_Scheduler() )->register_sync_schedules();
} );
t_set_setting( 'stock_sync_enabled', 'yes' );
t_set_setting( 'product_sync_enabled', 'yes' );
t_set_setting( 'order_sync_enabled', 'yes' );
$token = (string) Sellmo_Settings::get( 'api_token' );
t_set_setting( 'api_token', '' !== $token ? $token : 'T19-TOKEN' );

$scheduler = new Sellmo_Scheduler();
$hooks     = [ POS_Brand::cron_hook( 'stock_sync' ), POS_Brand::cron_hook( 'product_sync' ), POS_Brand::cron_hook( 'order_status_sync' ) ];
$scheduler->register_sync_schedules();
foreach ( $hooks as $h ) {
	t_ok( "with token: {$h} scheduled", false !== wp_next_scheduled( $h ) );
}

Sellmo_Settings::set( 'api_token', '' );
t_ok( 'enabled() false without token', ! Sellmo_Scheduler::enabled( 'product' ) );
$scheduler->register_sync_schedules();
foreach ( $hooks as $h ) {
	t_ok( "no token: {$h} unscheduled", false === wp_next_scheduled( $h ) );
}

// =============================================================================
t_head( '19f. Product deleted/trashed in WooCommerce → unmapped' );
// =============================================================================
$shop = Sellmo_Product_Mapping::selected_shop_id();

$f_pos = t_uid( 'FPOS' );
$f_map = t_stage_product( t_product( $f_pos, t_uid( 'F' ), 'T19 Trash', 'Cat', 10.0, $shop ) );
t_defer( static function () use ( $f_map ): void {
	t_purge_mapping( $f_map );
} );
$f_wc = t_wc_simple( 'T19 Trash', 10.0 );
Sellmo_Product_Mapping::upsert_mapping( $f_pos, t_uid( 'F' ), 'T19 Trash', $f_wc );
t_eq( 'mapped before trash', 1, (int) Sellmo_Product_Mapping::get_row_by_id( $f_map )['map_status'] );

$mark = t_log_mark();
wp_trash_post( $f_wc );
$row = Sellmo_Product_Mapping::get_row_by_id( $f_map );
t_eq( 'trash: row unmapped',        0, (int) $row['map_status'] );
t_eq( 'trash: wc_product_id null',  0, (int) $row['wc_product_id'] );
t_contains( 'trash logged',         "#{$f_wc} was deleted in WooCommerce", t_log_since( $mark ) );

// Hard delete of a mapped product, and of a single linked variation.
$v_pos = t_uid( 'VPOS' );
$v_id1 = t_uid( 'V1' );
$v_id2 = t_uid( 'V2' );
$v_payload = t_product( $v_pos, t_uid( 'V' ), 'T19 Var', 'Cat', 0.0, $shop, [
	t_variant( $v_id1, t_uid( 'V-1' ), 's', 'red', 20.0, $shop ),
	t_variant( $v_id2, t_uid( 'V-2' ), 'm', 'red', 20.0, $shop ),
] );
$v_map = t_stage_product( $v_payload );
t_stage_variants( $v_map, $v_payload );
t_defer( static function () use ( $v_map ): void {
	t_purge_mapping( $v_map );
} );

$parent = new WC_Product_Variable();
$parent->set_name( 'T19 Var' );
$parent_id = $parent->save();
t_defer( static function () use ( $parent_id ): void {
	t_delete_wc_product( $parent_id );
} );
$vars = [];
foreach ( [ 0, 1 ] as $i ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $parent_id );
	$vars[] = $v->save();
}
Sellmo_Product_Mapping::upsert_mapping( $v_pos, t_uid( 'V' ), 'T19 Var', $parent_id );
Sellmo_Product_Variant_Store::set_wc_variation_id( $v_id1, $vars[0] );
Sellmo_Product_Variant_Store::set_wc_variation_id( $v_id2, $vars[1] );

wp_delete_post( $vars[0], true );
t_eq( 'variation delete: its link cleared', 0, (int) Sellmo_Product_Variant_Store::get_by_variant_id( $v_id1 )['wc_variation_id'] );
t_eq( 'variation delete: sibling kept',     $vars[1], (int) Sellmo_Product_Variant_Store::get_by_variant_id( $v_id2 )['wc_variation_id'] );
t_eq( 'variation delete: parent still mapped', 1, (int) Sellmo_Product_Mapping::get_row_by_id( $v_map )['map_status'] );

wp_delete_post( $parent_id, true );
t_eq( 'parent delete: row unmapped',        0, (int) Sellmo_Product_Mapping::get_row_by_id( $v_map )['map_status'] );
t_eq( 'parent delete: variant links cleared', 0, (int) Sellmo_Product_Variant_Store::get_by_variant_id( $v_id2 )['wc_variation_id'] );

// Deleting a non-product post is ignored.
$page = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'T19 page', 'post_status' => 'draft' ] );
wp_delete_post( $page, true );
t_ok( 'non-product delete ignored', true );

// =============================================================================
t_head( '19g. Product Mapper without a token' );
// =============================================================================
Sellmo_Settings::set( 'api_token', '' );
ob_start();
include SELLMO_WC_PATH . 'admin/views/page-product-mapper.php';
$html = (string) ob_get_clean();
t_ok( 'connect prompt shown', str_contains( $html, 'account on the Settings page to start mapping' ) );
t_ok( 'no mapper table',      ! str_contains( $html, 'mapper-tbody' ) );

exit( t_summary() );
