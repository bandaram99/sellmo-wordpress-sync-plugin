<?php
/**
 * Scenario 11 — order sync is bi-directional.
 *
 * Outbound (WC → POS) is covered by test-6. This suite covers the return leg:
 * GET /online_order_status is pulled and each POS status is applied to the
 * WooCommerce order that was pushed, on the button and on the cron event.
 *
 * Also asserts the guards that keep the pull safe: only orders this plugin
 * pushed are touched, Pending never overwrites a merchant's status, repeat runs
 * change nothing, and the schedule the settings dropdown offers is one WP-Cron
 * actually knows.
 *
 * Run: php tests/test-11-order-status-sync.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

t_set_setting( 'order_sync_enabled', 'yes' );

$order_sync = new Sellmo_Order_Sync();
$admin      = new Sellmo_Admin();

/** Create an order that has been pushed to the POS, and clean it up afterwards. */
$make_order = static function ( string $status, string $pos_id = 'POS-1' ): WC_Order {
	$order = wc_create_order();
	$order->set_status( $status );
	$order->update_meta_data( '_sellmo_order_id', $pos_id );
	$order->save();

	$id = $order->get_id();
	t_defer( static function () use ( $id ): void {
		wp_delete_post( $id, true );
	} );

	return wc_get_order( $id );
};

/** One /online_order_status row. */
$status_row = static fn( int $order_id, string $code, string $name ): array => [
	'system_unique_id'       => 'ZZTEST' . $order_id,
	'order_reference_id'     => (string) $order_id,
	'order_reference_number' => (string) $order_id,
	'order_status'           => $code,
	'status_name'            => $name,
];

// -----------------------------------------------------------------------------
// Fixtures: four pushed orders, one order never pushed from here.
// -----------------------------------------------------------------------------
$completed = $make_order( 'processing', 'POS-COMPLETED' );
$rejected  = $make_order( 'processing', 'POS-REJECTED' );
$pending   = $make_order( 'processing', 'POS-PENDING' );
$accepted  = $make_order( 'on-hold',    'POS-ACCEPTED' );

// A WooCommerce order this plugin never sent — the POS may still return an id
// that collides with it from another sales channel.
$foreign = wc_create_order();
$foreign->set_status( 'processing' );
$foreign->save();
$foreign_id = $foreign->get_id();
t_defer( static function () use ( $foreign_id ): void {
	wp_delete_post( $foreign_id, true );
} );

t_head( '11. Fixtures' );
t_eq( 'pushed order starts as processing', 'processing', $completed->get_status() );
t_ok( 'foreign order carries no POS meta',  ! $foreign->meta_exists( '_sellmo_order_id' ) );

// -----------------------------------------------------------------------------
// 11a. The pull applies each POS status to the right order
// -----------------------------------------------------------------------------
t_head( '11a. POS statuses applied to WooCommerce' );

T_Fake_API::install( [
	'/online_order_status' => [
		'order_status' => [
			$status_row( $completed->get_id(),  '2', 'Completed' ),
			$status_row( $rejected->get_id(),   '3', 'Rejected' ),
			$status_row( $pending->get_id(),    '0', 'Pending' ),
			$status_row( $accepted->get_id(),   '1', 'Accepted' ),
			$status_row( $foreign_id,           '2', 'Completed' ),
			// A reference that is not an order at all.
			$status_row( 99999999,              '2', 'Completed' ),
		],
		'cursor' => '',
	],
] );

$mark   = t_log_mark();
$result = $order_sync->sync_order_statuses();

t_ok( 'pull reported success', ! empty( $result['success'] ), wp_json_encode( $result ) );
t_eq( 'three orders updated',  3, (int) $result['count'] );
t_eq( 'four of ours checked',  4, (int) $result['checked'] );

t_eq( 'Completed → completed',  'completed',  wc_get_order( $completed->get_id() )->get_status() );
t_eq( 'Rejected → cancelled',   'cancelled',  wc_get_order( $rejected->get_id() )->get_status() );
t_eq( 'Accepted → processing',  'processing', wc_get_order( $accepted->get_id() )->get_status() );
t_eq( 'Pending leaves the order alone', 'processing', wc_get_order( $pending->get_id() )->get_status() );

// The request really was a GET carrying the cursor in the body.
$sent = T_Fake_API::requests_for( '/online_order_status' );
t_eq( 'exactly one status request', 1, count( $sent ) );
t_eq( 'sent as GET',                'GET', strtoupper( (string) $sent[0]['method'] ) );
t_ok( 'cursor sent in the body',    is_array( $sent[0]['body'] ) && array_key_exists( 'cursor', $sent[0]['body'] ) );

// -----------------------------------------------------------------------------
// 11b. Orders this plugin never pushed are not touched
// -----------------------------------------------------------------------------
t_head( '11b. Foreign orders are left alone' );

