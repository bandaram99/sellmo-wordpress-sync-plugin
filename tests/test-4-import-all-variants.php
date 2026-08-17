<?php
/**
 * Scenario 4 — "Import all to WooCommerce" imports every variant.
 *
 * Drives the real wp_ajax_sellmo_wc_mapper_import_all_variants handler and
 * asserts that a WooCommerce variable product is created with one variation per
 * in-shop variant, each variation linked back to its variant row, priced,
 * stocked, and that the run is logged.
 *
 * Run: php tests/test-4-import-all-variants.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Adopt the site's real shop (see the safety note in test-3) so the fixture is
// in scope; out-of-shop variants use a synthetic id.
$shop_mine  = Sellmo_Product_Mapping::selected_shop_id();
$shop_other = t_uid( 'OTHERSHOP' );

if ( '' === $shop_mine ) {
	echo "SKIP: no shop selected in Settings — variant import is shop-scoped.\n";
	exit( 0 );
}

// -----------------------------------------------------------------------------
// Fixture: a staged variable product, 5 in-shop variants + 1 in another shop.
// -----------------------------------------------------------------------------
$pos_id = t_uid( 'IMPALL' );
$code   = T_PREFIX . '-IMP-400';

$in_shop_ids = [];
$variants    = [];

foreach ( [ [ 's', 'red', 300.0 ], [ 'm', 'red', 350.0 ], [ 'l', 'blue', 400.0 ], [ 'xl', 'blue', 450.0 ], [ 'xxl', 'black', 500.0 ] ] as $i => $spec ) {
	$vid            = t_uid( 'IV' . $i );
	$in_shop_ids[]  = $vid;
	$variants[]     = t_variant( $vid, "{$code}-" . ( $i + 1 ), $spec[0], $spec[1], $spec[2], $shop_mine );
}

$out_of_shop_id = t_uid( 'IVOUT' );
$variants[]     = t_variant( $out_of_shop_id, "{$code}-9", 'xs', 'grey', 250.0, $shop_other );

$payload    = t_product( $pos_id, $code, 'Import All Tee', 'Apparel', 0.0, $shop_mine, $variants );
$mapping_id = t_stage_product( $payload );
t_stage_variants( $mapping_id, $payload );

t_ok( 'fixture: mapping row staged', $mapping_id > 0 );
t_eq( 'fixture: 6 variant rows stored', 6, count( Sellmo_Product_Variant_Store::get_variants_for_product( $mapping_id ) ) );

$row = Sellmo_Product_Mapping::get_row_by_id( $mapping_id );
t_eq( 'fixture: starts unmapped', 0, (int) ( $row['wc_product_id'] ?? 0 ) );

// -----------------------------------------------------------------------------
// 4a. The mapper offers only the in-shop variants for import
// -----------------------------------------------------------------------------
t_head( '4a. Only in-shop variants are offered' );

$admin = new Sellmo_Admin();
$rows  = t_call( $admin, 'build_variant_rows_for_mapper', [ $mapping_id, 0 ] );

t_eq( '5 in-shop variants listed', 5, count( $rows ) );
t_ok(
	'out-of-shop variant excluded',
	! in_array( $out_of_shop_id, array_map( static fn( array $r ): string => (string) $r['variant_id'], $rows ), true )
);

// -----------------------------------------------------------------------------
// 4b. "Import all to WooCommerce" — the real AJAX endpoint
// -----------------------------------------------------------------------------
t_head( '4b. Import all to WooCommerce' );

/*
 * Two endpoints are needed here:
 *
 *   /products  — the row is still UNMAPPED, and resolve_sellmo_payload() only
 *                reads raw_payload off a *mapped* row. For an unmapped one it
 *                falls back to the full catalogue, which is the real path the
 *                Product Mapper takes when importing a not-yet-mapped product.
 *   /inventory — stock for the parent + variations is pulled at the end of the
 *                import.
 */
