<?php
/**
 * Scenario 17 — Auto map by SKU, and keep-vs-delete when a POS product goes away.
 *
 * 17a–17h drive Sellmo_Auto_Mapper over staged fixtures: a unique simple match
 * maps; duplicate SKUs, duplicate POS codes, already-mapped targets, type
 * mismatches, partial / split variant matches and out-of-shop rows map nothing.
 *
 * 17i proves "Sync Products Now" runs the auto map only when the setting is on.
 *
 * 17j–17l prove that with Auto map OFF a deleted POS product / variant leaves
 * its WooCommerce product in place, unlinked; with it ON it is deleted.
 *
 * Run: php tests/test-17-auto-map-by-sku.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

$shop = Sellmo_Product_Mapping::selected_shop_id();
if ( '' === $shop ) {
	echo "SKIP: no shop selected in Settings — auto map is shop-scoped.\n";
	exit( 0 );
}
$other_shop = t_uid( 'OTHERSHOP' );
$map_table  = Sellmo_Product_Mapping::table_name();

// Real rows must come out of every auto-map run exactly as they went in.
$real_sql    = "SELECT pos_product_id, wc_product_id, map_status FROM `{$map_table}` WHERE pos_product_id NOT LIKE %s ORDER BY id";
$real_before = (array) $wpdb->get_results( $wpdb->prepare( $real_sql, T_PREFIX . '%' ), ARRAY_A );

/** WC simple product with a SKU and no POS meta (a merchant's own product). */
$wc_with_sku = static function ( string $name, string $sku ): int {
	$id = t_wc_simple( $name, 10.0 );
	delete_post_meta( $id, '_sellmo_sync_enabled' );
	update_post_meta( $id, '_sku', $sku ); // Raw write: lets a test create a duplicate SKU.
	return $id;
};

/** WC variable product whose variations carry the given SKUs. */
$wc_variable = static function ( string $name, array $skus ): array {
	$parent = new WC_Product_Variable();
	$parent->set_name( $name );
	$parent->set_status( 'publish' );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array_map( 'strval', array_keys( $skus ) ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$parent->set_attributes( [ $attr ] );
	$parent_id = $parent->save();

	$ids = [];
	foreach ( array_values( $skus ) as $i => $sku ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent_id );
		$v->set_attributes( [ 'size' => (string) $i ] );
		$v->set_regular_price( '10' );
		$v->set_status( 'publish' );
		$ids[] = $v->save();
		update_post_meta( end( $ids ), '_sku', $sku );
	}

	t_defer( static function () use ( $parent_id ): void {
		t_delete_wc_product( $parent_id );
	} );

	return [ $parent_id, $ids ];
};

/** Stage a POS product (+ its variants) and return its mapping row id. */
$stage = static function ( array $payload ): int {
	$id = t_stage_product( $payload );
	t_stage_variants( $id, $payload );
	return $id;
};

$c = static fn( string $n ): string => T_PREFIX . '-AM-' . $n;

// -----------------------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------------------
// A — simple, unique match.
$a_pos = t_uid( 'AMA' );
$a_map = $stage( t_product( $a_pos, $c( '100' ), 'AM Simple', 'Cat', 50.0, $shop ) );
$a_wc  = $wc_with_sku( 'Merchant Mug', $c( '100' ) );

// B — two WC products share the SKU.
$b_map = $stage( t_product( t_uid( 'AMB' ), $c( '200' ), 'AM Dup SKU', 'Cat', 50.0, $shop ) );
$b_wc1 = $wc_with_sku( 'Dup 1', $c( '200' ) );
$b_wc2 = $wc_with_sku( 'Dup 2', $c( '200' ) );

// C — two POS products share the code.
$c_map1 = $stage( t_product( t_uid( 'AMC1' ), $c( '300' ), 'AM Dup Code 1', 'Cat', 50.0, $shop ) );
$c_map2 = $stage( t_product( t_uid( 'AMC2' ), $c( '300' ), 'AM Dup Code 2', 'Cat', 50.0, $shop ) );
$c_wc   = $wc_with_sku( 'Dup Code Target', $c( '300' ) );

