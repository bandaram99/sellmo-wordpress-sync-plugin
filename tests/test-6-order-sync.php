<?php
/**
 * Scenario 6 — an order is pushed to the POS correctly.
 *
 * Covers the payload built from a WooCommerce order, the Order Time Zone
 * setting actually converting the order timestamps, the HTTP push and what it
 * records on the order, stock being reduced by the ordered quantity, and the
 * log entries for both success and failure.
 *
 * No order is ever sent to the real POS — POST /online_orders is stubbed.
 *
 * Run: php tests/test-6-order-sync.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

t_set_setting( 'order_sync_enabled', 'yes' );
t_set_setting( 'terminal_key', 'ZZTEST-TERMINAL' );

$order_sync = new Sellmo_Order_Sync();

// -----------------------------------------------------------------------------
// Fixture: a product with a POS code and stock, and an order for 3 of it.
// -----------------------------------------------------------------------------
$pos_code = T_PREFIX . '-ORD-700';
$wc_id    = t_wc_simple( 'Orderable Widget', 250.00, $pos_code, t_uid( 'ORDPROD' ), 10 );

$order = wc_create_order();
$order->add_product( wc_get_product( $wc_id ), 3 );
$order->set_billing_first_name( 'Ada' );
$order->set_billing_last_name( 'Lovelace' );
$order->set_billing_email( 'ada@example.test' );
$order->set_billing_phone( '0771234567' );
$order->set_customer_note( 'Leave at reception' );
$order->calculate_totals();
$order->save();

$order_id = $order->get_id();
t_defer( static function () use ( $order_id ): void {
	t_delete_order( $order_id );
} );

t_ok( 'fixture: order created', $order_id > 0 );

// Pin the creation time so the timezone assertions are exact.
$order->set_date_created( '2026-01-15T12:00:00+00:00' );
$order->save();
$order = wc_get_order( $order_id );

// -----------------------------------------------------------------------------
// 6a. Payload contents
// -----------------------------------------------------------------------------
t_head( '6a. Order payload' );

t_set_setting( 'order_timezone', 'UTC' );
$payload = t_call( $order_sync, 'build_payload', [ $order ] );

t_eq( 'order reference is the WC order id', (string) $order_id, (string) $payload['order_reference_id'] );
t_eq( 'terminal key forwarded',             'ZZTEST-TERMINAL',  (string) $payload['target_terminal'] );
t_eq( 'customer first name',                'Ada',              (string) $payload['customer_first_name'] );
t_eq( 'customer last name',                 'Lovelace',         (string) $payload['customer_last_name'] );
t_eq( 'customer email',                     'ada@example.test', (string) $payload['customer_email'] );

// An order with no billing email must OMIT the key rather than send "". The POS
// validates the format of whatever it receives and answers HTTP 400
// "Invalid email format", rejecting the entire order — and the sync then retries
// and fails on every run. Orders legitimately have no email: phone orders,
// admin-created orders, checkouts with the field made optional.
$no_email = wc_get_order( $order_id );
$no_email->set_billing_email( '' );
$no_email->save();

$bare = t_call( $order_sync, 'build_payload', [ $no_email ] );
t_ok( 'no billing email -> customer_email key is omitted, not empty', ! array_key_exists( 'customer_email', $bare ) );
t_eq( 'the rest of the payload is unaffected', (string) $order_id, (string) $bare['order_reference_id'] );

$no_email->set_billing_email( 'ada@example.test' );
$no_email->save();
t_eq( 'customer note forwarded',            'Leave at reception', (string) $payload['order_comment'] );
t_eq( 'one line item',                      1,                  count( $payload['order_items'] ) );

$item = $payload['order_items'][0];
t_eq(   'line carries the POS product code', $pos_code, (string) $item['product_code'] );
t_eq(   'line carries the ordered quantity', 3,         (int) $item['product_qty'] );
t_near( 'line unit price',                   250.00,    (float) $item['product_unit_price'] );
t_near( 'line total',                        750.00,    (float) $item['product_price'] );
t_near( 'order total',                       750.00,    (float) $payload['order_total'] );

// -----------------------------------------------------------------------------
// 6b. Order Time Zone setting is honoured
// -----------------------------------------------------------------------------
t_head( '6b. Order Time Zone conversion' );

// The order was created at 2026-01-15 12:00:00 UTC. Each configured zone must
// shift the wall-clock strings the POS receives by exactly that zone's offset.
$expected = [
	'UTC'              => [ '2026-01-15', '2026-01-15 12:00:00' ],
	'Asia/Colombo'     => [ '2026-01-15', '2026-01-15 17:30:00' ], // +05:30
	'Asia/Tokyo'       => [ '2026-01-15', '2026-01-15 21:00:00' ], // +09:00
	'America/New_York' => [ '2026-01-15', '2026-01-15 07:00:00' ], // -05:00
];

foreach ( $expected as $tz => [ $exp_date, $exp_datetime ] ) {
	Sellmo_Settings::set( 'order_timezone', $tz );

	$p = t_call( $order_sync, 'build_payload', [ $order ] );

	t_eq( "{$tz}: order_date_time converted", $exp_datetime, (string) $p['order_date_time'] );
	t_eq( "{$tz}: order_date converted",      $exp_date,     (string) $p['order_date'] );
}

// A zone that crosses the date boundary must move the date too.
Sellmo_Settings::set( 'order_timezone', 'Pacific/Auckland' ); // +13:00 in January
$p = t_call( $order_sync, 'build_payload', [ $order ] );
t_eq( 'Pacific/Auckland: date rolls to the next day', '2026-01-16', (string) $p['order_date'] );
t_eq( 'Pacific/Auckland: time converted',             '2026-01-16 01:00:00', (string) $p['order_date_time'] );

// An unset / invalid zone must fall back to the WooCommerce site time zone.
Sellmo_Settings::set( 'order_timezone', '' );
$p_site   = t_call( $order_sync, 'build_payload', [ $order ] );
$site_now = ( clone $order->get_date_created() )->date( 'Y-m-d H:i:s' );
t_eq( 'empty setting falls back to the site time zone', $site_now, (string) $p_site['order_date_time'] );

Sellmo_Settings::set( 'order_timezone', 'Not/AZone' );
$p_bad = t_call( $order_sync, 'build_payload', [ $order ] );
t_eq( 'invalid zone falls back to the site time zone', $site_now, (string) $p_bad['order_date_time'] );

// Restore a known zone for the push assertions.
Sellmo_Settings::set( 'order_timezone', 'UTC' );

// -----------------------------------------------------------------------------
// 6c. The push, and what it records on the order
// -----------------------------------------------------------------------------
t_head( '6c. Push to the POS' );

T_Fake_API::install( [
	'/online_orders' => [ 'id' => 'POS-ORDER-9001', 'status' => 'ok' ],
] );

$mark   = t_log_mark();
$result = t_call( $order_sync, 'push_order', [ $order, true ] );

t_ok( 'push reported success', (bool) $result['success'], wp_json_encode( $result ) );
t_eq( 'POS order id captured', 'POS-ORDER-9001', (string) $result['sellmo_id'] );

$sent = T_Fake_API::requests_for( '/online_orders' );
t_eq( 'exactly one request sent', 1, count( $sent ) );
t_eq( 'sent via POST',            'POST', (string) $sent[0]['method'] );

$body = $sent[0]['body'];
t_eq(   'sent payload carries the product code', $pos_code, (string) $body['order_items'][0]['product_code'] );
t_eq(   'sent payload carries the quantity',     3,         (int) $body['order_items'][0]['product_qty'] );
t_eq(   'sent payload carries the UTC timestamp', '2026-01-15 12:00:00', (string) $body['order_date_time'] );
t_near( 'sent payload carries the total',        750.00,    (float) $body['order_total'] );

$order = wc_get_order( $order_id );
t_eq( 'POS order id stored on the order', 'POS-ORDER-9001', (string) $order->get_meta( '_sellmo_order_id' ) );
t_eq( 'no sync error recorded',           '',               (string) $order->get_meta( '_sellmo_sync_error' ) );

// The POS now owns the order: WooCommerce holds it until /online_order_status
// reports back (test-11 covers the return leg moving it on from here).
t_eq( 'pushed order put on hold', 'on-hold', $order->get_status() );

// A re-push of an order the POS has already reported on must not drag it back.
// Its own order, so the hold never touches the stock fixture below.
$reported = wc_create_order();
$reported->update_meta_data( '_sellmo_pos_order_status', '2' );
$reported->set_status( 'completed' );
$reported->save();
$reported_id = $reported->get_id();
t_defer( static function () use ( $reported_id ): void {
	t_delete_order( $reported_id );
} );

t_call( $order_sync, 'push_order', [ $reported, true ] );
t_eq( 're-push leaves a POS-reported order alone', 'completed', wc_get_order( $reported_id )->get_status() );

$notes = wc_get_order_notes( [ 'order_id' => $order_id, 'limit' => 10 ] );
$note_text = implode( ' | ', array_map( static fn( $n ): string => $n->content, $notes ) );
t_ok( 'an order note records the sync', false !== strpos( $note_text, 'POS-ORDER-9001' ), $note_text );

$logs = t_log_since( $mark );
t_contains( 'success written to the sync log', 'POS-ORDER-9001', $logs );

// -----------------------------------------------------------------------------
// 6d. Stock is reduced by the ordered quantity
// -----------------------------------------------------------------------------
t_head( '6d. Inventory reduction' );

// The push put the order on hold, and WooCommerce reduces stock on that
// transition — so the 3 ordered units are already gone by this point.
t_eq( 'WooCommerce stock reduced by 3', 7, (int) wc_get_product( $wc_id )->get_stock_quantity() );

// This is what WooCommerce core fires when an order reaches processing.
wc_maybe_reduce_stock_levels( $order_id );

t_eq( 'processing does not reduce it again', 7, (int) wc_get_product( $wc_id )->get_stock_quantity() );

// Reduction is guarded against running twice for the same order.
wc_maybe_reduce_stock_levels( $order_id );
t_eq( 'stock not double-reduced', 7, (int) wc_get_product( $wc_id )->get_stock_quantity() );

/*
 * The POS-side decrement is performed by SalesPlay when it receives the order,
 * so what this plugin must get right is the product_code + product_qty it sends
 * — both asserted in 6c. Verifying the POS ledger itself would require pushing
 * a real order to the live backend and re-reading /inventory, which no
 * automated test should do to a merchant's account.
 */
