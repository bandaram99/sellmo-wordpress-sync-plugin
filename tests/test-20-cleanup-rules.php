<?php
/**
 * Scenario 20 — What is deleted vs kept when the token is removed, the plugin
 * is uninstalled, or a POS product / variant disappears.
 *
 * The rule (Sellmo_Cleanup):
 *   "Also delete linked products" ticked      → every created/linked product deleted.
 *   Otherwise a product is deleted ONLY when   → the plugin created it, Auto map
 *                                                is on, and SKU == POS code
 *                                                (trimmed, case-insensitive).
 *   Everything else                            → kept, all Sellmo meta stripped.
 *
 * 20a  sku_matches() edge cases.
 * 20b  Full matrix: Auto map × origin × SKU × simple/variable × opt-in box.
 * 20c  count_deletable() (the number the token-change warning shows).
 * 20d  Footprint: plain merchant products untouched; variation-only links reached.
 * 20e  Imported images: deleted when unused, kept when a kept product uses them.
 * 20f  Created categories: deleted when empty, kept when a kept product uses them.
 * 20g  POS product deleted (remove_wc_product): same SKU rule.
 * 20h  POS variant deleted (prune_absent_variants): same SKU rule per variation.
 * 20i  Uninstall reads Auto map without the settings class.
 * 20j  End to end through the real Settings save handler (Remove token), incl.
 *      the Auto map box changed in the same submit. Runs only when the site has
 *      no real synced products/images/categories; plugin options and mapper rows
 *      are snapshotted and restored.
 *
 * Run: php tests/test-20-cleanup-rules.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

// Real footprint, measured before this file creates any fixture (gates 20j).
$real_footprint  = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta}
	 WHERE meta_key IN ( '_sellmo_created', '_pos_product_code', '_salesplay_pos_product_id', '_salesplay_variant_id', '_sellmo_image_source_url' )"
);
$real_footprint += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = '_sellmo_created'" );
$post_floor      = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );

const T20_LINK_META = [ '_salesplay_pos_product_id', '_pos_product_code', '_salesplay_variant_id', '_sellmo_sync_enabled', '_sellmo_last_synced', '_sellmo_created' ];

$exists = static fn( int $id ): bool => null !== get_post( $id );

/** Any Sellmo meta left on a post. */
$meta_left = static function ( int $id ): array {
	$left = [];
	foreach ( T20_LINK_META as $key ) {
		if ( '' !== (string) get_post_meta( $id, $key, true ) ) {
			$left[] = $key;
		}
	}
	return $left;
};

/**
 * Build a fixture product.
 *
 * @param string $origin  created | linked | released | merchant
 * @param string $sku     SKU to store ('' for none)
 * @param string $code    POS product code
 * @param bool   $variable With two variations
 * @return array{0:int,1:array<int,int>} [ product id, variation ids ]
 */
$make = static function ( string $origin, string $sku, string $code, bool $variable ): array {
	$product = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
	$product->set_name( "ZZTEST T20 {$origin}" );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$id = (int) $product->save();

	$vars = [];
	if ( $variable ) {
		foreach ( [ 1, 2 ] as $n ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $id );
			$v->set_regular_price( '10' );
			$vid    = (int) $v->save();
			$vars[] = $vid;
			update_post_meta( $vid, '_sku', "{$code}-{$n}" );
			if ( 'created' === $origin || 'linked' === $origin ) {
				update_post_meta( $vid, '_salesplay_variant_id', "{$code}-VID-{$n}" );
				update_post_meta( $vid, '_sellmo_sync_enabled', 'yes' );
			}
		}
	}

	t_defer( static function () use ( $id ): void {
		t_delete_wc_product( $id );
	} );

	// Direct meta write: WC's unique-SKU check would reject the case/space variants.
	if ( '' !== $sku ) {
		update_post_meta( $id, '_sku', $sku );
	}

	if ( 'created' === $origin || 'linked' === $origin ) {
		update_post_meta( $id, '_pos_product_code', $code );
		update_post_meta( $id, '_salesplay_pos_product_id', "{$code}-POS" );
		update_post_meta( $id, '_sellmo_sync_enabled', 'yes' );
		update_post_meta( $id, '_sellmo_last_synced', (string) time() );
	}
	if ( 'created' === $origin || 'released' === $origin ) {
		update_post_meta( $id, '_sellmo_created', 'yes' );
	}

	return [ $id, $vars ];
};

