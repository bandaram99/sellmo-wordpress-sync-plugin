<?php
declare(strict_types=1);

/**
 * Variant population sync for Sellmo POS Integration.
 *
 * Walks the Sellmo /products endpoint and, for every product that carries
 * variants AND is mapped to a WooCommerce product (has a row in
 * {prefix}sellmo_product_mapping), stores each variant in
 * {prefix}sellmo_product_variants. The variant row's product_id foreign key is
 * the mapping row's id. Stock for each variant is read from /inventory.
 *
 *     /products (variants) + /inventory (stock)  ->  sellmo_product_variants
 *
 * @package Sellmo_WooCommerce
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Product_Variant_Sync
 *
 * @since 1.1.0
 */
class Sellmo_Product_Variant_Sync {

	/**
	 * Safety cap on pages fetched per run.
	 */
	private const MAX_PAGES = 100;

	/**
	 * Public entry point. Wrapped by Sellmo_Sync_Guard so a thrown exception or a
	 * WP_Error never bubbles up as a fatal.
	 *
	 * @since  1.1.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	public function run(): array {
		set_time_limit( 300 );

		return Sellmo_Sync_Guard::run(
			fn(): array => $this->do_sync(),
			'product',
			'pos_to_wc',
			'Variant sync'
		);
	}

	/**
	 * Core loop: page /products, store variants for mapped products.
	 *
	 * @since  1.1.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	private function do_sync(): array {
		$api = new Sellmo_API();

		// Stock snapshot from /inventory, keyed by Sellmo id and by product code.
		[ $stock_by_id, $stock_by_code ] = $this->build_stock_maps( $api );

		$cursor             = '';
		$page               = 0;
		$variants_stored    = 0;
		$products_with_vars = 0;
		$skipped_no_row     = 0;

		do {
			$page++;
			$response = $api->get_products( $cursor );

			if ( is_wp_error( $response ) ) {
				return [
					'success' => false,
					'count'   => $variants_stored,
					'message' => $response->get_error_message(),
				];
			}

			$products = $response['products'] ?? $response['data'] ?? [];
			if ( empty( $products ) ) {
				break;
			}

			foreach ( $products as $product ) {
				if ( ! is_array( $product ) ) {
					continue;
				}

				$variants = isset( $product['variants'] ) && is_array( $product['variants'] ) ? $product['variants'] : [];
				if ( empty( $variants ) ) {
					continue;
				}

				$products_with_vars++;

				// The variant row's foreign key is the parent product's row id in
				// wp_sellmo_product_mapping. do_delta_sync stages every product before
				// this runs, so a row exists whether the product is mapped or staged.
				$mapping_id = Sellmo_Product_Mapping::get_id_by_sellmo(
					(string) ( $product['id'] ?? '' ),
					(string) ( $product['product_code'] ?? '' )
				);
				if ( $mapping_id <= 0 ) {
					$skipped_no_row++;
					continue;
				}

				foreach ( $variants as $variant ) {
					if ( ! is_array( $variant ) ) {
						continue;
					}

					$variant_id = (string) ( $variant['variant_id'] ?? '' );
					if ( '' === $variant_id ) {
						continue;
					}

					$variant_code = (string) ( $variant['product_code'] ?? '' );

					// Stock: inventory rows key a variant by its own id (product_id)
					// and by its product_code; prefer the id match.
					$stock = $stock_by_id[ $variant_id ]
						?? ( '' !== $variant_code ? ( $stock_by_code[ $variant_code ] ?? 0 ) : 0 );

					$stored = Sellmo_Product_Variant_Store::upsert_variant( [
						'product_id'    => $mapping_id,
						'variant_id'    => $variant_id,
						'product_code'  => $variant_code,
						'default_cost'  => (float) ( $variant['default_cost'] ?? 0 ),
						'option1_value' => (string) ( $variant['option1_value'] ?? '' ),
						'option2_value' => (string) ( $variant['option2_value'] ?? '' ),
						'option3_value' => (string) ( $variant['option3_value'] ?? '' ),
						'shop_ids'      => $this->extract_shop_ids( $variant ),
						'stock_count'   => (int) $stock,
						// stock_status is derived from stock_count inside upsert_variant().
					] );

					if ( $stored ) {
						$variants_stored++;
					}
				}
			}

			$cursor = $this->extract_cursor( $response );

		} while ( '' !== $cursor && $page < self::MAX_PAGES );

		Sellmo_Logger::log(
			'product',
			'pos_to_wc',
			'success',
			sprintf(
				'Variant sync: %d variants stored across %d product(s) with variants; %d skipped (no mapping row).',
				$variants_stored,
				$products_with_vars - $skipped_no_row,
				$skipped_no_row
			)
		);

		return [
			'success' => true,
			'count'   => $variants_stored,
			'message' => sprintf(
				/* translators: %d: number of variants stored */
				__( '%d variant(s) stored.', 'sellmo-woocommerce' ),
				$variants_stored
			),
		];
	}

	/**
	 * Build stock lookup maps from the /inventory endpoint.
	 *
	 * @since  1.1.0
	 * @param  Sellmo_API $api API client.
	 * @return array{0: array<string, int>, 1: array<string, int>} [ by Sellmo id, by product code ].
	 */
	private function build_stock_maps( Sellmo_API $api ): array {
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

			$cursor = $this->extract_cursor( $response );

		} while ( '' !== $cursor && $page < self::MAX_PAGES );

		return [ $by_id, $by_code ];
	}

	/**
	 * Extract the list of shop ids a variant belongs to.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $variant Variant payload.
	 * @return array<int, string>
	 */
	private function extract_shop_ids( array $variant ): array {
		$shops = isset( $variant['shops'] ) && is_array( $variant['shops'] ) ? $variant['shops'] : [];
		$ids   = [];

		foreach ( $shops as $shop ) {
			if ( is_array( $shop ) && ! empty( $shop['shop_id'] ) ) {
				$ids[] = (string) $shop['shop_id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Extract the pagination cursor from a Sellmo API response.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $response Decoded response.
	 * @return string
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
