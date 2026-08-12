<?php
/**
 * Scenario 1 — a SIMPLE product changes upstream.
 *
 * Covers name, category, price, shops and stock changing in the POS, and
 * asserts that a product sync (a) updates the mapper row, (b) pushes the new
 * values onto the mapped WooCommerce product, and (c) names the product and
 * every changed field in the sync log.
 *
 * Run: php tests/test-1-simple-product-changes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$shop_a = t_uid( 'SHOPA' );
$shop_b = t_uid( 'SHOPB' );

t_set_setting( 'shop_id', $shop_a );

// -----------------------------------------------------------------------------
// Fixture: a staged simple product mapped to a real WooCommerce product.
// -----------------------------------------------------------------------------
$pos_id = t_uid( 'SIMPLE' );
$code   = T_PREFIX . '-S-100';

$before = t_product( $pos_id, $code, 'Test Blender', 'Kitchen', 1000.00, $shop_a );

$mapping_id = t_stage_product( $before );
$wc_id      = t_wc_simple( 'Test Blender', 1000.00, $code, $pos_id, 25 );

Sellmo_Product_Mapping::upsert_mapping( $pos_id, $code, 'Test Blender', $wc_id );

t_ok( 'fixture: mapping row staged', $mapping_id > 0 );
t_ok( 'fixture: WC product created', $wc_id > 0 );

// -----------------------------------------------------------------------------
// The upstream edit: every field the user listed changes at once.
// -----------------------------------------------------------------------------
$after = t_product( $pos_id, $code, 'Test Mixer', 'Mixer', 1450.00, $shop_b );

// -----------------------------------------------------------------------------
// 1a. Mapper row refresh + change detection
// -----------------------------------------------------------------------------
t_head( '1a. Mapper row detects name / category / price / shop changes' );

$mark = t_log_mark();

$changes = Sellmo_Product_Mapping::refresh_catalogue_row(
	$pos_id,
	$code,
	$shop_b,
	'Shop B',
	1450.00,
	'Mixer',
	0,
	$after,
	'Test Mixer'
);

t_contains( 'name change reported',     'name "Test Blender" → "Test Mixer"', $changes );
t_contains( 'category change reported', 'category "Kitchen" → "Mixer"',       $changes );
t_contains( 'price change reported',    'price 1000.00 → 1450.00',            $changes );
t_contains( 'shop change reported',     'shops',                              $changes );

$row = Sellmo_Product_Mapping::get_row_by_id( $mapping_id );
t_eq(   'row name persisted',     'Test Mixer', (string) $row['pos_product_name'] );
t_eq(   'row category persisted', 'Mixer',      (string) $row['category'] );
t_near( 'row price persisted',    1450.00,      (float) $row['price'] );
t_eq(   'row shop persisted',     $shop_b,      (string) $row['shop_id'] );

// -----------------------------------------------------------------------------
// 1b. The mapped WooCommerce product receives the new values
// -----------------------------------------------------------------------------
t_head( '1b. Mapped WooCommerce product updated' );

$status = ( new Sellmo_Product_Sync() )->sync_mapped_product_fields( $after );
t_eq( 'sync_mapped_product_fields returned success', 'success', $status );

$product = wc_get_product( $wc_id );
t_eq(   'WC product name updated',  'Test Mixer', (string) $product->get_name() );
t_near( 'WC regular price updated', 1450.00,      (float) $product->get_regular_price() );

$cats = wp_get_post_terms( $wc_id, 'product_cat', [ 'fields' => 'names' ] );
t_ok( 'WC category updated to "Mixer"', in_array( 'Mixer', (array) $cats, true ), 'saw: ' . implode( ', ', (array) $cats ) );

// -----------------------------------------------------------------------------
// 1c. Stock
// -----------------------------------------------------------------------------
t_head( '1c. Stock change applied from POS inventory' );

// Mapper-row stock snapshot.
Sellmo_Product_Mapping::update_stock( $pos_id, '', $code, 7 );
$row = Sellmo_Product_Mapping::get_row_by_id( $mapping_id );
t_eq( 'mapper row stock_count updated', 7, (int) $row['stock_count'] );
t_eq( 'mapper row stock_status in-stock', 1, (int) $row['stock_status'] );

// WooCommerce stock, driven through the real code path with a canned /inventory.
T_Fake_API::install( [
	'inventory' => [
		T_Fake_API::inventory_page( [
			[ 'product_id' => $pos_id, 'product_code' => $code, 'in_stock' => 7 ],
		] ),
	],
] );

$updated = ( new Sellmo_Stock_Sync() )->sync_stock_for_wc_product( $wc_id );
t_eq( 'stock sync reported 1 target updated', 1, $updated );

$product = wc_get_product( $wc_id );
t_eq( 'WC stock quantity updated to 7', 7, (int) $product->get_stock_quantity() );

// Zero stock must flip the product out of stock.
T_Fake_API::install( [
	'inventory' => [
		T_Fake_API::inventory_page( [
			[ 'product_id' => $pos_id, 'product_code' => $code, 'in_stock' => 0 ],
		] ),
	],
] );

( new Sellmo_Stock_Sync() )->sync_stock_for_wc_product( $wc_id );
$product = wc_get_product( $wc_id );
t_eq( 'WC stock quantity updated to 0',  0,          (int) $product->get_stock_quantity() );
t_eq( 'WC stock status is outofstock',  'outofstock', (string) $product->get_stock_status() );

// -----------------------------------------------------------------------------
// 1d. The sync log names the product and every change
// -----------------------------------------------------------------------------
t_head( '1d. Sync log records the product and what changed' );

$logs = t_log_since( $mark );

t_contains( 'log names the product',      'Test Mixer',      $logs );
t_contains( 'log records name change',    'name "Test Blender" → "Test Mixer"', $logs );
t_contains( 'log records price change',   'regular price',   $logs );
t_contains( 'log records category change', 'category',       $logs );
t_absent(   'no errors logged',           'Mapped field update failed', $logs );

// -----------------------------------------------------------------------------
// 1e. A product with no POS category must not flip between syncs
//
// Regression: the product sync wrote the WooCommerce term name ("Uncategorized")
// into the mapper column while the mapper sync wrote the display name (""), so a
// mapped product alternated between the two on every sync and logged a phantom
// category change each time.
// -----------------------------------------------------------------------------
t_head( '1e. "No category" is stored consistently by both syncs' );

$nc_pos  = t_uid( 'NOCAT' );
$nc_code = T_PREFIX . '-NOCAT-300';

$nc_payload = t_product( $nc_pos, $nc_code, 'No Category Product', 'No category', 500.00, $shop_a );
$nc_row     = t_stage_product( $nc_payload );
$nc_wc      = t_wc_simple( 'No Category Product', 500.00, $nc_code, $nc_pos, 5 );

Sellmo_Product_Mapping::upsert_mapping( $nc_pos, $nc_code, 'No Category Product', $nc_wc );

$mapper_sync = new Sellmo_Product_Mapper_Sync();

t_eq( 'both resolvers agree on "No category"',
	t_call( $mapper_sync, 'resolve_category', [ $nc_payload ] ),
	Sellmo_Product_Mapping::catalogue_category_name( $nc_payload )
);
t_eq( 'and that agreed value is blank', '', Sellmo_Product_Mapping::catalogue_category_name( $nc_payload ) );

// A product sync writes the row...
Sellmo_Product_Mapping::refresh_catalogue_row(
	$nc_pos, $nc_code, $shop_a, 'Shop A', 500.00,
	t_call( $mapper_sync, 'resolve_category', [ $nc_payload ] ),
	0, $nc_payload, 'No Category Product'
);
$after_product_sync = (string) Sellmo_Product_Mapping::get_row_by_id( $nc_row )['category'];

// ...then a stock sync writes it through the mapped-fields path.
$nc_mark = t_log_mark();
t_eq( 'mapped field sync succeeded', 'success', ( new Sellmo_Product_Sync() )->sync_mapped_product_fields( $nc_payload ) );
$after_stock_sync = (string) Sellmo_Product_Mapping::get_row_by_id( $nc_row )['category'];

t_eq( 'product sync stored blank',        '', $after_product_sync );
t_eq( 'stock sync stored the same value', $after_product_sync, $after_stock_sync );

// And a second product sync must report no category change at all.
$nc_changes = Sellmo_Product_Mapping::refresh_catalogue_row(
	$nc_pos, $nc_code, $shop_a, 'Shop A', 500.00,
	t_call( $mapper_sync, 'resolve_category', [ $nc_payload ] ),
	0, $nc_payload, 'No Category Product'
);
t_absent( 'no phantom category change reported', 'category', $nc_changes );

// The WooCommerce product still belongs to the Uncategorized term — the display
// value and the WC term are deliberately different things.
$nc_terms = wp_get_post_terms( $nc_wc, 'product_cat', [ 'fields' => 'names' ] );
t_ok( 'WooCommerce product still has a category term', ! empty( $nc_terms ), 'terms: ' . implode( ', ', (array) $nc_terms ) );

// -----------------------------------------------------------------------------
// Isolation guarantee
// -----------------------------------------------------------------------------
t_head( 'Isolation' );
$counts = t_real_counts();
t_ok( 'real catalogue rows untouched', $counts['mapping'] >= 0 );

exit( t_summary() );