// =============================================================================
t_head( '20a. sku_matches()' );
// =============================================================================
[ $p ] = $make( 'merchant', '', 'X', false );
$cases = [
	[ 'ABC-1',    'ABC-1',   true,  'identical' ],
	[ 'abc-1',    'ABC-1',   true,  'case differs' ],
	[ '  ABC-1 ', 'ABC-1',   true,  'surrounding spaces' ],
	[ 'ABC-1',    'ABC-2',   false, 'different code' ],
	[ '',         'ABC-1',   false, 'no SKU' ],
	[ 'ABC-1',    '',        false, 'no POS code' ],
	[ '',         '',        false, 'both empty' ],
	[ 'ABC-10',   'ABC-1',   false, 'prefix only' ],
];
foreach ( $cases as [ $sku, $code, $want, $label ] ) {
	update_post_meta( $p, '_sku', $sku );
	t_eq( "sku_matches: {$label}", $want, Sellmo_Cleanup::sku_matches( $p, $code ) );
}

// =============================================================================
t_head( '20b. Purge matrix: Auto map × origin × SKU × type × opt-in box' );
// =============================================================================
$n = 0;
foreach ( [ 'yes', 'no' ] as $auto ) {
	t_set_setting( 'auto_map_enabled', $auto );

	foreach ( [ 'created', 'linked', 'released' ] as $origin ) {
		foreach ( [ 'match', 'case', 'mismatch', 'none' ] as $sku_kind ) {
			foreach ( [ false, true ] as $variable ) {
				foreach ( [ false, true ] as $box ) {
					$n++;
					$code = t_uid( "M{$n}" );
					$sku  = [ 'match' => $code, 'case' => '  ' . strtolower( $code ) . ' ', 'mismatch' => $code . '-OTHER', 'none' => '' ][ $sku_kind ];

					[ $id, $vars ] = $make( $origin, $sku, $code, $variable );

					$want_delete = $box || ( 'created' === $origin && 'yes' === $auto && in_array( $sku_kind, [ 'match', 'case' ], true ) );
					$label       = sprintf(
						'auto=%-3s %-8s sku=%-8s %-8s box=%-3s → %s',
						$auto,
						$origin,
						$sku_kind,
						$variable ? 'variable' : 'simple',
						$box ? 'on' : 'off',
						$want_delete ? 'deleted' : 'kept'
					);

					t_eq( "{$label}: is_deletable", 'created' === $origin && 'yes' === $auto && in_array( $sku_kind, [ 'match', 'case' ], true ), Sellmo_Cleanup::is_deletable( $id ) );

					$returned = Sellmo_Cleanup::purge_product( $id, $box );

					if ( $want_delete ) {
						$gone = ! $exists( $id ) && ! array_filter( $vars, $exists );
						t_ok( $label, $returned && $gone, 'returned=' . var_export( $returned, true ) . ' product exists=' . var_export( $exists( $id ), true ) );
						continue;
					}

					$problems = [];
					if ( $returned ) {
						$problems[] = 'purge_product() reported a delete';
					}
					if ( ! $exists( $id ) ) {
						$problems[] = 'product deleted';
					}
					foreach ( $vars as $vid ) {
						if ( ! $exists( $vid ) ) {
							$problems[] = "variation #{$vid} deleted";
						} elseif ( $meta_left( $vid ) ) {
							$problems[] = "variation #{$vid} still has " . implode( ',', $meta_left( $vid ) );
						}
					}
					if ( $exists( $id ) && $meta_left( $id ) ) {
						$problems[] = 'product still has ' . implode( ',', $meta_left( $id ) );
					}
					if ( $exists( $id ) && (string) get_post_meta( $id, '_sku', true ) !== $sku ) {
						$problems[] = 'SKU changed';
					}
					t_ok( $label, ! $problems, implode( '; ', $problems ) );
				}
			}
		}
	}
}

