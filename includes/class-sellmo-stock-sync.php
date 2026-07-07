<?php
declare(strict_types=1);

/**
 * Stock sync handler for Sellmo POS Integration.
 *
 * Pulls product payloads from the Sellmo API and writes them to WooCommerce
 * product stock. Supports simple products, variations, and paginated responses.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Stock_Sync
 *
 * Syncs stock levels from Sellmo product payloads to WooCommerce stock.
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
	 * Populate WooCommerce stock for a single WC product (and, if variable, all
	 * of its variations) from the Sellmo /inventory endpoint.
	 *
	 * Used right after importing a product so the new product/variations carry
	 * their current stock immediately (the /products endpoint has no stock data).
	 *
	 * @since  1.1.0
	 * @param  int $wc_product_id WooCommerce product ID.
	 * @return int                Number of products/variations whose stock was set.
	 */
	public function sync_stock_for_wc_product( int $wc_product_id ): int {
		$product = wc_get_product( $wc_product_id );
		if ( ! $product ) {
			return 0;
		}

		// One pass over /inventory, keyed by Sellmo id and by product code.
		$api     = new Sellmo_API();
		$by_id   = [];
		$by_code = [];
		$cursor  = '';
		$page    = 0;

		do {
			$page++;
			$response = $api->get_inventory( $cursor );
			if ( is_wp_error( $response ) ) {
				break;
			}

			$levels = $response['inventory_levels'] ?? $response['data'] ?? [];
			if ( empty( $levels ) ) {
				break;
			}

			foreach ( $levels as $level ) {
				if ( ! is_array( $level ) || ! isset( $level['in_stock'] ) || ! is_numeric( $level['in_stock'] ) ) {
					continue;
				}
				$stock = (int) $level['in_stock'];
				$pid   = (string) ( $level['product_id'] ?? '' );
				$code  = (string) ( $level['product_code'] ?? '' );
				if ( '' !== $pid ) {
					$by_id[ $pid ] = $stock;
				}
				if ( '' !== $code ) {
					$by_code[ $code ] = $stock;
				}
			}

			$cursor = $this->extract_next_cursor( $response );
		} while ( '' !== $cursor && $page < 60 );

		// Variations hold stock for variable products; the product itself otherwise.
		$targets = $product->is_type( 'variable' ) ? $product->get_children() : [ $wc_product_id ];
		$updated = 0;

		foreach ( $targets as $target_id ) {
			$target_id = (int) $target_id;

			$variant_id = (string) get_post_meta( $target_id, '_salesplay_variant_id', true );
			$pos_id     = (string) get_post_meta( $target_id, '_salesplay_pos_product_id', true );
			$code       = (string) get_post_meta( $target_id, '_pos_product_code', true );

			$stock = null;
			if ( '' !== $variant_id && isset( $by_id[ $variant_id ] ) ) {
				$stock = $by_id[ $variant_id ];
			} elseif ( '' !== $pos_id && isset( $by_id[ $pos_id ] ) ) {
				$stock = $by_id[ $pos_id ];
			} elseif ( '' !== $code && isset( $by_code[ $code ] ) ) {
				$stock = $by_code[ $code ];
			}

			if ( null === $stock ) {
				continue;
			}

			$target_product = wc_get_product( $target_id );
			if ( $target_product && false !== wc_update_product_stock( $target_product, max( 0, $stock ), 'set' ) ) {
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Webhook-triggered single-product sync.
	 *
	 * Pages through the Sellmo products API until the item with the given
	 * product code or SalesPlay id is found, then updates the matching WC
	 * product/variation.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_code Sellmo product_code to look up.
	 * @return bool   True if the stock was updated successfully.
	 */
	public function sync_single_product( string $sellmo_product_code ): bool {
		if ( '' === $sellmo_product_code ) {
			return false;
		}

		try {
			$lookup           = $this->collect_product_lookup_map();
			$inventory_records = $this->collect_inventory_records();
		} catch ( \RuntimeException $e ) {
			Sellmo_Logger::log( 'stock', 'pos_to_wc', 'error', $e->getMessage(), $sellmo_product_code );
			return false;
		}

		foreach ( $inventory_records as $record ) {
			$target = $this->resolve_inventory_target( $record, $lookup );

			if ( null === $target ) {
				continue;
			}

			$matches_code   = $sellmo_product_code === (string) ( $target['product_code'] ?? '' );
			$matches_id     = $sellmo_product_code === (string) ( $target['product_id'] ?? '' );
			$matches_variant = $sellmo_product_code === (string) ( $target['variant_id'] ?? '' );

			if ( ! $matches_code && ! $matches_id && ! $matches_variant ) {
				continue;
			}

			$mapping = $this->get_mapping_for_stock_item( $target );
			if ( is_array( $mapping ) ) {
				Sellmo_Logger::log(
					'stock',
					'pos_to_wc',
					'skipped',
					sprintf(
						'Product skipped because mapped to WooCommerce product #%d',
						absint( $mapping['wc_product_id'] ?? 0 )
					),
					(string) ( $target['product_code'] ?? $sellmo_product_code )
				);
				return true;
			}

			return 'success' === $this->update_stock_for_item( $target );
		}

		Sellmo_Logger::log(
			'stock',
			'pos_to_wc',
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
		$synced    = 0;
		$skipped   = 0;
		$errors    = 0;

		try {
			$lookup           = $this->collect_product_lookup_map();
			$inventory_records = $this->collect_inventory_records();
		} catch ( \RuntimeException $e ) {
			$message = $e->getMessage();

			Sellmo_Logger::log( 'stock', 'pos_to_wc', 'error', $message );

			Sellmo_Settings::set( 'last_stock_sync', [
				'time'   => time(),
				'status' => 'error',
				'count'  => 0,
			] );

			return [ 'success' => false, 'count' => 0, 'message' => $message ];
		}

		foreach ( $inventory_records as $record ) {
			$target = $this->resolve_inventory_target( $record, $lookup );

			// Persist the Sellmo stock snapshot into the mapper/mapping tables,
			// attributing variant stock to its parent product via the lookup map.
			$this->store_stock_snapshot( $record, $target );

			if ( null === $target ) {
				// Inventory row not represented in /products IDs; ignore silently.
				continue;
			}

			$mapping = $this->get_mapping_for_stock_item( $target );
			if ( is_array( $mapping ) ) {
				$skipped++;
				Sellmo_Logger::log(
					'stock',
					'pos_to_wc',
					'skipped',
					sprintf(
						'Product skipped because mapped to WooCommerce product #%d',
						absint( $mapping['wc_product_id'] ?? 0 )
					),
					(string) ( $target['product_code'] ?? '' )
				);
				continue;
			}

			$outcome = $this->update_stock_for_item( $target );

			match ( $outcome ) {
				'success' => $synced++,
				'skipped' => $skipped++,
				default   => $errors++,
			};
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
			'pos_to_wc',
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
	 * Persist a Sellmo stock snapshot into the mapper and mapping tables.
	 *
	 * Prefers the map-resolved target (so a variant's stock is attributed to its
	 * parent product id, which is what the staging table is keyed by) and falls
	 * back to the raw inventory record when the row could not be resolved.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed>      $record Raw inventory record.
	 * @param  array<string, mixed>|null $target Resolved target, or null.
	 * @return void
	 */
	private function store_stock_snapshot( array $record, ?array $target ): void {
		if ( is_array( $target ) ) {
			$product_id   = (string) ( $target['product_id'] ?? '' );
			$variant_id   = (string) ( $target['variant_id'] ?? '' );
			$product_code = (string) ( $target['product_code'] ?? '' );
			$in_stock     = $this->extract_item_stock( $target );
		} else {
			$product_id   = (string) ( $record['product_id'] ?? '' );
			$variant_id   = (string) ( $record['variant_id'] ?? '' );
			$product_code = (string) ( $record['product_code'] ?? '' );
			$in_stock     = $this->extract_item_stock( $record );
		}

		if ( null === $in_stock ) {
			return;
		}

		Sellmo_Product_Mapping::update_stock( $product_id, $variant_id, $product_code, $in_stock );
	}

	/**
	 * Update WooCommerce stock for a flattened Sellmo stock item.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $item Flattened Sellmo stock item.
	 * @return string
	 */
	private function update_stock_for_item( array $item ): string {
		$quantity = $this->extract_item_stock( $item );
		if ( null === $quantity ) {
			return 'skipped';
		}

		$product_id = $this->find_product_id_by_item( $item );
		$lookup_key = $this->resolve_sellmo_item_key( $item );
		$log_item_id = (string) ( $item['variant_id'] ?? '' );
		if ( '' === $log_item_id ) {
			$log_item_id = (string) ( $item['product_id'] ?? $product_id );
		}

		if ( 0 === $product_id ) {
			Sellmo_Logger::log(
				'stock',
				'pos_to_wc',
				'skipped',
				sprintf(
					'No WooCommerce product found for Sellmo item %s. product_id=%s variant_id=%s product_code=%s in_stock=%s',
					$lookup_key,
					(string) ( $item['product_id'] ?? '' ),
					(string) ( $item['variant_id'] ?? '' ),
					(string) ( $item['product_code'] ?? '' ),
					isset( $item['in_stock'] ) ? (string) $item['in_stock'] : ''
				),
				$log_item_id
			);
			return 'skipped';
		}

		if ( ! $this->is_sync_enabled( $product_id ) ) {
			Sellmo_Logger::log(
				'stock',
				'pos_to_wc',
				'skipped',
				sprintf(
					'Sync disabled for product #%d. product_id=%s variant_id=%s product_code=%s in_stock=%s',
					$product_id,
					(string) ( $item['product_id'] ?? '' ),
					(string) ( $item['variant_id'] ?? '' ),
					(string) ( $item['product_code'] ?? '' ),
					isset( $item['in_stock'] ) ? (string) $item['in_stock'] : ''
				),
				$log_item_id
			);
			return 'skipped';
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			Sellmo_Logger::log(
				'stock',
				'pos_to_wc',
				'error',
				sprintf(
					'Could not load WC product #%d for Sellmo item %s. product_id=%s variant_id=%s product_code=%s in_stock=%s',
					$product_id,
					$lookup_key,
					(string) ( $item['product_id'] ?? '' ),
					(string) ( $item['variant_id'] ?? '' ),
					(string) ( $item['product_code'] ?? '' ),
					isset( $item['in_stock'] ) ? (string) $item['in_stock'] : ''
				),
				$log_item_id
			);
			return 'error';
		}

		$result = wc_update_product_stock( $product, max( 0, $quantity ), 'set' );

		if ( false === $result ) {
			Sellmo_Logger::log(
				'stock',
				'pos_to_wc',
				'error',
				sprintf(
					'Failed to update stock to in_stock=%d for product #%d. product_id=%s variant_id=%s product_code=%s',
					max( 0, $quantity ),
					$product_id,
					(string) ( $item['product_id'] ?? '' ),
					(string) ( $item['variant_id'] ?? '' ),
					(string) ( $item['product_code'] ?? '' )
				),
				$log_item_id
			);
			return 'error';
		}

		Sellmo_Logger::log(
			'stock',
			'pos_to_wc',
			'success',
			sprintf(
				'Stock set to in_stock=%d for product #%d. product_id=%s variant_id=%s product_code=%s',
				max( 0, $quantity ),
				$product_id,
				(string) ( $item['product_id'] ?? '' ),
				(string) ( $item['variant_id'] ?? '' ),
				(string) ( $item['product_code'] ?? '' )
			),
			$log_item_id
		);

		return 'success';
	}

	/**
	 * Build an in-memory lookup map from the /products endpoint.
	 *
	 * @since  1.0.0
	 * @return array{ products_by_id: array<string, array<string, mixed>>, variants_by_id: array<string, array<string, mixed>>, products_by_code: array<string, array<string, mixed>> }
	 */
	private function collect_product_lookup_map(): array {
		$api       = new Sellmo_API();
		$cursor    = '';
		$page      = 0;
		$max_pages = 20;
		$lookup    = [
			'products_by_id'   => [],
			'variants_by_id'   => [],
			'products_by_code' => [],
		];

		do {
			$page++;
			$response = $api->get_products( $cursor );

			if ( is_wp_error( $response ) ) {
				throw new \RuntimeException( $response->get_error_message() );
			}

			$products = $this->extract_products( $response );
			if ( empty( $products ) ) {
				break;
			}

			foreach ( $products as $product ) {
				if ( ! is_array( $product ) ) {
					continue;
				}

				$this->index_product_lookup_entry( $lookup, $product );
			}

			$cursor = $this->extract_next_cursor( $response );
			if ( $page >= $max_pages ) {
				break;
			}
		} while ( '' !== $cursor );

		return $lookup;
	}

	/**
	 * Index a single product and its variants into the lookup map.
	 *
	 * @since  1.0.0
	 * @param  array{ products_by_id: array<string, array<string, mixed>>, variants_by_id: array<string, array<string, mixed>>, products_by_code: array<string, array<string, mixed>> } $lookup Lookup map.
	 * @param  array<string, mixed> $product Product payload.
	 * @return void
	 */
	private function index_product_lookup_entry( array &$lookup, array $product ): void {
		$product_id   = (string) ( $product['id'] ?? '' );
		$product_code = (string) ( $product['product_code'] ?? '' );

		$product_entry = [
			'product_id'   => $product_id,
			'product_code' => $product_code,
			'variants'     => isset( $product['variants'] ) && is_array( $product['variants'] ) ? $product['variants'] : [],
		];

		if ( '' !== $product_id ) {
			$lookup['products_by_id'][ $product_id ] = $product_entry;
		}

		if ( '' !== $product_code ) {
			$lookup['products_by_code'][ $product_code ] = $product_entry;
		}

		$variants = $product_entry['variants'];
		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			$variant_id = (string) ( $variant['variant_id'] ?? $variant['id'] ?? '' );
			if ( '' === $variant_id ) {
				continue;
			}

			$lookup['variants_by_id'][ $variant_id ] = [
				'variant_id'          => $variant_id,
				'parent_product_id'   => $product_id,
				'parent_product_code' => $product_code,
				'product_code'        => (string) ( $variant['product_code'] ?? '' ),
			];
		}
	}

	/**
	 * Collect all inventory rows from the /inventory endpoint.
	 *
	 * @since  1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_inventory_records(): array {
		$api       = new Sellmo_API();
		$cursor    = '';
		$page      = 0;
		$max_pages = 20;
		$records   = [];

		do {
			$page++;
			$response = $api->get_inventory( $cursor );

			if ( is_wp_error( $response ) ) {
				throw new \RuntimeException( $response->get_error_message() );
			}

			$inventory_levels = $this->extract_inventory_levels( $response );
			if ( empty( $inventory_levels ) ) {
				break;
			}

			foreach ( $inventory_levels as $record ) {
				if ( is_array( $record ) ) {
					$records[] = $record;
				}
			}

			$cursor = $this->extract_next_cursor( $response );
			if ( $page >= $max_pages ) {
				break;
			}
		} while ( '' !== $cursor );

		return $records;
	}

	/**
	 * Extract inventory rows from an inventory API response.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $response Response payload.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_inventory_levels( array $response ): array {
		if ( isset( $response['inventory_levels'] ) && is_array( $response['inventory_levels'] ) ) {
			return $response['inventory_levels'];
		}

		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			return $response['data'];
		}

		return [];
	}

	/**
	 * Resolve a raw inventory record to a normalized target.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $record Raw inventory record.
	 * @param  array{ products_by_id: array<string, array<string, mixed>>, variants_by_id: array<string, array<string, mixed>>, products_by_code: array<string, array<string, mixed>> } $lookup Lookup map.
	 * @return array<string, mixed>|null
	 */
	private function resolve_inventory_target( array $record, array $lookup ): ?array {
		$inventory_product_id   = (string) ( $record['product_id'] ?? '' );
		$inventory_product_code = (string) ( $record['product_code'] ?? '' );
		$in_stock               = $this->extract_item_stock( $record );

		if ( null === $in_stock ) {
			return null;
		}

		if ( '' !== $inventory_product_id && isset( $lookup['variants_by_id'][ $inventory_product_id ] ) ) {
			$variant = $lookup['variants_by_id'][ $inventory_product_id ];

			return [
				'type'         => 'variant',
				'product_id'   => (string) ( $variant['parent_product_id'] ?? '' ),
				'variant_id'   => $inventory_product_id,
				'product_code' => $inventory_product_code !== '' ? $inventory_product_code : (string) ( $variant['product_code'] ?? '' ),
				'in_stock'     => $in_stock,
				'record'       => $record,
			];
		}

		if ( '' !== $inventory_product_id && isset( $lookup['products_by_id'][ $inventory_product_id ] ) ) {
			$product = $lookup['products_by_id'][ $inventory_product_id ];

			return [
				'type'         => 'product',
				'product_id'   => $inventory_product_id,
				'variant_id'   => '',
				'product_code' => $inventory_product_code !== '' ? $inventory_product_code : (string) ( $product['product_code'] ?? '' ),
				'in_stock'     => $in_stock,
				'record'       => $record,
			];
		}

		return null;
	}

	/**
	 * Extract top-level products from a /products response.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $response API response.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_products( array $response ): array {
		if ( isset( $response['products'] ) && is_array( $response['products'] ) ) {
			return $response['products'];
		}

		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			return $response['data'];
		}

		return [];
	}

	/**
	 * Extract a stock count from a product or variant payload.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $item Source payload.
	 * @return int|null
	 */
	private function extract_item_stock( array $item ): ?int {
		if ( isset( $item['in_stock'] ) && is_numeric( $item['in_stock'] ) ) {
			return (int) $item['in_stock'];
		}

		return null;
	}

	/**
	 * Build a stable key for a flattened Sellmo item.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $item Flattened stock item.
	 * @return string
	 */
	private function resolve_sellmo_item_key( array $item ): string {
		$variant_id = (string) ( $item['variant_id'] ?? '' );
		if ( '' !== $variant_id ) {
			return $variant_id;
		}

		$product_code = (string) ( $item['product_code'] ?? '' );
		if ( '' !== $product_code ) {
			return $product_code;
		}

		return (string) ( $item['product_id'] ?? '' );
	}

	/**
	 * Resolve mapping for a normalized stock item.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $item Normalized stock item.
	 * @return array<string, mixed>|null
	 */
	private function get_mapping_for_stock_item( array $item ): ?array {
		$variant_id = (string) ( $item['variant_id'] ?? '' );
		if ( '' !== $variant_id ) {
			$mapping = Sellmo_Product_Mapping::get_mapping_by_sellmo_id( $variant_id );
			if ( null !== $mapping ) {
				return $mapping;
			}
		}

		$product_id = (string) ( $item['product_id'] ?? '' );
		if ( '' !== $product_id ) {
			$mapping = Sellmo_Product_Mapping::get_mapping_by_sellmo_id( $product_id );
			if ( null !== $mapping ) {
				return $mapping;
			}
		}

		$product_code = (string) ( $item['product_code'] ?? '' );
		if ( '' !== $product_code ) {
			return Sellmo_Product_Mapping::get_mapping_by_sellmo_code( $product_code );
		}

		return null;
	}

	/**
	 * Resolve a WooCommerce product or variation for a flattened Sellmo item.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $item Flattened stock item.
	 * @return int
	 */
	private function find_product_id_by_item( array $item ): int {
		$product_id = (string) ( $item['product_id'] ?? '' );
		$variant_id = (string) ( $item['variant_id'] ?? '' );
		$code       = (string) ( $item['product_code'] ?? '' );

		if ( '' !== $variant_id ) {
			$variation_id = $this->find_variation_id_by_salesplay_variant_id( $variant_id );
			if ( $variation_id > 0 ) {
				return $variation_id;
			}
		}

		if ( '' !== $product_id ) {
			$parent_id = $this->find_product_id_by_salesplay_product_id( $product_id );
			if ( $parent_id > 0 ) {
				return $parent_id;
			}
		}

		if ( '' !== $code ) {
			return $this->find_product_id_by_code( $code );
		}

		return 0;
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
					'key'   => '_pos_product_code',
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
					update_post_meta( $id, '_pos_product_code', $code );
				}
			}
		}

		wp_cache_set( $cache_key, $id, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $id;
	}

	/**
	 * Find a WC product ID by SalesPlay product id.
	 *
	 * @since  1.0.0
	 * @param  string $salesplay_product_id SalesPlay product id.
	 * @return int
	 */
	private function find_product_id_by_salesplay_product_id( string $salesplay_product_id ): int {
		if ( '' === $salesplay_product_id ) {
			return 0;
		}

		$query = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'private' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => '_salesplay_pos_product_id',
					'value' => $salesplay_product_id,
				],
			],
		] );

		return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find a WC variation ID by SalesPlay variant id.
	 *
	 * @since  1.0.0
	 * @param  string $variant_id SalesPlay variant id.
	 * @return int
	 */
	private function find_variation_id_by_salesplay_variant_id( string $variant_id ): int {
		if ( '' === $variant_id ) {
			return 0;
		}

		$query = new WP_Query( [
			'post_type'      => 'product_variation',
			'post_status'    => [ 'publish', 'private' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => '_salesplay_variant_id',
					'value' => $variant_id,
				],
			],
		] );

		return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
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
