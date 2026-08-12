<?php
/**
 * Scenario 7 — "Sync Products Now" and "Sync Stock Now" run cleanly end to end.
 *
 * Drives the real dashboard entry points:
 *     Sellmo_Product_Mapper_Sync::run_incremental_sync()   (Sync Products Now)
 *     Sellmo_Stock_Sync_Pagination::run()                  (Sync Stock Now)
 *
 * and asserts new products are staged, upstream edits propagate, deleted
 * products and variants are reconciled, stock lands, no error rows are written,
 * and REAL catalogue rows come through completely untouched.
 *
 * ---------------------------------------------------------------------------
 * SAFETY — why the stub echoes the real catalogue back
 *
 * run_incremental_sync() calls reconcile_absent(): every mapper row whose POS id
 * is missing from the response is deleted, along with its WooCommerce product.
 * Feeding it a stub containing only test products would therefore wipe the
 * merchant's entire catalogue.
 *
 * So the stubbed /products page is built as: every real row's own stored
 * raw_payload, echoed back verbatim, plus this test's fixtures. Real rows stay
 * "alive" for the reconcile and round-trip to identical values, which 7e then
 * verifies row by row.
 * ---------------------------------------------------------------------------
 *
 * Run: php tests/test-7-sync-engines.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

$shop = Sellmo_Product_Mapping::selected_shop_id();
if ( '' === $shop ) {
	echo "SKIP: no shop selected in Settings.\n";
	exit( 0 );
}

$map_table = Sellmo_Product_Mapping::table_name();

// -----------------------------------------------------------------------------
// Snapshot the real catalogue so 7e can prove it survived unchanged.
// -----------------------------------------------------------------------------
$snapshot_sql = "SELECT pos_product_id, pos_product_code, pos_product_name, category, price, variant_count, wc_product_id, map_status
                 FROM `{$map_table}` WHERE pos_product_id NOT LIKE %s ORDER BY id";

$before_rows = (array) $wpdb->get_results( $wpdb->prepare( $snapshot_sql, T_PREFIX . '%' ), ARRAY_A );

t_head( 'Baseline' );
t_ok( 'real catalogue rows found to protect', count( $before_rows ) > 0, 'no real rows — the reconcile guard is untested' );

// Echo each real row back to the sync exactly as it was last stored.
$echo = [];
foreach ( $before_rows as $r ) {
	$row     = $wpdb->get_row( $wpdb->prepare( "SELECT raw_payload FROM `{$map_table}` WHERE pos_product_id = %s", $r['pos_product_id'] ), ARRAY_A );
	$decoded = json_decode( (string) ( $row['raw_payload'] ?? '' ), true );

	if ( is_array( $decoded ) && ! empty( $decoded['id'] ) ) {
		$echo[] = $decoded;
		continue;
	}

	// No usable payload — synthesise one that round-trips to the same columns.
	$echo[] = [
		'id'            => (string) $r['pos_product_id'],
		'product_code'  => (string) $r['pos_product_code'],
		'product_name'  => (string) $r['pos_product_name'],
		'category'      => (string) $r['category'],
		'selling_price' => (float) $r['price'],
		'shops'         => [ [ 'shop_id' => $shop, 'price' => (float) $r['price'], 'available_for_sale' => true ] ],
		'variants'      => [],
	];
}
t_eq( 'every real row is echoed back to the sync', count( $before_rows ), count( $echo ) );

// -----------------------------------------------------------------------------
// Fixtures: one brand-new simple product, one brand-new variable product.
// -----------------------------------------------------------------------------
$new_simple_id   = t_uid( 'SYNCSIMPLE' );
$new_simple_code = T_PREFIX . '-SY-800';
$new_simple      = t_product( $new_simple_id, $new_simple_code, 'Sync New Kettle', 'Kitchen', 640.00, $shop );

$new_var_id   = t_uid( 'SYNCVAR' );
$new_var_code = T_PREFIX . '-SY-900';
$sv1 = t_uid( 'SV1' );
$sv2 = t_uid( 'SV2' );
$sv3 = t_uid( 'SV3' );

$new_var = t_product( $new_var_id, $new_var_code, 'Sync New Cap', 'Apparel', 0.0, $shop, [
	t_variant( $sv1, "{$new_var_code}-1", 's', 'red',  400.00, $shop ),
	t_variant( $sv2, "{$new_var_code}-2", 'm', 'red',  400.00, $shop ),
	t_variant( $sv3, "{$new_var_code}-3", 'l', 'blue', 450.00, $shop ),
] );

t_defer( static function () use ( $new_simple_id, $new_simple_code, $new_var_id, $new_var_code ): void {
	t_purge_mapping( Sellmo_Product_Mapping::get_id_by_sellmo( $new_simple_id, $new_simple_code ) );
	t_purge_mapping( Sellmo_Product_Mapping::get_id_by_sellmo( $new_var_id, $new_var_code ) );
} );

// =============================================================================
// 7a. Sync Products Now — first run stages the new products
// =============================================================================
t_head( '7a. Sync Products Now stages new products' );

T_Fake_API::install( [
	'products'  => [ T_Fake_API::products_page( array_merge( $echo, [ $new_simple, $new_var ] ) ) ],
	'inventory' => [ T_Fake_API::inventory_page( [
		[ 'product_id' => $new_simple_id, 'product_code' => $new_simple_code, 'in_stock' => 5 ],
		[ 'product_id' => $sv1, 'product_code' => "{$new_var_code}-1", 'in_stock' => 6 ],
		[ 'product_id' => $sv2, 'product_code' => "{$new_var_code}-2", 'in_stock' => 7 ],
		[ 'product_id' => $sv3, 'product_code' => "{$new_var_code}-3", 'in_stock' => 8 ],
	] ) ],
	'/shops'    => [ 'shops' => [ [ 'shop_id' => $shop, 'shop_name' => 'My Shop' ] ] ],
] );

$mark   = t_log_mark();
$result = ( new Sellmo_Product_Mapper_Sync() )->run_incremental_sync();

t_ok( 'product sync reported success', (bool) $result['success'], wp_json_encode( $result ) );
t_eq( 'two new products staged',       2, (int) $result['count'] );

$simple_row_id = Sellmo_Product_Mapping::get_id_by_sellmo( $new_simple_id, $new_simple_code );
$var_row_id    = Sellmo_Product_Mapping::get_id_by_sellmo( $new_var_id, $new_var_code );

t_ok( 'simple product has a mapper row',   $simple_row_id > 0 );
t_ok( 'variable product has a mapper row', $var_row_id > 0 );

$row = Sellmo_Product_Mapping::get_row_by_id( $var_row_id );
t_eq( 'variable row records 3 variants', 3, (int) $row['variant_count'] );
t_eq( 'variant rows stored',             3, count( Sellmo_Product_Variant_Store::get_variants_for_product( $var_row_id ) ) );

// Per-variant stock is written by the variant sync that run_incremental_sync()
// triggers — Stock_Sync_Pagination only maintains the mapping table (see 7d).
$variant_stock = [];
foreach ( Sellmo_Product_Variant_Store::get_variants_for_product( $var_row_id ) as $sv ) {
	$variant_stock[ (string) $sv['variant_id'] ] = (int) $sv['stock_count'];
}
t_eq( 'variant 1 stock stored', 6, $variant_stock[ $sv1 ] ?? -1 );
t_eq( 'variant 2 stock stored', 7, $variant_stock[ $sv2 ] ?? -1 );
t_eq( 'variant 3 stock stored', 8, $variant_stock[ $sv3 ] ?? -1 );

$errors = t_log_since( $mark, 'error' );
t_ok( 'no errors logged on the first run', empty( $errors ), implode( ' | ', $errors ) );

// =============================================================================
// 7b. Second identical run is a clean no-op
// =============================================================================
t_head( '7b. Re-running with no upstream change is a no-op' );

$mark2   = t_log_mark();
$result2 = ( new Sellmo_Product_Mapper_Sync() )->run_incremental_sync();

t_ok( 'second run reported success', (bool) $result2['success'] );
t_eq( 'nothing new staged',          0, (int) $result2['count'] );
t_ok( 'no errors logged',            empty( t_log_since( $mark2, 'error' ) ) );
t_absent( 'no spurious change entries', 'Mapper row updated for "Sync New Kettle"', t_log_since( $mark2 ) );

// =============================================================================
// 7c. Upstream edits propagate, deletions reconcile
// =============================================================================
t_head( '7c. Upstream edits and deletions' );

// Simple product repriced + recategorised; variable product loses a variant.
$edited_simple = t_product( $new_simple_id, $new_simple_code, 'Sync New Kettle', 'Appliances', 999.00, $shop );
$edited_var    = t_product( $new_var_id, $new_var_code, 'Sync New Cap', 'Apparel', 0.0, $shop, [
	t_variant( $sv1, "{$new_var_code}-1", 's', 'red', 400.00, $shop ),
	t_variant( $sv2, "{$new_var_code}-2", 'm', 'red', 475.00, $shop ),
] );

T_Fake_API::install( [
	'products'  => [ T_Fake_API::products_page( array_merge( $echo, [ $edited_simple, $edited_var ] ) ) ],
	'inventory' => [ T_Fake_API::inventory_page( [
		[ 'product_id' => $sv1, 'product_code' => "{$new_var_code}-1", 'in_stock' => 6 ],
		[ 'product_id' => $sv2, 'product_code' => "{$new_var_code}-2", 'in_stock' => 7 ],
	] ) ],
	'/shops'    => [ 'shops' => [ [ 'shop_id' => $shop, 'shop_name' => 'My Shop' ] ] ],
] );

$mark3 = t_log_mark();
( new Sellmo_Product_Mapper_Sync() )->run_incremental_sync();

$row = Sellmo_Product_Mapping::get_row_by_id( $simple_row_id );
t_eq(   'category change propagated', 'Appliances', (string) $row['category'] );
t_near( 'price change propagated',    999.00,       (float) $row['price'] );

$row = Sellmo_Product_Mapping::get_row_by_id( $var_row_id );
t_eq( 'variant count reduced to 2', 2, (int) $row['variant_count'] );
t_eq( 'removed variant pruned',     2, count( Sellmo_Product_Variant_Store::get_variants_for_product( $var_row_id ) ) );

$logs3 = t_log_since( $mark3 );
t_contains( 'category change logged',   'category "Kitchen" → "Appliances"', $logs3 );
t_contains( 'variant removal logged',   'variant "l / blue" removed',        $logs3 );
t_contains( 'variant reprice logged',   'variant "m / red" price 400.00 → 475.00', $logs3 );
t_ok( 'no errors logged', empty( t_log_since( $mark3, 'error' ) ) );

// A product deleted upstream loses its mapper row.
T_Fake_API::install( [
	'products'  => [ T_Fake_API::products_page( array_merge( $echo, [ $edited_var ] ) ) ],
	'inventory' => [ T_Fake_API::inventory_page( [] ) ],
	'/shops'    => [ 'shops' => [ [ 'shop_id' => $shop, 'shop_name' => 'My Shop' ] ] ],
] );

$mark4 = t_log_mark();
( new Sellmo_Product_Mapper_Sync() )->run_incremental_sync();

t_eq( 'deleted product removed from the mapper', 0, Sellmo_Product_Mapping::get_id_by_sellmo( $new_simple_id, $new_simple_code ) );
t_ok( 'surviving product kept',                  Sellmo_Product_Mapping::get_id_by_sellmo( $new_var_id, $new_var_code ) > 0 );
t_ok( 'no errors logged', empty( t_log_since( $mark4, 'error' ) ) );

// =============================================================================
// 7d. Sync Stock Now
// =============================================================================
t_head( '7d. Sync Stock Now' );

T_Fake_API::install( [
	'products'  => [ T_Fake_API::products_page( array_merge( $echo, [ $edited_var ] ) ) ],
	'inventory' => [ T_Fake_API::inventory_page( [
		[ 'product_id' => $sv1, 'product_code' => "{$new_var_code}-1", 'in_stock' => 21 ],
		[ 'product_id' => $sv2, 'product_code' => "{$new_var_code}-2", 'in_stock' => 22 ],
	] ) ],
	'/shops'    => [ 'shops' => [ [ 'shop_id' => $shop, 'shop_name' => 'My Shop' ] ] ],
] );

// Zero the parent's cached stock first so the assertion below cannot pass on a
// value left over from the earlier product sync.
Sellmo_Product_Mapping::update_stock( $new_var_id, '', $new_var_code, 0 );
t_eq( 'stock zeroed before the run', 0, (int) Sellmo_Product_Mapping::get_row_by_id( $var_row_id )['stock_count'] );

$mark5        = t_log_mark();
$stock_result = ( new Sellmo_Stock_Sync_Pagination() )->run();

t_ok( 'stock sync reported success', (bool) ( $stock_result['success'] ?? false ), wp_json_encode( $stock_result ) );

/*
 * Stock_Sync_Pagination writes stock through Sellmo_Product_Mapping::update_stock(),
 * which only ever touches the MAPPING table — never the variant store. A variant's
 * inventory row is attributed back to its parent product row, so the parent's
 * stock_count ends up holding the last variant figure processed (21 or 22 here).
 * Per-variant stock lives in the variant store and is maintained by the variant
 * sync instead (asserted in 7a).
 */