// =============================================================================
t_head( '20c. count_deletable() matches the rule' );
// =============================================================================
t_set_setting( 'auto_map_enabled', 'yes' );
$base = Sellmo_Cleanup::count_deletable();
$make( 'created', 'C1-' . $n, 'C1-' . $n, false );                 // deletable
$make( 'created', 'C2-' . $n, 'C2-' . $n, true );                  // deletable (variable)
$make( 'created', 'nope', 'C3-' . $n, false );                     // SKU mismatch
$make( 'linked', 'L1-' . $n, 'L1-' . $n, false );                  // merchant product
$make( 'released', 'R1-' . $n, 'R1-' . $n, false );                // no POS code left
t_eq( 'Auto map on: +2 deletable', $base + 2, Sellmo_Cleanup::count_deletable() );
Sellmo_Settings::set( 'auto_map_enabled', 'no' );
t_eq( 'Auto map off: nothing deletable', 0, Sellmo_Cleanup::count_deletable() );

// =============================================================================
t_head( '20d. Footprint selection' );
// =============================================================================
$footprint = static fn(): array => (array) t_call_static( 'Sellmo_Cleanup', 'footprint_product_ids' );

[ $plain ] = $make( 'merchant', 'PLAIN-' . $n, 'PLAIN-' . $n, false );
t_ok( 'plain merchant product (no POS meta) not in footprint', ! in_array( $plain, $footprint(), true ) );

[ $vparent, $vkids ] = $make( 'merchant', '', 'VONLY-' . $n, true );
update_post_meta( $vkids[0], '_salesplay_variant_id', 'VONLY-VID' );
t_ok( 'parent of a linked variation is in footprint', in_array( $vparent, $footprint(), true ) );
t_ok( 'variation ids themselves are not listed',      ! in_array( $vkids[0], $footprint(), true ) );
Sellmo_Cleanup::purge_product( $vparent, false );
t_ok( 'variation-only link: parent kept',       $exists( $vparent ) );
t_eq( 'variation-only link: variation unlinked', [], $meta_left( $vkids[0] ) );

// =============================================================================
t_head( '20e. Imported images' );
// =============================================================================
$attachment = static function ( string $title ): int {
	$id = (int) wp_insert_attachment( [ 'post_title' => $title, 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ] );
	update_post_meta( $id, '_sellmo_image_source_url', "https://example.test/{$title}.png" );
	t_defer( static function () use ( $id ): void {
		wp_delete_attachment( $id, true );
	} );
	return $id;
};

[ $kept_thumb ]   = $make( 'linked', 'IMG1-' . $n, 'IMG1-' . $n, false );
[ $kept_gallery ] = $make( 'linked', 'IMG2-' . $n, 'IMG2-' . $n, false );
$img_thumb   = $attachment( 'zztest-thumb' );
$img_gallery = $attachment( 'zztest-gallery' );
$img_unused  = $attachment( 'zztest-unused' );
update_post_meta( $kept_thumb, '_thumbnail_id', (string) $img_thumb );
update_post_meta( $kept_gallery, '_product_image_gallery', "999999,{$img_gallery}" );

Sellmo_Cleanup::delete_unused_images( [ $img_thumb, $img_gallery, $img_unused ] );
t_ok( 'thumbnail of a kept product kept',      $exists( $img_thumb ) );
t_eq( '...and its import meta stripped',       '', (string) get_post_meta( $img_thumb, '_sellmo_image_source_url', true ) );
t_eq( '...and still the product thumbnail',    (string) $img_thumb, (string) get_post_meta( $kept_thumb, '_thumbnail_id', true ) );
t_ok( 'gallery image of a kept product kept',  $exists( $img_gallery ) );
t_ok( 'unused imported image deleted',         ! $exists( $img_unused ) );

// =============================================================================
t_head( '20f. Created categories' );
// =============================================================================
$category = static function ( string $name, int $parent = 0 ): int {
	$term = wp_insert_term( $name . ' ' . wp_generate_password( 6, false ), 'product_cat', [ 'parent' => $parent ] );
	$id   = (int) $term['term_id'];
	add_term_meta( $id, '_sellmo_created', 'yes', true );
	t_defer( static function () use ( $id ): void {
		wp_delete_term( $id, 'product_cat' );
	} );
	return $id;
};

