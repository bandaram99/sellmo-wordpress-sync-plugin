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
		Sellmo_Sync_Guard::extend_time_limit( 300 );

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

		// id -> shop_name map from /shops, so each variant row carries the
		// human-readable shop names alongside the ids pulled from the variant.
		$shop_names = Sellmo_Product_Mapping::fetch_shop_names( $api );

		$cursor             = '';
		$page               = 0;
		$variants_stored    = 0;
		$variants_removed   = 0;
		$variations_added   = 0;
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

				if ( ! empty( $variants ) ) {
					$products_with_vars++;
				}

				// The variant row's foreign key is the parent product's row id in
				// wp_sellmo_product_mapping. do_delta_sync stages every product before
				// this runs, so a row exists whether the product is mapped or staged.
				// ponytail: resolved for every product, not only ones with variants, so a
				// product whose variants were ALL deleted upstream can still be pruned.
				$mapping_id = Sellmo_Product_Mapping::get_id_by_sellmo(
					(string) ( $product['id'] ?? '' ),
					(string) ( $product['product_code'] ?? '' )
				);
				if ( $mapping_id <= 0 ) {
					if ( ! empty( $variants ) ) {
						$skipped_no_row++;
					}
					continue;
				}

				// Variant ids the POS still returns for this product — anything stored
				// outside this set was deleted upstream and is pruned after the upserts.
				$live_variant_ids = [];

				foreach ( $variants as $variant ) {
					if ( ! is_array( $variant ) ) {
						continue;
					}

					$variant_id = (string) ( $variant['variant_id'] ?? '' );
					if ( '' === $variant_id ) {
						continue;
					}

					$live_variant_ids[] = $variant_id;

					$variant_code = (string) ( $variant['product_code'] ?? '' );

					// Shops the variant is sellable in — ids + aligned names. Always
					// written so shop_ids/shop_names track upstream add/remove on every
					// "Sync Products Now"; selected-shop filtering happens at display time.
					[ $variant_shop_ids, $variant_shop_names ] = $this->resolve_shops( $variant, $shop_names );

					// Log a variant's shop change (old → new) before the upsert overwrites
					// it. ponytail: one extra indexed read per variant; fine at these sizes.
					$existing_variant = Sellmo_Product_Variant_Store::get_by_variant_id( $variant_id );
					if ( is_array( $existing_variant ) ) {
						$old_shops = $this->decode_names( (string) ( $existing_variant['shop_names'] ?? '' ) );
						$new_shops = implode( ', ', $variant_shop_names );
						if ( $old_shops !== $new_shops ) {
							Sellmo_Logger::log(
								'product',
								'pos_to_wc',
								'success',
								sprintf(
									'Variant "%s" shops "%s" → "%s".',
									'' !== $variant_code ? $variant_code : $variant_id,
									'' !== $old_shops ? $old_shops : 'none',
									'' !== $new_shops ? $new_shops : 'none'
								),
								$variant_code
							);
						}
					}

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
						'shop_ids'      => $variant_shop_ids,
						'shop_names'    => $variant_shop_names,
						'stock_count'   => (int) $stock,
						// stock_status is derived from stock_count inside upsert_variant().
					] );

					if ( $stored ) {
						$variants_stored++;
					}
				}

				// Drop variants deleted in the backoffice so the Product Mapper stops
				// listing them (and their WooCommerce variation, if one was imported).
				$variants_removed += $this->prune_absent_variants( $mapping_id, $live_variant_ids, $product );

				// The mirror of the prune: variants ADDED in the backoffice become
				// variations on the mapped WooCommerce product. Isolated so one bad
				// product cannot abort the whole variant sync.
				if ( ! empty( $live_variant_ids ) ) {
					try {
						$variations_added += ( new Sellmo_Product_Sync() )->sync_mapped_variations( $product );
					} catch ( \Throwable $e ) {
						Sellmo_Logger::log(
							'product',
							'pos_to_wc',
							'error',
							'Adding new variants to WooCommerce failed: ' . $e->getMessage(),
							(string) ( $product['product_code'] ?? '' )
						);
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
				'Variant sync: %d variants stored across %d product(s) with variants; %d added to WooCommerce (new in POS); %d removed (deleted in POS); %d skipped (no mapping row).',
				$variants_stored,
				$products_with_vars - $skipped_no_row,
				$variations_added,
				$variants_removed,
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
	 * Remove stored variants that the POS no longer returns for a product.
	 *
	 * Each pruned variant is logged by name, and any WooCommerce variation it had
	 * been imported to is deleted — the POS variant behind it no longer exists, so
	 * leaving the variation would keep an unsellable row on the storefront. Parent
	 * variable products are re-synced so their cached price range drops the removed
	 * variations.
	 *
	 * @since  1.5.0
	 * @param  int                  $mapping_id       Parent mapping row id.
	 * @param  array<int, string>   $live_variant_ids Variant ids present upstream right now.
	 * @param  array<string, mixed> $product          Parent product payload (for the log).
	 * @return int                                    Number of variants removed.
	 */
	private function prune_absent_variants( int $mapping_id, array $live_variant_ids, array $product ): int {
		$removed = Sellmo_Product_Variant_Store::delete_absent_for_product( $mapping_id, $live_variant_ids );
		if ( empty( $removed ) ) {
			return 0;
		}

		$parent_ids = [];
		$delete     = Sellmo_Product_Mapping::auto_map_enabled();

		foreach ( $removed as $row ) {
			$wc_variation_id = (int) ( $row['wc_variation_id'] ?? 0 );

			// Auto map on: delete the variation. Off: keep it, unlinked.
			if ( $wc_variation_id > 0 ) {
				if ( $delete ) {
					$parent_id = (int) wp_get_post_parent_id( $wc_variation_id );
					if ( $parent_id > 0 ) {
						$parent_ids[ $parent_id ] = true;
					}
					wp_delete_post( $wc_variation_id, true );
				} else {
					Sellmo_Product_Mapping::release_wc_product( $wc_variation_id );
				}
			}

			Sellmo_Logger::log(
				'product',
				'pos_to_wc',
				'success',
				sprintf(
					'Variant "%s" removed from "%s" (deleted in POS)%s.',
					$this->variant_label( $row ),
					(string) ( $product['product_name'] ?? '' ),
					$wc_variation_id > 0
						? sprintf( $delete ? '; WooCommerce variation #%d deleted' : '; WooCommerce variation #%d kept as unmapped', $wc_variation_id )
						: ''
				),
				(string) ( $row['product_code'] ?? '' )
			);
		}

		// ponytail: refreshes the parent's price range / children cache. The parent's
		// attribute option lists are left as-is — an option no longer used by any
		// variation is harmless; rebuild them here if empty option terms show up.
		foreach ( array_keys( $parent_ids ) as $parent_id ) {
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $parent_id );
			}
			WC_Product_Variable::sync( $parent_id );
		}

		return count( $removed );
	}

	/**
	 * Human-readable label for a stored variant row.
	 *
	 * @since  1.5.0
	 * @param  array<string, mixed> $row Variant store row.
	 * @return string
	 */
	private function variant_label( array $row ): string {
		$options = [];
		foreach ( [ 'option1_value', 'option2_value', 'option3_value' ] as $key ) {
			$value = trim( (string) ( $row[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$options[] = $value;
			}
		}

		if ( ! empty( $options ) ) {
			return implode( ' / ', $options );
		}

		$code = trim( (string) ( $row['product_code'] ?? '' ) );

		return '' !== $code ? $code : (string) ( $row['variant_id'] ?? '' );
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
	 * Resolve the shops a variant belongs to as aligned id + name lists.
	 *
	 * Only shops the variant is actually sellable in are kept (a missing
	 * available_for_sale flag counts as available). Names come from the /shops
	 * id -> name map and are aligned by position with the ids.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed>  $variant    Variant payload.
	 * @param  array<string, string> $shop_names id -> shop_name map from /shops.
	 * @return array{0: array<int, string>, 1: array<int, string>} [ ids, names ]
	 */
	private function resolve_shops( array $variant, array $shop_names ): array {
		$shops = isset( $variant['shops'] ) && is_array( $variant['shops'] ) ? $variant['shops'] : [];

		$ids   = [];
		$names = [];
		foreach ( $shops as $shop ) {
			if ( ! is_array( $shop ) || empty( $shop['shop_id'] ) ) {
				continue;
			}

			// Only keep shops the variant is actually sellable in.
			if ( array_key_exists( 'available_for_sale', $shop ) && ! (bool) $shop['available_for_sale'] ) {
				continue;
			}

			$id = (string) $shop['shop_id'];
			if ( in_array( $id, $ids, true ) ) {
				continue;
			}

			$ids[]   = $id;
			$names[] = $shop_names[ $id ] ?? '';
		}

		return [ $ids, $names ];
	}

	/**
	 * Decode a stored JSON shop_names column into a comma-joined string.
	 *
	 * @since  1.4.0
	 * @param  string $json Stored JSON array (possibly empty).
	 * @return string
	 */
	private function decode_names( string $json ): string {
		if ( '' === trim( $json ) ) {
			return '';
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? implode( ', ', array_map( 'strval', $decoded ) ) : '';
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
