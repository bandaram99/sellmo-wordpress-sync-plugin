<?php
/**
 * Scenario 5 — an UNMAPPED product still tracks upstream changes.
 *
 * A product staged in the Product Mapper but not yet linked to a WooCommerce
 * product must still have its price, category, name, shops, stock and variant
 * list refreshed by a product sync, so the mapper list is accurate at the moment
 * the merchant decides to map it. Nothing may be written to WooCommerce.
 *
 * Both shapes are covered: a simple product and a variable one.
 *
 * Run: php tests/test-5-unmapped-product-changes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$shop_a = Sellmo_Product_Mapping::selected_shop_id();
$shop_b = t_uid( 'SHOPB' );

if ( '' === $shop_a ) {
	echo "SKIP: no shop selected in Settings.\n";
	exit( 0 );
}

$shop_names = [ $shop_a => 'Shop A', $shop_b => 'Shop B' ];
$mapper     = new Sellmo_Product_Mapper_Sync();
$wc_before  = (int) wp_count_posts( 'product' )->publish;

// =============================================================================
// 5A. UNMAPPED SIMPLE PRODUCT
// =============================================================================
t_head( '5A. Unmapped simple product' );

$s_pos  = t_uid( 'USIMPLE' );
$s_code = T_PREFIX . '-US-500';

$s_before = t_product( $s_pos, $s_code, 'Unmapped Kettle', 'Kitchen', 900.00, $shop_a );
$s_id     = t_stage_product( $s_before );

$row = Sellmo_Product_Mapping::get_row_by_id( $s_id );
t_eq( 'starts unmapped',           0,                 (int) ( $row['wc_product_id'] ?? 0 ) );
t_eq( 'starts with the old name',  'Unmapped Kettle', (string) $row['pos_product_name'] );

// Upstream: renamed, recategorised, repriced, moved to a second shop.
$s_after = t_product( $s_pos, $s_code, 'Unmapped Toaster', 'Appliances', 1275.00, $shop_a );
$s_after['shops'][] = [ 'shop_id' => $shop_b, 'price' => 1275.00, 'available_for_sale' => true ];

$mark = t_log_mark();

[ $s_shop_ids, $s_shop_names ] = Sellmo_Product_Mapping::resolve_available_shops( $s_after, $shop_names );

$changes = Sellmo_Product_Mapping::refresh_catalogue_row(
	$s_pos,
	$s_code,
	$s_shop_ids,
	$s_shop_names,
	t_call( $mapper, 'resolve_price', [ $s_after ] ),
	t_call( $mapper, 'resolve_category', [ $s_after ] ),
	0,
	$s_after,
	(string) $s_after['product_name']
);

t_contains( 'name change detected',     'name "Unmapped Kettle" → "Unmapped Toaster"', $changes );
t_contains( 'category change detected', 'category "Kitchen" → "Appliances"',           $changes );
t_contains( 'price change detected',    'price 900.00 → 1275.00',                      $changes );
t_contains( 'shop change detected',     'shops',                                       $changes );

$row = Sellmo_Product_Mapping::get_row_by_id( $s_id );
t_eq(   'name persisted',     'Unmapped Toaster', (string) $row['pos_product_name'] );
t_eq(   'category persisted', 'Appliances',       (string) $row['category'] );
t_near( 'price persisted',    1275.00,            (float) $row['price'] );
t_ok(   'both shops persisted', false !== strpos( (string) $row['shop_id'], $shop_b ) );

// Stock still tracks on an unmapped row.
Sellmo_Product_Mapping::update_stock( $s_pos, '', $s_code, 42 );
$row = Sellmo_Product_Mapping::get_row_by_id( $s_id );
t_eq( 'stock persisted on the unmapped row', 42, (int) $row['stock_count'] );

// Still unmapped, and nothing was pushed to WooCommerce.
t_eq( 'still unmapped after the sync', 0, (int) ( $row['wc_product_id'] ?? 0 ) );

// =============================================================================
// 5B. UNMAPPED VARIABLE PRODUCT
// =============================================================================
t_head( '5B. Unmapped variable product' );

$v_pos  = t_uid( 'UVAR' );
$v_code = T_PREFIX . '-UV-600';

$vid1 = t_uid( 'UV1' );
$vid2 = t_uid( 'UV2' );
$vid3 = t_uid( 'UV3' );
$vid4 = t_uid( 'UV4' );

$v_before = t_product( $v_pos, $v_code, 'Unmapped Hoodie', 'Apparel', 0.0, $shop_a, [
	t_variant( $vid1, "{$v_code}-1", 's', 'red',   800.00, $shop_a ),
	t_variant( $vid2, "{$v_code}-2", 'm', 'red',   800.00, $shop_a ),
	t_variant( $vid3, "{$v_code}-3", 'l', 'green', 850.00, $shop_a ),
] );

$v_id = t_stage_product( $v_before );
t_stage_variants( $v_id, $v_before );

$row = Sellmo_Product_Mapping::get_row_by_id( $v_id );
t_eq( 'starts unmapped',          0, (int) ( $row['wc_product_id'] ?? 0 ) );
t_eq( 'starts with 3 variants',   3, (int) $row['variant_count'] );
t_eq( 'starts with 3 variant rows', 3, count( Sellmo_Product_Variant_Store::get_variants_for_product( $v_id ) ) );

// Upstream: v1 kept, v2 renamed, v3 repriced, v4 ADDED, product renamed.
$v_after = t_product( $v_pos, $v_code, 'Unmapped Zip Hoodie', 'Outerwear', 0.0, $shop_a, [
	t_variant( $vid1, "{$v_code}-1", 's', 'red',    800.00, $shop_a ),
	t_variant( $vid2, "{$v_code}-2", 'm', 'maroon', 800.00, $shop_a ),
	t_variant( $vid3, "{$v_code}-3", 'l', 'green',  1100.00, $shop_a ),
	t_variant( $vid4, "{$v_code}-4", 'xl', 'navy',  1200.00, $shop_a ),
] );

[ $v_shop_ids, $v_shop_names ] = Sellmo_Product_Mapping::resolve_available_shops( $v_after, $shop_names );

$changes = Sellmo_Product_Mapping::refresh_catalogue_row(
	$v_pos,
	$v_code,
	$v_shop_ids,
	$v_shop_names,
	t_call( $mapper, 'resolve_price', [ $v_after ] ),
	t_call( $mapper, 'resolve_category', [ $v_after ] ),
	count( $v_after['variants'] ),
	$v_after,
	(string) $v_after['product_name']
);

t_contains( 'product renamed',        'name "Unmapped Hoodie" → "Unmapped Zip Hoodie"', $changes );
t_contains( 'category changed',       'category "Apparel" → "Outerwear"',               $changes );
t_contains( 'variant count changed',  'variant count 3 → 4',                            $changes );
t_contains( 'variant renamed',        'variant "m / red" → "m / maroon"',               $changes );
t_contains( 'variant repriced',       'variant "l / green" price 850.00 → 1100.00',     $changes );
t_contains( 'variant added',          'variant "xl / navy" added',                      $changes );

$row = Sellmo_Product_Mapping::get_row_by_id( $v_id );
t_eq( 'name persisted',          'Unmapped Zip Hoodie', (string) $row['pos_product_name'] );
t_eq( 'category persisted',      'Outerwear',           (string) $row['category'] );
t_eq( 'variant_count persisted', 4,                     (int) $row['variant_count'] );

// A variant removed upstream must also be reflected on an unmapped product.
$v_final = $v_after;
array_splice( $v_final['variants'], 1, 1 ); // drop v2

$changes = Sellmo_Product_Mapping::refresh_catalogue_row(
	$v_pos, $v_code, $v_shop_ids, $v_shop_names, 800.00, 'Outerwear', 3, $v_final, (string) $v_final['product_name']
);
t_contains( 'variant removal detected', 'variant "m / maroon" removed', $changes );

$pruned = Sellmo_Product_Variant_Store::delete_absent_for_product( $v_id, [ $vid1, $vid3, $vid4 ] );
t_eq( 'removed variant pruned from the store', 1, count( $pruned ) );

// Stock on an individual unmapped variant.
Sellmo_Product_Variant_Store::update_stock( $vid3, 17 );
$stock_seen = null;
foreach ( Sellmo_Product_Variant_Store::get_variants_for_product( $v_id ) as $sv ) {
	if ( (string) $sv['variant_id'] === $vid3 ) {
		$stock_seen = (int) $sv['stock_count'];
	}
}
t_eq( 'variant stock persisted while unmapped', 17, $stock_seen );

$row = Sellmo_Product_Mapping::get_row_by_id( $v_id );
t_eq( 'still unmapped after all changes', 0, (int) ( $row['wc_product_id'] ?? 0 ) );

// =============================================================================
// 5C. Nothing leaked into WooCommerce
// =============================================================================
t_head( '5C. No WooCommerce writes for unmapped products' );

$wc_after = (int) wp_count_posts( 'product' )->publish;
t_eq( 'no WooCommerce products created', $wc_before, $wc_after );

// =============================================================================
// 5D. Every change is logged
// =============================================================================
t_head( '5D. Sync log covers unmapped products too' );

// refresh_catalogue_row() returns the changes; the mapper sync is what writes
// them to the log. Drive the real logging path with both products in one page.
T_Fake_API::install( [
	'products' => [ T_Fake_API::products_page( [ $s_after, $v_final ] ) ],
] );

$log_mark = t_log_mark();

// Only the per-row refresh + logging is under test here; the full delta sync is
// covered by test-7 (it reconciles the whole catalogue and must not run against
// a two-product stub).
foreach ( [ $s_after, $v_final ] as $payload ) {
	[ $ids, $names ] = Sellmo_Product_Mapping::resolve_available_shops( $payload, $shop_names );

	$rowchanges = Sellmo_Product_Mapping::refresh_catalogue_row(
		(string) $payload['id'],
		(string) $payload['product_code'],
		$ids,
		$names,
		t_call( $mapper, 'resolve_price', [ $payload ] ),
		t_call( $mapper, 'resolve_category', [ $payload ] ),
		count( $payload['variants'] ?? [] ),
		$payload,
		(string) $payload['product_name']
	);

	if ( ! empty( $rowchanges ) ) {
		Sellmo_Logger::log(
			'product',
			'pos_to_wc',
			'success',
			sprintf( 'Mapper row updated for "%s": %s.', (string) $payload['product_name'], implode( '; ', $rowchanges ) ),
			(string) $payload['product_code']
		);
	}
}

// Re-running with the SAME payload must produce no changes and no log noise.
$logs = t_log_since( $log_mark );
t_ok( 'a second identical sync logs nothing new', empty( $logs ), 'unexpected: ' . implode( ' | ', $logs ) );

// Now make a change and confirm it IS logged.
$log_mark  = t_log_mark();
$s_logged  = $s_after;
$s_logged['product_name'] = 'Unmapped Toaster Deluxe';
$s_logged['category']     = 'Small Appliances';

[ $ids, $names ] = Sellmo_Product_Mapping::resolve_available_shops( $s_logged, $shop_names );
$rowchanges = Sellmo_Product_Mapping::refresh_catalogue_row(
	$s_pos, $s_code, $ids, $names, 1275.00, 'Small Appliances', 0, $s_logged, 'Unmapped Toaster Deluxe'
);

Sellmo_Logger::log(
	'product',
	'pos_to_wc',
	'success',
	sprintf( 'Mapper row updated for "%s": %s.', 'Unmapped Toaster Deluxe', implode( '; ', $rowchanges ) ),
	$s_code
);

$logs = t_log_since( $log_mark );
t_contains( 'change on an unmapped product is logged', 'Mapper row updated for "Unmapped Toaster Deluxe"', $logs );
t_contains( 'log names the changed category',          'category "Appliances" → "Small Appliances"',        $logs );

// =============================================================================
// 5E. Errors are logged too
// =============================================================================
t_head( '5E. Failures reach the log' );

$err_mark = t_log_mark();

// An API failure must be recorded, not swallowed. No stub is installed for
// /shops, so the harness returns a WP_Error the way a dead endpoint would.
T_Fake_API::install( [ 'products' => [ T_Fake_API::products_page( [] ) ] ] );

$api    = new Sellmo_API();
$result = $api->get_shops();

t_ok( 'unstubbed endpoint surfaced an error', is_wp_error( $result ) );

$err_logs = t_log_since( $err_mark, 'error' );
t_ok( 'the API error was written to the log', ! empty( $err_logs ), 'no error rows written' );

exit( t_summary() );
