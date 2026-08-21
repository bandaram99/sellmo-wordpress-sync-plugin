<?php
/**
 * Scenario 14 — the webhook REST route is never unauthenticated.
 *
 * The route is registered with permission_callback => '__return_true' because
 * callers are POS servers, not logged-in users, so the HMAC check inside the
 * handler is the ONLY thing standing between the internet and a sync run.
 * It previously skipped that check whenever the shared secret was empty — and
 * the secret shipped empty by default, so a fresh install accepted anonymous
 * payloads. This suite pins the fixed behaviour.
 *
 * Sellmo_Plugin::init() does not currently instantiate Sellmo_Webhook, so the
 * route is not registered on a normal request. This suite registers it itself
 * and drives the real handler, so the guarantee holds the moment the feature is
 * switched back on — which is exactly when a regression would go unnoticed.
 *
 * Run: php tests/test-14-webhook-auth.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** POST a body to the webhook route and return [status, message]. */
function t14_post( string $body, ?string $signature ): array {
	$request = new WP_REST_Request( 'POST', '/sellmo-wc/v1/webhook' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( $body );

	if ( null !== $signature ) {
		$request->set_header( 'X-Sellmo-Signature', $signature );
	}

	$response = rest_get_server()->dispatch( $request );
	$data     = (array) $response->get_data();

	return [ (int) $response->get_status(), (string) ( $data['message'] ?? '' ) ];
}

// Register the route for this process, whether or not the bootstrap wires it up.
$webhook = new Sellmo_Webhook();
$webhook->register_routes();

$payload = (string) wp_json_encode( [ 'event' => 'product.updated', 'data' => [ 'id' => 'ZZTEST-nope' ] ] );

// Block the async processor: a 200 must never mean real sync work here.
$processed = 0;
add_filter(
	'pre_schedule_event',
	static function ( $pre, $event ) use ( &$processed ) {
		if ( isset( $event->hook ) && 'sellmo_wc_process_webhook' === $event->hook ) {
			$processed++;
			return false; // Swallow it — do not queue a real sync.
		}
		return $pre;
	},
	10,
	2
);

// -----------------------------------------------------------------------------
t_head( 'Route wiring' );
// -----------------------------------------------------------------------------

$routes = rest_get_server()->get_routes();
t_ok( 'webhook route is registered', isset( $routes['/sellmo-wc/v1/webhook'] ) );
t_ok(
	'route is public by design — the HMAC check is the only gate',
	'__return_true' === ( $routes['/sellmo-wc/v1/webhook'][0]['permission_callback'] ?? null )
);

// -----------------------------------------------------------------------------
t_head( 'Secret generation' );
// -----------------------------------------------------------------------------

t_set_setting( 'webhook_secret', '' );

$secret = Sellmo_Webhook::ensure_secret();

t_ok( 'ensure_secret() creates a secret when none is stored', '' !== $secret );
t_eq( 'secret is 64 characters', 64, strlen( $secret ) );
t_ok( 'secret is alphanumeric (safe to hand-copy into the POS backoffice)', 1 === preg_match( '/^[A-Za-z0-9]+$/', $secret ) );
t_eq( 'secret is persisted', $secret, (string) Sellmo_Settings::get( 'webhook_secret' ) );
t_eq( 'ensure_secret() is idempotent', $secret, Sellmo_Webhook::ensure_secret() );

// -----------------------------------------------------------------------------
t_head( 'Unsigned requests are rejected' );
// -----------------------------------------------------------------------------

[ $status, $message ] = t14_post( $payload, null );
t_eq( 'no signature header → 401', 401, $status );

[ $status ] = t14_post( $payload, '' );
t_eq( 'empty signature → 401', 401, $status );

[ $status ] = t14_post( $payload, str_repeat( 'a', 64 ) );
t_eq( 'wrong signature → 401', 401, $status );

[ $status ] = t14_post( $payload, hash_hmac( 'sha256', $payload, 'not-the-secret' ) );
t_eq( 'signature from the wrong secret → 401', 401, $status );

[ $status ] = t14_post( '{"event":"product.updated"}', hash_hmac( 'sha256', $payload, $secret ) );
t_eq( 'signature that does not cover the sent body → 401', 401, $status );

t_eq( 'no payload reached the async processor', 0, $processed );

// -----------------------------------------------------------------------------
t_head( 'The regression itself: an empty secret must not disable the check' );
// -----------------------------------------------------------------------------

// Blank the option behind ensure_secret()'s back, exactly as a pre-fix install
// or a hand-edited option would look, then fire an unsigned request.
Sellmo_Settings::set( 'webhook_secret', '' );

[ $status, $message ] = t14_post( $payload, null );
t_eq( 'unsigned request with a blank stored secret → 401', 401, $status );
t_eq( 'no payload reached the async processor', 0, $processed );

// The handler self-heals: it generated a secret rather than serving an open route.
$regenerated = (string) Sellmo_Settings::get( 'webhook_secret' );
t_ok( 'handler regenerated the missing secret', '' !== $regenerated );

// -----------------------------------------------------------------------------
t_head( 'A correctly signed request is accepted' );
// -----------------------------------------------------------------------------

[ $status, $message ] = t14_post( $payload, hash_hmac( 'sha256', $payload, $regenerated ) );
t_eq( 'valid signature → 200', 200, $status );
t_eq( 'valid payload was queued for async processing', 1, $processed );

exit( t_summary() );