// D — the matching WC product is already mapped to another POS product.
$d_map  = $stage( t_product( t_uid( 'AMD' ), $c( '400' ), 'AM Taken', 'Cat', 50.0, $shop ) );
$d_wc   = $wc_with_sku( 'Already Mapped', $c( '400' ) );
$d2_pos = t_uid( 'AMD2' );
$stage( t_product( $d2_pos, $c( '401' ), 'AM Owner', 'Cat', 50.0, $shop ) );
Sellmo_Product_Mapping::upsert_mapping( $d2_pos, $c( '401' ), 'AM Owner', $d_wc );

// E — POS simple vs a WC variation (type mismatch).
$e_map = $stage( t_product( t_uid( 'AME' ), $c( '500' ), 'AM Mismatch', 'Cat', 50.0, $shop ) );
$wc_variable( 'Mismatch Parent', [ $c( '500' ) ] );

// F — variable, every variant matches one WC parent.
$f_pos = t_uid( 'AMF' );
$f_v1  = t_uid( 'AMF1' );
$f_v2  = t_uid( 'AMF2' );
$f_map = $stage( t_product( $f_pos, $c( '600' ), 'AM Variable', 'Cat', 0.0, $shop, [
	t_variant( $f_v1, $c( '600-1' ), 's', 'red', 20.0, $shop ),
	t_variant( $f_v2, $c( '600-2' ), 'm', 'red', 20.0, $shop ),
] ) );
[ $f_parent, $f_vars ] = $wc_variable( 'Merchant Tee', [ $c( '600-1' ), $c( '600-2' ) ] );

// G — variable, only one of two variants matches.
$g_map = $stage( t_product( t_uid( 'AMG' ), $c( '700' ), 'AM Partial', 'Cat', 0.0, $shop, [
	t_variant( t_uid( 'AMG1' ), $c( '700-1' ), 's', 'red', 20.0, $shop ),
	t_variant( t_uid( 'AMG2' ), $c( '700-2' ), 'm', 'red', 20.0, $shop ),
] ) );
$wc_variable( 'Partial Tee', [ $c( '700-1' ) ] );

// H — variable, variants match variations under two different parents.
$h_map = $stage( t_product( t_uid( 'AMH' ), $c( '800' ), 'AM Split', 'Cat', 0.0, $shop, [
	t_variant( t_uid( 'AMH1' ), $c( '800-1' ), 's', 'red', 20.0, $shop ),
	t_variant( t_uid( 'AMH2' ), $c( '800-2' ), 'm', 'red', 20.0, $shop ),
] ) );
$wc_variable( 'Split A', [ $c( '800-1' ) ] );
$wc_variable( 'Split B', [ $c( '800-2' ) ] );

// I — out of the selected shop.
$i_map = $stage( t_product( t_uid( 'AMI' ), $c( '900' ), 'AM Other Shop', 'Cat', 50.0, $other_shop ) );
$wc_with_sku( 'Other Shop Target', $c( '900' ) );

$row = static fn( int $id ): array => (array) Sellmo_Product_Mapping::get_row_by_id( $id );

// =============================================================================
t_head( '17a–h. Auto map over the fixtures' );
// =============================================================================
$mark   = t_log_mark();
$result = ( new Sellmo_Auto_Mapper() )->run();

t_eq( 'A: simple unique match mapped',            $a_wc, (int) ( $row( $a_map )['wc_product_id'] ?? 0 ) );
t_eq( 'A: POS id stamped on the WC product',      $a_pos, (string) get_post_meta( $a_wc, '_salesplay_pos_product_id', true ) );
t_eq( 'A: WC name untouched',                     'Merchant Mug', wc_get_product( $a_wc )->get_name() );
t_eq( 'A: WC SKU untouched',                      $c( '100' ), wc_get_product( $a_wc )->get_sku() );
t_eq( 'B: duplicate WC SKU → not mapped',         0, (int) $row( $b_map )['map_status'] );
t_eq( 'C: duplicate POS code → not mapped (1)',   0, (int) $row( $c_map1 )['map_status'] );
t_eq( 'C: duplicate POS code → not mapped (2)',   0, (int) $row( $c_map2 )['map_status'] );
t_eq( 'D: already-mapped WC product not taken',   0, (int) $row( $d_map )['map_status'] );
t_eq( 'E: simple vs variation → not mapped',      0, (int) $row( $e_map )['map_status'] );
t_eq( 'F: variable parent mapped',                $f_parent, (int) ( $row( $f_map )['wc_product_id'] ?? 0 ) );
t_eq( 'F: variant 1 linked',                      $f_vars[0], (int) Sellmo_Product_Variant_Store::get_by_variant_id( $f_v1 )['wc_variation_id'] );
t_eq( 'F: variant 2 linked',                      $f_vars[1], (int) Sellmo_Product_Variant_Store::get_by_variant_id( $f_v2 )['wc_variation_id'] );
t_eq( 'F: variant id stamped on the variation',   $f_v2, (string) get_post_meta( $f_vars[1], '_salesplay_variant_id', true ) );
t_eq( 'G: partial variant match → not mapped',    0, (int) $row( $g_map )['map_status'] );
t_eq( 'H: split across parents → not mapped',     0, (int) $row( $h_map )['map_status'] );
t_eq( 'I: out-of-shop → not mapped',              0, (int) $row( $i_map )['map_status'] );
t_ok( 'run reports 2 mapped (A + F)',             2 === $result['mapped'], wp_json_encode( $result ) );
t_ok( 'run reports ambiguous skips',              $result['ambiguous'] >= 3, wp_json_encode( $result ) );

