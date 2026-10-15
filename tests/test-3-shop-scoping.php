<?php
/**
 * Scenario 3 — shop selection scopes what is shown and mappable.
 *
 * With a shop selected in Settings, only products (simple AND variable) that
 * are sellable in that shop may appear in the Product Mapper and be mapped.
 * Products belonging to another shop must be hidden, must be refused for
 * mapping, and — if already mapped — must be removed from WooCommerce while
 * their mapper row is kept.
 *
 * Run: php tests/test-3-shop-scoping.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/*
 * SAFETY — read this before editing.
 *
 * enforce_selected_shop_scope() sweeps the WHOLE mapping table: every mapped row
 * whose shop list excludes the selected shop has its WooCommerce product DELETED.
 * Pointing shop_id at a synthetic test shop therefore marks every REAL product as
 * out-of-shop and destroys them. (It did exactly that once — real products #519
 * and #552 were deleted by an earlier version of this file.)
 *
 * So: this test never overrides shop_id. It adopts the site's REAL selected shop
 * as "mine" and uses a synthetic id only for "another shop". Real rows stay in
 * scope; only this test's own fixture is ever out of scope.
 */
$shop_mine  = Sellmo_Product_Mapping::selected_shop_id();
$shop_other = t_uid( 'OTHERSHOP' );

if ( '' === $shop_mine ) {
	echo "SKIP: no shop selected in Settings — shop scoping cannot be tested safely.\n";
	echo "      Choose a shop on the Settings screen, then re-run.\n";
	exit( 0 );
}

$shop_names = [ $shop_mine => 'My Shop', $shop_other => 'Other Shop' ];

// -----------------------------------------------------------------------------
// 3a. Shop resolution from the payload
// -----------------------------------------------------------------------------
t_head( '3a. Shop membership resolved from the payload' );

$simple_mine = t_product( t_uid( 'SM' ), T_PREFIX . '-SM', 'Simple Mine', 'Cat', 100.0, $shop_mine );
$simple_other = t_product( t_uid( 'SO' ), T_PREFIX . '-SO', 'Simple Other', 'Cat', 100.0, $shop_other );

[ $ids_mine ] = Sellmo_Product_Mapping::resolve_available_shops( $simple_mine, $shop_names );
[ $ids_other ] = Sellmo_Product_Mapping::resolve_available_shops( $simple_other, $shop_names );

t_eq( 'simple product resolves its own shop',    $shop_mine,  $ids_mine );
t_eq( 'other simple product resolves its shop',  $shop_other, $ids_other );

// A variable product carries shops on its VARIANTS, not at product level —
// the parent must still resolve to the union of its variants' shops.
$var_code = T_PREFIX . '-VM';
$var_mine = t_product( t_uid( 'VM' ), $var_code, 'Variable Mine', 'Cat', 0.0, $shop_mine, [
	t_variant( t_uid( 'VMA' ), "{$var_code}-1", 's', 'red', 300.0, $shop_mine ),
	t_variant( t_uid( 'VMB' ), "{$var_code}-2", 'm', 'red', 300.0, $shop_mine ),
] );

[ $ids_var ] = Sellmo_Product_Mapping::resolve_available_shops( $var_mine, $shop_names );
t_eq( 'variable product inherits shop from its variants', $shop_mine, $ids_var );

// A variant that is not available_for_sale must not contribute its shop.
$unavailable = t_variant( t_uid( 'VX' ), "{$var_code}-9", 'xl', 'grey', 300.0, $shop_other );
$unavailable['shops'][0]['available_for_sale'] = false;
$mixed = t_product( t_uid( 'VMIX' ), T_PREFIX . '-VMIX', 'Mixed', 'Cat', 0.0, $shop_mine, [
	t_variant( t_uid( 'VMIXA' ), T_PREFIX . '-VMIX-1', 's', 'red', 300.0, $shop_mine ),
	$unavailable,
] );

[ $ids_mixed ] = Sellmo_Product_Mapping::resolve_available_shops( $mixed, $shop_names );
t_eq( 'not-for-sale variant shop excluded', $shop_mine, $ids_mixed );

// -----------------------------------------------------------------------------
// 3b. The in-scope predicate the mapper list filters on
// -----------------------------------------------------------------------------
t_head( '3b. Selected-shop predicate' );

t_ok( 'product in the selected shop is in scope',     Sellmo_Product_Mapping::is_in_selected_shop( $shop_mine ) );
t_ok( 'product in another shop is out of scope',      ! Sellmo_Product_Mapping::is_in_selected_shop( $shop_other ) );
t_ok( 'product in both shops is in scope',            Sellmo_Product_Mapping::is_in_selected_shop( "{$shop_other},{$shop_mine}" ) );
t_ok( 'product with no shops is out of scope',        ! Sellmo_Product_Mapping::is_in_selected_shop( '' ) );
t_eq( 'selected shop reads back from settings',       $shop_mine, Sellmo_Product_Mapping::selected_shop_id() );

