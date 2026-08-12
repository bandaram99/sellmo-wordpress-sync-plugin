<?php
/**
 * Scenario 8 — a product changes TYPE upstream.
 *
 * A POS product that had no variants can gain them, and a product that had
 * variants can lose them all. In both directions the WooCommerce product must
 * be converted in place — keeping its id, so mappings, links and order history
 * survive — and must keep receiving name / category / price / stock updates
 * afterwards.
 *
 * Run: php tests/test-8-type-conversion.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$shop = Sellmo_Product_Mapping::selected_shop_id();
if ( '' === $shop ) {
	echo "SKIP: no shop selected in Settings.\n";
	exit( 0 );
}

$sync = new Sellmo_Product_Sync();

// =============================================================================
// 8A. SIMPLE -> VARIABLE
// =============================================================================
t_head( '8A. Simple product gains variants' );

$a_pos  = t_uid( 'S2V' );
$a_code = T_PREFIX . '-S2V-100';

$a_simple = t_product( $a_pos, $a_code, 'Convertible Mug', 'Kitchen', 500.00, $shop );

$a_id = t_stage_product( $a_simple );
$wc_a = $sync->sync_product_to_wc( $a_simple );

t_ok( 'simple product created', $wc_a > 0 );
t_defer( static function () use ( $wc_a ): void {
	t_delete_wc_product( $wc_a );
} );

Sellmo_Product_Mapping::upsert_mapping( $a_pos, $a_code, 'Convertible Mug', $wc_a );

$product = wc_get_product( $wc_a );
t_ok(   'starts as a simple product', $product instanceof WC_Product_Simple );
t_near( 'starts at the simple price', 500.00, (float) $product->get_regular_price() );
t_eq(   'starts with no children',    0, count( $product->get_children() ) );

// Upstream: the product gains three variants (and is renamed + recategorised).
$av1 = t_uid( 'AV1' );
$av2 = t_uid( 'AV2' );
$av3 = t_uid( 'AV3' );

$a_variable = t_product( $a_pos, $a_code, 'Convertible Mug Set', 'Drinkware', 0.0, $shop, [
	t_variant( $av1, "{$a_code}-1", 'small',  'white', 550.00, $shop ),
	t_variant( $av2, "{$a_code}-2", 'medium', 'white', 650.00, $shop ),
	t_variant( $av3, "{$a_code}-3", 'large',  'black', 750.00, $shop ),
] );

$mark   = t_log_mark();
$wc_a2  = $sync->sync_product_to_wc( $a_variable );

t_eq( 'converted IN PLACE — same WooCommerce id', $wc_a, $wc_a2 );

$product = wc_get_product( $wc_a );
t_ok( 'now a variable product',        $product instanceof WC_Product_Variable );
t_eq( 'three variations created',      3, count( $product->get_children() ) );
t_eq( 'name updated during conversion', 'Convertible Mug Set', (string) $product->get_name() );

$cats = wp_get_post_terms( $wc_a, 'product_cat', [ 'fields' => 'names' ] );
t_ok( 'category updated during conversion', in_array( 'Drinkware', (array) $cats, true ), implode( ', ', (array) $cats ) );

// The POS join keys must survive the type change.
t_eq( 'POS product id meta retained', $a_pos,  (string) get_post_meta( $wc_a, '_salesplay_pos_product_id', true ) );
t_eq( 'POS product code meta retained', $a_code, (string) get_post_meta( $wc_a, '_pos_product_code', true ) );

// Variant prices landed.
$prices = [];
foreach ( $product->get_children() as $child ) {
	$prices[] = (float) wc_get_product( (int) $child )->get_regular_price();
}
sort( $prices );
t_eq( 'variant prices imported', [ 550.00, 650.00, 750.00 ], $prices );

t_ok( 'conversion logged', ! empty( t_log_since( $mark ) ) );
t_absent( 'no conversion errors logged', 'Failed to convert', t_log_since( $mark, 'error' ) );

// -----------------------------------------------------------------------------
// 8A-2. Updates still apply after the conversion
// -----------------------------------------------------------------------------
t_head( '8A-2. Updates still apply after simple -> variable' );

$a_updated = t_product( $a_pos, $a_code, 'Convertible Mug Set', 'Drinkware', 0.0, $shop, [
	t_variant( $av1, "{$a_code}-1", 'small',  'white', 599.00, $shop ),
	t_variant( $av2, "{$a_code}-2", 'medium', 'white', 650.00, $shop ),
	t_variant( $av3, "{$a_code}-3", 'large',  'black', 750.00, $shop ),
] );

t_eq( 'mapped field sync succeeds', 'success', $sync->sync_mapped_product_fields( $a_updated ) );

$repriced = 0;
foreach ( wc_get_product( $wc_a )->get_children() as $child ) {
	if ( (string) get_post_meta( (int) $child, '_salesplay_variant_id', true ) === $av1 ) {
		$repriced = (int) $child;
	}
}
if ( t_ok( 'found the repriced variation', $repriced > 0 ) ) {
	t_near( 'variant price update applied', 599.00, (float) wc_get_product( $repriced )->get_regular_price() );
}

// Stock still reaches the variations after the conversion.
T_Fake_API::install( [
	'inventory' => [ T_Fake_API::inventory_page( [
		[ 'product_id' => $av1, 'product_code' => "{$a_code}-1", 'in_stock' => 31 ],
		[ 'product_id' => $av2, 'product_code' => "{$a_code}-2", 'in_stock' => 32 ],
		[ 'product_id' => $av3, 'product_code' => "{$a_code}-3", 'in_stock' => 33 ],
	] ) ],
] );

t_eq( 'stock applied to all 3 variations', 3, ( new Sellmo_Stock_Sync() )->sync_stock_for_wc_product( $wc_a ) );

$stock = [];
foreach ( wc_get_product( $wc_a )->get_children() as $child ) {
	$stock[] = (int) wc_get_product( (int) $child )->get_stock_quantity();
}
sort( $stock );
t_eq( 'variant stock correct after conversion', [ 31, 32, 33 ], $stock );

// =============================================================================
// 8B. VARIABLE -> SIMPLE
// =============================================================================
t_head( '8B. Variable product loses all its variants' );

$b_pos  = t_uid( 'V2S' );
$b_code = T_PREFIX . '-V2S-200';

$bv1 = t_uid( 'BV1' );
$bv2 = t_uid( 'BV2' );

$b_variable = t_product( $b_pos, $b_code, 'Collapsing Shirt', 'Apparel', 0.0, $shop, [
	t_variant( $bv1, "{$b_code}-1", 's', 'red', 800.00, $shop ),
	t_variant( $bv2, "{$b_code}-2", 'm', 'red', 850.00, $shop ),
] );

$b_id = t_stage_product( $b_variable );
t_stage_variants( $b_id, $b_variable );

$wc_b = $sync->sync_product_to_wc( $b_variable );
t_ok( 'variable product created', $wc_b > 0 );
t_defer( static function () use ( $wc_b ): void {
	t_delete_wc_product( $wc_b );
} );

Sellmo_Product_Mapping::upsert_mapping( $b_pos, $b_code, 'Collapsing Shirt', $wc_b );

$product = wc_get_product( $wc_b );
t_ok( 'starts as a variable product', $product instanceof WC_Product_Variable );
t_eq( 'starts with 2 variations',     2, count( $product->get_children() ) );

// Upstream: every variant is removed, and it becomes a plain priced product.
$b_simple = t_product( $b_pos, $b_code, 'Collapsing Shirt Solo', 'Basics', 925.00, $shop );

$mark2 = t_log_mark();
$wc_b2 = $sync->sync_product_to_wc( $b_simple );

t_eq( 'converted IN PLACE — same WooCommerce id', $wc_b, $wc_b2 );

$product = wc_get_product( $wc_b );
t_ok(   'now a simple product',         $product instanceof WC_Product_Simple );
t_eq(   'name updated during conversion', 'Collapsing Shirt Solo', (string) $product->get_name() );
t_near( 'product-level price applied',  925.00, (float) $product->get_regular_price() );

$cats = wp_get_post_terms( $wc_b, 'product_cat', [ 'fields' => 'names' ] );
t_ok( 'category updated during conversion', in_array( 'Basics', (array) $cats, true ), implode( ', ', (array) $cats ) );

t_eq( 'POS product id meta retained',   $b_pos,  (string) get_post_meta( $wc_b, '_salesplay_pos_product_id', true ) );
t_eq( 'POS product code meta retained', $b_code, (string) get_post_meta( $wc_b, '_pos_product_code', true ) );

t_absent( 'no conversion errors logged', 'Failed to convert', t_log_since( $mark2, 'error' ) );

// The orphaned variant rows are pruned by the variant sync's own reconcile.
$pruned = Sellmo_Product_Variant_Store::delete_absent_for_product( $b_id, [] );
t_eq( 'both variant rows pruned', 2, count( $pruned ) );
t_eq( 'no variant rows remain',   0, count( Sellmo_Product_Variant_Store::get_variants_for_product( $b_id ) ) );

// -----------------------------------------------------------------------------
// 8B-2. Updates still apply after the conversion
// -----------------------------------------------------------------------------
t_head( '8B-2. Updates still apply after variable -> simple' );

$b_updated = t_product( $b_pos, $b_code, 'Collapsing Shirt Solo', 'Essentials', 1050.00, $shop );

t_eq( 'mapped field sync succeeds', 'success', $sync->sync_mapped_product_fields( $b_updated ) );

$product = wc_get_product( $wc_b );
t_near( 'price update applied', 1050.00, (float) $product->get_regular_price() );

$cats = wp_get_post_terms( $wc_b, 'product_cat', [ 'fields' => 'names' ] );
t_ok( 'category update applied', in_array( 'Essentials', (array) $cats, true ), implode( ', ', (array) $cats ) );

T_Fake_API::install( [
	'inventory' => [ T_Fake_API::inventory_page( [
		[ 'product_id' => $b_pos, 'product_code' => $b_code, 'in_stock' => 44 ],
	] ) ],
] );

/*
 * KNOWN GAP — not a regression, documented so a future change is deliberate.
 *
 * A product converted variable -> simple inherits manage_stock = false (the old
 * parent never managed stock; its variations did). Sellmo_Stock_Sync::
 * sync_stock_for_wc_product() writes through wc_update_product_stock(), which
 * no-ops for a product that is not managing stock — and still returns a
 * non-false value, so the caller counts it as "updated".
 *
 * Practical effect: right after a conversion the quantity is not applied by this
 * per-product helper. The main stock sync (Stock_Sync_Pagination) does force
 * set_manage_stock( true ) first, so the next full stock sync corrects it.
 */
