<?php
declare(strict_types=1);

/**
 * Incremental (delta) product sync for Sellmo POS Integration.
 *
 * Replaces the old full-overwrite-to-WooCommerce behaviour of the dashboard
 * "Sync Products Now" button. Instead of writing to WooCommerce directly, it
 * pulls the Sellmo catalogue and inserts ONLY products that are not already
 * staged into the Product Mapper table. Existing staged rows and existing
 * WooCommerce-mapped products are left untouched — no overwrites, no duplicates.
 *
 *     Sellmo API  ->  Product Mapper table  ->  (user maps in UI)  ->  WooCommerce
 *
 * @package Sellmo_WooCommerce
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Product_Mapper_Sync
 *
 * @since 1.1.0
 */
class Sellmo_Product_Mapper_Sync {

	/**
	 * Safety cap on pages fetched per run.
	 */
	private const MAX_PAGES = 100;

	/** @var Sellmo_Product_Sync|null Shared price logic (see resolve_price()). */
	private ?Sellmo_Product_Sync $product_sync = null;

	/**
	 * Public entry point used by the dashboard "Sync Products Now" AJAX action.
	 *
	 * The whole run is wrapped by Sellmo_Sync_Guard so a thrown exception or a
	 * WP_Error never bubbles up as a fatal.
	 *
	 * @since  1.1.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	public function run_incremental_sync(): array {
		Sellmo_Sync_Guard::extend_time_limit( 300 );

		$result = Sellmo_Sync_Guard::run(
			fn(): array => $this->do_delta_sync(),
			'product',
			'pos_to_wc',
			'Incremental product mapper sync'
		);

		// After staging products, refresh the variant store for mapped products.
		// Isolated so a variant-sync failure never breaks the product sync result.
		try {
			( new Sellmo_Product_Variant_Sync() )->run();
		} catch ( \Throwable $e ) {
			Sellmo_Logger::log( 'product', 'pos_to_wc', 'error', 'Variant sync failed: ' . $e->getMessage() );
		}

		// Auto map runs last: it needs the freshly staged rows AND variant store.
		if ( ! empty( $result['success'] ) && Sellmo_Product_Mapping::auto_map_enabled() ) {
			try {
				$auto = ( new Sellmo_Auto_Mapper() )->run();

				$result['message'] .= ' ' . sprintf(
					/* translators: 1: auto-mapped count, 2: skipped (ambiguous) count */
					__( '%1$d auto-mapped by SKU, %2$d skipped (matched more than one item).', 'sellmo-woocommerce' ),
					$auto['mapped'],
					$auto['ambiguous']
				);
			} catch ( \Throwable $e ) {
				Sellmo_Logger::log( 'product', 'pos_to_wc', 'error', 'Auto map failed: ' . $e->getMessage() );
			}
		}

		return $result;
	}

	/**
	 * Core delta loop: page the API, insert only new products into the mapper table.
	 *
	 * @since  1.1.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	private function do_delta_sync(): array {
		$token = trim( (string) Sellmo_Settings::get( 'api_token' ) );
		if ( '' === $token ) {
			return [
				'success' => false,
				'count'   => 0,
				/* translators: %s: POS brand name, e.g. SalesPlay. */
				'message' => sprintf( __( '%s API token is not configured.', 'sellmo-woocommerce' ), POS_Brand::name() ),
			];
		}

		// All HTTP goes through Sellmo_API.
		$api = new Sellmo_API();

		// id -> shop_name map from /shops, so each staged row can carry a
		// human-readable shop name alongside the id pulled from the product.
		$shop_names = Sellmo_Product_Mapping::fetch_shop_names( $api );

		// Snapshot of already-staged ids so membership is O(1), not a query per row.
		$existing = Sellmo_Product_Mapping::get_existing_id_set();

		$cursor   = '';
		$page     = 0;
		$inserted = 0;
		$skipped  = 0;
		$seen_ids = []; // Every POS id returned this run — used to reconcile deletions.

		Sellmo_Logger::log( 'product', 'pos_to_wc', 'skipped', 'Incremental mapper sync started from dashboard.' );

		do {
			$page++;
			$response = $api->get_products( $cursor );

			if ( is_wp_error( $response ) ) {
				// HTTP/transport failure already captured the code + body in the log.
				return [
					'success' => false,
					'count'   => $inserted,
					'message' => $response->get_error_message(),
				];
			}

			$products = $this->extract_products( $response );
			if ( empty( $products ) ) {
				break;
			}

			foreach ( $products as $product ) {
				if ( ! is_array( $product ) ) {
					continue;
				}

				$sellmo_id = (string) ( $product['id'] ?? '' );
				$code      = (string) ( $product['product_code'] ?? '' );

				// Record every returned id (before any skip) so deletions can be
				// reconciled against the full catalogue after paging completes.
				if ( '' !== $sellmo_id ) {
					$seen_ids[ $sellmo_id ] = true;
				}

				// Validation: a product with neither an id nor a code cannot be mapped.
				if ( '' === $sellmo_id && '' === $code ) {
					$skipped++;
					continue;
				}

				// Current catalogue snapshot, resolved up front so it can be refreshed
				// on existing rows too — an upstream edit (shop/price/category/variants)
				// must propagate to already-staged and already-mapped products.
				[ $shop_ids, $shop_names_csv ] = Sellmo_Product_Mapping::resolve_available_shops( $product, $shop_names );
				$variant_count = isset( $product['variants'] ) && is_array( $product['variants'] ) ? count( $product['variants'] ) : 0;

				// Existing row (mapped or already staged): refresh its snapshot in place
				// — name, shops, price, category, variant_count, variants, raw_payload —
				// and log every field that changed. No new WooCommerce write happens here.
				if ( Sellmo_Product_Mapping::is_sellmo_product_mapped( $product ) || ( '' !== $sellmo_id && isset( $existing[ $sellmo_id ] ) ) ) {
					$changes = Sellmo_Product_Mapping::refresh_catalogue_row(
						$sellmo_id,
						$code,
						$shop_ids,
						$shop_names_csv,
						$this->resolve_price( $product ),
						$this->resolve_category( $product ),
						$variant_count,
						$product,
						(string) ( $product['product_name'] ?? '' )
					);

					if ( ! empty( $changes ) ) {
						Sellmo_Logger::log(
							'product',
							'pos_to_wc',
							'success',
							sprintf(
								'Mapper row updated for "%s": %s.',
								(string) ( $product['product_name'] ?? '' ),
								implode( '; ', $changes )
							),
							$code
						);
					}

					$skipped++;
					continue;
				}

				$ok = Sellmo_Product_Mapping::insert_new( [
					'pos_product_id'   => $sellmo_id,
					'pos_product_code' => $code,
					'pos_product_name' => (string) ( $product['product_name'] ?? '' ),
					'category'            => $this->resolve_category( $product ),
					'shop_id'             => $shop_ids,
					'shop_name'           => $shop_names_csv,
					'price'               => $this->resolve_price( $product ),
					'variant_count'       => isset( $product['variants'] ) && is_array( $product['variants'] )
						? count( $product['variants'] )
						: 0,
					'raw_payload'         => $product,
				] );

				if ( $ok ) {
					$inserted++;
					$existing[ $sellmo_id ] = true; // Prevent a duplicate within the same run.
				} else {
					$skipped++;
				}
			}

			$cursor = $this->extract_cursor( $response );

		} while ( '' !== $cursor && $page < self::MAX_PAGES );

		// Reconcile deletions — but only when the FULL catalogue was fetched. If
		// the page cap stopped us mid-catalogue ($cursor still set), skip it:
		// reconciling a partial snapshot would unlink still-valid products.
		$removed   = 0;
		$truncated = ( '' !== $cursor && $page >= self::MAX_PAGES );
		if ( ! $truncated ) {
			$removed = Sellmo_Product_Mapping::reconcile_absent( array_keys( $seen_ids ) );
		}

		// Enforce the selected-shop scope: products no longer in the selected shop
		// have their WooCommerce product removed and are un-mapped (the mapper row
		// is kept and flagged as belonging to another shop).
		$out_of_shop = Sellmo_Product_Mapping::enforce_selected_shop_scope();

		Sellmo_Settings::set( 'last_product_sync', [
			'time'   => time(),
			'status' => 'success',
			'count'  => $inserted,
		] );

		Sellmo_Logger::log(
			'product',
			'pos_to_wc',
			'success',
			sprintf(
				'%d new products staged in mapper, %d skipped, %d removed (deleted in POS), %d removed from WooCommerce (another shop).',
				$inserted,
				$skipped,
				$removed,
				$out_of_shop
			)
		);

		return [
			'success' => true,
			'count'   => $inserted,
			'message' => sprintf(
				/* translators: 1: inserted count, 2: skipped count, 3: removed count, 4: out-of-shop count */
				__( '%1$d new products added to the Product Mapper. %2$d already present. %3$d removed (deleted in POS). %4$d removed from WooCommerce (belong to another shop).', 'sellmo-woocommerce' ),
				$inserted,
				$skipped,
				$removed,
				$out_of_shop
			),
		];
	}

	/**
	 * Pull the products array from a /products response.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $response Decoded response.
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
	 * Pull the next pagination cursor from a /products response.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $response Decoded response.
	 * @return string Empty string when there are no further pages.
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

	/**
	 * Resolve a display category name from a product payload.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $product Product payload.
	 * @return string
	 */
	private function resolve_category( array $product ): string {
		return Sellmo_Product_Mapping::catalogue_category_name( $product );
	}

	/**
	 * Resolve the mapper price — the selected shop's price, exactly as Sync
	 * Stock computes it (see Sellmo_Product_Sync::catalogue_price()).
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $product Product payload.
	 * @return float
	 */
	private function resolve_price( array $product ): float {
		$this->product_sync ??= new Sellmo_Product_Sync();

		return $this->product_sync->catalogue_price( $product ) ?? 0.0;
	}
}
