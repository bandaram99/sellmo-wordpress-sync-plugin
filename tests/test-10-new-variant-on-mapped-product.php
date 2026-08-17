<?php
/**
 * Scenario 10 — a variant ADDED upstream reaches a mapped product in WooCommerce.
 *
 * The reported bug: a POS variable product that is already mapped gains a new
 * variant, and the new variation never appears in WooCommerce. Deletions were
 * already propagated (Sellmo_Product_Variant_Sync prunes them); creation was
 * missing, because the mapped-product update path only ever repriced variations
 * that already existed.
 *
 * This suite drives the REAL sync entry points a merchant triggers — the variant
 * sync behind "Sync Products Now" and the mapped-field pass behind "Sync Stock
 * Now" — not the internal create/convert helper, which is what let the gap hide.
 *
 * Run: php tests/test-10-new-variant-on-mapped-product.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$shop_mine  = Sellmo_Product_Mapping::selected_shop_id();
$shop_other = t_uid( 'OTHERSHOP' );

if ( '' === $shop_mine ) {
	echo "SKIP: no shop selected in Settings — variant scoping needs one.\n";
	exit( 0 );
}

$sync = new Sellmo_Product_Sync();

// -----------------------------------------------------------------------------
// Fixture: a mapped variable product with two variants, both imported.
// -----------------------------------------------------------------------------
$pos_id = t_uid( 'ADDVAR' );
$code   = T_PREFIX . '-ADD-100';
$v1     = t_uid( 'AV1' );
$v2     = t_uid( 'AV2' );

$before = t_product( $pos_id, $code, 'Growing Tee', 'Apparel', 0.0, $shop_mine, [
	t_variant( $v1, "{$code}-1", 's', 'red',  300.00, $shop_mine ),
	t_variant( $v2, "{$code}-2", 'm', 'red',  350.00, $shop_mine ),
] );

$mapping_id = t_stage_product( $before );
t_stage_variants( $mapping_id, $before );

$wc_id = $sync->sync_product_to_wc( $before );
t_ok( 'fixture: WooCommerce product created', $wc_id > 0 );

t_defer( static function () use ( $wc_id ): void {
	t_delete_wc_product( $wc_id );
} );

Sellmo_Product_Mapping::upsert_mapping( $pos_id, $code, 'Growing Tee', $wc_id );

// Link the two existing variants to their variations, as an import would.
foreach ( [ $v1, $v2 ] as $vid ) {
	$variation_id = $sync->find_variation_id_for_variant( $wc_id, [ 'variant_id' => $vid ] );
	if ( $variation_id > 0 ) {
		Sellmo_Product_Variant_Store::set_wc_variation_id( $vid, $variation_id );
	}
}

t_eq( 'fixture: two variations exist', 2, count( wc_get_product( $wc_id )->get_children() ) );
t_eq( 'fixture: product is mapped',    $wc_id, (int) Sellmo_Product_Mapping::get_row_by_id( $mapping_id )['wc_product_id'] );

// -----------------------------------------------------------------------------
// Upstream change: a third variant is added, with a brand-new option value
// ("l" was never used before, so the parent attribute must gain it), plus one
// variant that belongs to another shop and must NOT be created.
// -----------------------------------------------------------------------------
$v3  = t_uid( 'AV3' );
$v4  = t_uid( 'AV4' );

$after = t_product( $pos_id, $code, 'Growing Tee', 'Apparel', 0.0, $shop_mine, [
	t_variant( $v1, "{$code}-1", 's',  'red',   300.00, $shop_mine ),
	t_variant( $v2, "{$code}-2", 'm',  'red',   350.00, $shop_mine ),
	t_variant( $v3, "{$code}-3", 'l',  'blue',  400.00, $shop_mine ),
	t_variant( $v4, "{$code}-4", 'xl', 'green', 450.00, $shop_other ),
] );

// -----------------------------------------------------------------------------
// 10a. "Sync Products Now": the variant sync pushes the new variant to WC
// -----------------------------------------------------------------------------
t_head( '10a. Variant sync adds the new variant to WooCommerce' );

T_Fake_API::install( [
	'products'  => [ T_Fake_API::products_page( [ $after ] ) ],
	'inventory' => [ T_Fake_API::inventory_page( [
		[ 'product_id' => $v1, 'product_code' => "{$code}-1", 'in_stock' => 5 ],
		[ 'product_id' => $v2, 'product_code' => "{$code}-2", 'in_stock' => 6 ],
		[ 'product_id' => $v3, 'product_code' => "{$code}-3", 'in_stock' => 7 ],
	] ) ],
	'/shops'    => [ 'shops' => [ [ 'shop_id' => $shop_mine, 'shop_name' => 'Main' ] ] ],
] );

$mark   = t_log_mark();
$result = ( new Sellmo_Product_Variant_Sync() )->run();

t_ok( 'variant sync succeeded', ! empty( $result['success'] ), wp_json_encode( $result ) );

$children = wc_get_product( $wc_id )->get_children();
t_eq( 'the new variant became a WooCommerce variation', 3, count( $children ) );

// The new variation must be the right one, priced, and stocked.
$new_variation = $sync->find_variation_id_for_variant( $wc_id, [ 'variant_id' => $v3 ] );
t_ok( 'new variation found by variant id', $new_variation > 0 );

if ( $new_variation > 0 ) {
	$variation = wc_get_product( $new_variation );
	t_near( 'new variation carries the POS price', 400.00, (float) $variation->get_regular_price() );
	t_eq( 'new variation carries the POS SKU', "{$code}-3", (string) $variation->get_sku() );
	t_eq( 'new variation stock pulled', 7, (int) $variation->get_stock_quantity() );

	// A brand-new option value has to be registered on the parent, or the
	// variation saves with an empty attribute and is unbuyable. Read the parent
	// fresh — the object cached earlier in this process predates the change.
	clean_post_cache( $wc_id );
	wc_delete_product_transients( $wc_id );

	$options = [];
	foreach ( wc_get_product( $wc_id )->get_attributes() as $attribute ) {
		$options = array_merge( $options, (array) $attribute->get_options() );
	}
	t_ok( 'new option value registered on the parent', in_array( 'l', $options, true ), implode( ', ', $options ) );
	t_ok( 'the variation has its attributes set', ! empty( array_filter( $variation->get_attributes() ) ) );
}

// The other shop's variant must stay out of WooCommerce.
t_eq( 'out-of-shop variant not created', 0, $sync->find_variation_id_for_variant( $wc_id, [ 'variant_id' => $v4 ] ) );

// The variant row is linked, so the Product Mapper shows it as Mapped.
$stored = Sellmo_Product_Variant_Store::get_by_variant_id( $v3 );
t_eq( 'variant row links to the new variation', $new_variation, (int) ( $stored['wc_variation_id'] ?? 0 ) );

$logs = t_log_since( $mark );
t_contains( 'the addition is logged', 'New POS variant added to WooCommerce', $logs );
t_absent( 'no variant errors logged', 'Could not add variant', $logs );

// -----------------------------------------------------------------------------
// 10b. Running again adds nothing (no duplicate variations)
// -----------------------------------------------------------------------------
t_head( '10b. Re-running is idempotent' );

$again = ( new Sellmo_Product_Variant_Sync() )->run();
t_ok( 'second variant sync succeeded', ! empty( $again['success'] ) );
t_eq( 'still exactly 3 variations',    3, count( wc_get_product( $wc_id )->get_children() ) );

// -----------------------------------------------------------------------------
// 10c. "Sync Stock Now": the mapped-field pass also adds a new variant
// -----------------------------------------------------------------------------
t_head( '10c. Mapped-field sync adds a new variant too' );

$v5    = t_uid( 'AV5' );
$after2 = t_product( $pos_id, $code, 'Growing Tee', 'Apparel', 0.0, $shop_mine, [
	t_variant( $v1, "{$code}-1", 's',   'red',   300.00, $shop_mine ),
	t_variant( $v2, "{$code}-2", 'm',   'red',   350.00, $shop_mine ),
	t_variant( $v3, "{$code}-3", 'l',   'blue',  400.00, $shop_mine ),
	t_variant( $v5, "{$code}-5", 'xxl', 'black', 500.00, $shop_mine ),
] );

// The variant store must know the row (the mapper sync stages it in real runs).
t_stage_variants( $mapping_id, $after2 );

t_eq( 'mapped field sync succeeded', 'success', $sync->sync_mapped_product_fields( $after2 ) );
t_eq( 'the fourth variant became a variation', 4, count( wc_get_product( $wc_id )->get_children() ) );

$fifth = $sync->find_variation_id_for_variant( $wc_id, [ 'variant_id' => $v5 ] );
t_ok( 'variation created for the newest variant', $fifth > 0 );
if ( $fifth > 0 ) {
	t_near( 'and it is priced from the POS', 500.00, (float) wc_get_product( $fifth )->get_regular_price() );
}

// -----------------------------------------------------------------------------
// 10d. An unmapped product is left to the product sync, not this path
// -----------------------------------------------------------------------------
t_head( '10d. Unmapped products are untouched' );

$u_pos  = t_uid( 'ADDVARU' );
$u_code = T_PREFIX . '-ADD-200';
$u_v1   = t_uid( 'UV1' );

$unmapped = t_product( $u_pos, $u_code, 'Unmapped Tee', 'Apparel', 0.0, $shop_mine, [
	t_variant( $u_v1, "{$u_code}-1", 's', 'red', 100.00, $shop_mine ),
] );

$u_mapping = t_stage_product( $unmapped );
t_stage_variants( $u_mapping, $unmapped );

t_eq( 'nothing created for an unmapped product', 0, $sync->sync_mapped_variations( $unmapped ) );
t_eq( 'and it is still unmapped', 0, (int) ( Sellmo_Product_Mapping::get_row_by_id( $u_mapping )['wc_product_id'] ?? 0 ) );

// -----------------------------------------------------------------------------
// 10e. A mapped product that is still SIMPLE is reported, not silently skipped
// -----------------------------------------------------------------------------
t_head( '10e. Mapped simple product with POS variants' );

$s_pos  = t_uid( 'ADDVARS' );
$s_code = T_PREFIX . '-ADD-300';
$s_v1   = t_uid( 'SV1' );

$simple_mapping = t_stage_product( t_product( $s_pos, $s_code, 'Simple Then Variants', 'Apparel', 90.00, $shop_mine ) );
$simple_wc      = t_wc_simple( 'Simple Then Variants WC', 90.00, $s_code, $s_pos );
Sellmo_Product_Mapping::upsert_mapping( $s_pos, $s_code, 'Simple Then Variants', $simple_wc );

$s_payload = t_product( $s_pos, $s_code, 'Simple Then Variants', 'Apparel', 0.0, $shop_mine, [
	t_variant( $s_v1, "{$s_code}-1", 's', 'red', 120.00, $shop_mine ),
] );
t_stage_variants( $simple_mapping, $s_payload );

$mark_simple = t_log_mark();
t_eq( 'nothing created on a simple product', 0, $sync->sync_mapped_variations( $s_payload ) );
t_eq( 'the simple product gained no children', 0, count( wc_get_product( $simple_wc )->get_children() ) );
t_contains( 'and the reason is logged', 'not a variable product', t_log_since( $mark_simple ) );

exit( t_summary() );
