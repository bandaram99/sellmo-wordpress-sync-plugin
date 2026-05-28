<?php
declare(strict_types=1);

/**
 * Stock sync handler for Sellmo POS Integration.
 *
 * Pulls inventory levels from the Sellmo API and writes them to WooCommerce
 * product stock. Supports simple products, variations, and paginated responses.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Stock_Sync
 *
 * Syncs inventory levels from Sellmo to WooCommerce stock.
 * Cron callbacks are registered in Sellmo_Scheduler, not here.
 * All stock writes go through wc_update_product_stock().
 *
 * @since 1.0.0
 */
class Sellmo_Stock_Sync {

	/**
	 * Object-cache group used during a sync run to avoid repeated DB lookups.
	 */
	private const CACHE_GROUP = 'sellmo_wc_stock';

	/**
	 * Constructor.
	 *
	 * No hooks registered here — cron callbacks live in Sellmo_Scheduler.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {}

	// -------------------------------------------------------------------------
	// Public entry points
	// -------------------------------------------------------------------------

	/**
	 * Main cron entry point.
	 *
	 * Checks that stock sync is enabled before delegating to do_full_sync().
	 * Called by Sellmo_Scheduler on the sellmo_wc_stock_sync cron event.
	 *
	 * @since  1.0.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	public function run_sync(): array {
		if ( 'yes' !== (string) Sellmo_Settings::get( 'stock_sync_enabled' ) ) {
			return [ 'success' => true, 'count' => 0, 'message' => 'Stock sync is disabled.' ];
		}

		return $this->do_full_sync();
	}

	/**
	 * Dashboard manual-sync entry point.
	 *
	 * Runs regardless of the enabled toggle — lets admins force a sync.
	 * Extends the PHP time limit so a large catalog does not abort mid-run.
	 *
	 * @since  1.0.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	public function run_manual_sync(): array {
		// Extend execution time for the AJAX request. Silently ignored on hosts
		// that lock max_execution_time via php.ini.
		set_time_limit( 60 );

		return $this->do_full_sync();
	}

	/**
	 * Webhook-triggered single-product sync.
	 *
	 * Pages through the Sellmo inventory API until the item with the given
	 * product code is found, then updates the matching WC product/variation.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_code Sellmo product_code to look up.
	 * @return bool   True if the stock was updated successfully.
	 */
	public function sync_single_product( string $sellmo_product_code ): bool {
		if ( '' === $sellmo_product_code ) {
			return false;
		}

		$api       = new Sellmo_API();
		$cursor    = '';
		$page      = 0;
		$max_pages = 20;

		do {
			$page++;
			$response = $api->get_inventory( $cursor );

			if ( is_wp_error( $response ) ) {
				Sellmo_Logger::log(
					'stock',
					'sellmo_to_wc',
					'error',
					$response->get_error_message(),
					$sellmo_product_code
				);
				return false;
			}

			$items = $this->extract_items( $response );

			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $item ) {
				if ( (string) ( $item['product_code'] ?? '' ) !== $sellmo_product_code ) {
					continue;
				}

				$quantity = $this->extract_quantity( $item );
				if ( null === $quantity ) {
					continue;
				}

				return 'success' === $this->update_stock_for_code( $sellmo_product_code, $quantity );
			}

			$next_cursor = $this->extract_next_cursor( $response );
			$cursor      = $next_cursor;

			if ( $page >= $max_pages && '' !== $cursor ) {
				break;
			}

		} while ( '' !== $cursor );

