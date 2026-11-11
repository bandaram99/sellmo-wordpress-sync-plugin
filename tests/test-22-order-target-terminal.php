<?php
/**
 * Scenario 22 — a placed order is sent to the SELECTED terminal only.
 *
 * Orders go through the real placement path: the order moves to "processing",
 * WooCommerce fires woocommerce_order_status_processing, the plugin pushes it.
 * POST /online_orders is stubbed and every request is captured, so we assert
 * on what actually went over the wire.
 *
 * 22a. One order -> exactly one POST, target_terminal = the selected terminal,
 *      for the selected shop, and no other terminal key anywhere in the body.
 * 22b. Selecting a different terminal routes the next order there instead.
 * 22c. An order is never fanned out to every terminal of the shop.
 *
 * No order reaches the real POS. order_sync_enabled and terminal_key are
 * overridden and restored; the site's real shop_id is used, never a fake one.
 *
 * Run: php tests/test-22-order-target-terminal.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// The shop's three terminals; only the selected one may receive an order.
$terminals = [ 'ZZTEST-T1', 'ZZTEST-T2', 'ZZTEST-T3' ];
$shop_id   = (string) Sellmo_Settings::get( 'shop_id' );

T_Fake_API::install( [
	'/online_orders' => [ 'id' => 'POS-ORDER-2200', 'status' => 'ok' ],
] );

t_set_setting( 'order_sync_enabled', 'yes' );

$wc_id = t_wc_simple( 'Terminal Routed Widget', 100.00, T_PREFIX . '-TERM-1', t_uid( 'TERMPROD' ), 50 );

/**
 * Place an order the way checkout does and return the /online_orders requests
 * it caused.
 */
$place_order = static function () use ( $wc_id ): array {
	$before = count( T_Fake_API::requests_for( '/online_orders' ) );

	$order = wc_create_order();
	$order->add_product( wc_get_product( $wc_id ), 1 );
	$order->set_billing_first_name( 'Terminal' );
	$order->set_billing_email( 'terminal@example.test' );
	$order->calculate_totals();
	$order->save();
	$order_id = $order->get_id();
	t_defer( static function () use ( $order_id ): void {
		t_delete_order( $order_id );
	} );

	$order->update_status( 'processing' ); // fires the real push hook

	return array_slice( T_Fake_API::requests_for( '/online_orders' ), $before );
};

/** Terminal keys that appear anywhere in a request body. */
$keys_in = static function ( array $request ) use ( $terminals ): array {
	$json = (string) wp_json_encode( $request['body'] );
	return array_values( array_filter( $terminals, static fn( string $k ): bool => str_contains( $json, $k ) ) );
};

// =============================================================================
t_head( '22a. Order goes to the selected terminal only' );
// =============================================================================
t_set_setting( 'terminal_key', 'ZZTEST-T2' );
$sent = $place_order();

t_eq( 'exactly one order request sent', 1, count( $sent ) );
if ( $sent ) {
	t_eq( 'sent as POST',                       'POST', strtoupper( $sent[0]['method'] ) );
	t_eq( 'target_terminal = selected terminal', 'ZZTEST-T2', (string) ( $sent[0]['body']['target_terminal'] ?? '' ) );
	t_eq( 'for the selected shop',               $shop_id, (string) ( $sent[0]['body']['shop_id'] ?? '' ) );
	t_eq( 'no other terminal key in the body',   [ 'ZZTEST-T2' ], $keys_in( $sent[0] ) );
}

// =============================================================================
t_head( '22b. Changing the selection re-routes the next order' );
// =============================================================================
t_set_setting( 'terminal_key', 'ZZTEST-T3' );
$sent = $place_order();

t_eq( 'exactly one order request sent',      1, count( $sent ) );
if ( $sent ) {
	t_eq( 'target_terminal = newly selected', 'ZZTEST-T3', (string) ( $sent[0]['body']['target_terminal'] ?? '' ) );
	t_eq( 'old terminal not addressed',       [ 'ZZTEST-T3' ], $keys_in( $sent[0] ) );
}

// =============================================================================
t_head( '22c. Never fanned out to the whole shop' );
// =============================================================================
$all = T_Fake_API::requests_for( '/online_orders' );
t_eq( 'two orders -> two requests in total', 2, count( $all ) );
t_eq( 'each request addressed one terminal', [ 'ZZTEST-T2', 'ZZTEST-T3' ], array_map(
	static fn( array $r ): string => (string) ( $r['body']['target_terminal'] ?? '' ),
	$all
) );
t_ok( 'ZZTEST-T1 (not selected) never received an order', ! in_array( 'ZZTEST-T1', array_merge( ...array_map( $keys_in, $all ) ), true ) );

exit( t_summary() );