$cat_used   = $category( 'ZZTEST used' );
$cat_empty  = $category( 'ZZTEST empty' );
$cat_parent = $category( 'ZZTEST parent' );
$cat_child  = $category( 'ZZTEST child', $cat_parent );
[ $cp1 ] = $make( 'linked', 'CAT1-' . $n, 'CAT1-' . $n, false );
[ $cp2 ] = $make( 'linked', 'CAT2-' . $n, 'CAT2-' . $n, false );
wp_set_object_terms( $cp1, [ $cat_used ], 'product_cat' );
wp_set_object_terms( $cp2, [ $cat_child ], 'product_cat' );

Sellmo_Cleanup::delete_unused_categories( [ $cat_used, $cat_empty, $cat_parent, $cat_child ] );
$term_exists = static fn( int $id ): bool => get_term( $id, 'product_cat' ) instanceof WP_Term;
t_ok( 'category holding a kept product kept',        $term_exists( $cat_used ) );
t_eq( '...and its created flag stripped',            '', (string) get_term_meta( $cat_used, '_sellmo_created', true ) );
t_ok( 'empty created category deleted',              ! $term_exists( $cat_empty ) );
t_ok( 'parent whose child holds a product kept',     $term_exists( $cat_parent ) );
t_ok( 'child holding a product kept',                $term_exists( $cat_child ) );
t_ok( 'kept product still in its category',          has_term( $cat_used, 'product_cat', $cp1 ) );

// =============================================================================
t_head( '20g. POS product deleted → remove_wc_product()' );
// =============================================================================
$remove = static fn( int $id ): bool => (bool) t_call_static( 'Sellmo_Product_Mapping', 'remove_wc_product', [ $id ] );

$cases = [
	// auto,  sku matches?, variable, deleted?
	[ 'yes', true,  false, true ],
	[ 'yes', true,  true,  true ],
	[ 'yes', false, false, false ],
	[ 'yes', false, true,  false ],
	[ 'no',  true,  false, false ],
	[ 'no',  false, true,  false ],
];
foreach ( $cases as [ $auto, $match, $variable, $want ] ) {
	Sellmo_Settings::set( 'auto_map_enabled', $auto );
	$code          = t_uid( 'G' );
	[ $id, $vars ] = $make( 'linked', $match ? $code : 'OTHER-' . $code, $code, $variable );
	$mark          = t_log_mark();
	$label         = sprintf( 'auto=%s sku %s %s → %s', $auto, $match ? 'matches' : 'differs', $variable ? 'variable' : 'simple', $want ? 'deleted' : 'kept, unlinked' );

	$deleted = $remove( $id );
	t_eq( "{$label}: return value", $want, $deleted );
	if ( $want ) {
		t_ok( "{$label}: gone", ! $exists( $id ) && ! array_filter( $vars, $exists ) );
		continue;
	}
	t_ok( "{$label}: product + variations exist", $exists( $id ) && count( array_filter( $vars, $exists ) ) === count( $vars ) );
	t_eq( "{$label}: link meta stripped", [], array_values( array_diff( $meta_left( $id ), [ '_sellmo_last_synced' ] ) ) );
	t_contains( "{$label}: reason logged", 'yes' === $auto ? 'SKU does not match' : 'Auto map is off', t_log_since( $mark ) );
}