T_Fake_API::install( [
	'products'  => [ T_Fake_API::products_page( [ $payload ] ) ],
	'inventory' => [
		T_Fake_API::inventory_page( array_map(
			static fn( string $vid, int $i ): array => [
				'product_id'   => $vid,
				'product_code' => '',
				'in_stock'     => 10 + $i,
			],
			$in_shop_ids,
			array_keys( $in_shop_ids )
		) ),
	],
] );

$mark     = t_log_mark();
$response = t_ajax( $admin, 'ajax_mapper_import_all_variants', [ 'mapping_id' => $mapping_id ] );

t_ok( 'import reported success', ! empty( $response['success'] ), wp_json_encode( $response ) );

$wc_id = (int) ( $response['data']['wc_id'] ?? 0 );
t_ok( 'a WooCommerce product id came back', $wc_id > 0 );

if ( $wc_id <= 0 ) {
	exit( t_summary() );
}

t_defer( static function () use ( $wc_id ): void {
	t_delete_wc_product( $wc_id );
} );

// -----------------------------------------------------------------------------
// 4c. The imported product is correct
// -----------------------------------------------------------------------------
t_head( '4c. Imported product structure' );

$product = wc_get_product( $wc_id );
t_ok( 'product is a variable product', $product instanceof WC_Product_Variable );
t_eq( 'product name matches the POS name', 'Import All Tee', (string) $product->get_name() );
t_eq( 'one variation per in-shop variant', 5, count( $product->get_children() ) );

// Every in-shop variant must be linked back to its WooCommerce variation.
$linked = 0;
foreach ( Sellmo_Product_Variant_Store::get_variants_for_product( $mapping_id ) as $sv ) {
	$vid = (string) $sv['variant_id'];
	$wcv = (int) ( $sv['wc_variation_id'] ?? 0 );

	if ( in_array( $vid, $in_shop_ids, true ) ) {
		if ( $wcv > 0 && wc_get_product( $wcv ) instanceof WC_Product_Variation ) {
			$linked++;
		}
	} elseif ( $vid === $out_of_shop_id ) {
		t_eq( 'out-of-shop variant was NOT imported', 0, $wcv );
	}
}
t_eq( 'all 5 in-shop variants linked to a variation', 5, $linked );

// The parent is now mapped.
$row = Sellmo_Product_Mapping::get_row_by_id( $mapping_id );
t_eq( 'mapper row is now mapped to the product', $wc_id, (int) $row['wc_product_id'] );
t_eq( 'mapper row status is mapped',             1,      (int) $row['map_status'] );

// -----------------------------------------------------------------------------
// 4d. Prices and stock landed on each variation
// -----------------------------------------------------------------------------
t_head( '4d. Variation prices and stock' );

$expected_prices = [ 300.0, 350.0, 400.0, 450.0, 500.0 ];
$seen_prices     = [];
$stocked         = 0;

foreach ( $product->get_children() as $child ) {
	$variation     = wc_get_product( (int) $child );
	$seen_prices[] = (float) $variation->get_regular_price();
	if ( null !== $variation->get_stock_quantity() ) {
		$stocked++;
	}
}

sort( $seen_prices );
t_eq( 'every variant price imported', $expected_prices, $seen_prices );
t_eq( 'every variation carries stock', 5, $stocked );

// -----------------------------------------------------------------------------
// 4e. Re-running is idempotent (no duplicate variations)
// -----------------------------------------------------------------------------
t_head( '4e. Re-import is idempotent' );

$again = t_ajax( $admin, 'ajax_mapper_import_all_variants', [ 'mapping_id' => $mapping_id ] );
t_ok( 'second import succeeded', ! empty( $again['success'] ) );
t_eq( 'still exactly 5 variations', 5, count( wc_get_product( $wc_id )->get_children() ) );

// -----------------------------------------------------------------------------
// 4f. The import is logged
// -----------------------------------------------------------------------------
t_head( '4f. Sync log records the import' );

$logs = t_log_since( $mark );
t_ok( 'import wrote log entries', ! empty( $logs ) );
t_contains( 'log names the imported product code', $code, $logs );
t_absent( 'no import failures logged', 'Import failed', $logs );

