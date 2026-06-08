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

	private const MAX_PAGES = 200;

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

		// All HTTP goes through Sellmo_API (GET with query-string params, not a body).
		$api = new Sellmo_API();

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
		$sellmo_products = $this->fetch_sellmo_product_map( $api );
		if ( is_wp_error( $sellmo_products ) ) {
			return [ 'success' => false, 'count' => 0, 'message' => $sellmo_products->get_error_message() ];
		}

		// ── Step 3: page /inventory into one local array ─────────────────────
		$inventory = $this->fetch_all_inventory( $api );
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
	 * Inventory lookup: /inventory product_id matches the Sellmo product "id" field;
	 * falls back to the product_code when the UUID is not present in the inventory.
	 * Enables stock management, sets the quantity, and sets the stock status.
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

		$this->apply_stock( $product, $in_stock );

		Sellmo_Logger::log(
			'stock',
			'sellmo_to_wc',
			'success',
			sprintf(
				'Simple #%d (SKU: %s): stock set to %d (%s).',
				$product->get_id(),
				$sellmo_code,
				$in_stock,
				$in_stock > 0 ? 'instock' : 'outofstock'
			),
			(string) $product->get_id()
		);

		return 'success';
	}

	/**
	 * Sync stock for a variable WooCommerce product.
	 *
	 * For each Sellmo variant: read its in_stock from /inventory (keyed by
	 * variant_id, with a product_code fallback), resolve the matching WC variation
	 * by SKU, then enable stock management and set the quantity + status.
	 *
	 * The WC variation is matched first against the parent's own children (so we
	 * never touch a variation belonging to a different product), then via a global
	 * SKU lookup as a fallback.
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

		// Build a SKU → variation_id map from this parent's own children so each
		// Sellmo variant maps to the correct WC variation under this product.
		$child_sku_map = [];
		foreach ( $parent->get_children() as $child_id ) {
			$child = wc_get_product( (int) $child_id );
			if ( $child instanceof WC_Product ) {
				$child_sku = (string) $child->get_sku();
				if ( '' !== $child_sku ) {
					$child_sku_map[ $child_sku ] = (int) $child_id;
				}
			}
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

			// Resolve the WC variation by SKU: prefer this parent's children,
			// then fall back to a global SKU lookup.
			$wc_var_id = $child_sku_map[ $variant_code ] ?? 0;
			if ( 0 === $wc_var_id && '' !== $variant_code ) {
				$wc_var_id = (int) wc_get_product_id_by_sku( $variant_code );
			}
			if ( $wc_var_id <= 0 ) {
				continue;
			}

			$variation = wc_get_product( $wc_var_id );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}

			$this->apply_stock( $variation, $in_stock );

			Sellmo_Logger::log(
				'stock',
				'sellmo_to_wc',
				'success',
				sprintf(
					'Variation #%d (SKU: %s) of parent #%d: stock set to %d (%s).',
					$wc_var_id,
					$variant_code,
					$parent->get_id(),
					$in_stock,
					$in_stock > 0 ? 'instock' : 'outofstock'
				),
				(string) $wc_var_id
			);

			$any_updated = true;
		}

		// Updating variations outside the admin panel (CRON/API) does not always
		// refresh the parent's aggregate stock status, so force a sync.
		if ( $any_updated && class_exists( 'WC_Product_Variable' ) ) {
			WC_Product_Variable::sync( $parent->get_id() );
		}

		return $any_updated ? 'success' : 'skipped';
	}

	/**
	 * Apply a stock quantity to a WooCommerce product or variation.
	 *
	 * Enables stock management, sets the quantity, sets the stock status
	 * (instock when > 0, otherwise outofstock), and persists the change.
	 *
	 * @since  1.1.0
	 * @param  WC_Product $product  Product or variation object.
	 * @param  int        $quantity Quantity to set (already floored at 0).
	 * @return void
	 */
	private function apply_stock( WC_Product $product, int $quantity ): void {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $quantity );
		$product->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
		$product->save();
	}

	// ── API fetching ──────────────────────────────────────────────────────────

	/**
	 * Fetch the Sellmo product catalogue and index it by product UUID.
	 *
	 * Each entry is the raw product array from the API (including a 'variants' key
	 * when the product has variants). Uses Sellmo_API so the request is a GET with
	 * query-string params — never a GET with a JSON body (which crashes the
	 * WordPress Requests transport).
	 *
	 * @since  1.1.0
	 * @param  Sellmo_API $api Shared API client.
	 * @return array<string, array<string, mixed>>|WP_Error  UUID → product data.
	 */
	private function fetch_sellmo_product_map( Sellmo_API $api ): array|\WP_Error {
		$cursor = '';
		$page   = 0;
		$map    = [];

		do {
			$page++;
			$response = $api->get_products( $cursor );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$products = $this->extract_list( $response, [ 'products', 'data' ] );
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

			$cursor = $this->extract_cursor( $response );
		} while ( '' !== $cursor && $page < self::MAX_PAGES );

		return $map;
	}

	/**
	 * Page the /inventory endpoint and return all rows indexed by:
	 *   - Sellmo product_id (UUID — matches a product "id" or a "variant_id")
	 *   - 'code:{product_code}' (fallback when UUID matching fails)
	 *
	 * @since  1.1.0
	 * @param  Sellmo_API $api Shared API client.
	 * @return array<string, array{ in_stock: int, product_code: string }>|WP_Error
	 */
	private function fetch_all_inventory( Sellmo_API $api ): array|\WP_Error {
		$cursor    = '';
		$page      = 0;
		$inventory = [];

		do {
			$page++;
			$response = $api->get_inventory( $cursor );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$rows = $this->extract_list( $response, [ 'inventory_levels', 'inventory', 'data' ] );
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

			$cursor = $this->extract_cursor( $response );
		} while ( '' !== $cursor && $page < self::MAX_PAGES );

		return $inventory;
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
