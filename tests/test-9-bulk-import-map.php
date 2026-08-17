<?php
/**
 * Scenario 9 — "Import & map all to WooCommerce" bulk action, and Dashboard
 * dates rendered in the time zone chosen in Settings.
 *
 * 9a–9f drive the real wp_ajax_sellmo_wc_bulk_mapper handler with the new
 * `import_map` action over a mixed selection (simple, variable, already mapped,
 * out of shop, no reference) and assert what each row produced in WooCommerce.
 *
 * 9g–9i assert the Dashboard formats every date with the configured Order Time
 * Zone, falling back to the WordPress one when unset or invalid.
 *
 * Run: php tests/test-9-bulk-import-map.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Adopt the site's real shop (see the safety note in test-3) so the fixtures are
// in scope; the out-of-shop fixture uses a synthetic id.
$shop_mine  = Sellmo_Product_Mapping::selected_shop_id();
$shop_other = t_uid( 'OTHERSHOP' );

if ( '' === $shop_mine ) {
	echo "SKIP: no shop selected in Settings — mapper imports are shop-scoped.\n";
	exit( 0 );
}

$admin = new Sellmo_Admin();

// -----------------------------------------------------------------------------
// Fixtures: one simple, one variable, one already mapped, one in another shop.
// -----------------------------------------------------------------------------
$simple_pos     = t_uid( 'BIMPS' );
$simple_code    = T_PREFIX . '-BIMP-600';
$simple_payload = t_product( $simple_pos, $simple_code, 'Bulk Simple', 'Cat', 250.00, $shop_mine );
$simple_map     = t_stage_product( $simple_payload );

$var_pos      = t_uid( 'BIMPV' );
$var_code     = T_PREFIX . '-BIMP-700';
$variant_ids  = [];
$variants     = [];

foreach ( [ [ 's', 'red', 300.0 ], [ 'm', 'red', 350.0 ], [ 'l', 'blue', 400.0 ] ] as $i => $spec ) {
	$vid           = t_uid( 'BV' . $i );
	$variant_ids[] = $vid;
	$variants[]    = t_variant( $vid, "{$var_code}-" . ( $i + 1 ), $spec[0], $spec[1], $spec[2], $shop_mine );
}

$var_payload = t_product( $var_pos, $var_code, 'Bulk Variable', 'Cat', 0.00, $shop_mine, $variants );
$var_map     = t_stage_product( $var_payload );
t_stage_variants( $var_map, $var_payload );

$done_pos     = t_uid( 'BIMPD' );
$done_code    = T_PREFIX . '-BIMP-800';
$done_payload = t_product( $done_pos, $done_code, 'Bulk Already Mapped', 'Cat', 99.00, $shop_mine );
$done_map     = t_stage_product( $done_payload );
$done_wc      = t_wc_simple( 'Bulk Already Mapped WC', 99.00, $done_code, $done_pos );
Sellmo_Product_Mapping::upsert_mapping( $done_pos, $done_code, 'Bulk Already Mapped', $done_wc );

$out_pos     = t_uid( 'BIMPO' );
$out_code    = T_PREFIX . '-BIMP-900';
$out_payload = t_product( $out_pos, $out_code, 'Bulk Other Shop', 'Cat', 10.00, $shop_other );
$out_map     = t_stage_product( $out_payload );

t_head( '9. Fixtures' );
t_ok( 'simple product staged',   $simple_map > 0 );
t_ok( 'variable product staged', $var_map > 0 );
t_eq( 'variable product has 3 variant rows', 3, count( Sellmo_Product_Variant_Store::get_variants_for_product( $var_map ) ) );
t_eq( 'already-mapped fixture is mapped', $done_wc, (int) Sellmo_Product_Mapping::get_row_by_id( $done_map )['wc_product_id'] );
t_eq( 'simple product starts unmapped', 0, (int) Sellmo_Product_Mapping::get_row_by_id( $simple_map )['wc_product_id'] );
t_eq( 'variable product starts unmapped', 0, (int) Sellmo_Product_Mapping::get_row_by_id( $var_map )['wc_product_id'] );

T_Fake_API::install( [
	'products'  => [ T_Fake_API::products_page( [ $simple_payload, $var_payload, $done_payload, $out_payload ] ) ],
	'inventory' => [
		T_Fake_API::inventory_page( array_merge(
			[ [ 'product_id' => $simple_pos, 'product_code' => $simple_code, 'in_stock' => 7 ] ],
			array_map(
				static fn( string $vid, int $i ): array => [
					'product_id'   => $vid,
					'product_code' => '',
					'in_stock'     => 10 + $i,
				],
				$variant_ids,
				array_keys( $variant_ids )
			)
		) ),
	],
] );

// -----------------------------------------------------------------------------
// 9a. One bulk run maps everything selectable — simple AND variable
// -----------------------------------------------------------------------------
t_head( '9a. Bulk "Import & map all to WooCommerce"' );

$rows = [
	[ 'sellmo_id' => $simple_pos, 'sellmo_code' => $simple_code, 'sellmo_name' => 'Bulk Simple',         'mapping_id' => $simple_map ],
	[ 'sellmo_id' => $var_pos,    'sellmo_code' => $var_code,    'sellmo_name' => 'Bulk Variable',       'mapping_id' => $var_map ],
	[ 'sellmo_id' => $done_pos,   'sellmo_code' => $done_code,   'sellmo_name' => 'Bulk Already Mapped', 'mapping_id' => $done_map ],
	[ 'sellmo_id' => $out_pos,    'sellmo_code' => $out_code,    'sellmo_name' => 'Bulk Other Shop',     'mapping_id' => $out_map ],
	// A WooCommerce-only row carries no Sellmo reference at all.
	[ 'sellmo_id' => '',          'sellmo_code' => '',           'sellmo_name' => '',                    'mapping_id' => 0 ],
];

$mark   = t_log_mark();
$result = t_ajax( $admin, 'ajax_bulk_mapper', [ 'bulk_action' => 'import_map', 'rows' => $rows ] );

t_ok( 'request succeeded', ! empty( $result['success'] ), wp_json_encode( $result ) );
t_eq( 'two rows imported and mapped', 2, (int) ( $result['data']['processed'] ?? 0 ) );

$skipped = (array) ( $result['data']['skipped'] ?? [] );
t_eq( 'already-mapped row skipped',  1, (int) ( $skipped['sellmo_already_mapped'] ?? 0 ) );
t_eq( 'out-of-shop row skipped',     1, (int) ( $skipped['sellmo_other_shop'] ?? 0 ) );
t_eq( 'reference-less row skipped',  1, (int) ( $skipped['no_reference'] ?? 0 ) );
t_contains( 'message counts the changes', 'rows updated', [ (string) $result['data']['message'] ] );
t_contains( 'message names a skip reason', 'already mapped', [ (string) $result['data']['message'] ] );
t_ok( 'the run was logged', ! empty( t_log_since( $mark ) ) );

// -----------------------------------------------------------------------------
// 9b. The simple product landed as a mapped WooCommerce simple product
// -----------------------------------------------------------------------------
t_head( '9b. Simple product result' );

$simple_wc = (int) Sellmo_Product_Mapping::get_row_by_id( $simple_map )['wc_product_id'];
t_ok( 'simple product is now mapped', $simple_wc > 0 );

if ( $simple_wc > 0 ) {
	$product = wc_get_product( $simple_wc );
	t_ok( 'a simple WooCommerce product was created', $product instanceof WC_Product_Simple );
	t_eq( 'name matches the POS name',   'Bulk Simple', (string) $product->get_name() );
	t_near( 'price matches the POS price', 250.00, (float) $product->get_regular_price() );
	// Imported simple products do not manage stock (only variations do), but they
	// must carry the POS code the stock sync matches on.
	t_eq( 'POS product code recorded on the product', $simple_code, (string) get_post_meta( $simple_wc, '_pos_product_code', true ) );
	t_eq( 'the stock sync can match the imported product', 1, ( new Sellmo_Stock_Sync() )->sync_stock_for_wc_product( $simple_wc ) );
}

// -----------------------------------------------------------------------------
// 9c. The variable product landed with one variation per in-shop variant
// -----------------------------------------------------------------------------
t_head( '9c. Variable product result' );

$var_wc = (int) Sellmo_Product_Mapping::get_row_by_id( $var_map )['wc_product_id'];
t_ok( 'variable product is now mapped', $var_wc > 0 );

if ( $var_wc > 0 ) {
	$parent = wc_get_product( $var_wc );
	t_ok( 'a variable WooCommerce product was created', $parent instanceof WC_Product_Variable );
	t_eq( 'one variation per variant', 3, count( $parent->get_children() ) );

	$linked = 0;
	foreach ( Sellmo_Product_Variant_Store::get_variants_for_product( $var_map ) as $sv ) {
		$wcv = (int) ( $sv['wc_variation_id'] ?? 0 );
		if ( $wcv > 0 && wc_get_product( $wcv ) instanceof WC_Product_Variation ) {
			$linked++;
		}
	}
	t_eq( 'every variant linked to its variation', 3, $linked );

	$stock = [];
	foreach ( $parent->get_children() as $child ) {
		$stock[] = (int) wc_get_product( (int) $child )->get_stock_quantity();
	}
	sort( $stock );
	t_eq( 'each variation carries its /inventory stock', [ 10, 11, 12 ], $stock );
}

// -----------------------------------------------------------------------------
// 9d. Untouched rows really were untouched
// -----------------------------------------------------------------------------
t_head( '9d. Skipped rows unchanged' );

t_eq( 'already-mapped row still points at its original product', $done_wc, (int) Sellmo_Product_Mapping::get_row_by_id( $done_map )['wc_product_id'] );
t_eq( 'out-of-shop row was not imported',                        0,        (int) Sellmo_Product_Mapping::get_row_by_id( $out_map )['wc_product_id'] );

// -----------------------------------------------------------------------------
// 9e. Re-running creates nothing new
// -----------------------------------------------------------------------------
t_head( '9e. Re-running is idempotent' );

$again = t_ajax( $admin, 'ajax_bulk_mapper', [ 'bulk_action' => 'import_map', 'rows' => $rows ] );

t_ok( 'second run succeeded', ! empty( $again['success'] ) );
t_eq( 'simple product still maps to the same WC product', $simple_wc, (int) Sellmo_Product_Mapping::get_row_by_id( $simple_map )['wc_product_id'] );
t_eq( 'variable product still maps to the same WC product', $var_wc,   (int) Sellmo_Product_Mapping::get_row_by_id( $var_map )['wc_product_id'] );
t_eq( 'still exactly 3 variations', 3, count( wc_get_product( $var_wc )->get_children() ) );
// Both simple rows are mapped by now — the fixture that started mapped, and the
// one this test mapped in 9a.
t_eq( 'both simple rows are reported as already mapped', 2, (int) ( $again['data']['skipped']['sellmo_already_mapped'] ?? 0 ) );
// The variable row re-syncs (harmlessly) rather than being skipped, so exactly
// one row is still reported as processed.
t_eq( 'only the variable row re-ran', 1, (int) ( $again['data']['processed'] ?? -1 ) );

// -----------------------------------------------------------------------------
// 9f. Bad requests are still rejected
// -----------------------------------------------------------------------------
t_head( '9f. Request validation' );

$empty = t_ajax( $admin, 'ajax_bulk_mapper', [ 'bulk_action' => 'import_map', 'rows' => [] ] );
t_eq( 'empty selection rejected', false, (bool) ( $empty['success'] ?? true ) );

$bad = t_ajax( $admin, 'ajax_bulk_mapper', [ 'bulk_action' => 'import_everything', 'rows' => $rows ] );
t_eq( 'unknown action rejected', false, (bool) ( $bad['success'] ?? true ) );

// -----------------------------------------------------------------------------
// 9g. Dashboard dates use the time zone chosen in Settings
// -----------------------------------------------------------------------------
t_head( '9g. Dashboard time zone' );

$original_tz = Sellmo_Settings::get( 'order_timezone' );
t_defer( static function () use ( $original_tz ): void {
	Sellmo_Settings::set( 'order_timezone', $original_tz );
} );

$stamp = 1755425520; // 2025-08-17 10:12:00 UTC
$fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

// Pick a zone whose offset differs from the site's, so the two renderings cannot
// coincidentally match and pass a broken implementation.
$site_offset = wp_timezone()->getOffset( new DateTime( '@' . $stamp ) );
$tz_id       = 'Pacific/Kiritimati'; // UTC+14
if ( ( new DateTimeZone( $tz_id ) )->getOffset( new DateTime( '@' . $stamp ) ) === $site_offset ) {
	$tz_id = 'Pacific/Honolulu'; // UTC-10
}

Sellmo_Settings::set( 'order_timezone', $tz_id );
t_eq( 'display_timezone() follows the setting', $tz_id, Sellmo_Settings::display_timezone()->getName() );

$expected_tz   = (string) wp_date( $fmt, $stamp, new DateTimeZone( $tz_id ) );
$expected_site = (string) wp_date( $fmt, $stamp );
t_ok( 'the two zones really do render differently', $expected_tz !== $expected_site, "both rendered as {$expected_tz}" );

// Render the real Dashboard view with a known "Last run" time.
t_set_setting( 'last_product_sync', [ 'time' => $stamp, 'status' => 'success', 'count' => 0 ] );

ob_start();
require SELLMO_WC_PATH . 'admin/views/page-dashboard.php';
$html = (string) ob_get_clean();

t_ok( 'dashboard shows the time in the selected zone', false !== strpos( $html, $expected_tz ), "expected: {$expected_tz}" );
t_ok( 'dashboard does not show the site-zone time',    false === strpos( $html, $expected_site ), "unexpected: {$expected_site}" );

// The helpers themselves — the Unix-timestamp and MySQL-DATETIME paths.
t_eq( 'timestamp helper uses the selected zone', $expected_tz, sellmo_dashboard_format_ts( $stamp ) );
t_eq(
	'MySQL datetime helper uses the selected zone',
	$expected_tz,
	sellmo_dashboard_format_dt( gmdate( 'Y-m-d H:i:s', $stamp ) )
);

// -----------------------------------------------------------------------------
// 9h. No zone set, or a bogus one, falls back to the WordPress time zone
// -----------------------------------------------------------------------------
t_head( '9h. Fallback to the WordPress time zone' );

Sellmo_Settings::set( 'order_timezone', '' );
t_eq( 'unset falls back to the site zone', wp_timezone()->getName(), Sellmo_Settings::display_timezone()->getName() );
t_eq( 'helper falls back too',             $expected_site,           sellmo_dashboard_format_ts( $stamp ) );

Sellmo_Settings::set( 'order_timezone', 'Mars/Olympus_Mons' );
t_eq( 'an invalid zone falls back too', wp_timezone()->getName(), Sellmo_Settings::display_timezone()->getName() );

// -----------------------------------------------------------------------------
// 9i. Empty and zero timestamps still render as a dash
// -----------------------------------------------------------------------------
t_head( '9i. Empty values' );

Sellmo_Settings::set( 'order_timezone', $tz_id );
t_eq( 'zero timestamp',      '—', sellmo_dashboard_format_ts( 0 ) );
t_eq( 'empty datetime',      '—', sellmo_dashboard_format_dt( '' ) );
t_eq( 'zero datetime',       '—', sellmo_dashboard_format_dt( '0000-00-00 00:00:00' ) );

exit( t_summary() );
