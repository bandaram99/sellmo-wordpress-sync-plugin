<?php
declare(strict_types=1);

/**
 * Sellmo / SalesPlay API client for Sellmo POS Integration.
 *
 * All HTTP communication with the Sellmo API must go through this class.
 * Never use raw cURL — use wp_remote_get() / wp_remote_post() only.
 * Never log or output the API token.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_API
 *
 * Handles all outbound HTTP requests to the SalesPlay / Sellmo REST API.
 * Responses for GET endpoints are cached in transients to respect the
 * 300 requests / 300 seconds rate limit.
 *
 * @since 1.0.0
 */
class Sellmo_API {

	/**
	 * Base URL for all API calls.
	 */
	private const API_BASE = 'https://api.salesplaypos.com/v1.0';

	/**
	 * Bearer token read from options. Never exposed in logs or output.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Maximum number of retry attempts on HTTP 429 responses.
	 *
	 * @var int
	 */
	private int $max_retries = 3;

	/**
	 * Constructor.
	 *
	 * Reads the API token from stored options.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->token = (string) Sellmo_Settings::get( 'api_token' );
	}

	/**
	 * Execute an HTTP request against the Sellmo API.
	 *
	 * Handles exponential backoff on HTTP 429 (rate limited) up to
	 * $this->max_retries attempts. All other non-2xx responses return
	 * a WP_Error immediately without retrying.
	 *
	 * The $args array (which contains the Authorization header) is never
	 * passed to Sellmo_Logger to prevent token exposure in the log table.
	 *
	 * @since  1.0.0
	 * @param  string               $method   HTTP method: 'GET' or 'POST'.
	 * @param  string               $endpoint API endpoint path, e.g. '/products'.
	 * @param  array<string, mixed> $body     Optional request body for POST requests.
	 * @return array<string, mixed>|WP_Error  Decoded JSON array on success, WP_Error on failure.
	 */
	private function make_request( string $method, string $endpoint, array $body = [] ): array|\WP_Error {
		$url  = self::API_BASE . $endpoint;
		$args = [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			],
			'timeout' => 15,
		];

		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $body );
		}

		for ( $attempt = 0; $attempt < $this->max_retries; $attempt++ ) {
			$response = ( 'POST' === $method )
				? wp_remote_post( $url, $args )
				: wp_remote_get( $url, $args );

			// Connection-level failure — no retry.
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );

			// Rate limited — back off and retry.
			if ( 429 === $code ) {
				sleep( (int) pow( 2, $attempt ) );
				continue;
			}

			// Any other non-2xx — return error immediately.
			// Use the response body as the message when present so callers see the
			// exact JSON error detail (e.g. INVALID_VALUE) rather than just "Bad Request".
			if ( $code < 200 || $code >= 300 ) {
				$response_body = wp_remote_retrieve_body( $response );
				$message       = '' !== $response_body ? $response_body : wp_remote_retrieve_response_message( $response );

				return new \WP_Error(
					'sellmo_api_error',
					$message,
					[ 'status' => $code, 'endpoint' => $endpoint ]
				);
			}

			// Success — decode and return.
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( null === $decoded ) {
				return new \WP_Error( 'sellmo_invalid_json', 'Invalid JSON in Sellmo API response.', [ 'endpoint' => $endpoint ] );
			}

			return $decoded;
		}

		// Exhausted all retries on 429.
		return new \WP_Error(
			'sellmo_rate_limited',
			sprintf(
				'Sellmo API rate limit exceeded after %d retries on %s.',
				$this->max_retries,
				$endpoint
			)
		);
	}

	/**
	 * Fetch all shops associated with the account.
	 *
	 * Not cached — used only on the settings page to populate the shop dropdown.
	 *
	 * @since  1.0.0
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_shops(): array|\WP_Error {
		return $this->make_request( 'GET', '/shops' );
	}

	/**
	 * Fetch all product categories.
	 *
	 * Response is cached for 5 minutes.
	 *
	 * @since  1.0.0
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_categories(): array|\WP_Error {
		$transient_key = 'sellmo_wc_categories';
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = $this->make_request( 'GET', '/categories' );

		if ( ! is_wp_error( $result ) ) {
			set_transient( $transient_key, $result, 5 * MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Fetch a page of products, optionally starting from a cursor position.
	 *
	 * Requests 250 products per page (API maximum) to minimise request count.
	 * Each cursor position is cached separately for 5 minutes.
	 *
	 * @since  1.0.0
	 * @param  string $cursor Optional pagination cursor from a previous response.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_products( string $cursor = '' ): array|\WP_Error {
		$transient_key = 'sellmo_wc_products_' . md5( $cursor );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$endpoint = '/products?limit=250';
		if ( '' !== $cursor ) {
			$endpoint .= '&cursor=' . rawurlencode( $cursor );
		}

		$result = $this->make_request( 'GET', $endpoint );

		if ( ! is_wp_error( $result ) ) {
			set_transient( $transient_key, $result, 5 * MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Fetch a page of inventory levels, optionally starting from a cursor position.
	 *
	 * Requests 250 items per page. Cached for 1 minute to keep stock levels fresh.
	 *
	 * @since  1.0.0
	 * @param  string $cursor Optional pagination cursor from a previous response.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_inventory( string $cursor = '' ): array|\WP_Error {
		$transient_key = 'sellmo_wc_inventory_' . md5( $cursor );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$endpoint = '/inventory?limit=250';
		if ( '' !== $cursor ) {
			$endpoint .= '&cursor=' . rawurlencode( $cursor );
		}

		$result = $this->make_request( 'GET', $endpoint );

		if ( ! is_wp_error( $result ) ) {
			set_transient( $transient_key, $result, MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Push a WooCommerce order to the Sellmo API.
	 *
	 * Not cached — order creation is a write operation.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $order_data Formatted order payload.
	 * @return array<string, mixed>|WP_Error    API response array on success, WP_Error on failure.
	 */
	public function create_order( array $order_data ): array|\WP_Error {
		return $this->make_request( 'POST', '/online_orders', $order_data );
	}
}