// -----------------------------------------------------------------------------
// 4f-2. Bulk variant buttons follow the VARIANTS' state, not the parent's
//
// The reported case: a simple product became a variable one upstream, so the
// parent row is already mapped while all its variants are still unmapped. The
// parent must still offer "Import all to WooCommerce".
// -----------------------------------------------------------------------------
t_head( '4f-2. Import all / Unmap all availability' );

$row_for = static function ( array $response, string $code ): ?array {
	foreach ( (array) ( $response['data']['rows'] ?? [] ) as $r ) {
		if ( (string) ( $r['sellmo_code'] ?? '' ) === $code ) {
			return $r;
		}
	}
	return null;
};

$variant_states = static function ( ?array $row ): array {
	$mapped = 0;
	$unmapped = 0;
	foreach ( (array) ( $row['variants'] ?? [] ) as $v ) {
		if ( 'mapped' === (string) ( $v['status'] ?? '' ) ) {
			$mapped++;
		} else {
			$unmapped++;
		}
	}
	return [ 'mapped' => $mapped, 'unmapped' => $unmapped ];
};

// Right now the fixture is mapped AND all 5 variants are mapped.
$listed = $row_for( t_ajax( $admin, 'ajax_get_mapper_list', [ 'search' => $code ] ), $code );
t_ok( 'parent row is listed', null !== $listed );

$states = $variant_states( $listed );
t_eq( 'parent is mapped',        'mapped', (string) $listed['status'] );
t_eq( '5 variants are mapped',   5, $states['mapped'] );
t_eq( '0 variants are unmapped', 0, $states['unmapped'] );
t_ok( 'with 2+ mapped variants the parent qualifies for "Unmap all"', $states['mapped'] >= 2 );

// Unmap them all through the new endpoint. The parent must come away unmapped
// too — a row left showing "Mapped" with nothing mapped under it is the bug
// merchants reported.
$mark_unmap = t_log_mark();
$unmap_all  = t_ajax( $admin, 'ajax_mapper_unmap_all_variants', [ 'mapping_id' => $mapping_id ] );

t_ok( 'unmap all succeeded',   ! empty( $unmap_all['success'] ), wp_json_encode( $unmap_all ) );
t_eq( '5 variants unmapped',   5, (int) $unmap_all['data']['unmapped'] );
t_eq( 'the parent was unmapped too', true, (bool) ( $unmap_all['data']['parent_unmapped'] ?? false ) );
t_contains( 'message says so', 'unmapped as well', [ (string) $unmap_all['data']['message'] ] );
t_ok( 'unmap all was logged',  ! empty( t_log_since( $mark_unmap ) ) );

// The WooCommerce variations must survive — only the link is removed.
t_eq( 'WooCommerce variations left intact', 5, count( wc_get_product( $wc_id )->get_children() ) );
t_ok( 'the WooCommerce parent product survives', wc_get_product( $wc_id ) instanceof WC_Product_Variable );

// The mapper row itself no longer points at anything.
$row = Sellmo_Product_Mapping::get_row_by_id( $mapping_id );
t_eq( 'mapper row has no WooCommerce product', 0, (int) ( $row['wc_product_id'] ?? 0 ) );
t_eq( 'mapper row status is not mapped',       0, (int) ( $row['map_status'] ?? 1 ) );

$listed = $row_for( t_ajax( $admin, 'ajax_get_mapper_list', [ 'search' => $code ] ), $code );
$states = $variant_states( $listed );

t_eq( 'parent is now unmapped',     'not_mapped', (string) $listed['status'] );
t_eq( 'now 0 variants mapped',      0, $states['mapped'] );
t_eq( 'now 5 variants unmapped',    5, $states['unmapped'] );
t_ok( 'a parent with 2+ unmapped variants qualifies for "Import all"', $states['unmapped'] >= 2 );