$parent_stock = (int) Sellmo_Product_Mapping::get_row_by_id( $var_row_id )['stock_count'];

t_ok( 'parent stock refreshed from /inventory', in_array( $parent_stock, [ 21, 22 ], true ), "got: {$parent_stock}" );
t_eq( 'parent marked in stock', 1, (int) Sellmo_Product_Mapping::get_row_by_id( $var_row_id )['stock_status'] );

// Zero stock must flip the status.
T_Fake_API::install( [
	'products'  => [ T_Fake_API::products_page( array_merge( $echo, [ $edited_var ] ) ) ],
	'inventory' => [ T_Fake_API::inventory_page( [
		[ 'product_id' => $sv1, 'product_code' => "{$new_var_code}-1", 'in_stock' => 0 ],
		[ 'product_id' => $sv2, 'product_code' => "{$new_var_code}-2", 'in_stock' => 0 ],
	] ) ],
	'/shops'    => [ 'shops' => [ [ 'shop_id' => $shop, 'shop_name' => 'My Shop' ] ] ],
] );

( new Sellmo_Stock_Sync_Pagination() )->run();

t_eq( 'zero stock recorded',        0, (int) Sellmo_Product_Mapping::get_row_by_id( $var_row_id )['stock_count'] );
t_eq( 'parent marked out of stock', 0, (int) Sellmo_Product_Mapping::get_row_by_id( $var_row_id )['stock_status'] );