// -----------------------------------------------------------------------------
// 3c. Variant sub-rows in the mapper are scoped to the selected shop
// -----------------------------------------------------------------------------
t_head( '3c. Variant sub-rows filtered by selected shop' );

$mixed_code = T_PREFIX . '-VSCOPE';
$v_in_a  = t_uid( 'INA' );
$v_in_b  = t_uid( 'INB' );
$v_out   = t_uid( 'OUT' );

$scoped = t_product( t_uid( 'VSCOPE' ), $mixed_code, 'Scoped Variable', 'Cat', 0.0, $shop_mine, [
	t_variant( $v_in_a, "{$mixed_code}-1", 's', 'red',  300.0, $shop_mine ),
	t_variant( $v_in_b, "{$mixed_code}-2", 'm', 'red',  300.0, $shop_mine ),
	t_variant( $v_out,  "{$mixed_code}-3", 'l', 'blue', 300.0, $shop_other ),
] );

$scoped_id = t_stage_product( $scoped );
t_stage_variants( $scoped_id, $scoped );

t_eq( 'all 3 variants stored', 3, count( Sellmo_Product_Variant_Store::get_variants_for_product( $scoped_id ) ) );

$admin = new Sellmo_Admin();
$rows  = t_call( $admin, 'build_variant_rows_for_mapper', [ $scoped_id, 0 ] );

$shown = array_map( static fn( array $r ): string => (string) $r['variant_id'], $rows );

t_eq( 'only in-shop variants are shown', 2, count( $rows ) );
t_ok( 'in-shop variant A shown',  in_array( $v_in_a, $shown, true ) );
t_ok( 'in-shop variant B shown',  in_array( $v_in_b, $shown, true ) );
t_ok( 'out-of-shop variant hidden', ! in_array( $v_out, $shown, true ) );

// -----------------------------------------------------------------------------
// 3d. Mapping is refused for an out-of-shop product
// -----------------------------------------------------------------------------
t_head( '3d. Out-of-shop products cannot be mapped' );

$other_code   = T_PREFIX . '-OTHERONLY';
$other_pos_id = t_uid( 'OTHERONLY' );
$other        = t_product( $other_pos_id, $other_code, 'Other Shop Only', 'Cat', 100.0, $shop_other );
$other_id     = t_stage_product( $other );

t_ok(
	'product_in_another_shop() flags the out-of-shop product',
	(bool) t_call( $admin, 'product_in_another_shop', [ $other_pos_id, $other_code ] )
);

$mine_code   = T_PREFIX . '-MINEONLY';
$mine_pos_id = t_uid( 'MINEONLY' );
$mine        = t_product( $mine_pos_id, $mine_code, 'My Shop Only', 'Cat', 100.0, $shop_mine );
t_stage_product( $mine );

t_ok(
	'product_in_another_shop() clears the in-shop product',
	! (bool) t_call( $admin, 'product_in_another_shop', [ $mine_pos_id, $mine_code ] )
);

// Importing an out-of-shop product's variants must be refused outright.
$refused = t_call( $admin, 'import_variants_for_parent', [ $other_id, [ 'anything' ] ] );
t_ok( 'variant import refused for another shop', is_wp_error( $refused ) );
if ( is_wp_error( $refused ) ) {
	t_eq( 'refusal carries the right error code', 'sellmo_other_shop', $refused->get_error_code() );
}

// -----------------------------------------------------------------------------
// 3d-2. The mapper list hides other-shop rows unless "Show other-shop products"
//        is ticked, and flags the ones it does show.
// -----------------------------------------------------------------------------
t_head( '3d-2. "Show other-shop products" toggle and the "Another shop" status' );

/*
 * How the UI renders an out-of-shop row (see the screenshot in the ticket):
 *
 *   Simple Product | 10001 | test category 2 | 666.00 | — | [Another shop (No company2)] | —
 *
 * The server does NOT send the literal string "Another shop". It sends the row
 * with `in_selected_shop => false`, and admin/js/sellmo-admin.js:1406-1409 turns
 * that flag into the amber "Another shop" status badge, blanks the WooCommerce
 * Product column, and replaces the Map / Import actions with an em dash.
 *
 * So the flag below IS the "Another shop" status — it is the whole contract the
 * badge is rendered from. The row's own `status` field stays 'mapped' /
 * 'not_mapped' and is overridden client-side.
 *
 * Such rows are only present at all when "Show other-shop products" is ticked
 * (show_other_shops = '1'); unticked is the default and hides them.
 */

$find_row = static function ( array $response, string $code ): ?array {
	foreach ( (array) ( $response['data']['rows'] ?? [] ) as $row ) {
		if ( (string) ( $row['sellmo_code'] ?? '' ) === $code ) {
			return $row;
		}
	}
	return null;
};

// Unticked (default): other-shop products are hidden entirely.
$hidden = t_ajax( $admin, 'ajax_get_mapper_list', [ 'search' => T_PREFIX ] );

t_ok( 'mapper list responded', ! empty( $hidden['success'] ) );
t_ok( 'other-shop product hidden by default', null === $find_row( $hidden, $other_code ) );
t_ok( 'in-shop product visible by default',   null !== $find_row( $hidden, $mine_code ) );