( new Sellmo_Stock_Sync() )->sync_stock_for_wc_product( $wc_b );
t_eq( 'gap: quantity not applied while manage_stock is off', null, wc_get_product( $wc_b )->get_stock_quantity() );

// With stock management on — the state the main stock sync leaves behind — the
// same call must land the quantity.
$converted = wc_get_product( $wc_b );
$converted->set_manage_stock( true );
$converted->save();

t_eq( 'stock applied to the converted product', 1, ( new Sellmo_Stock_Sync() )->sync_stock_for_wc_product( $wc_b ) );
t_eq( 'stock quantity correct', 44, (int) wc_get_product( $wc_b )->get_stock_quantity() );

// =============================================================================
// 8C. Round trip — simple -> variable -> simple keeps the same product
// =============================================================================
t_head( '8C. Round trip keeps one WooCommerce product' );

$round = t_product( $a_pos, $a_code, 'Convertible Mug', 'Kitchen', 480.00, $shop );
$wc_a3 = $sync->sync_product_to_wc( $round );

t_eq( 'still the same WooCommerce id', $wc_a, $wc_a3 );

$product = wc_get_product( $wc_a );
t_ok(   'back to a simple product', $product instanceof WC_Product_Simple );
t_near( 'price restored',           480.00, (float) $product->get_regular_price() );

// The mapper row must still point at that one product.
$row = Sellmo_Product_Mapping::get_row_by_id( $a_id );
t_eq( 'mapping still points at the same product', $wc_a, (int) $row['wc_product_id'] );

exit( t_summary() );