t_eq( 'foreign order status unchanged', 'processing', wc_get_order( $foreign_id )->get_status() );
t_ok( 'foreign order got no POS status meta', '' === (string) wc_get_order( $foreign_id )->get_meta( '_sellmo_pos_order_status' ) );

// -----------------------------------------------------------------------------
// 11c. What the update records on the order
// -----------------------------------------------------------------------------
t_head( '11c. Order record' );

$done = wc_get_order( $completed->get_id() );
t_eq( 'POS status code stored', '2',         (string) $done->get_meta( '_sellmo_pos_order_status' ) );
t_eq( 'POS status name stored', 'Completed', (string) $done->get_meta( '_sellmo_pos_order_status_name' ) );

$notes = wc_get_order_notes( [ 'order_id' => $done->get_id(), 'limit' => 10 ] );
$text  = implode( ' | ', array_map( static fn( $n ): string => $n->content, $notes ) );
t_ok( 'an order note names the POS status', false !== strpos( $text, 'Completed' ), $text );

$logs = t_log_since( $mark );
t_contains( 'the change is logged', 'status processing → completed', $logs );
t_contains( 'the run is summarised', 'order status(es) updated', $logs );

// -----------------------------------------------------------------------------
// 11d. Re-running changes nothing
// -----------------------------------------------------------------------------
t_head( '11d. Idempotent' );

$again = $order_sync->sync_order_statuses();

t_ok( 'second pull succeeded', ! empty( $again['success'] ) );
t_eq( 'nothing updated the second time', 0, (int) $again['count'] );
t_eq( 'completed order still completed',  'completed', wc_get_order( $completed->get_id() )->get_status() );

// -----------------------------------------------------------------------------
// 11e. A later POS status still lands
// -----------------------------------------------------------------------------
t_head( '11e. A status that changes later' );

T_Fake_API::install( [
	'/online_order_status' => [
		'order_status' => [ $status_row( $pending->get_id(), '2', 'Completed' ) ],
		'cursor'       => '',
	],
] );

$later = $order_sync->sync_order_statuses();
t_eq( 'the newly completed order was updated', 1, (int) $later['count'] );
t_eq( 'Pending → Completed applied', 'completed', wc_get_order( $pending->get_id() )->get_status() );

// -----------------------------------------------------------------------------
// 11e-2. An incoming status never bounces back out to the POS
//
// Applying "Accepted" moves an order into Processing, which is the very hook
// that pushes orders out. Left unguarded, the return leg would re-push the
// order — and for an order whose push never recorded a POS id, the duplicate
// guard would not catch it either.
// -----------------------------------------------------------------------------
t_head( '11e-2. No push-back loop' );

$loop_order = wc_create_order();
$loop_order->set_status( 'on-hold' );
$loop_order->update_meta_data( '_sellmo_order_id', '' ); // Pushed, but no id came back.
$loop_order->save();
$loop_id = $loop_order->get_id();
t_defer( static function () use ( $loop_id ): void {
	wp_delete_post( $loop_id, true );
} );

T_Fake_API::install( [
	'/online_order_status' => [
		'order_status' => [ $status_row( $loop_id, '1', 'Accepted' ) ],
		'cursor'       => '',
	],
	// Stubbed so a push would succeed — proving none was attempted.
	'/online_orders' => [ 'id' => 'POS-SHOULD-NOT-HAPPEN' ],
] );

$loop_result = $order_sync->sync_order_statuses();

t_eq( 'the order moved to processing', 'processing', wc_get_order( $loop_id )->get_status() );
t_eq( 'one order updated',             1, (int) $loop_result['count'] );
t_eq( 'and nothing was pushed back to the POS', 0, count( T_Fake_API::requests_for( '/online_orders' ) ) );
t_eq( 'no POS order id was overwritten', '', (string) wc_get_order( $loop_id )->get_meta( '_sellmo_order_id' ) );

// -----------------------------------------------------------------------------
// 11f. The dashboard button runs the same code
// -----------------------------------------------------------------------------
t_head( '11f. "Sync Order Status Now" button' );

$ajax_order = $make_order( 'processing', 'POS-AJAX' );

T_Fake_API::install( [
	'/online_order_status' => [
		'order_status' => [ $status_row( $ajax_order->get_id(), '2', 'Completed' ) ],
		'cursor'       => '',
	],
] );

$response = t_ajax( $admin, 'ajax_sync_order_status_now' );

t_ok( 'button reported success', ! empty( $response['success'] ), wp_json_encode( $response ) );
t_contains( 'and says what it did', 'Order status sync complete', [ (string) ( $response['data']['message'] ?? '' ) ] );
t_eq( 'the order was completed', 'completed', wc_get_order( $ajax_order->get_id() )->get_status() );

