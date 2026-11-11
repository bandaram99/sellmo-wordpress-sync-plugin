<?php
/**
 * Scenario 21 — the Terminal dropdown lists every terminal of the chosen shop.
 *
 * Runs the REAL settings page markup and the REAL admin JS in headless Edge
 * (no Node/jsdom needed). The JS gets the /shops list from the real AJAX
 * handler, fed by a stubbed POS, so the whole chain is exercised:
 * POS /shops -> normalize_shops() -> fillShops()/fillTerminals() -> <select>.
 *
 * 21a. Every keyed terminal of a shop is an option (keyless ones are dropped,
 *      a missing name falls back to the key), in POS order.
 * 21b. The saved terminal is preselected.
 * 21c. A shop with no terminals says so; a one-terminal shop auto-selects it.
 * 21d. With no shop chosen the dropdown asks for a shop first.
 * 21e. Picking a terminal is exactly what the form submits.
 *
 * Nothing touches the real POS; only the terminal_key setting is overridden
 * (and restored) so 21b can prove preselection.
 *
 * Run: php tests/test-21-terminal-dropdown.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// ponytail: headless Edge is the browser on this dev box; add Chrome paths if the suite moves.
$browser = '';
foreach ( [
	'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
	'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
] as $candidate ) {
	if ( is_file( $candidate ) ) {
		$browser = $candidate;
		break;
	}
}
if ( '' === $browser ) {
	t_ok( 'Edge found for the headless dropdown test', false, 'install Microsoft Edge' );
	exit( t_summary() );
}

// -----------------------------------------------------------------------------
// Fixture: three shops — several terminals, none, exactly one.
// -----------------------------------------------------------------------------
T_Fake_API::install( [
	'/shops' => [ 'shops' => [
		[ 'id' => 'ZZTEST-SHOP-A', 'shop_name' => 'Multi Shop', 'terminal_list' => [
			[ 'pos_name' => 'POS 01',  'pos_key' => 'ZZTEST-T1' ],
			[ 'pos_key'  => 'ZZTEST-T2' ],                  // no name -> key is the label
			[ 'pos_name' => 'Kitchen', 'pos_key' => 'ZZTEST-T3' ],
			[ 'pos_name' => 'Broken' ],                     // no key -> not selectable
		] ],
		[ 'id' => 'ZZTEST-SHOP-B', 'shop_name' => 'Empty Shop', 'terminal_list' => [] ],
		[ 'id' => 'ZZTEST-SHOP-C', 'shop_name' => 'Solo Shop',  'terminal_list' => [
			[ 'pos_name' => 'Only One', 'pos_key' => 'ZZTEST-T9' ],
		] ],
	] ],
] );

t_set_setting( 'terminal_key', 'ZZTEST-T2' );

$admin = new Sellmo_Admin();
$shops = t_ajax( $admin, 'ajax_get_shops' );
t_ok( 'fixture: get_shops AJAX succeeded', ! empty( $shops['success'] ), wp_json_encode( $shops ) );

// The real settings page, as the merchant gets it.
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( t_admin_user_id() );
ob_start();
include SELLMO_WC_PATH . 'admin/views/page-settings.php';
$page = (string) ob_get_clean();

$js_files = glob( SELLMO_WC_PATH . 'admin/js/*-admin.js' );
t_ok( 'fixture: admin JS found', ! empty( $js_files ) );

// The localized config global is brand-renamed (sellmoAdmin, salesplayAdmin…).
$cfg_var  = preg_match( '/var cfg\s*=\s*window\.(\w+)/', (string) file_get_contents( $js_files[0] ?? '' ), $cm ) ? $cm[1] : 'sellmoAdmin';
$shop_id  = t_hook( 'sellmo_wc_shop_id' );
$term_id  = t_hook( 'sellmo_wc_terminal_key' );
$file_url = static fn( string $path ): string => 'file:///' . str_replace( '\\', '/', $path );

// $.post is stubbed: get_shops answers with the real handler's output, every
// other loader (categories…) gets a harmless failure.
$html = '<!doctype html><html><body>' . $page . '<pre id="t-result"></pre>'
	. '<script src="' . $file_url( ABSPATH . 'wp-includes/js/jquery/jquery.min.js' ) . '"></script>'
	. '<script>
		window.tErrors = [];
		window.onerror = function ( m ) { window.tErrors.push( String( m ) ); };
		window[' . wp_json_encode( $cfg_var ) . '] = { ajaxUrl: "stub", nonce: "stub", hasToken: true, i18n: {} };
		var tShops = ' . wp_json_encode( $shops ) . ';
		jQuery.post = function ( url, data ) {
			var res = /get_shops$/.test( data.action ) ? tShops : { success: false, data: {} };
			return jQuery.Deferred().resolve( res ).promise();
		};
	</script>'
	. '<script src="' . $file_url( $js_files[0] ?? '' ) . '"></script>'
	. '<script>
		var R = {}, $s = jQuery( "#' . $shop_id . '" ), $t = jQuery( "#' . $term_id . '" );
		function opts( $el ) {
			return $el.find( "option" ).map( function () {
				return { v: this.value, t: this.text, sel: this.selected };
			} ).get();
		}
		try {
			R.shops   = opts( $s );
			R.initial = opts( $t );
			$s.val( "ZZTEST-SHOP-A" ).trigger( "change" ); R.a = opts( $t );
			$s.val( "ZZTEST-SHOP-B" ).trigger( "change" ); R.b = opts( $t );
			$s.val( "ZZTEST-SHOP-C" ).trigger( "change" ); R.c = opts( $t );
			$s.val( "ZZTEST-SHOP-A" ).trigger( "change" ); $t.val( "ZZTEST-T3" );
			R.submitted = $t.closest( "form" ).serializeArray().filter( function ( f ) {
				return f.name === "' . $term_id . '";
			} );
		} catch ( e ) { window.tErrors.push( String( e ) ); }
		R.errors = window.tErrors;
		document.getElementById( "t-result" ).textContent = JSON.stringify( R );
	</script></body></html>';

$tmp_dir = trailingslashit( get_temp_dir() ) . 'zztest-terminal-dropdown';
wp_mkdir_p( $tmp_dir . '/profile' );
$html_file = $tmp_dir . '/page.html';
file_put_contents( $html_file, $html );
t_defer( static function () use ( $tmp_dir ): void {
	@unlink( $tmp_dir . '/page.html' );
} );

$cmd = escapeshellarg( $browser )
	. ' --headless=new --disable-gpu --no-first-run --allow-file-access-from-files'
	. ' --user-data-dir=' . escapeshellarg( $tmp_dir . '/profile' )
	. ' --dump-dom ' . escapeshellarg( $file_url( $html_file ) ) . ' 2>&1';
$dom = (string) shell_exec( $cmd );

$r = preg_match( '#<pre id="t-result">(.*?)</pre>#s', $dom, $m )
	? json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true )
	: null;
if ( ! t_ok( 'browser ran the page and reported back', is_array( $r ), substr( $dom, 0, 400 ) ) ) {
	exit( t_summary() );
}

$values = static fn( array $o ): array => array_column( $o, 'v' );
$labels = static fn( array $o ): array => array_column( $o, 't' );
$picked = static function ( array $o ): string {
	foreach ( $o as $opt ) {
		if ( $opt['sel'] ) {
			return $opt['v'];
		}
	}
	return '';
};

t_eq( 'no JS errors', [], $r['errors'] );
t_eq( 'shop dropdown lists every shop', [ '', 'ZZTEST-SHOP-A', 'ZZTEST-SHOP-B', 'ZZTEST-SHOP-C' ], $values( $r['shops'] ) );

// =============================================================================
t_head( '21a. Every terminal of the shop is selectable' );
// =============================================================================
t_eq( 'all keyed terminals listed, in POS order', [ '', 'ZZTEST-T1', 'ZZTEST-T2', 'ZZTEST-T3' ], $values( $r['a'] ) );
t_eq( 'labels: POS name, key when unnamed',       [ 'POS 01', 'ZZTEST-T2', 'Kitchen' ], array_slice( $labels( $r['a'] ), 1 ) );
t_ok( 'keyless terminal is not offered',          ! in_array( 'Broken', $labels( $r['a'] ), true ) );

// =============================================================================
t_head( '21b. Saved terminal is preselected' );
// =============================================================================
t_eq( 'saved ZZTEST-T2 is selected', 'ZZTEST-T2', $picked( $r['a'] ) );

// =============================================================================
t_head( '21c. Empty shop / single-terminal shop' );
// =============================================================================
t_eq( 'empty shop offers nothing',           [ '' ], $values( $r['b'] ) );
t_eq( 'empty shop says so',                  '— No terminals in this shop —', $r['b'][0]['t'] );
t_eq( 'single terminal listed',              [ '', 'ZZTEST-T9' ], $values( $r['c'] ) );
t_eq( 'single terminal auto-selected',       'ZZTEST-T9', $picked( $r['c'] ) );

// =============================================================================
t_head( '21d. No shop chosen' );
// =============================================================================
// The site's real saved shop is not in the fake list, so nothing is selected.
t_eq( 'only the placeholder', [ '' ], $values( $r['initial'] ) );
t_eq( 'asks for a shop first', '— Select a shop first —', $r['initial'][0]['t'] );

// =============================================================================
t_head( '21e. The picked terminal is what the form submits' );
// =============================================================================
t_eq( 'exactly one terminal field, carrying the pick', [ [ 'name' => $term_id, 'value' => 'ZZTEST-T3' ] ], $r['submitted'] );

exit( t_summary() );
