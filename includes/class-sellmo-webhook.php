<?php
declare(strict_types=1);

/**
 * Incoming webhook handler for Sellmo POS Integration.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Webhook
 *
 * Registers the REST endpoint POST /wp-json/sellmo-wc/v1/webhook.
 * Validates the X-Sellmo-Signature header using HMAC SHA256.
 * Returns 200 immediately and schedules async processing via
 * wp_schedule_single_event() for product.updated and inventory.updated events.
 *
 * @since 1.0.0
 */
class Sellmo_Webhook {

	/**
	 * REST API namespace.
	 */
	private const REST_NAMESPACE = 'sellmo-wc/v1';

	/**
	 * REST route path.
	 */
	private const REST_ROUTE = '/webhook';

	/**
	 * WP-Cron hook name for async webhook processing.
	 */
	private const PROCESS_HOOK = 'sellmo_wc_process_webhook';

	/**
	 * Constructor.
	 *
	 * Registers the REST route on rest_api_init and wires up the async
	 * processing callback to the sellmo_wc_process_webhook cron hook.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'rest_api_init',    [ $this, 'register_routes' ] );
		add_action( self::PROCESS_HOOK, [ $this, 'process_webhook' ] );
	}

	// -------------------------------------------------------------------------
	// Route registration
	// -------------------------------------------------------------------------

	/**
	 * Register POST /wp-json/sellmo-wc/v1/webhook with the WordPress REST API.
	 *
	 * Authentication is performed via HMAC signature check inside handle_webhook()
	 * rather than by WordPress user auth, so permission_callback always returns true.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_webhook' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	// -------------------------------------------------------------------------
	// Request handler
	// -------------------------------------------------------------------------

	/**
	 * Handle an incoming POST to the webhook endpoint.
	 *
	 * Validates the X-Sellmo-Signature HMAC SHA256 header, returns 200
	 * immediately, then schedules async payload processing so the response is
	 * not delayed by sync operations.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$raw_body  = $request->get_body();
		$signature = (string) ( $request->get_header( 'X-Sellmo-Signature' ) ?? '' );
		$secret    = (string) ( Sellmo_Settings::get( 'webhook_secret' ) ?: '' );

		if ( '' !== $secret ) {
			$expected = hash_hmac( 'sha256', $raw_body, $secret );

			if ( ! hash_equals( $expected, $signature ) ) {
				Sellmo_Logger::log(
					'webhook',
					'pos_to_wc',
					'error',
					'Webhook rejected: invalid signature.'
				);

				return new WP_REST_Response( [ 'message' => 'Invalid signature.' ], 401 );
			}
		}

		$payload = json_decode( $raw_body, true );

		if ( ! is_array( $payload ) ) {
			Sellmo_Logger::log(
				'webhook',
				'pos_to_wc',
				'error',
				'Webhook rejected: invalid JSON payload.'
			);

			return new WP_REST_Response( [ 'message' => 'Invalid JSON payload.' ], 400 );
		}

		wp_schedule_single_event( time(), self::PROCESS_HOOK, [ $payload ] );

		return new WP_REST_Response( [ 'message' => 'OK' ], 200 );
	}

	// -------------------------------------------------------------------------
	// Async processor
	// -------------------------------------------------------------------------

	/**
	 * Process a validated webhook payload asynchronously (via WP-Cron).
	 *
	 * Dispatches to Sellmo_Product_Sync::sync_single() for product.updated
	 * events and Sellmo_Stock_Sync::sync_single_product() for inventory.updated
	 * events. All other event types are logged as skipped.
	 *
	 * The payload shape Sellmo sends is:
	 *   { "event": "product.updated", "data": { "product_code": "123" } }
	 * or, as a fallback, a flat structure:
	 *   { "type": "product.updated", "product_code": "123" }
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $payload Decoded JSON payload from Sellmo.
	 * @return void
	 */
	public function process_webhook( array $payload ): void {
		$event        = (string) ( $payload['event'] ?? $payload['type'] ?? '' );
		$data         = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : $payload;
		$product_code = (string) ( $data['product_code'] ?? '' );

		try {
			switch ( $event ) {

				case 'product.updated':
					if ( '' === $product_code ) {
						Sellmo_Logger::log(
							'product',
							'pos_to_wc',
							'error',
							'Webhook product.updated payload missing product_code.'
						);
						break;
					}

					$sync    = new Sellmo_Product_Sync();
					$success = $sync->sync_single( $product_code );
					Sellmo_Logger::log(
						'product',
						'pos_to_wc',
						$success ? 'success' : 'error',
						$success
							? sprintf( 'Webhook: product %s synced.', $product_code )
							: sprintf( 'Webhook: product %s sync failed.', $product_code ),
						$product_code
					);
					break;

				case 'inventory.updated':
					if ( '' === $product_code ) {
						Sellmo_Logger::log(
							'stock',
							'pos_to_wc',
							'error',
							'Webhook inventory.updated payload missing product_code.'
						);
						break;
					}

					$sync    = new Sellmo_Stock_Sync();
					$success = $sync->sync_single_product( $product_code );
					Sellmo_Logger::log(
						'stock',
						'pos_to_wc',
						$success ? 'success' : 'error',
						$success
							? sprintf( 'Webhook: stock for %s updated.', $product_code )
							: sprintf( 'Webhook: stock for %s update failed.', $product_code ),
						$product_code
					);
					break;

				default:
					Sellmo_Logger::log(
						'webhook',
						'pos_to_wc',
						'skipped',
						sprintf( 'Webhook: unhandled event "%s".', $event )
					);
			}
		} catch ( \Throwable $e ) {
			Sellmo_Logger::log(
				'webhook',
				'pos_to_wc',
				'error',
				sprintf( 'Webhook processing exception for event "%s": %s', $event, $e->getMessage() ),
				$product_code
			);
		}
	}
}