t_ok( 'no errors logged during stock sync', empty( t_log_since( $mark5, 'error' ) ), implode( ' | ', t_log_since( $mark5, 'error' ) ) );
t_ok( 'stock sync wrote log entries',       ! empty( t_log_since( $mark5 ) ) );

// =============================================================================
// 7e. The real catalogue is byte-for-byte unchanged
// =============================================================================
t_head( '7e. Real catalogue untouched' );

$after_rows = (array) $wpdb->get_results( $wpdb->prepare( $snapshot_sql, T_PREFIX . '%' ), ARRAY_A );

// Key by POS id rather than compare by position. A row appearing mid-run (a
// concurrent sync in the browser, a cron tick) must not shift every subsequent
// comparison and fail the suite — what matters is that no row that existed
// BEFORE was deleted or altered.
$index_by_id = static function ( array $rows ): array {
	$indexed = [];
	foreach ( $rows as $row ) {
		$indexed[ (string) $row['pos_product_id'] ] = $row;
	}
	return $indexed;
};

$before_by_id = $index_by_id( $before_rows );
$after_by_id  = $index_by_id( $after_rows );

$missing = array_diff( array_keys( $before_by_id ), array_keys( $after_by_id ) );
t_eq( 'no pre-existing real row was deleted', [], array_values( $missing ) );

$diffs = [];
foreach ( $before_by_id as $pos_id => $before ) {
	$after = $after_by_id[ $pos_id ] ?? null;
	if ( null === $after ) {
		continue; // Already reported as missing above.
	}

	foreach ( $before as $field => $value ) {
		// price is DECIMAL — compare numerically, not as a string.
		$same = ( 'price' === $field )
			? abs( (float) $value - (float) ( $after[ $field ] ?? 0 ) ) < 0.005
			: (string) $value === (string) ( $after[ $field ] ?? '' );

		if ( ! $same ) {
			$diffs[] = "{$before['pos_product_code']}.{$field}: '{$value}' -> '" . ( $after[ $field ] ?? '' ) . "'";
		}
	}
}

t_ok( 'no real row was modified', empty( $diffs ), implode( "\n       ", $diffs ) );

// A row that appeared during the run is not damage, but say so rather than
// silently ignoring it — it explains any surprise in the numbers above.
$appeared = array_diff( array_keys( $after_by_id ), array_keys( $before_by_id ) );
if ( ! empty( $appeared ) ) {
	echo '  note: ' . count( $appeared ) . " real row(s) appeared during the run (concurrent sync?).\n";
}

exit( t_summary() );
