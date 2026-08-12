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

exit( t_summary() );