// =============================================================================
t_head( '20h. POS variant deleted → prune_absent_variants()' );
// =============================================================================
$shop = Sellmo_Product_Mapping::selected_shop_id();
foreach ( [ 'yes', 'no' ] as $auto ) {
	Sellmo_Settings::set( 'auto_map_enabled', $auto );

	$pos_id  = t_uid( 'HPOS' );
	$vid_ok  = t_uid( 'HV1' );
	$vid_bad = t_uid( 'HV2' );
	$code_ok = t_uid( 'H-1' );
	$payload = t_product( $pos_id, t_uid( 'H' ), 'T20 Prune', 'Cat', 0.0, $shop, [
		t_variant( $vid_ok, $code_ok, 's', 'red', 10.0, $shop ),
		t_variant( $vid_bad, t_uid( 'H-2' ), 'm', 'red', 10.0, $shop ),
	] );
	$map_id = t_stage_product( $payload );
	t_stage_variants( $map_id, $payload );

	[ $parent, $vars ] = $make( 'linked', '', t_uid( 'HP' ), true );
	update_post_meta( $vars[0], '_sku', strtoupper( $code_ok ) ); // matches (case-insensitive)
	update_post_meta( $vars[1], '_sku', 'SOMETHING-ELSE' );        // does not match
	Sellmo_Product_Variant_Store::set_wc_variation_id( $vid_ok, $vars[0] );
	Sellmo_Product_Variant_Store::set_wc_variation_id( $vid_bad, $vars[1] );

	// Upstream now returns neither variant.
	t_call( new Sellmo_Product_Variant_Sync(), 'prune_absent_variants', [ $map_id, [], $payload ] );

	if ( 'yes' === $auto ) {
		t_ok( 'auto=yes: variation with matching SKU deleted', ! $exists( $vars[0] ) );
	} else {
		t_ok( 'auto=no: variation with matching SKU kept',     $exists( $vars[0] ) );
		t_eq( 'auto=no: ...and unlinked',                      '', (string) get_post_meta( $vars[0], '_salesplay_variant_id', true ) );
	}
	t_ok( "auto={$auto}: variation with different SKU kept", $exists( $vars[1] ) );
	t_eq( "auto={$auto}: ...and unlinked",                     '', (string) get_post_meta( $vars[1], '_salesplay_variant_id', true ) );
	t_ok( "auto={$auto}: parent kept",                         $exists( $parent ) );
}

// =============================================================================
t_head( '20i. Uninstall-safe Auto map read' );
// =============================================================================
foreach ( [ 'yes', 'no' ] as $auto ) {
	Sellmo_Settings::set( 'auto_map_enabled', $auto );
	t_eq( "Cleanup sees auto_map={$auto}", 'yes' === $auto, Sellmo_Cleanup::auto_map_enabled() );
}
t_ok( 'uninstall.php loads the cleanup class itself', str_contains( (string) file_get_contents( SELLMO_WC_PATH . 'uninstall.php' ), "includes/class-cleanup.php'" ) );

// =============================================================================
t_head( '20j. End to end: Settings → Remove token' );
// =============================================================================
if ( $real_footprint > 0 ) {
	echo "  SKIP 20j: the site holds {$real_footprint} real synced item(s) a token removal would touch.\n";
	exit( t_summary() );
}

// Snapshot every plugin option and mapper/variant row; restore on exit.
$opt_like  = $wpdb->esc_like( POS_Brand::option( '' ) ) . '%';
$opts      = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s", $opt_like ), ARRAY_A );
$map_rows  = $wpdb->get_results( 'SELECT * FROM `' . Sellmo_Product_Mapping::table_name() . '`', ARRAY_A );
$var_rows  = $wpdb->get_results( 'SELECT * FROM `' . Sellmo_Product_Variant_Store::table_name() . '`', ARRAY_A );
t_defer( static function (): void {
	( new Sellmo_Scheduler() )->register_sync_schedules();
} );
t_defer( static function () use ( $opts, $opt_like, $map_rows, $var_rows ): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $opt_like ) );
	foreach ( $opts as $o ) {
		$wpdb->insert( $wpdb->options, $o );
	}
	foreach ( $map_rows as $r ) {
		$wpdb->replace( Sellmo_Product_Mapping::table_name(), $r );
	}
	foreach ( $var_rows as $r ) {
		$wpdb->replace( Sellmo_Product_Variant_Store::table_name(), $r );
	}
	wp_cache_flush();
} );

// Belt and braces: nothing that existed before this file may be deleted.
add_filter( 'pre_delete_post', static fn( $check, $post ) => ( $post instanceof WP_Post && $post->ID <= $post_floor ) ? false : $check, 1, 2 );

