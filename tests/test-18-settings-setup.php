<?php
/**
 * Scenario 18 — One-pass Settings setup.
 *
 * 18a. Connect tests the TYPED token (not the saved one), returns the shop list
 *      in the same response, and saves nothing.
 * 18b. A bad typed token reports the error and leaves the saved state alone.
 * 18c. Connect with a blank field still tests the saved token (old behaviour).
 * 18d. The token-change guard: every input combination, then the real save
 *      handler refusing an unconfirmed token change without saving anything.
 * 18e. The Settings page renders the Connect button and the confirmation box.
 *
 * SAFETY: an unconfirmed token change must never reach the purge, which would
 * delete the real catalogue. 18d proves the guard on its truth table FIRST and
 * only calls the real handler if every case passed.
 *
 * Run: php tests/test-18-settings-setup.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

global $wpdb;

$admin      = new Sellmo_Admin();
$saved_tok  = (string) Sellmo_Settings::get( 'api_token' );
$saved_shop = (string) Sellmo_Settings::get( 'shop_id' );
$conn_saved = Sellmo_Settings::get( 'connection_status' );
t_defer( static function () use ( $conn_saved ): void {
	Sellmo_Settings::set( 'connection_status', $conn_saved );
} );

// The saved-token parts (18c, the 18d handler run, the confirmation box) need
// a real saved token; everything else runs either way.
$has_saved = '' !== $saved_tok;
if ( ! $has_saved ) {
	echo "  note: no API token saved — skipping the checks that need one.\n";
}

// Capture the bearer token of every outgoing request; answer 401 for a bad one
// (runs after T_Fake_API's interceptor, so the 401 overrides its stub reply).
$GLOBALS['t_bearers'] = [];
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	$auth                   = (string) ( $args['headers']['Authorization'] ?? '' );
	$GLOBALS['t_bearers'][] = $auth;
	if ( false !== strpos( $auth, 'ZZTEST-BADTOKEN' ) ) {
		return [
			'headers'  => [],
			'body'     => '{"message":"Unauthenticated."}',
			'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
			'cookies'  => [],
			'filename' => null,
		];
	}
	return $pre;
}, 99, 3 );

T_Fake_API::install( [
	'/shops' => [ 'shops' => [
		[ 'id' => 'ZZTEST-SHOP-1', 'shop_name' => 'Test Shop', 'terminal_list' => [ [ 'pos_key' => 'ZZTEST-KEY' ] ] ],
	] ],
] );

// =============================================================================
t_head( '18a. Connect uses the typed token, returns shops, saves nothing' );
// =============================================================================
$GLOBALS['t_bearers'] = [];
$res = t_ajax( $admin, 'ajax_test_connection', [ 'token' => 'ZZTEST-NEWTOKEN' ] );

t_ok( 'connect succeeded',                    ! empty( $res['success'] ), wp_json_encode( $res ) );
t_eq( 'request carried the typed token',      [ 'Bearer ZZTEST-NEWTOKEN' ], $GLOBALS['t_bearers'] );
t_eq( 'shops returned in the same response',  'ZZTEST-SHOP-1', (string) ( $res['data']['shops'][0]['id'] ?? '' ) );
t_eq( 'terminal key returned for the shop',   'ZZTEST-KEY', (string) ( $res['data']['shops'][0]['terminal_key'] ?? '' ) );
t_eq( 'saved token unchanged',                $saved_tok, (string) Sellmo_Settings::get( 'api_token' ) );
t_eq( 'saved shop unchanged',                 $saved_shop, (string) Sellmo_Settings::get( 'shop_id' ) );
t_eq( 'saved connection status untouched',    $conn_saved, Sellmo_Settings::get( 'connection_status' ) );

$GLOBALS['t_bearers'] = [];
$shops = t_ajax( $admin, 'ajax_get_shops', [ 'token' => 'ZZTEST-NEWTOKEN' ] );
t_eq( 'get_shops also honours a typed token', [ 'Bearer ZZTEST-NEWTOKEN' ], $GLOBALS['t_bearers'] );
t_eq( 'get_shops returns the flat list',      'Test Shop', (string) ( $shops['data'][0]['name'] ?? '' ) );

// =============================================================================
t_head( '18b. A bad typed token reports the error, saves nothing' );
// =============================================================================
$res = t_ajax( $admin, 'ajax_test_connection', [ 'token' => 'ZZTEST-BADTOKEN' ] );
t_ok( 'connect failed',                 empty( $res['success'] ) );
t_ok( 'error message returned',         '' !== (string) ( $res['data']['message'] ?? '' ), wp_json_encode( $res ) );
t_eq( 'saved token unchanged',          $saved_tok, (string) Sellmo_Settings::get( 'api_token' ) );
t_eq( 'saved connection status untouched', $conn_saved, Sellmo_Settings::get( 'connection_status' ) );

// =============================================================================
t_head( '18c. Blank field tests the saved token' );
// =============================================================================
if ( $has_saved ) {
	$GLOBALS['t_bearers'] = [];
	$res = t_ajax( $admin, 'ajax_test_connection', [] );
	t_ok( 'connect succeeded',           ! empty( $res['success'] ) );
	t_eq( 'request carried the saved token', [ 'Bearer ' . $saved_tok ], $GLOBALS['t_bearers'] );
	t_ok( 'saved connection status recorded', ! empty( Sellmo_Settings::get( 'connection_status' )['success'] ) );
}

T_Fake_API::uninstall();

// =============================================================================
t_head( '18d. Token-change guard' );
// =============================================================================
$guard = static fn( string $old, string $new, bool $remove, bool $ok ): bool =>
	(bool) t_call_static( get_class( $admin ), 'token_change_unconfirmed', [ $old, $new, $remove, $ok ] );

$cases = [
	// old,  new,  remove, confirmed, blocked?
	[ 'OLD', 'NEW', false, false, true,  'different token, unconfirmed → blocked' ],
	[ 'OLD', 'NEW', false, true,  false, 'different token, confirmed → allowed' ],
	[ 'OLD', '',    false, false, false, 'blank field (keep token) → allowed' ],
	[ 'OLD', 'OLD', false, false, false, 'same token re-entered → allowed' ],
	[ '',    'NEW', false, false, false, 'first-time setup → allowed' ],
	[ 'OLD', 'NEW', true,  false, false, 'explicit remove-token box → allowed' ],
];
$guard_ok = true;
foreach ( $cases as [ $old, $new, $remove, $confirmed, $blocked, $label ] ) {
	$guard_ok = t_eq( $label, $blocked, $guard( $old, $new, $remove, $confirmed ) ) && $guard_ok;
}

if ( ! $guard_ok ) {
	echo "  SKIP 18d handler run: the guard is wrong, so a real save could purge the catalogue.\n";
} elseif ( $has_saved ) {
	$products_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" );
	$rows_before     = Sellmo_Product_Mapping::count_all();

	wp_set_current_user( t_admin_user_id() );
	$_POST = $_REQUEST = [
		t_hook( 'sellmo_wc_settings_nonce' ) => wp_create_nonce( t_hook( 'sellmo_wc_save_settings' ) ),
		t_hook( 'sellmo_wc_api_token' )      => 'ZZTEST-NEWTOKEN',
		t_hook( 'sellmo_wc_shop_id' )        => 'ZZTEST-SHOP-1',
	];

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

	t_ok( 'redirected with the "not confirmed" notice', false !== strpos( $redirect, 'sellmo_token_unconfirmed=1' ), $redirect );
	t_eq( 'token NOT replaced',          $saved_tok,  (string) Sellmo_Settings::get( 'api_token' ) );
	t_eq( 'shop NOT changed',            $saved_shop, (string) Sellmo_Settings::get( 'shop_id' ) );
	t_eq( 'no WooCommerce product purged', $products_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ) );
	t_eq( 'no mapper row purged',        $rows_before, Sellmo_Product_Mapping::count_all() );
}

// =============================================================================
t_head( '18e. Settings page markup' );
// =============================================================================
$render = static function (): string {
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	wp_set_current_user( t_admin_user_id() );
	ob_start();
	include SELLMO_WC_PATH . 'admin/views/page-settings.php';
	return (string) ob_get_clean();
};

$html = $render();
t_ok( 'Connect button present',              (bool) preg_match( '/id="[a-z]+-test-connection" class="button">\s*Connect/', $html ) );
if ( $has_saved ) {
	t_ok( 'token-change confirmation box present', false !== strpos( $html, 'name="' . t_hook( 'sellmo_wc_confirm_token_change' ) . '"' ) );
}

t_set_setting( 'api_token', '' );
$html = $render();
t_ok( 'Connect enabled with no token saved', ! preg_match( '/id="[a-z]+-test-connection"[^>]*disabled/', $html ) );
t_ok( 'no confirmation box on first setup',  false === strpos( $html, t_hook( 'sellmo_wc_confirm_token_change' ) ) );
t_ok( 'shop placeholder points at Connect',  false !== strpos( $html, 'click Connect' ) );

// Auto Map is opt-in: unticked unless the merchant saved it on, and the browser
// may not restore a ticked state on reload.
t_eq( 'Auto Map defaults to off', 'no', Sellmo_Settings::get_all_defaults()['auto_map_enabled'] );
t_set_setting( 'auto_map_enabled', 'no' );
$html    = $render();
$auto_in = preg_match( '/<input[^>]*name="' . preg_quote( t_hook( 'sellmo_wc_auto_map_enabled' ), '/' ) . '"[^>]*>/', $html, $m ) ? $m[0] : '';
t_ok( 'Auto Map checkbox rendered',          '' !== $auto_in );
t_ok( 'Auto Map unticked when off',          false === strpos( $auto_in, 'checked' ), $auto_in );
t_ok( 'Auto Map opts out of browser restore', false !== strpos( $auto_in, 'autocomplete="off"' ), $auto_in );

exit( t_summary() );
