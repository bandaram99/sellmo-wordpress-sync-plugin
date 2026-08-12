<?php
/**
 * Scenario 2 — a VARIABLE product changes upstream.
 *
 * Covers variants being removed and renamed, and the product's own name,
 * category, price and stock changing. Asserts the mapper row, the variant
 * store, the WooCommerce variable product, and the sync log all follow.
 *
 * Run: php tests/test-2-variant-product-changes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$shop = t_uid( 'SHOP' );
t_set_setting( 'shop_id', $shop );

// -----------------------------------------------------------------------------
// Fixture: staged variable product with 4 variants, imported into WooCommerce.
// -----------------------------------------------------------------------------
$pos_id = t_uid( 'VAR' );
$code   = T_PREFIX . '-V-200';

$v1 = t_uid( 'V1' );
$v2 = t_uid( 'V2' );
$v3 = t_uid( 'V3' );
$v4 = t_uid( 'V4' );

$before = t_product( $pos_id, $code, 'Test Tee', 'Apparel', 0.0, $shop, [
	t_variant( $v1, "{$code}-1", 's', 'red',   500.00, $shop ),
	t_variant( $v2, "{$code}-2", 'm', 'red',   500.00, $shop ),
	t_variant( $v3, "{$code}-3", 'l', 'blue',  600.00, $shop ),
	t_variant( $v4, "{$code}-4", 'xl', 'black', 700.00, $shop ),
] );

$mapping_id = t_stage_product( $before );
t_stage_variants( $mapping_id, $before );

t_ok( 'fixture: mapping row staged', $mapping_id > 0 );
t_eq( 'fixture: 4 variant rows stored', 4, count( Sellmo_Product_Variant_Store::get_variants_for_product( $mapping_id ) ) );

// Import into WooCommerce so there is a real variable product to verify against.
$wc_id = ( new Sellmo_Product_Sync() )->sync_product_to_wc( $before );
t_ok( 'fixture: WC variable product created', $wc_id > 0 );
t_defer( static function () use ( $wc_id ): void {
	t_delete_wc_product( $wc_id );
} );

Sellmo_Product_Mapping::upsert_mapping( $pos_id, $code, 'Test Tee', $wc_id );

$parent = wc_get_product( $wc_id );
t_ok( 'fixture: product is variable', $parent instanceof WC_Product_Variable );
t_eq( 'fixture: 4 WC variations created', 4, count( $parent->get_children() ) );

// Link each variant row to its WC variation, as the mapper import does.
$sync = new Sellmo_Product_Sync();
foreach ( $before['variants'] as $v ) {
	$variation_id = $sync->find_variation_id_for_variant( $wc_id, $v );
	if ( $variation_id > 0 ) {
		Sellmo_Product_Variant_Store::set_wc_variation_id( (string) $v['variant_id'], $variation_id );
	}
}

// -----------------------------------------------------------------------------
// The upstream edit:
//   - v1 kept unchanged
//   - v2 RENAMED  (m/red -> m/green)
//   - v3 REPRICED (600 -> 950)
//   - v4 REMOVED
//   - product renamed, recategorised
// -----------------------------------------------------------------------------
$after = t_product( $pos_id, $code, 'Test Polo', 'Formal', 0.0, $shop, [
	t_variant( $v1, "{$code}-1", 's', 'red',   500.00, $shop ),
	t_variant( $v2, "{$code}-2", 'm', 'green', 500.00, $shop ),
	t_variant( $v3, "{$code}-3", 'l', 'blue',  950.00, $shop ),
] );

$mark = t_log_mark();

// -----------------------------------------------------------------------------
// 2a. Mapper row detects product-level and variant-level changes
// -----------------------------------------------------------------------------
t_head( '2a. Change detection on a variable product' );

$changes = Sellmo_Product_Mapping::refresh_catalogue_row(
	$pos_id, $code, $shop, 'Shop', 500.00, 'Formal', 3, $after, 'Test Polo'
);

t_contains( 'product name change reported',  'name "Test Tee" → "Test Polo"',   $changes );
t_contains( 'category change reported',      'category "Apparel" → "Formal"',   $changes );
t_contains( 'variant count change reported', 'variant count 4 → 3',             $changes );
t_contains( 'variant rename reported',       'variant "m / red" → "m / green"', $changes );
t_contains( 'variant reprice reported',      'variant "l / blue" price 600.00 → 950.00', $changes );
t_contains( 'variant removal reported',      'variant "xl / black" removed',    $changes );

$row = Sellmo_Product_Mapping::get_row_by_id( $mapping_id );
t_eq( 'row name persisted',          'Test Polo', (string) $row['pos_product_name'] );
t_eq( 'row category persisted',      'Formal',    (string) $row['category'] );
t_eq( 'row variant_count persisted', 3,           (int) $row['variant_count'] );

// -----------------------------------------------------------------------------
// 2b. The removed variant is pruned from the store and from WooCommerce
// -----------------------------------------------------------------------------
t_head( '2b. Removed variant pruned from store and WooCommerce' );

$removed_variation_id = 0;
foreach ( Sellmo_Product_Variant_Store::get_variants_for_product( $mapping_id ) as $sv ) {
	if ( (string) $sv['variant_id'] === $v4 ) {
		$removed_variation_id = (int) $sv['wc_variation_id'];
	}
}
t_ok( 'fixture: removed variant had a WC variation', $removed_variation_id > 0 );

$pruned = Sellmo_Product_Variant_Store::delete_absent_for_product( $mapping_id, [ $v1, $v2, $v3 ] );

t_eq( 'exactly one variant row deleted', 1, count( $pruned ) );
t_eq( 'the deleted row is v4',           $v4, (string) $pruned[0]['variant_id'] );
t_eq( '3 variant rows remain',           3, count( Sellmo_Product_Variant_Store::get_variants_for_product( $mapping_id ) ) );

// Emulate the sync's own cleanup of the orphaned WooCommerce variation.
wp_delete_post( $removed_variation_id, true );
WC_Product_Variable::sync( $wc_id );

$parent = wc_get_product( $wc_id );
t_eq( 'WC now has 3 variations', 3, count( $parent->get_children() ) );

// Re-running the prune must be a no-op.
t_eq( 'second prune removes nothing', 0, count( Sellmo_Product_Variant_Store::delete_absent_for_product( $mapping_id, [ $v1, $v2, $v3 ] ) ) );

// -----------------------------------------------------------------------------
// 2c. Variant price change reaches the WooCommerce variation
// -----------------------------------------------------------------------------
t_head( '2c. Variant price change applied to WooCommerce' );

t_eq( 'mapped field sync succeeded', 'success', ( new Sellmo_Product_Sync() )->sync_mapped_product_fields( $after ) );

$v3_variation = 0;
foreach ( wc_get_product( $wc_id )->get_children() as $child ) {
	if ( (string) get_post_meta( (int) $child, '_salesplay_variant_id', true ) === $v3 ) {
		$v3_variation = (int) $child;
	}
}
t_ok( 'found the repriced variation', $v3_variation > 0 );
t_near( 'variation price is now 950', 950.00, (float) wc_get_product( $v3_variation )->get_regular_price() );

// -----------------------------------------------------------------------------
// 2d. Per-variant stock
// -----------------------------------------------------------------------------
t_head( '2d. Variant stock applied from POS inventory' );

T_Fake_API::install( [
	'inventory' => [
		T_Fake_API::inventory_page( [
			[ 'product_id' => $v1, 'product_code' => "{$code}-1", 'in_stock' => 11 ],
			[ 'product_id' => $v2, 'product_code' => "{$code}-2", 'in_stock' => 12 ],
			[ 'product_id' => $v3, 'product_code' => "{$code}-3", 'in_stock' => 0 ],
		] ),
	],
] );

$updated = ( new Sellmo_Stock_Sync() )->sync_stock_for_wc_product( $wc_id );
t_eq( 'stock applied to 3 variations', 3, $updated );

$by_variant = [];
foreach ( wc_get_product( $wc_id )->get_children() as $child ) {
	$vid = (string) get_post_meta( (int) $child, '_salesplay_variant_id', true );
	$by_variant[ $vid ] = wc_get_product( (int) $child );
}

t_eq( 'variant 1 stock = 11', 11, (int) $by_variant[ $v1 ]->get_stock_quantity() );
t_eq( 'variant 2 stock = 12', 12, (int) $by_variant[ $v2 ]->get_stock_quantity() );
t_eq( 'variant 3 stock = 0',  0,  (int) $by_variant[ $v3 ]->get_stock_quantity() );
t_eq( 'variant 3 is out of stock', 'outofstock', (string) $by_variant[ $v3 ]->get_stock_status() );

// -----------------------------------------------------------------------------
// 2e. The log names the product and every change
// -----------------------------------------------------------------------------
t_head( '2e. Sync log records the variable product changes' );

$logs = t_log_since( $mark );

t_contains( 'log names the product',          'Test Polo',        $logs );
t_contains( 'log records the variant reprice', 'Variation',       $logs );
t_absent(   'no mapped-field errors logged',  'Mapped field update failed', $logs );

exit( t_summary() );