$logs = t_log_since( $mark );
t_contains( 'mapping logged',   'Auto-mapped "AM Simple" to WooCommerce product #' . $a_wc, $logs );
t_contains( 'ambiguity logged', $c( '200' ), $logs );

t_head( '17a. Second run changes nothing' );
t_eq( 'second run maps nothing', 0, ( new Sellmo_Auto_Mapper() )->run()['mapped'] );

$real_after = (array) $wpdb->get_results( $wpdb->prepare( $real_sql, T_PREFIX . '%' ), ARRAY_A );
t_eq( 'real mapper rows untouched by auto map', $real_before, $real_after );

// =============================================================================
t_head( '17i. Sync Products Now runs auto map only when the setting is on' );
// =============================================================================
// Echo every real row back (see test-7) so the reconcile keeps the catalogue.
$echo = [];
foreach ( $real_before as $r ) {
	$p = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT raw_payload FROM `{$map_table}` WHERE pos_product_id = %s", $r['pos_product_id'] ) ), true );
	if ( is_array( $p ) && ! empty( $p['id'] ) ) {
		$echo[] = $p;
	}
}
// Real rows without a payload cannot be echoed — the reconcile would prune them.
if ( count( $echo ) !== count( $real_before ) ) {
	echo "SKIP 17i: a real row has no stored payload to echo.\n";
} else {
	// The sync rewrites real rows' snapshots (price, stock, …) from the echoed
	// payloads; put every real row back exactly as it was afterwards, so later
	// suites (test-7 compares prices) see the catalogue this test found.
	$var_table = Sellmo_Product_Variant_Store::table_name();
	$real_full = [
		$map_table => (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$map_table}` WHERE pos_product_id NOT LIKE %s", T_PREFIX . '%' ), ARRAY_A ),
		$var_table => (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$var_table}` WHERE product_code NOT LIKE %s", T_PREFIX . '%' ), ARRAY_A ),
	];
	$restore_real = static function () use ( $real_full ): void {
		global $wpdb;
		foreach ( $real_full as $table => $rows ) {
			foreach ( $rows as $r ) {
				$wpdb->update( $table, $r, [ 'id' => $r['id'] ] );
			}
		}
	};
	t_defer( $restore_real );

	$j_pos     = t_uid( 'AMJ' );
	$j_payload = t_product( $j_pos, $c( '950' ), 'AM Sync Simple', 'Cat', 50.0, $shop );
	$j_wc      = $wc_with_sku( 'Sync Target', $c( '950' ) );
	t_defer( static function () use ( $j_pos, $c ): void {
		t_purge_mapping( Sellmo_Product_Mapping::get_id_by_sellmo( $j_pos, $c( '950' ) ) );
	} );

	$fixtures = [];
	foreach ( [ $a_map, $b_map, $c_map1, $c_map2, $d_map, $e_map, $f_map, $g_map, $h_map, $i_map ] as $id ) {
		$fixtures[] = json_decode( (string) $row( $id )['raw_payload'], true );
	}
	$fixtures[] = (array) json_decode( (string) $row( Sellmo_Product_Mapping::get_id_by_sellmo( $d2_pos, $c( '401' ) ) )['raw_payload'], true );

	T_Fake_API::install( [
		'products'  => [ T_Fake_API::products_page( array_merge( $echo, $fixtures, [ $j_payload ] ) ) ],
		'inventory' => [ T_Fake_API::inventory_page( [] ) ],
		'/shops'    => [ 'shops' => [ [ 'shop_id' => $shop, 'shop_name' => 'My Shop' ] ] ],
	] );

	t_set_setting( 'auto_map_enabled', 'no' );
	( new Sellmo_Product_Mapper_Sync() )->run_incremental_sync();
	$j_map = Sellmo_Product_Mapping::get_id_by_sellmo( $j_pos, $c( '950' ) );
	t_ok(  'off: new product staged', $j_map > 0 );
	t_eq(  'off: not auto-mapped',    0, (int) $row( $j_map )['map_status'] );

	t_set_setting( 'auto_map_enabled', 'yes' );
	$res = ( new Sellmo_Product_Mapper_Sync() )->run_incremental_sync();
	t_eq( 'on: auto-mapped by the sync', $j_wc, (int) ( $row( $j_map )['wc_product_id'] ?? 0 ) );
	t_ok( 'on: sync message reports it', false !== strpos( (string) $res['message'], 'auto-mapped by SKU' ), (string) $res['message'] );

	T_Fake_API::uninstall();
	$real_after = (array) $wpdb->get_results( $wpdb->prepare( $real_sql, T_PREFIX . '%' ), ARRAY_A );
	t_eq( 'real mapper rows untouched by the sync', $real_before, $real_after );

	$restore_real();
	$restored = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$map_table}` WHERE pos_product_id NOT LIKE %s", T_PREFIX . '%' ), ARRAY_A );
	t_eq( 'real rows restored byte-for-byte', $real_full[ $map_table ], $restored );
}

// =============================================================================
t_head( '17j. POS product deleted, Auto map OFF → WC product kept, unlinked' );
// =============================================================================
// Live = every POS id we know of except A, plus every POS id stamped on a WC
// product, so the reconcile only ever touches fixture A.
$live_ids = array_merge(
	(array) $wpdb->get_col( "SELECT pos_product_id FROM `{$map_table}` WHERE pos_product_id <> ''" ),
	(array) $wpdb->get_col( "SELECT meta_value FROM `{$wpdb->postmeta}` WHERE meta_key = '_salesplay_pos_product_id' AND meta_value <> ''" )
);
$live_ids = array_values( array_diff( array_unique( $live_ids ), [ $a_pos ] ) );

t_set_setting( 'auto_map_enabled', 'no' );
Sellmo_Product_Mapping::reconcile_absent( $live_ids );

t_ok( 'WC product still exists',        wc_get_product( $a_wc ) instanceof WC_Product );
t_eq( 'mapper row removed',             0, Sellmo_Product_Mapping::get_id_by_sellmo( $a_pos, $c( '100' ) ) );
t_eq( 'POS id meta stripped',           '', (string) get_post_meta( $a_wc, '_salesplay_pos_product_id', true ) );
t_eq( 'sync flag stripped',             '', (string) get_post_meta( $a_wc, '_sellmo_sync_enabled', true ) );

// =============================================================================
t_head( '17k. POS variant deleted, Auto map OFF → WC variation kept, unlinked' );
// =============================================================================
$removed = t_call( new Sellmo_Product_Variant_Sync(), 'prune_absent_variants', [ $f_map, [ $f_v1 ], [ 'product_name' => 'AM Variable' ] ] );
t_eq( 'one variant pruned',             1, $removed );
t_ok( 'WC variation still exists',      get_post( $f_vars[1] ) instanceof WP_Post );
t_eq( 'variant id meta stripped',       '', (string) get_post_meta( $f_vars[1], '_salesplay_variant_id', true ) );

// =============================================================================
t_head( '17l. Auto map ON → deleted POS variant / product delete the WC side' );
// =============================================================================
t_set_setting( 'auto_map_enabled', 'yes' );
t_call( new Sellmo_Product_Variant_Sync(), 'prune_absent_variants', [ $f_map, [], [ 'product_name' => 'AM Variable' ] ] );
t_ok( 'WC variation deleted', ! ( get_post( $f_vars[0] ) instanceof WP_Post ) );

$live_ids = array_values( array_diff( $live_ids, [ $f_pos ] ) );
Sellmo_Product_Mapping::reconcile_absent( $live_ids );
t_ok( 'WC parent deleted', ! ( wc_get_product( $f_parent ) instanceof WC_Product ) );

exit( t_summary() );
