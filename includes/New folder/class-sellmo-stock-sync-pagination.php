<?php
declare(strict_types=1);

/**
 * Stock synchronisation with cursor pagination and WooCommerce CRUD updates.
 *
 * Implements the four-step stock sync exactly as specified:
 *
 *   Step 1  Fetch product IDs (and variant_id for each variant) from /products.
 *   Step 2  Page the /inventory endpoint with a do-while cursor loop into one
 *           local PHP array (the API caps page size, so this is mandatory).
 *   Step 3  Match each product/variant id against the inventory array.
 *   Step 4  Update WooCommerce via CRUD objects:
 *             in_stock  > 0  -> set quantity, status 'instock'
 *             in_stock <= 0  -> set quantity 0, status 'outofstock'
 *           Variations are updated at the variation level; simple products at
 *           the product level.
 *
 * @package Sellmo_WooCommerce
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Stock_Sync_Pagination
 *
 * @since 1.1.0
 */
class Sellmo_Stock_Sync_Pagination {

	private const PRODUCTS_ENDPOINT  = 'https://api.salesplaypos.com/v1.0/products';
	private const INVENTORY_ENDPOINT = 'https://api.salesplaypos.com/v1.0/inventory';
	private const PAGE_LIMIT         = '100';
	private const MAX_PAGES          = 200;

	/**
	 * Public entry point for "Sync Stock Now". Guarded against fatals.
	 *
	 * @since  1.1.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	public function run(): array {
		set_time_limit( 300 );

		return Sellmo_Sync_Guard::run(
			fn(): array => $this->do_stock_sync(),
			'stock',
			'sellmo_to_wc',
			'Stock sync'
		);
	}

	/**
	 * Orchestrate the four steps.
	 *
	 * @since  1.1.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	private function do_stock_sync(): array {
		$token = trim( (string) Sellmo_Settings::get( 'api_token' ) );
		if ( '' === $token ) {
			return [ 'success' => false, 'count' => 0, 'message' => __( 'SalesPlay API token is not configured.', 'sellmo-woocommerce' ) ];
		}

		// ── Step 1: product + variant id list. ────────────────────────────────
		$id_map = $this->fetch_product_id_map( $token );
		if ( is_wp_error( $id_map ) ) {
			return [ 'success' => false, 'count' => 0, 'message' => $id_map->get_error_message() ];
		}

		// ── Step 2: page /inventory into one local array, keyed by product_id. ─
		$inventory = $this->fetch_all_inventory( $token );
		if ( is_wp_error( $inventory ) ) {
			return [ 'success' => false, 'count' => 0, 'message' => $inventory->get_error_message() ];
		}

		// ── Step 3 + 4: match each id/variant_id and update WooCommerce. ───────
		$updated = 0;
		$skipped = 0;
		$errors  = 0;

		foreach ( $id_map as $entry ) {
			$sellmo_key = (string) $entry['inventory_key']; // variant_id for variants, product id otherwise.

			if ( ! isset( $inventory[ $sellmo_key ] ) ) {
				$skipped++;
				continue;
			}

			$in_stock = (int) $inventory[ $sellmo_key ]['in_stock'];

			$outcome = $this->update_wc_stock( $entry, $in_stock );

			match ( $outcome ) {
				'success' => $updated++,
				'skipped' => $skipped++,
				default   => $errors++,
			};
		}

		// Only flag a hard error when nothing succeeded; partial errors stay 'success'.
		$status = ( $errors > 0 && 0 === $updated ) ? 'error' : 'success';

		Sellmo_Settings::set( 'last_stock_sync', [
			'time'   => time(),
			'status' => $status,
			'count'  => $updated,
		] );

		Sellmo_Logger::log(
			'stock',
			'sellmo_to_wc',
			$status,
			sprintf( '%d updated, %d skipped, %d errors.', $updated, $skipped, $errors )
		);

		return [
			'success' => 'success' === $status,
			'count'   => $updated,
			'message' => '',
		];
	}

	/**
	 * Step 1 — build the local product/variant id map from /products.
	 *
	 * Each entry records how to find the matching WooCommerce object and which
	 * Sellmo id to look up in the inventory array.
	 *
	 * @since  1.1.0
	 * @param  string $token Bearer token.
	 * @return array<int, array{ type: string, sellmo_product_id: string, variant_id: string, product_code: string, inventory_key: string }>|WP_Error
	 */
	private function fetch_product_id_map( string $token ): array|\WP_Error {
		$cursor = '';
		$page   = 0;
		$map    = [];

		do {
			$page++;
			$result = $this->request_page( self::PRODUCTS_ENDPOINT, '/products', $token, $cursor );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$products = $this->extract_list( $result['body'], [ 'products', 'data' ] );
			if ( empty( $products ) ) {
				break;
			}

			foreach ( $products as $product ) {
				if ( ! is_array( $product ) ) {
					continue;
				}

				$product_id = (string) ( $product['id'] ?? '' );
				$code       = (string) ( $product['product_code'] ?? '' );
				$variants   = isset( $product['variants'] ) && is_array( $product['variants'] ) ? $product['variants'] : [];

				if ( empty( $variants ) ) {
					// Simple product: inventory is keyed by the product id.
					$map[] = [
						'type'              => 'simple',
						'sellmo_product_id' => $product_id,
						'variant_id'        => '',
						'product_code'      => $code,
						'inventory_key'     => $product_id,
					];
					continue;
				}

				// Variable product: one entry per variant, keyed by variant_id.
				foreach ( $variants as $variant ) {
					if ( ! is_array( $variant ) ) {
						continue;
					}
					$variant_id = (string) ( $variant['variant_id'] ?? $variant['id'] ?? '' );
					if ( '' === $variant_id ) {
						continue;
					}
					$map[] = [
						'type'              => 'variant',
						'sellmo_product_id' => $product_id,
						'variant_id'        => $variant_id,
						'product_code'      => (string) ( $variant['product_code'] ?? '' ),
						'inventory_key'     => $variant_id,
					];
				}
			}

			$cursor = $this->extract_cursor( $result['body'] );
		} while ( '' !== $cursor && $page < self::MAX_PAGES );

		return $map;
	}

