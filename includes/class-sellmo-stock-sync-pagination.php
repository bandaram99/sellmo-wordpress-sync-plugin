<?php
declare(strict_types=1);

/**
 * Stock sync pagination handler for Sellmo POS Integration.
 *
 * Pulls inventory levels from the Sellmo API and applies them to WooCommerce
 * products and variations based on matching meta keys.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Stock_Sync_Pagination
 *
 * @since 1.0.0
 */
class Sellmo_Stock_Sync_Pagination {

	/**
	 * Maximum pages fetched per full sync run to prevent infinite loops.
	 */
	private const MAX_PAGES = 100;

	/**
	 * Run the stock synchronization process.
	 *
	 * Loops through the Sellmo /inventory API endpoint, maps the items to
	 * local WooCommerce products, and updates their stock quantities. After the
	 * stock pass it also pushes each mapped product's catalogue image (image_url
	 * from /products) onto its WooCommerce product.
	 *
	 * @since  1.0.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	public function run(): array {
		$api     = new Sellmo_API();
		$cursor  = '';
		$page    = 0;
		$synced  = 0;
		$skipped = 0;
		$errors  = 0;

		// Only update stock for the shop configured in the plugin settings.
		$shop_id = (string) Sellmo_Settings::get( 'shop_id' );

		if ( '' === $shop_id ) {
			return [
				'success' => false,
				'count'   => 0,
				'message' => __( 'Sync failed: No shop ID configured in settings.', 'sellmo-woocommerce' ),
			];
		}

		Sellmo_Logger::log( 'stock', 'sellmo_to_wc', 'skipped', 'Manual stock sync started.' );

		// Step 1 — build the Sellmo product/variant lookup map from /products so
		// each inventory row can be attributed to the corresponding product or
		// (for variants) its parent product when writing the stock snapshot.
		$lookup = $this->collect_product_lookup_map();

		do {
			$page++;
			
			// Fetch the paginated inventory data
			$response = $api->get_inventory( $cursor );

			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();
				Sellmo_Logger::log( 'stock', 'sellmo_to_wc', 'error', 'API Error: ' . $error_message );

				return [
					'success' => false,
					'count'   => $synced,
					'message' => $error_message,
				];
			}

			$inventory_levels = $response['inventory_levels'] ?? $response['data'] ?? [];

			// If no data is returned on this page, exit the loop.
			if ( empty( $inventory_levels ) ) {
				break;
			}

			foreach ( $inventory_levels as $level ) {
				// Only process stock belonging to the currently selected shop.
				if ( isset( $level['shop_id'] ) && (string) $level['shop_id'] !== $shop_id ) {
					continue;
				}

				$product_code = (string) ( $level['product_code'] ?? '' );
				$sellmo_id    = (string) ( $level['product_id'] ?? '' );
				$variant_id   = (string) ( $level['variant_id'] ?? '' );
				$in_stock     = (int) ( $level['in_stock'] ?? 0 );

				// Validate that we have at least one identifier.
				if ( '' === $product_code && '' === $sellmo_id && '' === $variant_id ) {
					$skipped++;
					continue;
				}

				// Resolve this row to its product/variant via the map, then persist
				// the stock snapshot into the mapper/mapping tables.
				$this->store_stock_snapshot( $lookup, $sellmo_id, $variant_id, $product_code, $in_stock );

				// Look up the WooCommerce product or variation ID.
				$wc_product_id = $this->find_local_product_id( $product_code, $sellmo_id, $variant_id );

				if ( $wc_product_id > 0 ) {
					$product = wc_get_product( $wc_product_id );

					if ( $product ) {
						try {
							$product->set_manage_stock( true );
							$product->set_stock_quantity( $in_stock );
							$product->set_stock_status( $in_stock > 0 ? 'instock' : 'outofstock' );
							$product->save();
							
							$synced++;
						} catch ( Exception $e ) {
							$errors++;
							Sellmo_Logger::log(
								'stock',
								'sellmo_to_wc',
								'error',
								sprintf( 'Failed to save stock for WC Product #%d: %s', $wc_product_id, $e->getMessage() ),
								$product_code ?: $sellmo_id
							);
						}
					} else {
						$skipped++;
					}
				} else {
					$skipped++;
				}
			}

			// Extract the cursor for the next API request.
			$cursor = $this->extract_cursor( $response );

		} while ( '' !== $cursor && $page < self::MAX_PAGES );

		// Push the Sellmo catalogue image onto each mapped WooCommerce product.
		// Mapped products are handled here (not in the stock loop) so the manual
		// "Sync Stock Now" action also keeps their featured images up to date.
		$images_synced = $this->sync_mapped_product_images( $lookup );

		// Determine the final status to log.
		$status = ( $errors > 0 && 0 === $synced ) ? 'error' : 'success';

		Sellmo_Logger::log(
			'stock',
			'sellmo_to_wc',
			$status,
			sprintf(
				'Stock sync completed. %d items synced, %d skipped, %d errors, %d mapped images updated.',
				$synced,
				$skipped,
				$errors,
				$images_synced
			)
		);

		return [
			'success' => 'success' === $status,
			'count'   => $synced,
			'message' => ''
		];
	}

	/**
	 * Build the Sellmo product/variant lookup map from the /products endpoint.
	 *
	 * Indexes every product by id and code, and every variant by its variant id
	 * (so an inventory row keyed by a variant id can be traced to its parent
	 * product). Capped at 20 pages and degrades to an empty map on API failure —
	 * the stock sync still runs, it just loses variant-to-parent attribution.
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
				Sellmo_Logger::log(
					'stock',
					'sellmo_to_wc',
					'error',
					'Could not build product map for stock attribution: ' . $response->get_error_message()
				);
				break;
			}

			$products = $response['products'] ?? $response['data'] ?? [];
			if ( empty( $products ) ) {
				break;
			}

			foreach ( $products as $product ) {
				if ( ! is_array( $product ) ) {
					continue;
				}

				$product_id   = (string) ( $product['id'] ?? '' );
				$product_code = (string) ( $product['product_code'] ?? '' );
				$entry        = [
					'product_id'   => $product_id,
					'product_code' => $product_code,
					'image_url'    => (string) ( $product['image_url'] ?? $product['product_image_url'] ?? '' ),
				];

				if ( '' !== $product_id ) {
					$lookup['products_by_id'][ $product_id ] = $entry;
				}

				if ( '' !== $product_code ) {
					$lookup['products_by_code'][ $product_code ] = $entry;
				}

				$variants = isset( $product['variants'] ) && is_array( $product['variants'] ) ? $product['variants'] : [];
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

			$cursor = $this->extract_cursor( $response );

		} while ( '' !== $cursor && $page < $max_pages );

		return $lookup;
	}

	/**
	 * Push the Sellmo catalogue image onto every mapped WooCommerce product.
	 *
	 * For each mapped row, the product's image_url is resolved from the /products
	 * lookup map (by Sellmo product id, then by code) and sideloaded as the
	 * WooCommerce product's featured image. An existing featured image is
	 * preserved so a merchant's manually chosen image is never overwritten.
	 *
	 * @since  1.0.0
	 * @param  array{ products_by_id: array<string, array<string, mixed>>, products_by_code: array<string, array<string, mixed>> } $lookup Product lookup map.
	 * @return int Number of mapped products whose image was set.
	 */
	private function sync_mapped_product_images( array $lookup ): int {
		$mappings = Sellmo_Product_Mapping::get_all_mappings_indexed();
		$rows     = $mappings['by_id'] ?? [];

		if ( empty( $rows ) ) {
			return 0;
		}

		$product_sync = new Sellmo_Product_Sync();
		$updated      = 0;

		foreach ( $rows as $row ) {
			$wc_product_id = (int) ( $row['wc_product_id'] ?? 0 );
			if ( $wc_product_id <= 0 ) {
				continue;
			}

			$sellmo_id   = (string) ( $row['sellmo_product_id'] ?? '' );
			$sellmo_code = (string) ( $row['sellmo_product_code'] ?? '' );

			$image_url = '';
			if ( '' !== $sellmo_id && isset( $lookup['products_by_id'][ $sellmo_id ]['image_url'] ) ) {
				$image_url = (string) $lookup['products_by_id'][ $sellmo_id ]['image_url'];
			} elseif ( '' !== $sellmo_code && isset( $lookup['products_by_code'][ $sellmo_code ]['image_url'] ) ) {
				$image_url = (string) $lookup['products_by_code'][ $sellmo_code ]['image_url'];
			}

			if ( '' === $image_url ) {
				continue;
			}

			if ( $product_sync->set_product_image_from_url( $wc_product_id, $image_url ) ) {
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Persist a Sellmo stock snapshot into the mapper and mapping tables.
	 *
	 * When the inventory row's product_id is actually a variant id (per the
	 * lookup map), the stock is attributed to the parent product id as well, so
	 * the master row in the staging table is updated alongside any variant row.
	 * stock_status is derived inside update_stock(): 1 when in_stock > 0, else 0.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $lookup       Product/variant lookup map.
	 * @param  string               $sellmo_id    Inventory product_id (may be a variant id).
	 * @param  string               $variant_id   Variant id from the row, when present.
	 * @param  string               $product_code Inventory product_code.
	 * @param  int                  $in_stock     Stock count from /inventory.
	 * @return void
	 */
	private function store_stock_snapshot( array $lookup, string $sellmo_id, string $variant_id, string $product_code, int $in_stock ): void {
		$match_product_id = $sellmo_id;
		$match_variant_id = $variant_id;
		$match_code       = $product_code;

		// Inventory rows carry the variant's own id in product_id and have no
		// separate variant_id, so trace it back to the parent product here.
		if ( '' !== $sellmo_id && isset( $lookup['variants_by_id'][ $sellmo_id ] ) ) {
			$variant          = $lookup['variants_by_id'][ $sellmo_id ];
			$match_product_id = (string) ( $variant['parent_product_id'] ?? '' );
			$match_variant_id = $sellmo_id;

			if ( '' === $match_code ) {
				$match_code = (string) ( $variant['product_code'] ?? '' );
			}
		}

		Sellmo_Product_Mapping::update_stock( $match_product_id, $match_variant_id, $match_code, $in_stock );
	}

	/**
	 * Locate a WooCommerce Product or Variation ID using Sellmo identifiers.
	 *
	 * Uses an order of precedence: Variant ID -> Product Code -> Master Product ID.
	 *
	 * @since  1.0.0
	 * @param  string $product_code Sellmo product code (SKU equivalent).
	 * @param  string $sellmo_id    Sellmo master product ID.
	 * @param  string $variant_id   Sellmo variant ID.
	 * @return int                  The WooCommerce Post ID, or 0 if not found.
	 */
	private function find_local_product_id( string $product_code, string $sellmo_id, string $variant_id ): int {
		
		// 1. Variant ID: Most specific match for variations.
		if ( '' !== $variant_id ) {
			$query = new WP_Query( [
				'post_type'      => 'product_variation',
				'post_status'    => [ 'publish', 'private' ],
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'   => '_salesplay_variant_id',
						'value' => $variant_id,
					]
				]
			] );

			if ( ! empty( $query->posts ) ) {
				return (int) $query->posts[0];
			}
		}

		// 2. Product Code: Matches both simple products and variations.
		if ( '' !== $product_code ) {
			$query = new WP_Query( [
				'post_type'      => [ 'product', 'product_variation' ],
				'post_status'    => [ 'publish', 'private' ],
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'   => '_sellmo_product_code',
						'value' => $product_code,
					]
				]
			] );

			if ( ! empty( $query->posts ) ) {
				return (int) $query->posts[0];
			}
		}

		// 3. Master Product ID: Fallback for simple products.
		if ( '' !== $sellmo_id ) {
			$query = new WP_Query( [
				'post_type'      => 'product',
				'post_status'    => [ 'publish', 'private' ],
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'   => '_salesplay_pos_product_id',
						'value' => $sellmo_id,
					]
				]
			] );

			if ( ! empty( $query->posts ) ) {
				return (int) $query->posts[0];
			}
		}

		return 0; // Not found
	}

	/**
	 * Extract the pagination cursor from the Sellmo API response.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $response Decoded API response.
	 * @return string                         Next cursor string, or empty if done.
	 */
	private function extract_cursor( array $response ): string {
		foreach ( [ 'cursor', 'next_cursor', 'next_page_cursor' ] as $key ) {
			if ( ! empty( $response[ $key ] ) && is_string( $response[ $key ] ) ) {
				return $response[ $key ];
			}
		}

		if ( ! empty( $response['meta']['cursor'] ) && is_string( $response['meta']['cursor'] ) ) {
			return $response['meta']['cursor'];
		}

		return '';
	}
}