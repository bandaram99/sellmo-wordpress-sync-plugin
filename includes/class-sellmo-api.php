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
		$sync_type = $this->resolve_sync_type_from_endpoint( $endpoint );
		$direction = 'POST' === $method ? 'wc_to_sellmo' : 'sellmo_to_wc';
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
				Sellmo_Logger::log(
					$sync_type,
					$direction,
					'error',
					sprintf(
						'API transport error on %s %s: %s',
						$method,
						$endpoint,
						$this->truncate_for_log( $response->get_error_message() )
					),
					$endpoint
				);
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

				Sellmo_Logger::log(
					$sync_type,
					$direction,
					'error',
					sprintf(
						'API %s %s failed with HTTP %d: %s',
						$method,
						$endpoint,
						$code,
						$this->truncate_for_log( (string) $message )
					),
					$endpoint
				);

				return new \WP_Error(
					'sellmo_api_error',
					$message,
					[ 'status' => $code, 'endpoint' => $endpoint ]
				);
			}

			// Success — decode and return.
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( null === $decoded ) {
				Sellmo_Logger::log(
					$sync_type,
					$direction,
					'error',
					sprintf( 'API %s %s returned invalid JSON.', $method, $endpoint ),
					$endpoint
				);

				return new \WP_Error( 'sellmo_invalid_json', 'Invalid JSON in Sellmo API response.', [ 'endpoint' => $endpoint ] );
			}

			return $decoded;
		}

		// Exhausted all retries on 429.
		$message = sprintf(
			'Sellmo API rate limit exceeded after %d retries on %s.',
			$this->max_retries,
			$endpoint
		);

		Sellmo_Logger::log(
			$sync_type,
			$direction,
			'error',
			$message,
			$endpoint
		);

		return new \WP_Error(
			'sellmo_rate_limited',
			$message
		);
	}

	/**
	 * Resolve the sync log type from the API endpoint path.
	 *
	 * @since  1.0.0
	 * @param  string $endpoint API endpoint path.
	 * @return string
	 */
	private function resolve_sync_type_from_endpoint( string $endpoint ): string {
		if ( str_contains( $endpoint, '/online_orders' ) ) {
			return 'order';
		}

		if ( str_contains( $endpoint, '/inventory' ) ) {
			return 'stock';
		}

		if ( str_contains( $endpoint, '/products' ) || str_contains( $endpoint, '/categories' ) ) {
			return 'product';
		}

		return 'webhook';
	}

	/**
	 * Truncate log text to keep the sync log table readable.
	 *
	 * @since  1.0.0
	 * @param  string $message Log message.
	 * @return string
	 */
	private function truncate_for_log( string $message ): string {
		$max_length = 500;

		if ( strlen( $message ) <= $max_length ) {
			return $message;
		}

		return substr( $message, 0, $max_length - 3 ) . '...';
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
		$transient_key = POS_Brand::transient( 'categories' );
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
	 * Like /inventory, the /products cursor only advances when it is sent in the
	 * JSON body of the GET request (a query-string cursor is ignored), so this
	 * uses request_get_with_body() rather than the query-string make_request().
	 *
	 * @since  1.0.0
	 * @param  string $cursor Optional pagination cursor from a previous response.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_products( string $cursor = '' ): array|\WP_Error {
		$transient_key = POS_Brand::transient( 'products_' . md5( $cursor ) );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = $this->request_get_with_body(
			'/products',
			[
				'product_ids'    => '',
				'created_at_min' => '',
				'created_at_max' => '',
				'limit'          => '250',
				'cursor'         => $cursor,
			],
			'product'
		);

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
	 * The SalesPlay /inventory endpoint only paginates when limit + cursor are sent
	 * in the JSON body of a GET request (a query-string cursor is ignored, so the
	 * returned cursor never advances and the same first page repeats forever). This
	 * delegates to request_inventory() which sends the cursor in the body — matching
	 * the standalone export script — instead of the generic query-string make_request().
	 *
	 * @since  1.0.0
	 * @param  string $cursor Optional pagination cursor from a previous response.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_inventory( string $cursor = '' ): array|\WP_Error {
		$transient_key = POS_Brand::transient( 'inventory_' . md5( $cursor ) );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = $this->request_get_with_body(
			'/inventory',
			[
				'product_ids' => '',
				'shop_ids'    => '',
				'limit'       => '250',
				'cursor'      => $cursor,
			],
			'stock'
		);

		if ( ! is_wp_error( $result ) ) {
			set_transient( $transient_key, $result, MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Execute a single GET page request with the cursor carried in the JSON body.
	 *
	 * Several SalesPlay endpoints (/inventory, /products) only paginate when the
	 * cursor + limit are sent in the body of an uppercase GET, authenticated via
	 * the `token` header — a query-string cursor is ignored and never advances.
	 * WordPress's wp_remote_* helpers force GET data into the query string, so this
	 * calls WP's bundled Requests library directly with data_format = 'body'.
	 * Handles HTTP 429 with exponential backoff (up to max_retries) and never logs
	 * the token.
	 *
	 * @since  1.0.0
	 * @param  string               $endpoint  Endpoint path, e.g. '/inventory'.
	 * @param  array<string, mixed> $payload   Request body (must include the cursor).
	 * @param  string               $sync_type Log sync_type for any error rows.
	 * @return array<string, mixed>|WP_Error   Decoded JSON array on success, WP_Error on failure.
	 */
	private function request_get_with_body( string $endpoint, array $payload, string $sync_type ): array|\WP_Error {
		$url     = self::API_BASE . $endpoint;
		$headers = [
			'token'        => 'Bearer ' . $this->token,
			'Content-Type' => 'application/json',
		];
		$body    = wp_json_encode( $payload );
		$options = [
			'data_format' => 'body',
			'timeout'     => 20,
		];

		for ( $attempt = 0; $attempt < $this->max_retries; $attempt++ ) {
			try {
				$response = \WpOrg\Requests\Requests::request( $url, $headers, $body, 'GET', $options );
			} catch ( \Throwable $e ) {
				// Connection-level failure — no retry.
				Sellmo_Logger::log(
					$sync_type,
					'sellmo_to_wc',
					'error',
					sprintf( 'API transport error on GET %s: %s', $endpoint, $this->truncate_for_log( $e->getMessage() ) ),
					$endpoint
				);
				return new \WP_Error( 'sellmo_http_error', $e->getMessage(), [ 'endpoint' => $endpoint ] );
			}

			$code = (int) $response->status_code;

			// Rate limited — back off and retry.
			if ( 429 === $code ) {
				sleep( (int) pow( 2, $attempt ) );
				continue;
			}

			// Any other non-2xx — return error immediately.
			if ( $code < 200 || $code >= 300 ) {
				$message = '' !== (string) $response->body ? (string) $response->body : ( 'HTTP ' . $code );

				Sellmo_Logger::log(
					$sync_type,
					'sellmo_to_wc',
					'error',
					sprintf( 'API GET %s failed with HTTP %d: %s', $endpoint, $code, $this->truncate_for_log( $message ) ),
					$endpoint
				);

				return new \WP_Error(
					'sellmo_api_error',
					$message,
					[ 'status' => $code, 'endpoint' => $endpoint ]
				);
			}

			// Success — decode and return.
			$decoded = json_decode( (string) $response->body, true );

			if ( null === $decoded ) {
				Sellmo_Logger::log(
					$sync_type,
					'sellmo_to_wc',
					'error',
					sprintf( 'API GET %s returned invalid JSON.', $endpoint ),
					$endpoint
				);

				return new \WP_Error( 'sellmo_invalid_json', 'Invalid JSON in Sellmo API response.', [ 'endpoint' => $endpoint ] );
			}

			return $decoded;
		}

		// Exhausted all retries on 429.
		$message = sprintf( 'Sellmo API rate limit exceeded after %d retries on %s.', $this->max_retries, $endpoint );

		Sellmo_Logger::log( $sync_type, 'sellmo_to_wc', 'error', $message, $endpoint );

		return new \WP_Error( 'sellmo_rate_limited', $message );
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