	/**
	 * Step 2 — page the /inventory endpoint with a do-while cursor loop.
	 *
	 * Collects every inventory row into a single associative array keyed by the
	 * Sellmo product_id so Step 3 lookups are O(1).
	 *
	 * @since  1.1.0
	 * @param  string $token Bearer token.
	 * @return array<string, array{ in_stock: int, product_code: string }>|WP_Error
	 */
	private function fetch_all_inventory( string $token ): array|\WP_Error {
		$cursor    = '';
		$page      = 0;
		$inventory = [];

		do {
			$page++;
			$result = $this->request_page( self::INVENTORY_ENDPOINT, '/inventory', $token, $cursor );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$rows = $this->extract_list( $result['body'], [ 'inventory_levels', 'inventory', 'data' ] );
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$key = (string) ( $row['product_id'] ?? '' );
				if ( '' === $key || ! isset( $row['in_stock'] ) || ! is_numeric( $row['in_stock'] ) ) {
					continue;
				}
				$inventory[ $key ] = [
					'in_stock'     => (int) $row['in_stock'],
					'product_code' => (string) ( $row['product_code'] ?? '' ),
				];
			}

			$cursor = $this->extract_cursor( $result['body'] );
		} while ( '' !== $cursor && $page < self::MAX_PAGES );