// Unmapping again has nothing to do and must say so rather than silently pass.
$again = t_ajax( $admin, 'ajax_mapper_unmap_all_variants', [ 'mapping_id' => $mapping_id ] );
t_eq( 'second unmap all is rejected', false, (bool) ( $again['success'] ?? true ) );
t_contains( 'and explains why', 'No mapped variants', [ (string) ( $again['data']['message'] ?? '' ) ] );

// A bad reference is rejected.
$bad_ref = t_ajax( $admin, 'ajax_mapper_unmap_all_variants', [ 'mapping_id' => 0 ] );
t_eq( 'zero mapping id rejected', false, (bool) ( $bad_ref['success'] ?? true ) );

// -----------------------------------------------------------------------------
// 4f-3. Unmap all → import all round trip, and the parent-not-mapped case
// -----------------------------------------------------------------------------
t_head( '4f-3. Round trip and a parent that is already unmapped' );

// Everything is unmapped now; importing all must map the parent again.
$reimport = t_ajax( $admin, 'ajax_mapper_import_all_variants', [ 'mapping_id' => $mapping_id ] );
t_ok( 're-import after unmap all succeeded', ! empty( $reimport['success'] ), wp_json_encode( $reimport ) );

$row = Sellmo_Product_Mapping::get_row_by_id( $mapping_id );
t_ok( 'parent is mapped again',       (int) ( $row['wc_product_id'] ?? 0 ) > 0 );
t_eq( 'and its status says so',       1, (int) ( $row['map_status'] ?? 0 ) );

$states = $variant_states( $row_for( t_ajax( $admin, 'ajax_get_mapper_list', [ 'search' => $code ] ), $code ) );
t_eq( 'all 5 variants mapped again', 5, $states['mapped'] );

// Now unmap ONLY the parent, leaving the variants mapped. "Unmap all" must still
// unlink every variant and simply report that there was no parent link to drop.
$unmap_parent = t_ajax( $admin, 'ajax_unmap_product', [
	'sellmo_id'   => $pos_id,
	'sellmo_code' => $code,
	'sellmo_name' => 'Import All Tee',
] );
t_ok( 'parent-only unmap succeeded', ! empty( $unmap_parent['success'] ), wp_json_encode( $unmap_parent ) );
t_eq( 'variants are still mapped', 5, $variant_states( $row_for( t_ajax( $admin, 'ajax_get_mapper_list', [ 'search' => $code ] ), $code ) )['mapped'] );

$orphans = t_ajax( $admin, 'ajax_mapper_unmap_all_variants', [ 'mapping_id' => $mapping_id ] );

t_ok( 'unmap all still succeeded',        ! empty( $orphans['success'] ), wp_json_encode( $orphans ) );
t_eq( '5 variants unmapped',              5, (int) $orphans['data']['unmapped'] );
t_eq( 'no parent link was there to drop', false, (bool) ( $orphans['data']['parent_unmapped'] ?? true ) );
t_absent( 'message does not claim otherwise', 'unmapped as well', [ (string) $orphans['data']['message'] ] );
$final_row = Sellmo_Product_Mapping::get_row_by_id( $mapping_id );
t_ok( 'the mapper row survives',      is_array( $final_row ) );
t_eq( 'parent row is still unmapped', 0, (int) ( $final_row['wc_product_id'] ?? 0 ) );

// A SIMPLE product has no variants, so neither bulk button applies.
$simple_code = T_PREFIX . '-NOVAR-450';
$simple_id   = t_stage_product( t_product( t_uid( 'NOVAR' ), $simple_code, 'No Variants', 'Cat', 90.0, $shop_mine ) );
$simple_row  = $row_for( t_ajax( $admin, 'ajax_get_mapper_list', [ 'search' => $simple_code ] ), $simple_code );

t_ok( 'simple product listed', null !== $simple_row );
t_eq( 'simple product reports no variants', 0, (int) ( $simple_row['variant_count'] ?? -1 ) );
t_ok( 'simple product carries no variant rows', empty( $simple_row['variants'] ) );