$admin  = new Sellmo_Admin();
$submit = static function ( array $fields ) use ( $admin ): string {
	Sellmo_Settings::set( 'api_token', 'ZZTEST-TOKEN' );
	wp_set_current_user( t_admin_user_id() );
	$post = [ t_hook( 'sellmo_wc_settings_nonce' ) => wp_create_nonce( t_hook( 'sellmo_wc_save_settings' ) ) ];
	foreach ( $fields as $k => $v ) {
		$post[ t_hook( $k ) ] = $v;
	}
	$_POST = $_REQUEST = $post;

	$redirect = '';
	$catch    = static function ( $location ) use ( &$redirect ) {
		$redirect = (string) $location;
		throw new T_Died( 'redirect' );
	};
	add_filter( 'wp_redirect', $catch, 1 );
	try {
		$admin->handle_save_settings();
	} catch ( T_Died $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Expected: the handler redirected.
	} finally {
		remove_filter( 'wp_redirect', $catch, 1 );
		$_POST = $_REQUEST = [];
	}
	return $redirect;
};

// Run 1 — stored Auto map ON, but the merchant unticks it in the same submit.
Sellmo_Settings::set( 'auto_map_enabled', 'yes' );
[ $e_created ] = $make( 'created', 'E1-' . $n, 'E1-' . $n, false );
$submit( [ 'sellmo_wc_remove_token' => 'yes' ] );
t_eq( 'token removed',                                      '', (string) Sellmo_Settings::get( 'api_token' ) );
t_ok( 'Auto map unticked in same submit → imported kept',   $exists( $e_created ) );
t_eq( '...and detached',                                    [], $meta_left( $e_created ) );

// Run 2 — Auto map ticked, box unticked.
[ $e_match ]        = $make( 'created', 'E2-' . $n, 'E2-' . $n, true );
[ $e_mismatch ]     = $make( 'created', 'EDITED', 'E3-' . $n, false );
[ $e_linked ]       = $make( 'linked', 'E4-' . $n, 'E4-' . $n, false );
[ $e_merchant ]     = $make( 'merchant', 'E5-' . $n, 'E5-' . $n, false );
$e_img              = $attachment( 'zztest-e2e' );
update_post_meta( $e_mismatch, '_thumbnail_id', (string) $e_img );
Sellmo_Settings::set( 'auto_map_enabled', 'yes' );
$expect = Sellmo_Cleanup::count_deletable(); // e_match plus deletable fixtures left by 20c.
$mark   = t_log_mark();
$submit( [ 'sellmo_wc_remove_token' => 'yes', 'sellmo_wc_auto_map_enabled' => 'yes' ] );
t_ok( 'imported + SKU match + Auto map → deleted',          ! $exists( $e_match ) );
t_ok( 'imported, SKU edited → kept',                        $exists( $e_mismatch ) );
t_ok( '...with its imported image',                         $exists( $e_img ) );
t_ok( 'linked merchant product (SKU matches) → kept',       $exists( $e_linked ) );
t_eq( '...and detached',                                    [], $meta_left( $e_linked ) );
t_ok( 'plain merchant product untouched',                   $exists( $e_merchant ) );
t_contains( 'purge logged with the warned count',           "API token changed — deleted {$expect} product(s)", t_log_since( $mark ) );
t_eq( 'nothing deletable left',                             0, Sellmo_Cleanup::count_deletable() );

// Run 3 — opt-in box ticked: everything linked goes, plain merchant stays.
[ $o_linked ]  = $make( 'linked', 'O1-' . $n, 'O1-' . $n, false );
[ $o_created ] = $make( 'created', 'O-EDIT', 'O2-' . $n, true );
$submit( [ 'sellmo_wc_remove_token' => 'yes', 'sellmo_wc_delete_linked' => 'yes' ] );
t_ok( 'box ticked: linked deleted',                         ! $exists( $o_linked ) );
t_ok( 'box ticked: imported (any SKU, Auto map off) deleted', ! $exists( $o_created ) );
t_ok( 'box ticked: plain merchant product untouched',       $exists( $e_merchant ) );

exit( t_summary() );
