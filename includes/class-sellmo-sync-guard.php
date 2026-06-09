<?php
declare(strict_types=1);

/**
 * Resilience wrapper + HTTP failure logging utility for Sellmo POS Integration.
 *
 * Centralises the try-catch / is_wp_error() handling that every sync action
 * needs, and captures the exact HTTP status + response body on transport
 * failures instead of letting them die silently.
 *
 * @package Sellmo_WooCommerce
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Sync_Guard
 *
 * Static helpers only. Use Sellmo_Sync_Guard::run() to wrap any sync callable,
 * and Sellmo_Sync_Guard::log_response() to record an HTTP code + body.
 *
 * @since 1.1.0
 */
class Sellmo_Sync_Guard {

	/**
	 * Run a sync callable behind a try-catch + WP_Error guard.
	 *
	 * Any thrown Throwable or returned WP_Error is logged via Sellmo_Logger and
	 * converted into the standard sync result shape so callers never have to
	 * handle a raw exception.
	 *
	 * @since  1.1.0
	 * @param  callable $callback  The sync routine. Should return an array or WP_Error.
	 * @param  string   $sync_type Log sync_type: 'product', 'stock', 'order', 'webhook'.
	 * @param  string   $direction Log direction: 'sellmo_to_wc' or 'wc_to_sellmo'.
	 * @param  string   $context   Short human label used in the log message.
	 * @return array{ success: bool, count: int, message: string }
	 */
	public static function run(
		callable $callback,
		string $sync_type = 'product',
		string $direction = 'sellmo_to_wc',
		string $context = 'sync'
	): array {
		try {
			$result = $callback();

			// Callable handed us a WP_Error — log and normalise.
			if ( is_wp_error( $result ) ) {
				self::log_wp_error( $result, $sync_type, $direction, $context );

				return [
					'success' => false,
					'count'   => 0,
					'message' => $result->get_error_message(),
				];
			}

			// Already in the standard result shape.
			if ( is_array( $result ) ) {
				return [
					'success' => (bool) ( $result['success'] ?? true ),
					'count'   => (int) ( $result['count'] ?? 0 ),
					'message' => (string) ( $result['message'] ?? '' ),
				];
			}

			return [ 'success' => true, 'count' => 0, 'message' => '' ];

		} catch ( \Throwable $e ) {
			Sellmo_Logger::log(
				$sync_type,
				$direction,
				'error',
				sprintf(
					'%s crashed: %s (%s:%d)',
					$context,
					$e->getMessage(),
					basename( $e->getFile() ),
					$e->getLine()
				)
			);

			return [
				'success' => false,
				'count'   => 0,
				'message' => sprintf(
					/* translators: %s: context label */
					__( '%s failed due to a server error. Check the Sync Log for details.', 'sellmo-woocommerce' ),
					$context
				),
			];
		}
	}

	/**
	 * Log a WP_HTTP response failure with its HTTP status code and body.
	 *
	 * Handles both transport-level WP_Error responses (no connection) and
	 * non-2xx HTTP responses (server returned an error payload). The body is
	 * truncated so a large HTML error page never bloats the log table.
	 *
	 * @since  1.1.0
	 * @param  array|WP_Error $response  Raw return value from wp_remote_* / wp_remote_request().
	 * @param  string         $endpoint  Endpoint path for context, e.g. '/products'.
	 * @param  string         $sync_type Log sync_type.
	 * @param  string         $direction Log direction.
	 * @return bool                      True when the response is a usable 2xx, false when logged as a failure.
	 */
	public static function log_response(
		array|\WP_Error $response,
		string $endpoint,
		string $sync_type = 'product',
		string $direction = 'sellmo_to_wc'
	): bool {
		// Transport failure: DNS, timeout, refused connection.
		if ( is_wp_error( $response ) ) {
			Sellmo_Logger::log(
				$sync_type,
				$direction,
				'error',
				sprintf(
					'Connection failed on %s [%s]: %s',
					$endpoint,
					$response->get_error_code(),
					self::truncate( $response->get_error_message() )
				),
				$endpoint
			);
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		// Non-2xx: capture the exact HTTP code and the response body.
		if ( $code < 200 || $code >= 300 ) {
			Sellmo_Logger::log(
				$sync_type,
				$direction,
				'error',
				sprintf(
					'HTTP %d on %s. Response: %s',
					$code,
					$endpoint,
					self::truncate( '' !== $body ? $body : wp_remote_retrieve_response_message( $response ) )
				),
				$endpoint
			);
			return false;
		}

		return true;
	}

	/**
	 * Log a WP_Error, including HTTP status when present in its error data.
	 *
	 * @since  1.1.0
	 * @param  WP_Error $error     The error object.
	 * @param  string   $sync_type Log sync_type.
	 * @param  string   $direction Log direction.
	 * @param  string   $context   Short human label.
	 * @return void
	 */
	public static function log_wp_error(
		\WP_Error $error,
		string $sync_type,
		string $direction,
		string $context
	): void {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		Sellmo_Logger::log(
			$sync_type,
			$direction,
			'error',
			sprintf(
				'%s failed%s: %s',
				$context,
				$status > 0 ? sprintf( ' (HTTP %d)', $status ) : '',
				self::truncate( $error->get_error_message() )
			),
			is_array( $data ) && isset( $data['endpoint'] ) ? (string) $data['endpoint'] : ''
		);
	}

	/**
	 * Mirror a critical message into the WooCommerce logger when available.
	 *
	 * Lets fatal sync failures show up under WooCommerce > Status > Logs in
	 * addition to the plugin's own Sync Log, without a hard WC_Logger dependency.
	 *
	 * @since  1.1.0
	 * @param  string $level   One of: emergency, alert, critical, error, warning, notice, info, debug.
	 * @param  string $message Message to record.
	 * @return void
	 */
	public static function wc_log( string $level, string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( $logger && method_exists( $logger, 'log' ) ) {
			$logger->log( $level, $message, [ 'source' => 'sellmo-pos' ] );
		}
	}

	/**
	 * Truncate a log message so the log table stays readable.
	 *
	 * @since  1.1.0
	 * @param  string $message Raw message.
	 * @param  int    $max     Maximum character length.
	 * @return string
	 */
	private static function truncate( string $message, int $max = 500 ): string {
		$message = trim( $message );

		if ( strlen( $message ) <= $max ) {
			return $message;
		}

		return substr( $message, 0, $max - 3 ) . '...';
	}
}