// Ticked: other-shop products appear, flagged so the UI shows "Another shop".
$shown_resp = t_ajax( $admin, 'ajax_get_mapper_list', [ 'search' => T_PREFIX, 'show_other_shops' => '1' ] );
$other_row  = $find_row( $shown_resp, $other_code );

t_ok( 'other-shop product shown when ticked', null !== $other_row );

if ( null !== $other_row ) {
	// STATUS: "Another shop" — driven entirely by this flag being false.
	t_eq( 'STATUS "Another shop": in_selected_shop is false', false, (bool) $other_row['in_selected_shop'] );
	t_eq( 'other-shop row is not mapped',      'not_mapped', (string) $other_row['status'] );
	t_eq( 'other-shop row has no WC product',  0,            (int) $other_row['wc_product_id'] );
	t_ok( 'other-shop row still carries its shop name', '' !== (string) $other_row['shop_name'] );
}

$mine_row_resp = $find_row( $shown_resp, $mine_code );
t_ok( 'in-shop product still listed when ticked', null !== $mine_row_resp );
if ( null !== $mine_row_resp ) {
	// STATUS: normal — the badge shows Mapped / Not Mapped, actions are enabled.
	t_eq( 'in-shop row flagged in scope', true, (bool) $mine_row_resp['in_selected_shop'] );
}

// -----------------------------------------------------------------------------
// 3e. Already-mapped out-of-shop products are pulled from WooCommerce
// -----------------------------------------------------------------------------
t_head( '3e. Mapped out-of-shop product removed from WooCommerce, row kept' );

$stray_wc = t_wc_simple( 'Stray Shop Product', 100.0, $other_code, $other_pos_id );
Sellmo_Product_Mapping::upsert_mapping( $other_pos_id, $other_code, 'Other Shop Only', $stray_wc );

$row = Sellmo_Product_Mapping::get_row_by_id( $other_id );
t_eq( 'fixture: row is mapped', $stray_wc, (int) $row['wc_product_id'] );

// Snapshot every OTHER mapped product so we can prove the sweep touched only
// this test's fixture. A regression here means real merchant data is at risk.
global $wpdb;
$map_table   = Sellmo_Product_Mapping::table_name();
$other_mapped = (array) $wpdb->get_col(
	$wpdb->prepare(
		"SELECT wc_product_id FROM `{$map_table}` WHERE wc_product_id IS NOT NULL AND id <> %d",
		$other_id
	)
);

// Deletion is the Auto map ON behaviour; OFF (keep, unlinked) is checked in 3g.
t_set_setting( 'auto_map_enabled', 'yes' );

$mark    = t_log_mark();
$removed = Sellmo_Product_Mapping::enforce_selected_shop_scope();

t_ok( 'scope enforcement removed at least one product', $removed >= 1 );

$survivors = 0;
foreach ( $other_mapped as $wc ) {
	if ( wc_get_product( (int) $wc ) instanceof WC_Product ) {
		$survivors++;
	}
}
t_eq( 'every other mapped product survived the sweep', count( $other_mapped ), $survivors );
t_ok( 'WooCommerce product deleted', ! ( wc_get_product( $stray_wc ) instanceof WC_Product ) );

$row = Sellmo_Product_Mapping::get_row_by_id( $other_id );
t_ok( 'mapper row is kept', is_array( $row ) );
t_eq( 'mapper row is now unmapped', 0, (int) ( $row['wc_product_id'] ?? 0 ) );

// The in-shop product must be untouched by the same pass.
$mine_row = Sellmo_Product_Mapping::get_row_by_id(
	Sellmo_Product_Mapping::get_id_by_sellmo( $mine_pos_id, $mine_code )
);
t_ok( 'in-shop product row survives', is_array( $mine_row ) );

t_head( '3f. Log records the shop-scope removal' );
$logs = t_log_since( $mark );
t_ok( 'scope enforcement wrote a log entry', ! empty( $logs ), 'no log rows written' );

// -----------------------------------------------------------------------------
// 3g. Auto map OFF: the out-of-shop WooCommerce product is kept, unlinked
// -----------------------------------------------------------------------------
t_head( '3g. Auto map off: out-of-shop product kept as unmapped' );

t_set_setting( 'auto_map_enabled', 'no' );
$kept_wc = t_wc_simple( 'Stray Shop Product 2', 100.0, $other_code, $other_pos_id );
Sellmo_Product_Mapping::upsert_mapping( $other_pos_id, $other_code, 'Other Shop Only', $kept_wc );

Sellmo_Product_Mapping::enforce_selected_shop_scope();

t_ok( 'WooCommerce product kept', wc_get_product( $kept_wc ) instanceof WC_Product );
t_eq( 'POS id meta stripped', '', (string) get_post_meta( $kept_wc, '_salesplay_pos_product_id', true ) );
t_eq( 'mapper row unmapped', 0, (int) ( Sellmo_Product_Mapping::get_row_by_id( $other_id )['wc_product_id'] ?? 0 ) );

exit( t_summary() );
