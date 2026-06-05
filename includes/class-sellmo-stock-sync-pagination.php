<?php
declare(strict_types=1);

/**
 * Mapping-aware stock synchronisation.
 *
 * Sync flow:
 *   1. Load all active Sellmo ↔ WooCommerce mappings from the DB.
 *   2. Fetch the Sellmo product catalogue (for variant data on variable products).
 *   3. Page the /inventory endpoint into one local array.
 *   4. For each mapping:
 *        Simple product  → enable manage_stock, set quantity + status.
 *        Variable product → match every Sellmo variant to a WC variation by SKU,
 *                           then enable manage_stock + set quantity + status per variation.
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

	// ── Public entry point ────────────────────────────────────────────────────

	/**
	 * Run the stock sync. Called by the "Sync Stock Now" button and the cron.
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

	// ── Orchestration ─────────────────────────────────────────────────────────

	/**
	 * Run the four-step mapping-aware sync.
	 *
	 * @since  1.1.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	private function do_stock_sync(): array {
		$token = trim( (string) Sellmo_Settings::get( 'api_token' ) );
		if ( '' === $token ) {
			return [ 'success' => false, 'count' => 0, 'message' => __( 'SalesPlay API token is not configured.', 'sellmo-woocommerce' ) ];
		}

		// ── Step 1: load active mappings ─────────────────────────────────────
		$all_mappings = Sellmo_Product_Mapping::get_all_mappings_indexed();
		$by_id        = $all_mappings['by_id']; // sellmo_product_id → row

		if ( empty( $by_id ) ) {
			Sellmo_Logger::log( 'stock', 'sellmo_to_wc', 'skipped', 'Stock sync: no mapped products found.' );
			return [
				'success' => true,
				'count'   => 0,
				'message' => __( 'No mapped products to sync.', 'sellmo-woocommerce' ),
			];
		}

		// ── Step 2: fetch Sellmo product catalogue (variant details) ─────────
		$sellmo_products = $this->fetch_sellmo_product_map( $token );
		if ( is_wp_error( $sellmo_products ) ) {
			return [ 'success' => false, 'count' => 0, 'message' => $sellmo_products->get_error_message() ];
		}

		// ── Step 3: page /inventory into one local array ─────────────────────
		$inventory = $this->fetch_all_inventory( $token );
		if ( is_wp_error( $inventory ) ) {
			return [ 'success' => false, 'count' => 0, 'message' => $inventory->get_error_message() ];
		}

		// ── Step 4: update WooCommerce stock for every mapped product ─────────
		$updated = 0;
		$skipped = 0;
		$errors  = 0;

		foreach ( $by_id as $sellmo_id => $mapping ) {
			$wc_id       = (int)    ( $mapping['wc_product_id']       ?? 0 );
			$sellmo_code = (string) ( $mapping['sellmo_product_code'] ?? '' );

			if ( $wc_id <= 0 ) {
				$skipped++;
				continue;
			}

			$wc_product = wc_get_product( $wc_id );
			if ( ! $wc_product instanceof WC_Product ) {
				Sellmo_Logger::log( 'stock', 'sellmo_to_wc', 'error',
					sprintf( 'Could not load WooCommerce product #%d.', $wc_id ), (string) $wc_id );
				$errors++;
				continue;
			}

			if ( $wc_product->is_type( 'variable' ) ) {
				// Variable product — update each variation via Sellmo variant data.
				$outcome = $this->sync_variable_product(
					$wc_product,
					$sellmo_id,
					$sellmo_code,
					$sellmo_products,
					$inventory
				);
			} else {
				// Simple product — look up inventory directly.
				$outcome = $this->sync_simple_product( $wc_product, $sellmo_id, $sellmo_code, $inventory );
			}

			match ( $outcome ) {
				'success' => $updated++,
				'skipped' => $skipped++,
				default   => $errors++,
			};
		}

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

	// ── Per-product sync helpers ──────────────────────────────────────────────

	/**
	 * Sync stock for a simple WooCommerce product.
	 *
	 * Inventory lookup: /inventory product_id matches the Sellmo product "id" field.
	 * WooCommerce automatically recalculates stock_status on save() when
	 * manage_stock is enabled, so set_stock_status() is not called explicitly.
	 *
	 * @since  1.1.0
	 * @param  WC_Product            $product      WooCommerce product object.
	 * @param  string                $sellmo_id    Sellmo product UUID ("id" from /products).
	 * @param  string                $sellmo_code  Sellmo product code (SKU fallback).
	 * @param  array<string, array>  $inventory    Inventory map keyed by UUID and 'code:{code}'.
	 * @return string 'success' | 'skipped' | 'error'
	 */
	private function sync_simple_product(
		WC_Product $product,
		string $sellmo_id,
		string $sellmo_code,
		array $inventory
	): string {
		// /inventory product_id = Sellmo product "id" for simple products.
		// Fallback to product_code when the UUID isn't present in the inventory response.
		$inv = $inventory[ $sellmo_id ]
			?? ( '' !== $sellmo_code ? ( $inventory[ 'code:' . $sellmo_code ] ?? null ) : null );

		if ( null === $inv ) {
			return 'skipped';
		}

		$in_stock = max( 0, (int) $inv['in_stock'] );

		// Enable stock management and set quantity.
		// WooCommerce recalculates stock_status automatically on save().
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $in_stock );
		$product->save();

		Sellmo_Logger::log(
			'stock',
			'sellmo_to_wc',
			'success',
			sprintf( 'Simple #%d (SKU: %s): stock set to %d.', $product->get_id(), $sellmo_code, $in_stock ),
			(string) $product->get_id()
		);

		return 'success';
	}

	/**
	 * Sync stock for a variable WooCommerce product.
	 *
	 * Inventory lookup: /inventory product_id matches the Sellmo "variant_id" field
	 * for each variant. The WC variation is resolved by its SKU (= Sellmo product_code).
	 * WooCommerce recalculates stock_status automatically on save().
	 *
	 * @since  1.1.0
	 * @param  WC_Product            $parent          WooCommerce parent product.
	 * @param  string                $sellmo_id       Sellmo product UUID ("id" from /products).
	 * @param  string                $sellmo_code     Sellmo product code (SKU fallback).
	 * @param  array<string, array>  $sellmo_products Sellmo product map (UUID → product data).
	 * @param  array<string, array>  $inventory        Inventory map keyed by UUID and 'code:{code}'.
	 * @return string 'success' | 'skipped' | 'error'
	 */
	private function sync_variable_product(
		WC_Product $parent,
		string $sellmo_id,
		string $sellmo_code,
		array $sellmo_products,
		array $inventory
	): string {
		// Find the Sellmo product record that carries the variants array.
		$sellmo_product = $sellmo_products[ $sellmo_id ] ?? null;

		// Fallback: match by product_code when the UUID isn't in the local map.
		if ( null === $sellmo_product && '' !== $sellmo_code ) {
			foreach ( $sellmo_products as $sp ) {
				if ( (string) ( $sp['product_code'] ?? '' ) === $sellmo_code ) {
					$sellmo_product = $sp;
					break;
				}
			}
		}

		$variants = ( $sellmo_product && is_array( $sellmo_product['variants'] ?? null ) )
			? $sellmo_product['variants']
			: [];

		if ( empty( $variants ) ) {
			return 'skipped';
		}

		$any_updated = false;

		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			// /inventory product_id = variant_id for variant rows.
			$variant_id   = (string) ( $variant['variant_id'] ?? $variant['id'] ?? '' );
			$variant_code = (string) ( $variant['product_code'] ?? '' );

			$inv = ( '' !== $variant_id ? ( $inventory[ $variant_id ] ?? null ) : null )
				?? ( '' !== $variant_code ? ( $inventory[ 'code:' . $variant_code ] ?? null ) : null );

			if ( null === $inv ) {
				continue;
			}

			$in_stock = max( 0, (int) $inv['in_stock'] );

			// Resolve the WC variation by its SKU (= Sellmo variant product_code).
			$wc_var_id = '' !== $variant_code ? (int) wc_get_product_id_by_sku( $variant_code ) : 0;
			if ( $wc_var_id <= 0 ) {
				continue;
			}

			$variation = wc_get_product( $wc_var_id );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}

			// Enable stock management and set quantity.
			// WooCommerce recalculates stock_status automatically on save().
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $in_stock );
			$variation->save();

			Sellmo_Logger::log(
				'stock',
				'sellmo_to_wc',
				'success',
				sprintf(
					'Variation #%d (SKU: %s) of parent #%d: stock set to %d.',
					$wc_var_id,
					$variant_code,
					$parent->get_id(),
					$in_stock
				),
				(string) $wc_var_id
			);

			$any_updated = true;
		}

		return $any_updated ? 'success' : 'skipped';
	}

	// ── API fetching ──────────────────────────────────────────────────────────

	/**
	 * Fetch the Sellmo product catalogue and index it by product UUID.
	 *
	 * Each entry is the raw product array from the API (including a 'variants' key
	 * when the product has variants).
	 *
	 * @since  1.1.0
	 * @param  string $token Bearer token.
	 * @return array<string, array<string, mixed>>|WP_Error  UUID → product data.
	 */
	private function fetch_sellmo_product_map( string $token ): array|\WP_Error {
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
				$pid = (string) ( $product['id'] ?? '' );
				if ( '' !== $pid ) {
					$map[ $pid ] = $product;
				}
			}

			$cursor = $this->extract_cursor( $result['body'] );
		} while ( '' !== $cursor && $page < self::MAX_PAGES );

		return $map;
	}

	/**
	 * Page the /inventory endpoint and return all rows indexed by:
	 *   - Sellmo product_id (UUID)
	 *   - 'code:{product_code}' (fallback when UUID matching fails)
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
				if ( ! is_array( $row ) || ! isset( $row['in_stock'] ) || ! is_numeric( $row['in_stock'] ) ) {
					continue;
				}

				$in_stock = (int) $row['in_stock'];
				$code     = (string) ( $row['product_code'] ?? '' );

				$pid = (string) ( $row['product_id'] ?? '' );
				if ( '' !== $pid ) {
					$inventory[ $pid ] = [ 'in_stock' => $in_stock, 'product_code' => $code ];
				}

				if ( '' !== $code ) {
					$inventory[ 'code:' . $code ] = [ 'in_stock' => $in_stock, 'product_code' => $code ];
				}
			}

			$cursor = $this->extract_cursor( $result['body'] );
		} while ( '' !== $cursor && $page < self::MAX_PAGES );

		return $inventory;
	}

	// ── HTTP helper ───────────────────────────────────────────────────────────

	/**
	 * Issue one GET request (with JSON body) and return the decoded response.
	 *
	 * @since  1.1.0
	 * @param  string $url      Full endpoint URL.
	 * @param  string $endpoint Short path for logging.
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

	// ── Response helpers ──────────────────────────────────────────────────────

	/**
	 * Pull a list from a decoded response, trying each candidate key in order.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $response
	 * @param  string[]             $keys
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
	 * Pull the next pagination cursor from a decoded response.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $response
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