t_eq( 'quantity sent to the POS matches the WC reduction', 3, (int) $body['order_items'][0]['product_qty'] );

// -----------------------------------------------------------------------------
// 6e. Failures are recorded on the order and in the log
// -----------------------------------------------------------------------------
t_head( '6e. Failure path' );

$order2 = wc_create_order();
$order2->add_product( wc_get_product( $wc_id ), 1 );
$order2->calculate_totals();
$order2->save();
$order2_id = $order2->get_id();
t_defer( static function () use ( $order2_id ): void {
	t_delete_order( $order2_id );
} );

// No /online_orders stub this time, so the harness returns a transport error.
T_Fake_API::install( [] );

$err_mark = t_log_mark();
$failure  = t_call( $order_sync, 'push_order', [ $order2, false ] );

t_eq( 'push reported failure', false, (bool) $failure['success'] );

$order2 = wc_get_order( $order2_id );
t_ok( 'sync error stored on the order', '' !== (string) $order2->get_meta( '_sellmo_sync_error' ) );
t_eq( 'no POS order id stored',         '', (string) $order2->get_meta( '_sellmo_order_id' ) );

$err_notes = wc_get_order_notes( [ 'order_id' => $order2_id, 'limit' => 10 ] );
$err_text  = implode( ' | ', array_map( static fn( $n ): string => $n->content, $err_notes ) );
t_ok( 'an order note records the failure', false !== stripos( $err_text, 'failed' ), $err_text );