		return $inventory;
	}

	/**
	 * Step 4 — write stock to WooCommerce using CRUD objects.
	 *
	 * Variations are loaded as WC_Product_Variation and updated at the variation
	 * level; simple products at the product level. Using set_manage_stock(true) +
	 * set_stock_quantity() + set_stock_status() + save() ensures WooCommerce
	 * clears its own caches correctly (unlike raw update_post_meta()).
	 *
	 * @since  1.1.0
	 * @param  array{ type: string, sellmo_product_id: string, variant_id: string, product_code: string, inventory_key: string } $entry    Id-map entry.
	 * @param  int                                                                                                               $in_stock In-stock value from the inventory array.
	 * @return string 'success' | 'skipped' | 'error'
	 */
	private function update_wc_stock( array $entry, int $in_stock ): string {
		$product_id = $this->resolve_wc_id( $entry );
		if ( 0 === $product_id ) {
			return 'skipped';
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			Sellmo_Logger::log(
				'stock',
				'sellmo_to_wc',
				'error',
				sprintf( 'Could not load WooCommerce object #%d.', $product_id ),
				(string) $product_id
			);
			return 'error';
		}

		// Never write stock onto a variable parent — only its variations carry stock.
		if ( $product->is_type( 'variable' ) ) {
			return 'skipped';
		}

		$quantity = max( 0, $in_stock );

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $quantity );
		$product->set_stock_status( $in_stock > 0 ? 'instock' : 'outofstock' );
		$product->save();

		Sellmo_Logger::log(
			'stock',
			'sellmo_to_wc',
			'success',
			sprintf(
				'Stock set to %d (%s) for #%d. product_id=%s variant_id=%s code=%s',
				$quantity,
				$in_stock > 0 ? 'instock' : 'outofstock',
				$product_id,
				$entry['sellmo_product_id'],
				$entry['variant_id'],
				$entry['product_code']
			),
			(string) $product_id
		);

		return 'success';
	}

	/**
	 * Resolve a WooCommerce product/variation id from an id-map entry.
	 *
	 * Tries SalesPlay variant id meta, then SalesPlay product id meta, then code.
	 *
	 * @since  1.1.0
	 * @param  array<string, string> $entry Id-map entry.
	 * @return int
	 */
	private function resolve_wc_id( array $entry ): int {
		if ( '' !== $entry['variant_id'] ) {
			$id = $this->find_id_by_meta( '_salesplay_variant_id', $entry['variant_id'], 'product_variation' );
			if ( $id > 0 ) {
				return $id;
			}
		}

		if ( '' !== $entry['sellmo_product_id'] ) {
			$id = $this->find_id_by_meta( '_salesplay_pos_product_id', $entry['sellmo_product_id'], 'product' );
			if ( $id > 0 ) {
				return $id;
			}
		}

		if ( '' !== $entry['product_code'] ) {
			$id = $this->find_id_by_meta( '_sellmo_product_code', $entry['product_code'], [ 'product', 'product_variation' ] );
			if ( $id > 0 ) {
				return $id;
			}
			return (int) wc_get_product_id_by_sku( $entry['product_code'] );
		}

		return 0;
	}

	/**
	 * Find a post id by a single meta key/value within given post type(s).
	 *
	 * @since  1.1.0
	 * @param  string          $key       Meta key.
	 * @param  string          $value     Meta value.
	 * @param  string|string[] $post_type Post type or list.
	 * @return int
	 */
	private function find_id_by_meta( string $key, string $value, string|array $post_type ): int {
		$query = new WP_Query( [
			'post_type'      => $post_type,
			'post_status'    => [ 'publish', 'private', 'draft' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'key' => $key, 'value' => $value ],
			],
		] );

		return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Issue one GET request with a JSON body and return the decoded array.
	 *
	 * wp_remote_get() cannot send a body, so wp_remote_request() with
	 * method=GET + body is required by the SalesPlay API. Failures are logged
	 * with their HTTP code + response body by Sellmo_Sync_Guard.
	 *
	 * @since  1.1.0
	 * @param  string $url      Full endpoint URL.
	 * @param  string $endpoint Short endpoint path for logging.
	 * @param  string $token    Bearer token.
	 * @param  string $cursor   Pagination cursor.
	 * @return array{ body: array<string, mixed> }|WP_Error
	 */
	private function request_page( string $url, string $endpoint, string $token, string $cursor ): array|\WP_Error {
		$payload = [
			'product_ids' => '',
			'shop_ids'    => '',
			'limit'       => self::PAGE_LIMIT,
			'cursor'      => $cursor,
		];

		$response = wp_remote_request(
			$url,
			[
				'method'  => 'GET',
				'timeout' => 45,
				'headers' => [
					'Content-Type' => 'application/json',
					'token'        => 'Bearer ' . $token,
				],
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( ! Sellmo_Sync_Guard::log_response( $response, $endpoint, 'stock', 'sellmo_to_wc' ) ) {
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			return new \WP_Error(
				'sellmo_stock_http_error',
				sprintf( __( 'SalesPlay %s returned HTTP %d.', 'sellmo-woocommerce' ), $endpoint, $code ),
				[ 'status' => $code, 'endpoint' => $endpoint ]
			);
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'sellmo_stock_invalid_json',
				sprintf( __( 'Invalid JSON from SalesPlay %s.', 'sellmo-woocommerce' ), $endpoint ),
				[ 'endpoint' => $endpoint ]
			);
		}

		return [ 'body' => $decoded ];
	}

	/**
	 * Pull a list from a response, trying each candidate key in order.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $response Decoded response.
	 * @param  string[]             $keys     Candidate list keys.
	 * @return array<int, mixed>
	 */
	private function extract_list( array $response, array $keys ): array {
		foreach ( $keys as $key ) {
			if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
				return $response[ $key ];
			}
		}
		return [];
	}

	/**
	 * Pull the next pagination cursor from a response.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $response Decoded response.
	 * @return string Empty string when there are no more pages.
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