// -----------------------------------------------------------------------------
// 4g. Bulk mapper actions ("Auto-map by matching SKU" / "Unmap selected")
// -----------------------------------------------------------------------------
t_head( '4g. Bulk mapper actions' );

$bulk_pos  = t_uid( 'BULK' );
$bulk_code = T_PREFIX . '-BULK-500';
$bulk_row  = t_stage_product( t_product( $bulk_pos, $bulk_code, 'Bulk Target', 'Cat', 120.00, $shop_mine ) );

$bulk_rows = [ [ 'sellmo_id' => $bulk_pos, 'sellmo_code' => $bulk_code, 'sellmo_name' => 'Bulk Target' ] ];

// -- Auto-map with NO matching SKU: must say so, not just "0 rows processed".
$mark_bulk = t_log_mark();
$no_match  = t_ajax( $admin, 'ajax_bulk_mapper', [ 'bulk_action' => 'auto_map_sku', 'rows' => $bulk_rows ] );

t_ok( 'request succeeded',          ! empty( $no_match['success'] ) );
t_eq( 'nothing was mapped',         0, (int) $no_match['data']['processed'] );
t_eq( 'skip reason recorded',       1, (int) ( $no_match['data']['skipped']['no_sku_match'] ?? 0 ) );
t_contains( 'message explains why', 'no WooCommerce product has a matching SKU', [ $no_match['data']['message'] ] );
t_absent( 'message is not a bare count', '0 rows processed.', [ $no_match['data']['message'] ] );
t_ok( 'the run was logged', ! empty( t_log_since( $mark_bulk ) ) );

// -- Unmap something that is not mapped: same treatment.
$not_mapped = t_ajax( $admin, 'ajax_bulk_mapper', [ 'bulk_action' => 'unmap', 'rows' => $bulk_rows ] );

t_eq( 'nothing was unmapped',       0, (int) $not_mapped['data']['processed'] );
t_eq( 'skip reason recorded',       1, (int) ( $not_mapped['data']['skipped']['not_mapped'] ?? 0 ) );
t_contains( 'message explains why', 'not currently mapped', [ $not_mapped['data']['message'] ] );

// -- Auto-map WITH a matching SKU: must actually map.
$bulk_wc = t_wc_simple( 'Bulk Target WC', 120.00, '', '', 0 );
$bulk_product = wc_get_product( $bulk_wc );
$bulk_product->set_sku( $bulk_code );
$bulk_product->save();

$mapped = t_ajax( $admin, 'ajax_bulk_mapper', [ 'bulk_action' => 'auto_map_sku', 'rows' => $bulk_rows ] );

t_eq( 'one row mapped',        1, (int) $mapped['data']['processed'] );
t_eq( 'no rows skipped',       0, (int) array_sum( (array) ( $mapped['data']['skipped'] ?? [] ) ) );
t_contains( 'message confirms the change', 'row updated', [ $mapped['data']['message'] ] );
t_eq( 'mapper row now points at the WC product', $bulk_wc, (int) Sellmo_Product_Mapping::get_row_by_id( $bulk_row )['wc_product_id'] );

// -- Unmap it again: must actually unmap.
$unmapped = t_ajax( $admin, 'ajax_bulk_mapper', [ 'bulk_action' => 'unmap', 'rows' => $bulk_rows ] );

t_eq( 'one row unmapped', 1, (int) $unmapped['data']['processed'] );
t_eq( 'mapper row no longer mapped', 0, (int) Sellmo_Product_Mapping::get_row_by_id( $bulk_row )['wc_product_id'] );

// -- A bad request is still rejected.
$bad = t_ajax( $admin, 'ajax_bulk_mapper', [ 'bulk_action' => 'not_an_action', 'rows' => $bulk_rows ] );
t_eq( 'unknown action rejected', false, (bool) ( $bad['success'] ?? true ) );

$empty = t_ajax( $admin, 'ajax_bulk_mapper', [ 'bulk_action' => 'unmap', 'rows' => [] ] );
t_eq( 'empty selection rejected', false, (bool) ( $empty['success'] ?? true ) );

exit( t_summary() );