$err_logs = t_log_since( $err_mark, 'error' );
t_ok( 'failure written to the sync log', ! empty( $err_logs ), 'no error rows written' );

// -----------------------------------------------------------------------------
// 6f. Duplicate protection
// -----------------------------------------------------------------------------
t_head( '6f. Duplicate protection' );

T_Fake_API::install( [
	'/online_orders' => [ 'id' => 'POS-ORDER-DUPLICATE' ],
] );

// The already-synced order carries _sellmo_order_id, so the automatic hook must
// skip it (the manual meta-box button intentionally bypasses this guard).
$order_sync->sync_order( $order_id, wc_get_order( $order_id ) );

t_eq( 'no second request was sent',       0, count( T_Fake_API::requests_for( '/online_orders' ) ) );
t_eq( 'original POS order id unchanged',  'POS-ORDER-9001', (string) wc_get_order( $order_id )->get_meta( '_sellmo_order_id' ) );

// -----------------------------------------------------------------------------
// 6g. The Order Time Zone dropdown
// -----------------------------------------------------------------------------
t_head( '6g. Time zone dropdown' );

// Labels use STANDARD time, so they are identical whatever the date — including
// for zones that observe daylight saving. Running this suite in July and in
// January must produce the same strings.
$fixed_offsets = [
	// Fractional zones — the whole point of the exercise.
	'Asia/Kabul'          => '(UTC+04:30)', // Afghanistan
	'Asia/Colombo'        => '(UTC+05:30)', // Sri Lanka
	'Asia/Kolkata'        => '(UTC+05:30)', // India
	'Asia/Kathmandu'      => '(UTC+05:45)', // Nepal
	'Asia/Yangon'         => '(UTC+06:30)', // Myanmar
	'Asia/Tehran'         => '(UTC+03:30)', // Iran
	'Australia/Adelaide'  => '(UTC+09:30)', // observes DST — standard offset
	// Whole-hour zones, including DST observers.
	'UTC'                 => '(UTC+00:00)',
	'Europe/London'       => '(UTC+00:00)', // BST in summer, still labelled +00:00
	'America/Los_Angeles' => '(UTC-08:00)', // PDT in summer, still labelled -08:00
	'America/New_York'    => '(UTC-05:00)',
	'Asia/Bangkok'        => '(UTC+07:00)',
	'Asia/Singapore'      => '(UTC+08:00)',
	'Asia/Tokyo'          => '(UTC+09:00)',
];