		Sellmo_Logger::log(
			'stock',
			'sellmo_to_wc',
			'skipped',
			"No inventory record found in Sellmo for product code: {$sellmo_product_code}",
			$sellmo_product_code
		);
		return false;
	}

	// -------------------------------------------------------------------------
	// Core sync logic
	// -------------------------------------------------------------------------

	/**
	 * Fetch all inventory pages from Sellmo and update WC stock for each item.
	 *
	 * Updates sellmo_wc_last_stock_sync with a summary on completion.
	 *
	 * @since  1.0.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	private function do_full_sync(): array {
		$api       = new Sellmo_API();
		$cursor    = '';
		$page      = 0;
		$max_pages = 20;
		$synced    = 0;
		$skipped   = 0;
		$errors    = 0;
		$hit_limit = false;

		do {
			$page++;
			$response = $api->get_inventory( $cursor );

			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();

				Sellmo_Logger::log( 'stock', 'sellmo_to_wc', 'error', $message );

				Sellmo_Settings::set( 'last_stock_sync', [
					'time'   => time(),
					'status' => 'error',
					'count'  => $synced,
				] );

				return [ 'success' => false, 'count' => $synced, 'message' => $message ];
			}

			$items = $this->extract_items( $response );

			// An empty page signals end-of-data even when a cursor value is present.
			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $item ) {
				$code     = (string) ( $item['product_code'] ?? '' );
				$quantity = $this->extract_quantity( $item );

				if ( '' === $code || null === $quantity ) {
					continue;
				}

				$outcome = $this->update_stock_for_code( $code, $quantity );

				match ( $outcome ) {
					'success' => $synced++,
					'skipped' => $skipped++,
					default   => $errors++,
				};
			}

			$next_cursor = $this->extract_next_cursor( $response );
			$cursor      = $next_cursor;

			// Safety cap: 20 pages × 250 items = 5,000 products max per run.
			if ( $page >= $max_pages && '' !== $cursor ) {
				$hit_limit = true;
				break;
			}

		} while ( '' !== $cursor );

		if ( $hit_limit ) {
			Sellmo_Logger::log(
				'stock',
				'sellmo_to_wc',
				'skipped',
				sprintf( 'Safety limit reached after %d pages. Run sync again to continue.', $max_pages )
			);
		}

		// Treat a run with partial errors as "success" if at least something synced.
		$status = ( $errors > 0 && 0 === $synced ) ? 'error' : 'success';

		Sellmo_Settings::set( 'last_stock_sync', [
			'time'   => time(),
			'status' => $status,
			'count'  => $synced,
		] );

		Sellmo_Logger::log(
			'stock',
			'sellmo_to_wc',
			$status,
			sprintf( '%d synced, %d skipped, %d errors.', $synced, $skipped, $errors )
		);

		return [
			'success' => 'success' === $status,
			'count'   => $synced,
			'message' => '',
		];
	}

	/**
	 * Update WooCommerce stock for the product/variation matching $code.
	 *
	 * Returns 'success', 'skipped', or 'error'. Products with no WC match or
	 * with _sellmo_sync_enabled !== 'yes' are silently skipped.
	 *
	 * @since  1.0.0
	 * @param  string $code     Sellmo product_code.
	 * @param  int    $quantity New stock quantity.
	 * @return string           'success' | 'skipped' | 'error'
	 */
	private function update_stock_for_code( string $code, int $quantity ): string {
		$product_id = $this->find_product_id_by_code( $code );

		if ( 0 === $product_id ) {
			// No linked WC product — normal for Sellmo products not imported yet.
			return 'skipped';
		}

		if ( ! $this->is_sync_enabled( $product_id ) ) {
			// Sync disabled on this product — skip silently.
			return 'skipped';
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			Sellmo_Logger::log(
				'stock',
				'sellmo_to_wc',
				'error',
				"Could not load WC product #{$product_id} (code: {$code}).",
				$code
			);
			return 'error';
		}

		// Parent variable products do not hold stock themselves; only variations do.
		if ( $product->is_type( 'variable' ) ) {
			Sellmo_Logger::log(
				'stock',
				'sellmo_to_wc',
				'skipped',
				"Product #{$product_id} is a variable parent — update its variations instead.",
				$code
			);
			return 'skipped';
		}

		$result = wc_update_product_stock( $product, $quantity, 'set' );

		if ( false === $result ) {
			Sellmo_Logger::log(
				'stock',
				'sellmo_to_wc',
				'error',
				"Failed to update stock to {$quantity} for product #{$product_id} (code: {$code}).",
				$code
			);
			return 'error';
		}

		Sellmo_Logger::log(
			'stock',
			'sellmo_to_wc',
			'success',
			"Stock set to {$quantity} for product #{$product_id} (code: {$code}).",
			(string) $product_id
		);
		return 'success';
	}

	// -------------------------------------------------------------------------
	// Lookup helpers
	// -------------------------------------------------------------------------

	/**
	 * Find a WC product or variation ID by its Sellmo product code.
	 *
	 * Searches both 'product' and 'product_variation' post types. Results are
	 * cached in the WP object cache for 5 minutes to avoid hammering the DB
	 * during a bulk sync run.
	 *
	 * @since  1.0.0
	 * @param  string $code Sellmo product_code meta value.
	 * @return int          WC post ID, or 0 if not found.
	 */
	private function find_product_id_by_code( string $code ): int {
		$cache_key = 'pid_' . md5( $code );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$query = new WP_Query( [
			'post_type'      => [ 'product', 'product_variation' ],
			'post_status'    => [ 'publish', 'private' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => '_sellmo_product_code',
					'value' => $code,
				],
			],
		] );

		$id = ! empty( $query->posts ) ? (int) $query->posts[0] : 0;

		// Backward compatibility: allow existing Woo products linked by SKU only.
		if ( 0 === $id ) {
			$sku_id = (int) wc_get_product_id_by_sku( $code );

			if ( $sku_id > 0 ) {
				$post_type = get_post_type( $sku_id );
				if ( in_array( $post_type, [ 'product', 'product_variation' ], true ) ) {
					$id = $sku_id;
					update_post_meta( $id, '_sellmo_product_code', $code );
				}
			}
		}

		wp_cache_set( $cache_key, $id, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $id;
	}

	/**
	 * Check whether sync is enabled for a given product or variation.
	 *
	 * For variations, falls back to the parent product's _sellmo_sync_enabled
	 * if the meta is not set directly on the variation.
	 *
	 * @since  1.0.0
	 * @param  int $product_id WC product or variation post ID.
	 * @return bool
	 */
	private function is_sync_enabled( int $product_id ): bool {
		$meta = (string) get_post_meta( $product_id, '_sellmo_sync_enabled', true );

		if ( '' !== $meta ) {
			return 'yes' === $meta;
		}

		// For a variation, fall back to the parent product's toggle.
		if ( 'product_variation' === get_post_type( $product_id ) ) {
			$parent_id = (int) wp_get_post_parent_id( $product_id );
			if ( $parent_id > 0 ) {
				return 'yes' === (string) get_post_meta( $parent_id, '_sellmo_sync_enabled', true );
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// API response parsing helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract the items array from an inventory API response.
	 *
	 * Handles both `{ "data": [...] }` and `{ "inventory": [...] }` shapes.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $response Decoded API response.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_items( array $response ): array {
		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			return $response['data'];
		}
		if ( isset( $response['inventory'] ) && is_array( $response['inventory'] ) ) {
			return $response['inventory'];
		}
		return [];
	}

	/**
	 * Extract the stock quantity from an inventory item.
	 *
	 * Returns null if no recognisable quantity field is present. Clamps to 0
	 * minimum so negative API values are never written to WooCommerce.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $item Single inventory item from the API.
	 * @return int|null
	 */
	private function extract_quantity( array $item ): ?int {
		foreach ( [ 'quantity', 'available_quantity', 'stock_quantity', 'stock' ] as $field ) {
			if ( isset( $item[ $field ] ) ) {
				return max( 0, (int) $item[ $field ] );
			}
		}
		return null;
	}

	/**
	 * Extract the pagination cursor from an API response.
	 *
	 * Returns an empty string when there are no further pages.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $response Decoded API response.
	 * @return string
	 */
	private function extract_next_cursor( array $response ): string {
		foreach ( [ 'cursor', 'next_cursor', 'next_page_cursor' ] as $key ) {
			if ( ! empty( $response[ $key ] ) && is_string( $response[ $key ] ) ) {
				return $response[ $key ];
			}
		}

		// Some APIs nest cursor under a 'meta' key.
		if ( ! empty( $response['meta']['cursor'] ) && is_string( $response['meta']['cursor'] ) ) {
			return $response['meta']['cursor'];
		}

		return '';
	}
}