$meta = (array) Sellmo_Settings::get( 'last_order_status_sync' );
t_ok( 'the run is recorded for the dashboard', ! empty( $meta['time'] ) );
t_eq( 'and recorded as a success', 'success', (string) ( $meta['status'] ?? '' ) );

// With order sync switched off the button refuses rather than calling the API.
t_set_setting( 'order_sync_enabled', 'no' );
$disabled = t_ajax( $admin, 'ajax_sync_order_status_now' );
t_eq( 'disabled order sync is rejected', false, (bool) ( $disabled['success'] ?? true ) );
Sellmo_Settings::set( 'order_sync_enabled', 'yes' );

// -----------------------------------------------------------------------------
// 11g. An API failure is reported, not swallowed
// -----------------------------------------------------------------------------
t_head( '11g. API failure' );

T_Fake_API::install( [] ); // No stub — the harness fails the call.

$err_mark = t_log_mark();
$failed   = $order_sync->sync_order_statuses();

t_eq( 'failure reported',      false, (bool) $failed['success'] );
t_ok( 'failure logged',        ! empty( t_log_since( $err_mark, 'error' ) ) );
t_eq( 'dashboard shows the error', 'error', (string) ( ( (array) Sellmo_Settings::get( 'last_order_status_sync' ) )['status'] ?? '' ) );

// -----------------------------------------------------------------------------
// 11h. The schedule: cron interval + hook
// -----------------------------------------------------------------------------
t_head( '11h. Order status schedule' );

$scheduler = new Sellmo_Scheduler();
$schedules = wp_get_schedules();

foreach ( [ 5, 10, 15, 20, 30 ] as $minutes ) {
	$key = Sellmo_Scheduler::minute_schedule( $minutes );

	t_ok( "WP-Cron knows the {$minutes}-minute schedule", isset( $schedules[ $key ] ), implode( ', ', array_keys( $schedules ) ) );
	t_eq( "and its interval is {$minutes} minutes", $minutes * 60, (int) ( $schedules[ $key ]['interval'] ?? 0 ) );
	t_ok( "the dropdown offers it", array_key_exists( $key, Sellmo_Scheduler::order_interval_choices() ) );
}

// Every choice the settings dropdown offers must be schedulable, or saving it
// silently stops the cron event.
foreach ( array_keys( Sellmo_Scheduler::order_interval_choices() ) as $choice ) {
	t_ok( "schedulable: {$choice}", isset( $schedules[ $choice ] ) );
}

// Selecting an interval reschedules the event.
$hook = Sellmo_Scheduler::order_hook();
t_set_setting( 'order_sync_interval', Sellmo_Scheduler::minute_schedule( 5 ) );
$scheduler->reschedule_order();

$event = wp_get_scheduled_event( $hook );
t_ok( 'the order-status event is scheduled', false !== $event );
t_eq( 'on the chosen recurrence', Sellmo_Scheduler::minute_schedule( 5 ), (string) ( $event->schedule ?? '' ) );

// An unknown stored value falls back rather than leaving the event unscheduled.
Sellmo_Settings::set( 'order_sync_interval', 'not_a_schedule' );
t_eq( 'unknown interval falls back to 15 minutes', Sellmo_Scheduler::minute_schedule( 15 ), Sellmo_Scheduler::order_interval() );

// Turning order sync off clears the event.
Sellmo_Settings::set( 'order_sync_enabled', 'no' );
$scheduler->reschedule_order();
t_eq( 'disabling order sync unschedules it', false, wp_next_scheduled( $hook ) );

Sellmo_Settings::set( 'order_sync_enabled', 'yes' );
$scheduler->reschedule_order();
t_ok( 'enabling it schedules it again', (bool) wp_next_scheduled( $hook ) );

// -----------------------------------------------------------------------------
// 11i. The cron callback runs the same pull
// -----------------------------------------------------------------------------
t_head( '11i. Cron callback' );

$cron_order = $make_order( 'processing', 'POS-CRON' );

T_Fake_API::install( [
	'/online_order_status' => [
		'order_status' => [ $status_row( $cron_order->get_id(), '3', 'Rejected' ) ],
		'cursor'       => '',
	],
] );

$scheduler->run_order_status_sync();
t_eq( 'cron applied the POS status', 'cancelled', wc_get_order( $cron_order->get_id() )->get_status() );

// Disabled order sync makes the cron callback a no-op.
$noop_order = $make_order( 'processing', 'POS-NOOP' );
T_Fake_API::install( [
	'/online_order_status' => [
		'order_status' => [ $status_row( $noop_order->get_id(), '2', 'Completed' ) ],
		'cursor'       => '',
	],
] );

Sellmo_Settings::set( 'order_sync_enabled', 'no' );
$scheduler->run_order_status_sync();
t_eq( 'disabled cron changes nothing', 'processing', wc_get_order( $noop_order->get_id() )->get_status() );
Sellmo_Settings::set( 'order_sync_enabled', 'yes' );

exit( t_summary() );