foreach ( $fixed_offsets as $zone => $label ) {
	t_eq( "{$zone} offset", $label, Sellmo_Settings::utc_offset_label( $zone ) );
}

// Sri Lanka specifically — the offset the ticket called out as wrong elsewhere.
t_absent( 'Sri Lanka is not +08:30', '(UTC+08:30)', [ Sellmo_Settings::utc_offset_label( 'Asia/Colombo' ) ] );

// Negative offsets format correctly.
t_ok(
	'negative offsets render with a minus sign',
	1 === preg_match( '/^\(UTC-\d{2}:\d{2}\)$/', Sellmo_Settings::utc_offset_label( 'America/New_York' ) ),
	Sellmo_Settings::utc_offset_label( 'America/New_York' )
);

t_eq( 'an unknown identifier yields no label', '', Sellmo_Settings::utc_offset_label( 'Not/AZone' ) );

$choices = Sellmo_Settings::timezone_choices();
t_eq( 'two option groups', 2, count( $choices ) );

$common = array_values( $choices )[0];
$all    = array_values( $choices )[1];

t_ok( 'common group is short',      count( $common ) > 5 && count( $common ) < 40 );
t_ok( 'all group covers every zone', count( $all ) === count( timezone_identifiers_list() ) );

// Every value must be a real IANA identifier, or the save handler rejects it
// and Sellmo_Order_Sync silently ignores the setting.
$valid   = timezone_identifiers_list();
$invalid = array_diff( array_keys( $all ), $valid );
t_eq( 'every option value is a valid identifier', [], array_values( $invalid ) );

$common_invalid = array_diff( array_keys( $common ), $valid );
t_eq( 'every common option value is valid', [], array_values( $common_invalid ) );

// Labels read offset-first.
foreach ( [ $common, $all ] as $group ) {
	$bad = 0;
	foreach ( $group as $label ) {
		if ( 1 !== preg_match( '/^\(UTC[+-]\d{2}:\d{2}\) \S/', (string) $label ) ) {
			$bad++;
		}
	}
	t_eq( 'every label is "(UTC±HH:MM) Name"', 0, $bad );
}

t_eq( 'Sri Lanka labelled as asked', '(UTC+05:30) Sri Lanka, India', (string) $common['Asia/Colombo'] );
t_eq( 'Nepal labelled as asked',     '(UTC+05:45) Nepal',            (string) $common['Asia/Kathmandu'] );
t_eq( 'Myanmar labelled as asked',   '(UTC+06:30) Myanmar',          (string) $common['Asia/Yangon'] );

// Sorted west to east, and inside the documented range. Offsets are re-read
// from each label so the assertion tests what a user actually sees.
$offsets = [];
foreach ( $all as $label ) {
	preg_match( '/^\(UTC([+-])(\d{2}):(\d{2})\)/', (string) $label, $m );
	$offsets[] = ( '-' === $m[1] ? -1 : 1 ) * ( ( (int) $m[2] * 3600 ) + ( (int) $m[3] * 60 ) );
}

$sorted = $offsets;
sort( $sorted );
t_eq( 'all-zones list is sorted by offset', $sorted, $offsets );
t_ok( 'offsets stay within UTC-12:00 .. UTC+14:00', min( $offsets ) >= -12 * 3600 && max( $offsets ) <= 14 * 3600 );

// Fractional offsets must be representable — a list of whole hours only would
// mean the formatter is truncating.
$minutes = array_unique( array_map( static fn( int $o ): int => intdiv( abs( $o ) % 3600, 60 ), $offsets ) );
sort( $minutes );
t_eq( 'quarter- and half-hour offsets present', [ 0, 30, 45 ], $minutes );

// A zone chosen from the dropdown must actually drive the payload.
Sellmo_Settings::set( 'order_timezone', 'Asia/Kathmandu' );
$p_np = t_call( $order_sync, 'build_payload', [ $order ] );
t_eq( 'Nepal (+05:45) applied to the order payload', '2026-01-15 17:45:00', (string) $p_np['order_date_time'] );

exit( t_summary() );
