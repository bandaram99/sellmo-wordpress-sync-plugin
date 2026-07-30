<?php
declare(strict_types=1);

/**
 * Product sync handler for Sellmo POS Integration.
 *
 * Pulls product data from the Sellmo API and applies it to WooCommerce
 * products. Supports simple products, variable products with variants,
 * image sideloading with deduplication, and paginated API responses.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Product_Sync
 *
 * Imports and updates WooCommerce products from Sellmo.
 * Products are matched via the _pos_product_code post meta key.
 * Cron callbacks are registered in Sellmo_Scheduler, not here.
 *
 * @since 1.0.0
 */
class Sellmo_Product_Sync {

	/**
	 * Object-cache group used during a sync run to avoid repeated DB lookups.
	 */
	private const CACHE_GROUP = 'sellmo_wc_product';

	/**
	 * SalesPlay products endpoint.
	 */
	private const PRODUCTS_ENDPOINT = '{{API_BASE}}/products';

	/**
	 * API page size.
	 */
	private const PAGE_LIMIT = '50';

	/**
	 * Maximum pages fetched per full sync run.
	 */
	private const MAX_PAGES = 100;

	/**
	 * Default operational shop ID used when the configured shop is unavailable.
	 */
	private const DEFAULT_PRIMARY_SHOP_ID = 'a1grd1FUdDF4Z0lyMVROZG9QTHM2dz09';

	/**
	 * Variant option field -> default local attribute label mapping.
	 *
	 * @var array<string, string>
	 */
	private const OPTION_ATTRIBUTE_LABELS = [
		'option1_value' => 'Option 1',
		'option2_value' => 'Option 2',
		'option3_value' => 'Option 3',
	];

	/**
	 * Last sync error captured during a product import/update.
	 *
	 * @var array<string, mixed>
	 */
	private array $last_error = [];

	/**
	 * Constructor.
	 *
	 * No hooks registered here — cron callbacks live in Sellmo_Scheduler.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {}

	// =========================================================================
	// Public entry points
	// =========================================================================

	/**
	 * Main cron entry point.
	 *
	 * Checks that product sync is enabled before delegating to do_full_sync().
	 * Called by Sellmo_Scheduler on the sellmo_wc_product_sync cron event.
	 *
	 * @since  1.0.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	public function run_sync(): array {
		if ( 'yes' !== (string) Sellmo_Settings::get( 'product_sync_enabled' ) ) {
			return [ 'success' => true, 'count' => 0, 'message' => 'Product sync is disabled.' ];
		}

		return $this->run_full_sync( false );
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
		set_time_limit( 300 );

		return $this->run_full_sync( false );
	}

	/**
	 * Dashboard AJAX manual sync entry point with HTTP/API tracing.
	 *
	 * @since  1.0.0
	 * @return array{ success: bool, count: int, message: string, trace?: array<string, mixed>, error?: array<string, mixed> }
	 */
	public function run_ajax_trace_sync(): array {
		set_time_limit( 300 );

		return $this->run_full_sync( true );
	}

	/**
	 * Import a single new Sellmo product into WooCommerce.
	 *
	 * Creates a simple or variable WC product based on whether the Sellmo
	 * product carries variant data. Sets name, description, regular price,
	 * SKU, and sideloads the product image. For variable products, creates
	 * WC product attributes and variations from the Sellmo variant list.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $sellmo_product Decoded Sellmo product object.
	 * @return int                                  New WC product post ID, or 0 on failure.
	 */
	public function import_product( array $sellmo_product ): int {
		$mapping = Sellmo_Product_Mapping::get_mapping_for_sellmo_product( $sellmo_product );
		if ( is_array( $mapping ) ) {
			$code = (string) ( $sellmo_product['product_code'] ?? '' );
			Sellmo_Logger::log(
				'product',
				'pos_to_wc',
				'skipped',
				sprintf(
					'Product skipped because mapped to WooCommerce product #%d',
					(int) ( $mapping['wc_product_id'] ?? 0 )
				),
				$code
			);

			return 0;
		}

		return $this->sync_or_create_product( $sellmo_product );
	}

	/**
	 * Create or update a WooCommerce product from a Sellmo payload, bypassing
	 * import_product()'s "already mapped" guard.
	 *
	 * Used by the per-variant mapper import so a variation can be added to a
	 * parent variable product that is already mapped. Pass a payload whose
	 * `variants` array holds exactly the variations that should exist — the
	 * variation sync deletes any WC variation whose code is not in that set.
	 *
	 * @since  1.4.0
	 * @param  array<string, mixed> $sellmo_product Decoded Sellmo product payload.
	 * @return int                                  WC product id, 0 on failure.
	 */
	public function sync_product_to_wc( array $sellmo_product ): int {
		return $this->sync_or_create_product( $sellmo_product );
	}

	/**
	 * Resolve the WooCommerce variation id for a Sellmo variant under a parent.
	 *
	 * Tries the SalesPlay variant-id meta first, then the POS product code, then
	 * the SKU — the same keys the sync writes.
	 *
	 * @since  1.4.0
	 * @param  int                  $parent_id Parent WooCommerce product id.
	 * @param  array<string, mixed> $variant   Sellmo variant payload.
	 * @return int                             Variation id, 0 when not found.
	 */
	public function find_variation_id_for_variant( int $parent_id, array $variant ): int {
		$variant_id = (string) ( $variant['variant_id'] ?? '' );
		if ( '' !== $variant_id ) {
			$found = $this->find_variation_id_by_salesplay_variant_id( $parent_id, $variant_id );
			if ( $found > 0 ) {
				return $found;
			}
		}

		$code = (string) ( $variant['product_code'] ?? '' );
		if ( '' !== $code ) {
			$found = $this->find_variation_id_by_code_for_parent( $parent_id, $code );
			if ( $found > 0 ) {
				return $found;
			}
			$found = $this->find_variation_id_by_sku_for_parent( $parent_id, $code );
			if ( $found > 0 ) {
				return $found;
			}
		}

		return 0;
	}

	/**
	 * Stamp Sellmo join-key meta onto an existing WooCommerce product.
	 *
	 * Called when a Sellmo product is mapped to a pre-existing WooCommerce product
	 * (the "map to existing" flow), which otherwise carries none of the meta the
	 * stock/product sync relies on. Without these keys the sync cannot match the
	 * product — or its variations — back to the Sellmo catalogue.
	 *
	 * Product name/description/price are intentionally NOT touched here: mapping to
	 * an existing product must never clobber content the merchant already owns.
	 * Variation join keys are matched by SKU == Sellmo variant product_code.
	 *
	 * @since  1.2.0
	 * @param  int                  $wc_product_id  Target WooCommerce product ID.
	 * @param  array<string, mixed> $sellmo_product Decoded Sellmo product payload.
	 * @return void
	 */
	public function link_wc_product_meta( int $wc_product_id, array $sellmo_product ): void {
		if ( $wc_product_id <= 0 ) {
			return;
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$salesplay_id = (string) ( $sellmo_product['id'] ?? '' );
		$code         = (string) ( $sellmo_product['product_code'] ?? '' );

		if ( '' !== $salesplay_id ) {
			update_post_meta( $wc_product_id, '_salesplay_pos_product_id', $salesplay_id );
		}
		if ( '' !== $code ) {
			update_post_meta( $wc_product_id, '_pos_product_code', $code );
		}
		// Opt the mapped product into sync so the stock/field passes act on it.
		update_post_meta( $wc_product_id, '_sellmo_sync_enabled', 'yes' );

		// Best-effort variation join keys: match each Sellmo variant to a WC
		// variation by SKU == variant product_code.
		$variants = isset( $sellmo_product['variants'] ) && is_array( $sellmo_product['variants'] )
			? $sellmo_product['variants']
			: [];

		if ( empty( $variants ) || ! $product instanceof WC_Product_Variable ) {
			return;
		}

		$variant_by_code = [];
		foreach ( $variants as $variant ) {
			if ( is_array( $variant ) && '' !== (string) ( $variant['product_code'] ?? '' ) ) {
				$variant_by_code[ (string) $variant['product_code'] ] = $variant;
			}
		}

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$sku = (string) $variation->get_sku();
			if ( '' === $sku || ! isset( $variant_by_code[ $sku ] ) ) {
				continue;
			}

			$variant = $variant_by_code[ $sku ];
			update_post_meta( (int) $variation_id, '_salesplay_variant_id', (string) ( $variant['variant_id'] ?? '' ) );
			update_post_meta( (int) $variation_id, '_pos_product_code', $sku );
		}
	}

	/**
	 * Push price + category from a Sellmo payload onto a MAPPED WooCommerce product.
	 *
	 * Used by the stock sync so "Sync Stock Now" also refreshes the name, price and
	 * category of mapped products (stock itself flows through the stock sync's own
	 * loop). Product description is left untouched. The mapper table's cached
	 * name/price/category/variant_count columns are mirrored so the Product Mapper
	 * list stays in step with the live product.
	 *
	 * @since  1.2.0
	 * @param  array<string, mixed> $sellmo_product Decoded Sellmo product payload.
	 * @return string 'success' | 'skipped' | 'error'
	 */
	public function sync_mapped_product_fields( array $sellmo_product ): string {
		$mapping = Sellmo_Product_Mapping::get_mapping_for_sellmo_product( $sellmo_product );
		if ( ! is_array( $mapping ) ) {
			return 'skipped';
		}

		$wc_product_id = (int) ( $mapping['wc_product_id'] ?? 0 );
		if ( $wc_product_id <= 0 ) {
			return 'skipped';
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product instanceof WC_Product ) {
			return 'error';
		}

		// Ensure join-key meta exists (backfills mappings made before meta stamping,
		// so the stock pass can match this product and its variations this run).
		$this->link_wc_product_meta( $wc_product_id, $sellmo_product );

		// Snapshot the current WooCommerce values so we can log exactly what changed.
		$old_regular = (string) $product->get_regular_price();
		$old_sale    = (string) $product->get_sale_price();
		$old_cats    = $this->wc_category_names( $wc_product_id );
		$old_name    = (string) $product->get_name();

		try {
			// Product name: keep the mapped product's title in step with the POS name.
			if ( isset( $sellmo_product['product_name'] ) ) {
				$new_name = (string) $sellmo_product['product_name'];
				if ( '' !== $new_name && $new_name !== $old_name ) {
					$product->set_name( $new_name );
				}
			}

			// Regular price: prefer the configured shop's price, fall back to selling_price.
			$price = $this->extract_shop_price( $sellmo_product );
			if ( null === $price && isset( $sellmo_product['selling_price'] ) ) {
				$price = (float) $sellmo_product['selling_price'];
			}
			if ( null !== $price ) {
				$product->set_regular_price( (string) $price );
			}

			// Sale price only when a discount is explicitly set.
			if ( isset( $sellmo_product['discounted_price'] ) && (float) $sellmo_product['discounted_price'] > 0 ) {
				$product->set_sale_price( (string) $sellmo_product['discounted_price'] );
			} else {
				$product->set_sale_price( '' );
			}

			$this->apply_product_category( $product, $sellmo_product );
			$product->save();

			$this->log_field_changes( $product, $wc_product_id, $sellmo_product, $old_regular, $old_sale, $old_cats, $old_name );

			// Update each existing variation's price from its Sellmo variant.
			// Variant stock flows through the stock loop; this covers price.
			$this->update_mapped_variation_prices( $product, $sellmo_product );

			$variant_count = isset( $sellmo_product['variants'] ) && is_array( $sellmo_product['variants'] )
				? count( $sellmo_product['variants'] )
				: 0;

			// For a variable product the parent carries no price of its own — use the
			// first variant's price as the representative value for the mapper list.
			$mapper_price = $price;
			if ( null === $mapper_price && $variant_count > 0 ) {
				$mapper_price = $this->first_variant_price( $sellmo_product );
			}

			Sellmo_Product_Mapping::update_catalogue_fields(
				(string) ( $sellmo_product['id'] ?? '' ),
				(string) ( $sellmo_product['product_code'] ?? '' ),
				null !== $mapper_price ? (float) $mapper_price : null,
				$this->resolve_sellmo_category_name( $sellmo_product ),
				$variant_count,
				(string) ( $sellmo_product['product_name'] ?? '' )
			);

			return 'success';
		} catch ( \Throwable $e ) {
			Sellmo_Logger::log(
				'product',
				'pos_to_wc',
				'error',
				"Mapped field update failed for WC product #{$wc_product_id}: " . $e->getMessage(),
				(string) ( $sellmo_product['product_code'] ?? '' )
			);
			return 'error';
		}
	}

	/**
	 * Comma-separated product_cat term names for a WooCommerce product.
	 *
	 * Uses wp_get_post_terms (uncached) so a read taken right after a category
	 * change reflects the new terms, not a stale object cache.
	 *
	 * @since  1.3.0
	 * @param  int $wc_product_id WooCommerce product id.
	 * @return string
	 */
	private function wc_category_names( int $wc_product_id ): string {
		$names = wp_get_post_terms( $wc_product_id, 'product_cat', [ 'fields' => 'names' ] );

		return is_array( $names ) ? implode( ', ', $names ) : '';
	}

	/**
	 * Log each price/category field that changed on a mapped WooCommerce product.
	 *
	 * Only fields that actually changed are reported, each as "old → new", so the
	 * Sync Log spells out exactly what a stock sync altered on the store side.
	 *
	 * @since  1.3.0
	 * @param  WC_Product           $product        Saved WooCommerce product.
	 * @param  int                  $wc_product_id  WooCommerce product id.
	 * @param  array<string, mixed> $sellmo_product Source POS payload (for item id).
	 * @param  string               $old_regular    Regular price before the update.
	 * @param  string               $old_sale       Sale price before the update.
	 * @param  string               $old_cats       Category names before the update.
	 * @param  string               $old_name       Product name before the update.
	 * @return void
	 */
	private function log_field_changes( WC_Product $product, int $wc_product_id, array $sellmo_product, string $old_regular, string $old_sale, string $old_cats, string $old_name = '' ): void {
		$new_regular = (string) $product->get_regular_price();
		$new_sale    = (string) $product->get_sale_price();
		$new_cats    = $this->wc_category_names( $wc_product_id );
		$new_name    = (string) $product->get_name();

		$changes = [];

		if ( $old_name !== $new_name ) {
			$changes[] = sprintf(
				'name "%s" → "%s"',
				'' !== $old_name ? $old_name : 'n/a',
				'' !== $new_name ? $new_name : 'n/a'
			);
		}

		if ( $old_regular !== $new_regular ) {
			$changes[] = sprintf(
				'regular price %s → %s',
				'' !== $old_regular ? $old_regular : 'n/a',
				'' !== $new_regular ? $new_regular : 'n/a'
			);
		}

		if ( $old_sale !== $new_sale ) {
			$changes[] = sprintf(
				'sale price %s → %s',
				'' !== $old_sale ? $old_sale : 'none',
				'' !== $new_sale ? $new_sale : 'none'
			);
		}

		if ( $old_cats !== $new_cats ) {
			$changes[] = sprintf(
				'category "%s" → "%s"',
				'' !== $old_cats ? $old_cats : 'none',
				'' !== $new_cats ? $new_cats : 'none'
			);
		}

		if ( empty( $changes ) ) {
			return;
		}

		Sellmo_Logger::log(
			'product',
			'pos_to_wc',
			'success',
			sprintf(
				'Fields updated for "%s" (WC #%d): %s.',
				$product->get_name(),
				$wc_product_id,
				implode( '; ', $changes )
			),
			(string) ( $sellmo_product['product_code'] ?? '' )
		);
	}

	/**
	 * Update the price of each existing variation on a mapped variable product.
	 *
	 * Matches each WooCommerce variation to its Sellmo variant by the stamped
	 * _salesplay_variant_id, then by _pos_product_code, then by SKU. Only prices
	 * are written — variations are never created or deleted here (that would
	 * rebuild a merchant's product), and stock is handled by the stock sync.
	 *
	 * @since  1.2.0
	 * @param  WC_Product           $product        Parent product (any type; no-op unless variable).
	 * @param  array<string, mixed> $sellmo_product Sellmo product payload.
	 * @return void
	 */
	private function update_mapped_variation_prices( WC_Product $product, array $sellmo_product ): void {
		if ( ! $product instanceof WC_Product_Variable ) {
			return;
		}

		$variants = isset( $sellmo_product['variants'] ) && is_array( $sellmo_product['variants'] )
			? $sellmo_product['variants']
			: [];
		if ( empty( $variants ) ) {
			return;
		}

		// Index Sellmo variants by their id and by their product_code.
		$by_variant_id = [];
		$by_code       = [];
		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}
			$vid   = (string) ( $variant['variant_id'] ?? '' );
			$vcode = (string) ( $variant['product_code'] ?? '' );
			if ( '' !== $vid ) {
				$by_variant_id[ $vid ] = $variant;
			}
			if ( '' !== $vcode ) {
				$by_code[ $vcode ] = $variant;
			}
		}

		$changed = false;

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$svid  = (string) get_post_meta( (int) $variation_id, '_salesplay_variant_id', true );
			$scode = (string) get_post_meta( (int) $variation_id, '_pos_product_code', true );
			$sku   = (string) $variation->get_sku();

			$variant = $by_variant_id[ $svid ] ?? $by_code[ $scode ] ?? $by_code[ $sku ] ?? null;
			if ( ! is_array( $variant ) ) {
				continue;
			}

			$price = $this->extract_shop_price( $variant );
			if ( null === $price && isset( $variant['selling_price'] ) ) {
				$price = (float) $variant['selling_price'];
			}
			if ( null === $price ) {
				continue;
			}

			$old_price = (string) $variation->get_regular_price();

			$variation->set_regular_price( (string) $price );

			if ( isset( $variant['discounted_price'] ) && (float) $variant['discounted_price'] > 0 ) {
				$variation->set_sale_price( (string) $variant['discounted_price'] );
			} else {
				$variation->set_sale_price( '' );
			}

			$variation->save();
			$changed = true;

			// Log the per-variation price change (old → new) when it actually moved.
			$new_price = (string) $variation->get_regular_price();
			if ( $old_price !== $new_price ) {
				Sellmo_Logger::log(
					'product',
					'pos_to_wc',
					'success',
					sprintf(
						'Variation "%s" (WC #%d) price %s → %s.',
						'' !== $sku ? $sku : $svid,
						(int) $variation_id,
						'' !== $old_price ? $old_price : 'n/a',
						'' !== $new_price ? $new_price : 'n/a'
					),
					(string) ( $variant['product_code'] ?? '' )
				);
			}
		}

		// Refresh the parent's cached price range / lookup tables so the storefront
		// reflects the new variation prices.
		if ( $changed ) {
			WC_Product_Variable::sync( $product->get_id() );
		}
	}

	/**
	 * Return the price of the first Sellmo variant that carries one.
	 *
	 * @since  1.2.0
	 * @param  array<string, mixed> $sellmo_product Sellmo product payload.
	 * @return float|null
	 */
	private function first_variant_price( array $sellmo_product ): ?float {
		$variants = isset( $sellmo_product['variants'] ) && is_array( $sellmo_product['variants'] )
			? $sellmo_product['variants']
			: [];

		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}
			$price = $this->extract_shop_price( $variant );
			if ( null === $price && isset( $variant['selling_price'] ) ) {
				$price = (float) $variant['selling_price'];
			}
			if ( null !== $price ) {
				return $price;
			}
		}

		return null;
	}

	/**
	 * Webhook-triggered single-product sync.
	 *
	 * Pages through the Sellmo products API until the item with the given
	 * product code is found, then updates the matching WC product.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_code Sellmo product_code to look up.
	 * @return bool                        True if the product was updated successfully.
	 */
	public function sync_single( string $sellmo_product_code ): bool {
		if ( '' === $sellmo_product_code ) {
			return false;
		}

		$cursor = '';
		$page   = 0;

		do {
			$page++;
			$page_result = $this->request_products_page( $cursor, $page );

			if ( is_wp_error( $page_result ) ) {
				Sellmo_Logger::log(
					'product',
					'pos_to_wc',
					'error',
					$page_result->get_error_message(),
					$sellmo_product_code
				);
				return false;
			}

			$products = $page_result['products'];

			if ( empty( $products ) ) {
				break;
			}

			foreach ( $products as $sellmo_product ) {
				if ( (string) ( $sellmo_product['product_code'] ?? '' ) !== $sellmo_product_code ) {
					continue;
				}

				$mapping = Sellmo_Product_Mapping::get_mapping_for_sellmo_product( $sellmo_product );
				if ( is_array( $mapping ) ) {
					Sellmo_Logger::log(
						'product',
						'pos_to_wc',
						'skipped',
						sprintf(
							'Product skipped because mapped to WooCommerce product #%d',
							(int) ( $mapping['wc_product_id'] ?? 0 )
						),
						$sellmo_product_code
					);

					return true;
				}

				return $this->sync_or_create_product( $sellmo_product ) > 0;
			}

			$cursor = $page_result['cursor'];

		} while ( '' !== $cursor && $page < self::MAX_PAGES );

		Sellmo_Logger::log(
			'product',
			'pos_to_wc',
			'skipped',
			"No Sellmo product found for code '{$sellmo_product_code}'.",
			$sellmo_product_code
		);
		return false;
	}

	/**
	 * Push a Sellmo image URL onto an existing WooCommerce product.
	 *
	 * Used when a Sellmo product is mapped to a WooCommerce product so the
	 * mapped product receives the catalogue image. The remote image is
	 * sideloaded once (deduplicated by source URL) and set as the product's
	 * featured image.
	 *
	 * By default an existing featured image is preserved so a merchant's
	 * manually chosen image is never clobbered; pass $overwrite = true to
	 * force the Sellmo image even when one is already set.
	 *
	 * @since  1.0.0
	 * @param  int    $product_id WC product post ID to receive the image.
	 * @param  string $image_url  Remote Sellmo image URL.
	 * @param  bool   $overwrite  Replace an existing featured image when true.
	 * @return bool               True if the featured image was set.
	 */
	public function set_product_image_from_url( int $product_id, string $image_url, bool $overwrite = false ): bool {
		$image_url = trim( $image_url );

		if ( $product_id <= 0 || '' === $image_url ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		// Don't clobber an image the merchant already set unless asked to.
		if ( ! $overwrite && $product->get_image_id() > 0 ) {
			return false;
		}

		$image_id = $this->sideload_image( $image_url, $product_id );
		if ( $image_id <= 0 ) {
			return false;
		}

		$product->set_image_id( $image_id );
		$product->save();

		return true;
	}

	// =========================================================================
	// Core sync logic
	// =========================================================================

	/**
	 * Fetch all product pages from Sellmo and update matched WC products.
	 *
	 * Only updates products that already have a matching WC product
	 * (_pos_product_code meta) with _sellmo_sync_enabled = "yes".
	 * Applies the sellmo_wc_sync_categories filter when set.
	 *
	 * @since  1.0.0
	 * @return array{ success: bool, count: int, message: string }
	 */
	private function run_full_sync( bool $collect_trace ): array {
		$cursor    = '';
		$page      = 0;
		$synced    = 0;
		$skipped   = 0;
		$errors    = 0;
		$hit_limit = false;
		$trace     = [
			'started_at' => time(),
			'requests'   => [],
		];

		do {
			$page++;
			$page_result = $this->request_products_page( $cursor, $page );

			if ( is_wp_error( $page_result ) ) {
				$message = $page_result->get_error_message();
				$error   = $page_result->get_error_data();

				Sellmo_Logger::log( 'product', 'pos_to_wc', 'error', $message );

				Sellmo_Settings::set( 'last_product_sync', [
					'time'   => time(),
					'status' => 'error',
					'count'  => $synced,
				] );

				$result = [
					'success' => false,
					'count'   => $synced,
					'message' => $message,
					'error'   => is_array( $error ) ? $error : [],
				];

				if ( $collect_trace ) {
					$result['trace'] = $trace;
				}

				return $result;
			}

			if ( $collect_trace ) {
				$trace['requests'][] = $page_result['trace'];
			}

			$products = $page_result['products'];

			// An empty page signals end-of-data even when a cursor is present.
			if ( empty( $products ) ) {
				break;
			}

			foreach ( $products as $sellmo_product ) {
				$mapping = Sellmo_Product_Mapping::get_mapping_for_sellmo_product( $sellmo_product );
				if ( is_array( $mapping ) ) {
					$skipped++;
					Sellmo_Logger::log(
						'product',
						'pos_to_wc',
						'skipped',
						sprintf(
							'Product skipped because mapped to WooCommerce product #%d',
							(int) ( $mapping['wc_product_id'] ?? 0 )
						),
						(string) ( $sellmo_product['product_code'] ?? '' )
					);
					continue;
				}

				if ( ! $this->passes_category_filter( $sellmo_product ) ) {
					$skipped++;
					continue;
				}

				$code = (string) ( $sellmo_product['product_code'] ?? '' );

				if ( '' === $code ) {
					$skipped++;
					continue;
				}

				$product_id = $this->sync_or_create_product( $sellmo_product );

				if ( $product_id > 0 ) {
					$synced++;
					Sellmo_Logger::log(
						'product',
						'pos_to_wc',
						'success',
						'Product synced because no mapping exists',
						$code
					);
				} else {
					$errors++;

					if ( $collect_trace && ! empty( $this->last_error ) ) {
						$trace['failures'][] = $this->last_error;
					}
				}
			}

			$cursor = $page_result['cursor'];

			if ( $page >= self::MAX_PAGES && '' !== $cursor ) {
				$hit_limit = true;
				break;
			}

		} while ( '' !== $cursor );

		if ( $hit_limit ) {
			Sellmo_Logger::log(
				'product',
				'pos_to_wc',
				'skipped',
				sprintf( 'Safety limit reached after %d pages. Run sync again to continue.', self::MAX_PAGES )
			);
		}

		$status = ( $errors > 0 && 0 === $synced ) ? 'error' : 'success';

		Sellmo_Settings::set( 'last_product_sync', [
			'time'   => time(),
			'status' => $status,
			'count'  => $synced,
		] );

		Sellmo_Logger::log(
			'product',
			'pos_to_wc',
			$status,
			sprintf( '%d synced, %d skipped, %d errors.', $synced, $skipped, $errors )
		);

		$result = [
			'success' => 'success' === $status,
			'count'   => $synced,
			'message' => '',
		];

		if ( $errors > 0 ) {
			$result['error'] = [
				'message' => sprintf( '%d product sync failure(s) were detected.', $errors ),
				'count'   => $errors,
			];
		}

		if ( $collect_trace ) {
			$trace['summary'] = [
				'pages'   => $page,
				'synced'  => $synced,
				'skipped' => $skipped,
				'errors'  => $errors,
			];
			$result['trace'] = $trace;
		}

		return $result;
	}

	/**
	 * Update an existing WC product from a Sellmo product record.
	 *
	 * Applies name, description, prices, image, and — for variable products —
	 * syncs variations. Returns 'success', 'skipped', or 'error'.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $sellmo_product Decoded Sellmo product.
	 * @param  int                  $product_id     WC product post ID.
	 * @return string               'success' | 'skipped' | 'error'
	 */
	private function sync_or_create_product( array $sellmo_product ): int {
		$code        = (string) ( $sellmo_product['product_code'] ?? '' );
		$salesplay_id = (string) ( $sellmo_product['id'] ?? '' );
		$variants    = $this->filter_variants_for_parent(
			$sellmo_product,
			isset( $sellmo_product['variants'] ) && is_array( $sellmo_product['variants'] ) ? $sellmo_product['variants'] : []
		);
		$has_variants = ! empty( $variants );

		$product_id = $this->find_product_id_by_salesplay_id( $salesplay_id );
		if ( 0 === $product_id && '' !== $code ) {
			$product_id = $this->find_product_id_by_code( $code );
		}

		try {
			if ( $product_id > 0 ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					$this->last_error = [
						'message'      => "Failed to load WooCommerce product #{$product_id}.",
						'product_code' => $code,
						'salesplay_id' => $salesplay_id,
					];

					Sellmo_Logger::log(
						'product',
						'pos_to_wc',
						'error',
						"Sync failed for product '{$code}': could not load WooCommerce product #{$product_id}.",
						$code
					);

					return 0;
				}

				if ( $has_variants && ! $product instanceof WC_Product_Variable ) {
					$product = $this->convert_to_variable_product( $product );
				} elseif ( ! $has_variants && ! $product instanceof WC_Product_Simple ) {
					$product = $this->convert_to_simple_product( $product );
				}
			} else {
				$product = $has_variants ? new WC_Product_Variable() : new WC_Product_Simple();
			}

			$this->apply_core_fields( $product, $sellmo_product );

			if ( '' !== $salesplay_id ) {
				$product->update_meta_data( '_salesplay_pos_product_id', $salesplay_id );
			}

			if ( '' !== $code ) {
				$product->update_meta_data( '_pos_product_code', $code );
			}

			$product->update_meta_data( '_sellmo_sync_enabled', 'yes' );
			$product->update_meta_data( '_sellmo_last_synced', (string) time() );

			// Flag products the plugin itself created (not merely linked), so the
			// cleanup routine can delete them while leaving merchant-owned products
			// that were only mapped in place. $product_id is still the pre-save
			// lookup result here: 0 means this is a fresh create.
			if ( 0 === $product_id ) {
				$product->update_meta_data( '_sellmo_created', 'yes' );
			}

			$product_id = $product->save();
			if ( $product_id <= 0 ) {
				$this->last_error = [
					'message'      => 'WooCommerce refused to save the product.',
					'product_code' => $code,
					'salesplay_id' => $salesplay_id,
				];

				Sellmo_Logger::log(
					'product',
					'pos_to_wc',
					'error',
					"Sync failed for product '{$code}': WooCommerce refused to save the product.",
					$code
				);

				return 0;
			}

			$image_url = (string) ( $sellmo_product['image_url'] ?? $sellmo_product['product_image_url'] ?? '' );
			if ( '' !== $image_url ) {
				$image_id = $this->sideload_image( $image_url, $product_id );
				if ( $image_id > 0 ) {
					$product->set_image_id( $image_id );
					$product->save();
				}
			}

			if ( $has_variants && $product instanceof WC_Product_Variable ) {
				$this->sync_variations( $product, $variants );
			}

			Sellmo_Logger::log(
				'product',
				'pos_to_wc',
				'success',
				"Synced SalesPlay product '{$code}' to WC product #{$product_id}.",
				(string) $product_id
			);

			return $product_id;
		} catch ( \Throwable $e ) {
			$this->last_error = [
				'message'      => $e->getMessage(),
				'product_code' => $code,
				'salesplay_id' => $salesplay_id,
			];

			Sellmo_Logger::log(
				'product',
				'pos_to_wc',
				'error',
				"Sync failed for product '{$code}': " . $e->getMessage(),
				$code
			);

			return 0;
		}
	}

	/**
	 * Apply core scalar fields from a Sellmo product to a WC product object.
	 *
	 * Covers name, description, regular price, sale price, and SKU.
	 * Does NOT call save() — the caller is responsible for persisting.
	 *
	 * @since  1.0.0
	 * @param  WC_Product           $product        WC product to mutate.
	 * @param  array<string, mixed> $sellmo_product Decoded Sellmo product.
	 */
	private function apply_core_fields( WC_Product $product, array $sellmo_product ): void {
		if ( isset( $sellmo_product['product_name'] ) ) {
			$product->set_name( (string) $sellmo_product['product_name'] );
		}

		$this->apply_product_category( $product, $sellmo_product );

		if ( isset( $sellmo_product['product_description'] ) ) {
			$product->set_description( (string) $sellmo_product['product_description'] );
		}

		$price = $this->extract_shop_price( $sellmo_product );
		if ( null !== $price ) {
			$product->set_regular_price( (string) $price );
		} elseif ( isset( $sellmo_product['selling_price'] ) ) {
			$product->set_regular_price( (string) $sellmo_product['selling_price'] );
		}

		// Only write a sale price when discounted_price is explicitly > 0.
		if ( isset( $sellmo_product['discounted_price'] ) && (float) $sellmo_product['discounted_price'] > 0 ) {
			$product->set_sale_price( (string) $sellmo_product['discounted_price'] );
		} else {
			$product->set_sale_price( '' );
		}

		// Set SKU from product_code only when no SKU exists, to avoid clobbering
		// a SKU the merchant set manually. On import the product has no SKU yet.
		$code = (string) ( $sellmo_product['product_code'] ?? '' );
		if ( '' !== $code && '' === $product->get_sku() ) {
			try {
				$product->set_sku( $code );
			} catch ( \WC_Data_Exception $e ) {
				// SKU already used by another product — leave the existing SKU intact.
				Sellmo_Logger::log(
					'product',
					'pos_to_wc',
					'skipped',
					"SKU '{$code}' already exists in WooCommerce. Keeping existing SKU.",
					$code
				);
			}
		}

		$product->set_status( $this->is_available_for_sale( $sellmo_product ) ? 'publish' : 'private' );
	}

	/**
	 * Apply Sellmo category to the WooCommerce product.
	 *
	 * If category is missing or explicitly "No category", the product is assigned
	 * to WooCommerce's "Uncategorized" term.
	 *
	 * @since  1.0.0
	 * @param  WC_Product           $product        Product being synced.
	 * @param  array<string, mixed> $sellmo_product Sellmo product payload.
	 * @return void
	 */
	private function apply_product_category( WC_Product $product, array $sellmo_product ): void {
		$category_name = $this->resolve_sellmo_category_name( $sellmo_product );
		$term_id       = $this->ensure_product_category_term( $category_name );

		if ( $term_id > 0 ) {
			$product->set_category_ids( [ $term_id ] );
		}
	}

	/**
	 * Resolve the category name from Sellmo payload.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $sellmo_product Sellmo product payload.
	 * @return string
	 */
	private function resolve_sellmo_category_name( array $sellmo_product ): string {
		$raw_category = $sellmo_product['category'] ?? '';

		if ( is_array( $raw_category ) ) {
			$raw_category = (string) ( $raw_category['name'] ?? $raw_category['title'] ?? $raw_category['label'] ?? '' );
		}

		$category_name = sanitize_text_field( (string) $raw_category );

		if ( '' === $category_name || 0 === strcasecmp( $category_name, 'no category' ) ) {
			return 'Uncategorized';
		}

		return $category_name;
	}

	/**
	 * Ensure the WooCommerce product category term exists and return its ID.
	 *
	 * @since  1.0.0
	 * @param  string $category_name Category name.
	 * @return int
	 */
	private function ensure_product_category_term( string $category_name ): int {
		$category_name = sanitize_text_field( $category_name );

		if ( '' === $category_name ) {
			$category_name = 'Uncategorized';
		}

		if ( 0 === strcasecmp( $category_name, 'Uncategorized' ) ) {
			$default_uncategorized = (int) get_option( 'default_product_cat' );
			if ( $default_uncategorized > 0 ) {
				$term = get_term( $default_uncategorized, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					return (int) $term->term_id;
				}
			}
		}

		$existing = term_exists( $category_name, 'product_cat' );

		if ( false === $existing || null === $existing ) {
			$inserted = wp_insert_term( $category_name, 'product_cat' );
			if ( is_wp_error( $inserted ) ) {
				Sellmo_Logger::log(
					'product',
					'pos_to_wc',
					'error',
					sprintf( 'Failed to create product category "%s": %s', $category_name, $inserted->get_error_message() ),
					$category_name
				);

				return 0;
			}

			$new_term_id = (int) ( $inserted['term_id'] ?? 0 );

			// Flag categories the plugin created so cleanup can remove them without
			// touching categories the merchant made.
			if ( $new_term_id > 0 ) {
				add_term_meta( $new_term_id, '_sellmo_created', 'yes', true );
			}

			return $new_term_id;
		}

		if ( is_array( $existing ) ) {
			return (int) ( $existing['term_id'] ?? 0 );
		}

		return (int) $existing;
	}

	// =========================================================================
	// Variable product / variation handling
	// =========================================================================

	/**
	 * Create or update WC variations from a Sellmo variants list.
	 *
	 * First registers all attribute options at the product level (required by
	 * WooCommerce before variations can reference them), then creates or
	 * updates one WC_Product_Variation per Sellmo variant entry.
	 *
	 * @since  1.0.0
	 * @param  WC_Product_Variable              $product  Parent variable product.
	 * @param  array<int, array<string, mixed>> $variants Sellmo variants array.
	 */
	private function sync_variations( WC_Product_Variable $product, array $variants ): void {
		$option_labels     = $this->build_option_labels( $variants );
		$attribute_options = $this->collect_variant_attributes( $variants, $option_labels );
		$slug_map          = $this->register_product_attributes( $product, $attribute_options, $option_labels );
		$seen_codes        = [];

		foreach ( $variants as $variant ) {
			$code = (string) ( $variant['product_code'] ?? '' );
			if ( '' !== $code ) {
				$seen_codes[ $code ] = true;
			}

			$this->update_or_create_variation( $product, $variant, $slug_map );
		}

		$this->delete_stale_variations_by_code( $product->get_id(), array_keys( $seen_codes ) );

		$this->sync_parent_datastore( $product );
	}

	/**
	 * Build a map of attribute name → unique option values from variant data.
	 *
	 * Handles both associative option objects ({ "Color": "Red" }) and
	 * keyed arrays ([ { "name": "Color", "value": "Red" }, ... ]).
	 *
	 * @since  1.0.0
	 * @param  array<int, array<string, mixed>> $variants
	 * @return array<string, array<string>>     option_key => [option1, option2, ...]
	 */
	private function collect_variant_attributes( array $variants, array $option_labels ): array {
		$options = [];

		foreach ( $variants as $variant ) {
			$parsed = $this->parse_variant_options( $variant );

			foreach ( $parsed as $option_key => $attr_value ) {
				if ( '' === $option_key || '' === $attr_value ) {
					continue;
				}

				// Key by the raw option slot (option1_value, ...) so the slug map
				// built in register_product_attributes() lines up with the option
				// keys used by update_or_create_variation().
				if ( ! isset( $options[ $option_key ] ) ) {
					$options[ $option_key ] = [];
				}

				if ( ! in_array( $attr_value, $options[ $option_key ], true ) ) {
					$options[ $option_key ][] = $attr_value;
				}
			}
		}

		return $options;
	}

	/**
	 * Normalise a single variant's options into an associative name → value map.
	 *
	 * Sellmo may represent options as `{ "Color": "Red" }` or as
	 * `[ { "name": "Color", "value": "Red" }, ... ]`. Both are normalised to
	 * the flat associative form.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $variant
	 * @return array<string, string> attr_name => attr_value
	 */
	private function parse_variant_options( array $variant ): array {
		$values = [];

		foreach ( array_keys( self::OPTION_ATTRIBUTE_LABELS ) as $option_key ) {
			$value = (string) ( $variant[ $option_key ] ?? '' );
			if ( '' === trim( $value ) ) {
				continue;
			}

			$values[ $option_key ] = sanitize_text_field( $value );
		}

		if ( empty( $values ) ) {
			$fallback = $this->resolve_variant_fallback_label( $variant );
			if ( '' !== $fallback ) {
				$values['option1_value'] = $fallback;
			}
		}

		return $values;
	}

	/**
	 * Build a stable fallback label for variants that have no explicit options.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $variant Variant payload.
	 * @return string
	 */
	private function resolve_variant_fallback_label( array $variant ): string {
		$variant_id = sanitize_text_field( (string) ( $variant['variant_id'] ?? '' ) );
		if ( '' !== $variant_id ) {
			return 'Variant ' . $variant_id;
		}

		$product_code = sanitize_text_field( (string) ( $variant['product_code'] ?? '' ) );
		if ( '' !== $product_code ) {
			return $product_code;
		}

		return '';
	}

	/**
	 * Register local attributes on a variable product so variations can reference them.
	 *
	 * Uses local (non-taxonomy) attributes (set_id(0)) so no global WC attribute
	 * taxonomy registration is required. Merges new options into any options already
	 * present on the attribute, and preserves any attributes not touched by Sellmo.
	 *
	 * @since  1.0.0
	 * @param  WC_Product_Variable         $product          Variable product to modify.
	 * @param  array<string, array<string>> $attribute_options option_key => [option, ...]
	 * @return array<string, string>                          option_key => slug map.
	 */
	private function register_product_attributes( WC_Product_Variable $product, array $attribute_options, array $option_labels ): array {
		$existing  = $product->get_attributes();
		$new_attrs = [];
		$slug_map  = [];

		foreach ( $attribute_options as $option_key => $option_values ) {
			$label = $option_labels[ $option_key ] ?? ( self::OPTION_ATTRIBUTE_LABELS[ $option_key ] ?? $option_key );
			$slug  = sanitize_title( $label );

			$attribute = $existing[ $slug ] ?? new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $label );
			$attribute->set_options( array_values( array_unique( $option_values ) ) );
			$attribute->set_position( count( $new_attrs ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );

			$new_attrs[ $slug ] = $attribute;
			$slug_map[ $option_key ] = $slug;
		}

		// Preserve existing attributes that Sellmo did not touch.
		foreach ( $existing as $ex_slug => $ex_attr ) {
			if ( ! isset( $new_attrs[ $ex_slug ] ) ) {
				$new_attrs[ $ex_slug ] = $ex_attr;
			}
		}

		$product->set_attributes( $new_attrs );
		$product->save();

		return $slug_map;
	}

	/**
	 * Build resolved attribute labels for option1/2/3 slots.
	 *
	 * @since  1.0.0
	 * @param  array<int, array<string, mixed>> $variants Variant payload list.
	 * @return array<string, string>
	 */
	private function build_option_labels( array $variants ): array {
		$labels = [];

		foreach ( self::OPTION_ATTRIBUTE_LABELS as $option_key => $default_label ) {
			$label = '';

			$label_key = str_replace( '_value', '_name', $option_key );
			foreach ( $variants as $variant ) {
				$candidate = sanitize_text_field( (string) ( $variant[ $label_key ] ?? '' ) );
				if ( '' === $candidate ) {
					continue;
				}

				$label = $candidate;
				break;
			}

			$labels[ $option_key ] = $this->normalise_label( '' !== $label ? $label : $default_label );
		}

		return $labels;
	}

	/**
	 * Create or update a single WC product variation from a Sellmo variant.
	 *
	 * Looks up an existing variation by Sellmo product_code. If none is found,
	 * a new WC_Product_Variation is created under the parent product.
	 *
	 * @since  1.0.0
	 * @param  WC_Product_Variable  $product       Parent variable product.
	 * @param  array<string, mixed> $variant       Sellmo variant object.
	 * @param  array<string, string> $slug_map     option_key => local attribute slug.
	 */
	private function update_or_create_variation(
		WC_Product_Variable $product,
		array $variant,
		array $slug_map
	): void {
		$variant_code = (string) ( $variant['product_code'] ?? '' );
		$variant_id   = (string) ( $variant['variant_id'] ?? '' );

		$variation_id = ( '' !== $variant_id )
			? $this->find_variation_id_by_salesplay_variant_id( $product->get_id(), $variant_id )
			: 0;

		if ( 0 === $variation_id && '' !== $variant_code ) {
			$variation_id = $this->find_variation_id_by_code_for_parent( $product->get_id(), $variant_code );
		}

		if ( 0 === $variation_id && '' !== $variant_code ) {
			$variation_id = $this->find_variation_id_by_sku_for_parent( $product->get_id(), $variant_code );
		}

		if ( $variation_id > 0 ) {
			$variation = new WC_Product_Variation( $variation_id );
		} else {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
		}

		$price = $this->extract_shop_price( $variant );
		if ( null !== $price ) {
			$variation->set_regular_price( (string) $price );
		} elseif ( isset( $variant['selling_price'] ) ) {
			$variation->set_regular_price( (string) $variant['selling_price'] );
		}

		if ( isset( $variant['discounted_price'] ) && (float) $variant['discounted_price'] > 0 ) {
			$variation->set_sale_price( (string) $variant['discounted_price'] );
		} else {
			$variation->set_sale_price( '' );
		}

		// Build the attribute map that the variation row requires.
		// WC_Product_Variation::set_attributes() expects [ slug => option_value ].
		$variation_attributes = [];
		$parsed_options       = $this->parse_variant_options( $variant );

		if ( ! empty( $parsed_options ) ) {
			foreach ( $parsed_options as $option_key => $attr_value ) {
				if ( ! isset( $slug_map[ $option_key ] ) ) {
					continue;
				}

				$variation_attributes[ $slug_map[ $option_key ] ] = $attr_value;
			}
		}

		$variation->set_attributes( $variation_attributes );
		$variation->set_status( $this->is_available_for_sale( $variant ) ? 'publish' : 'private' );
		$variation->set_manage_stock( true );

		$stock_quantity = $this->extract_variant_stock_quantity( $variant );
		if ( null !== $stock_quantity ) {
			$variation->set_stock_quantity( $stock_quantity );
			$variation->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
		}

		if ( '' !== $variant_code ) {
			try {
				$variation->set_sku( $variant_code );
			} catch ( \WC_Data_Exception $e ) {
				// Keep current SKU if a conflict exists.
			}
		}

		$barcode = (string) ( $variant['product_barcode'] ?? '' );
		if ( '' !== $barcode ) {
			$variation->update_meta_data( '_sku_barcode', $barcode );
		}

		if ( isset( $variant['default_cost'] ) ) {
			$variation->update_meta_data( '_salesplay_default_cost', (string) $variant['default_cost'] );
		}

		$variation->update_meta_data( '_sellmo_sync_enabled', 'yes' );
		$variation->update_meta_data( '_sellmo_last_synced', (string) time() );
		$variation->update_meta_data( '_salesplay_variant_id', (string) ( $variant['variant_id'] ?? '' ) );

		$saved_id = $variation->save();

		if ( $saved_id <= 0 ) {
			throw new \RuntimeException(
				sprintf(
					'WooCommerce refused to save variation for product %s%s',
					$variant_code !== '' ? $variant_code . ' ' : '',
					$variant_id !== '' ? '(variant ' . $variant_id . ')' : ''
				)
			);
		}

		if ( $saved_id && '' !== $variant_code ) {
			update_post_meta( $saved_id, '_pos_product_code', $variant_code );
		}

		if ( $saved_id > 0 ) {
			update_post_meta( $saved_id, '_salesplay_variant_id', $variant_id );
		}
	}

	/**
	 * Sync the parent variable product datastore so WooCommerce recognizes new variations.
	 *
	 * @since  1.0.0
	 * @param  WC_Product_Variable $product Parent variable product.
	 * @return void
	 */
	private function sync_parent_datastore( WC_Product_Variable $product ): void {
		$parent_id = $product->get_id();
		$data_store = $product->get_data_store();

		if ( is_object( $data_store ) ) {
			if ( method_exists( $data_store, 'sync_managed_variations' ) ) {
				$data_store->sync_managed_variations( $product );
			}

			if ( method_exists( $data_store, 'sync_price' ) ) {
				$data_store->sync_price( $product );
			}
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $parent_id );
		}

		WC_Product_Variable::sync( $parent_id );
	}

	/**
	 * Build a SalesPlay page request using GET with JSON payload body.
	 *
	 * @since  1.0.0
	 * @param  string $cursor Cursor value.
	 * @param  int    $page   Page index.
	 * @return array{ products: array<int, array<string, mixed>>, cursor: string, trace: array<string, mixed> }|WP_Error
	 */
	private function request_products_page( string $cursor, int $page ): array|\WP_Error {
		$token = trim( (string) Sellmo_Settings::get( 'api_token' ) );
		if ( '' === $token ) {
			return new \WP_Error( 'salesplay_missing_token', 'SalesPlay API token is not configured.' );
		}

		$payload = [
			'product_ids'     => '',
			'created_at_min'  => '',
			'created_at_max'  => '',
			'limit'           => self::PAGE_LIMIT,
			'cursor'          => $cursor,
		];

		$request_url = add_query_arg( $payload, self::PRODUCTS_ENDPOINT );

		$response = wp_remote_request(
			$request_url,
			[
				'method'  => 'GET',
				'timeout' => 20,
				'headers' => [
					'Token'        => 'Bearer ' . $token,
					'Content-Type' => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'salesplay_request_error',
				$response->get_error_message(),
				[
					'page'            => $page,
					'cursor'          => $cursor,
					'request_payload' => $payload,
				]
			);
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			return new \WP_Error(
				'salesplay_http_status_error',
				sprintf( 'SalesPlay API returned HTTP %d.', $status ),
				[
					'page'            => $page,
					'cursor'          => $cursor,
					'http_status'     => $status,
					'request_payload' => $payload,
					'response_body'   => $raw_body,
				]
			);
		}

		$decoded = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'salesplay_invalid_json',
				'Invalid JSON returned by SalesPlay API.',
				[
					'page'            => $page,
					'cursor'          => $cursor,
					'request_payload' => $payload,
					'response_body'   => $raw_body,
				]
			);
		}

		$products = $this->extract_products( $decoded );
		$next     = $this->extract_next_cursor( $decoded );

		return [
			'products' => $products,
			'cursor'   => $next,
			'trace'    => [
				'page'              => $page,
				'request_payload'   => $payload,
				'http_status'       => $status,
				'response_cursor'   => $next,
				'products_count'    => count( $products ),
				'sample_product_ids' => array_values( array_slice( array_map( static fn( array $p ): string => (string) ( $p['product_code'] ?? '' ), $products ), 0, 3 ) ),
			],
		];
	}

	/**
	 * Convert product to variable type and reload.
	 *
	 * @since  1.0.0
	 * @param  WC_Product $product Existing product.
	 * @return WC_Product_Variable
	 */
	private function convert_to_variable_product( WC_Product $product ): WC_Product_Variable {
		$product_id = $product->get_id();
		wp_set_object_terms( $product_id, 'variable', 'product_type' );

		$converted = wc_get_product( $product_id );
		if ( ! $converted instanceof WC_Product_Variable ) {
			throw new \RuntimeException( 'Failed to convert product to variable type.' );
		}

		return $converted;
	}

	/**
	 * Convert product to simple type and reload.
	 *
	 * @since  1.0.0
	 * @param  WC_Product $product Existing product.
	 * @return WC_Product_Simple
	 */
	private function convert_to_simple_product( WC_Product $product ): WC_Product_Simple {
		$product_id = $product->get_id();
		wp_set_object_terms( $product_id, 'simple', 'product_type' );

		$converted = wc_get_product( $product_id );
		if ( ! $converted instanceof WC_Product_Simple ) {
			throw new \RuntimeException( 'Failed to convert product to simple type.' );
		}

		return $converted;
	}

	/**
	 * Ensure only variants belonging to the current parent are processed.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed>              $sellmo_product Parent payload.
	 * @param  array<int, array<string, mixed>>  $variants       Raw variants.
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_variants_for_parent( array $sellmo_product, array $variants ): array {
		$parent_code = (string) ( $sellmo_product['product_code'] ?? '' );
		$parent_id   = (string) ( $sellmo_product['id'] ?? '' );
		if ( '' === $parent_code ) {
			return $variants;
		}

		$filtered = [];
		foreach ( $variants as $variant ) {
			$variant_parent = (string) ( $variant['variant_product_code'] ?? '' );
			$variant_parent_id = (string) ( $variant['variant_product_id'] ?? $variant['parent_product_id'] ?? $variant['parent_id'] ?? $variant['product_id'] ?? '' );
			$variant_code   = (string) ( $variant['product_code'] ?? '' );

			if ( '' !== $parent_id && '' !== $variant_parent_id && $variant_parent_id !== $parent_id ) {
				continue;
			}

			if ( '' !== $variant_parent && $variant_parent !== $parent_code ) {
				continue;
			}

			if ( '' === $variant_parent && '' !== $variant_code && 0 !== strpos( $variant_code, $parent_code . '-' ) ) {
				continue;
			}

			$filtered[] = $variant;
		}

		return $filtered;
	}

	/**
	 * Normalise attribute labels.
	 *
	 * @since  1.0.0
	 * @param  string $label Raw label.
	 * @return string
	 */
	private function normalise_label( string $label ): string {
		$label = trim( $label );

		if ( '' === $label ) {
			return 'Option';
		}

		return ucwords( strtolower( $label ) );
	}

	/**
	 * Find parent product by SalesPlay encoded ID meta.
	 *
	 * @since  1.0.0
	 * @param  string $salesplay_id Encoded SalesPlay product id.
	 * @return int
	 */
	private function find_product_id_by_salesplay_id( string $salesplay_id ): int {
		if ( '' === $salesplay_id ) {
			return 0;
		}

		$query = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'private', 'draft' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => '_salesplay_pos_product_id',
					'value' => $salesplay_id,
				],
			],
		] );

		return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find variation by Sellmo code under a specific parent.
	 *
	 * @since  1.0.0
	 * @param  int    $parent_id Parent product id.
	 * @param  string $code      Variant code.
	 * @return int
	 */
	private function find_variation_id_by_code_for_parent( int $parent_id, string $code ): int {
		if ( $parent_id <= 0 || '' === $code ) {
			return 0;
		}

		$query = new WP_Query( [
			'post_type'      => 'product_variation',
			'post_status'    => [ 'publish', 'private' ],
			'post_parent'    => $parent_id,
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

		return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find variation by SKU under a specific parent.
	 *
	 * @since  1.0.0
	 * @param  int    $parent_id Parent product id.
	 * @param  string $sku       Variant SKU.
	 * @return int
	 */
	private function find_variation_id_by_sku_for_parent( int $parent_id, string $sku ): int {
		if ( $parent_id <= 0 || '' === $sku ) {
			return 0;
		}

		$variation_id = (int) wc_get_product_id_by_sku( $sku );
		if ( $variation_id <= 0 ) {
			return 0;
		}

		if ( 'product_variation' !== get_post_type( $variation_id ) ) {
			return 0;
		}

		return (int) wp_get_post_parent_id( $variation_id ) === $parent_id ? $variation_id : 0;
	}

	/**
	 * Find variation by SalesPlay variant id under a specific parent.
	 *
	 * @since  1.0.0
	 * @param  int    $parent_id Parent product id.
	 * @param  string $variant_id SalesPlay variant id.
	 * @return int
	 */
	private function find_variation_id_by_salesplay_variant_id( int $parent_id, string $variant_id ): int {
		if ( $parent_id <= 0 || '' === $variant_id ) {
			return 0;
		}

		$query = new WP_Query( [
			'post_type'      => 'product_variation',
			'post_status'    => [ 'publish', 'private' ],
			'post_parent'    => $parent_id,
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
	 * Delete stale variations absent from the latest API payload.
	 *
	 * @since  1.0.0
	 * @param  int               $parent_id Parent product id.
	 * @param  array<int,string> $valid_codes Valid variant codes.
	 * @return void
	 */
	private function delete_stale_variations_by_code( int $parent_id, array $valid_codes ): void {
		$children = get_posts( [
			'post_type'      => 'product_variation',
			'post_status'    => [ 'publish', 'private' ],
			'post_parent'    => $parent_id,
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		] );

		$valid_lookup = array_fill_keys( array_map( 'strval', $valid_codes ), true );

		foreach ( $children as $variation_id ) {
			$code = (string) get_post_meta( (int) $variation_id, '_pos_product_code', true );
			if ( '' === $code ) {
				continue;
			}

			if ( ! isset( $valid_lookup[ $code ] ) ) {
				wp_delete_post( (int) $variation_id, true );
			}
		}
	}

	/**
	 * Extract matching shop price from product/variant shops array.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $item Product or variant payload.
	 * @return float|null
	 */
	private function extract_shop_price( array $item ): ?float {
		$shops = isset( $item['shops'] ) && is_array( $item['shops'] ) ? $item['shops'] : [];
		if ( empty( $shops ) ) {
			return null;
		}

		$configured_shop = (string) ( Sellmo_Settings::get( 'shop_id' ) ?: '' );
		if ( '' !== $configured_shop ) {
			foreach ( $shops as $shop ) {
				if ( is_array( $shop ) && (string) ( $shop['shop_id'] ?? '' ) === $configured_shop ) {
					return isset( $shop['price'] ) ? (float) $shop['price'] : null;
				}
			}
		}

		$first = reset( $shops );

		return is_array( $first ) && isset( $first['price'] ) ? (float) $first['price'] : null;
	}

	/**
	 * Extract stock quantity from the primary operational shop.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $variant Variant payload.
	 * @return int|null
	 */
	private function extract_variant_stock_quantity( array $variant ): ?int {
		$shops = isset( $variant['shops'] ) && is_array( $variant['shops'] ) ? $variant['shops'] : [];
		if ( empty( $shops ) ) {
			return null;
		}

		$primary_shop_id = (string) ( Sellmo_Settings::get( 'shop_id' ) ?: self::DEFAULT_PRIMARY_SHOP_ID );

		foreach ( $shops as $shop ) {
			if ( ! is_array( $shop ) ) {
				continue;
			}

			if ( (string) ( $shop['shop_id'] ?? '' ) !== $primary_shop_id ) {
				continue;
			}

			foreach ( [ 'stock_quantity', 'quantity', 'available_quantity', 'inventory_quantity' ] as $key ) {
				if ( isset( $shop[ $key ] ) && is_numeric( $shop[ $key ] ) ) {
					return (int) $shop[ $key ];
				}
			}
		}

		return null;
	}

	/**
	 * Determine sale availability from shops[].available_for_sale.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $item Product or variant payload.
	 * @return bool
	 */
	private function is_available_for_sale( array $item ): bool {
		$shops = isset( $item['shops'] ) && is_array( $item['shops'] ) ? $item['shops'] : [];
		if ( empty( $shops ) ) {
			return true;
		}

		foreach ( $shops as $shop ) {
			if ( ! is_array( $shop ) ) {
				continue;
			}

			if ( array_key_exists( 'available_for_sale', $shop ) ) {
				return (bool) $shop['available_for_sale'];
			}
		}

		return true;
	}

	// =========================================================================
	// Image handling
	// =========================================================================

	/**
	 * Sideload a remote image into the WP media library.
	 *
	 * Checks for an existing attachment with a matching _sellmo_image_source_url
	 * meta value before downloading, so the same remote URL is never imported
	 * twice. Requires wp-admin/includes/media.php, file.php, and image.php,
	 * which are loaded on-demand and safe to call outside wp-admin context.
	 *
	 * @since  1.0.0
	 * @param  string $url        Remote image URL from Sellmo.
	 * @param  int    $product_id WC product post ID used as the attachment parent.
	 * @return int                Attachment post ID, or 0 on failure.
	 */
	private function sideload_image( string $url, int $product_id ): int {
		if ( '' === $url ) {
			return 0;
		}

		$existing = $this->find_attachment_by_source_url( $url );
		if ( $existing > 0 ) {
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$result = media_sideload_image( $url, $product_id, null, 'id' );

		if ( is_wp_error( $result ) ) {
			Sellmo_Logger::log(
				'product',
				'pos_to_wc',
				'error',
				'Image sideload failed for product #' . $product_id . ': ' . $result->get_error_message(),
				(string) $product_id
			);
			return 0;
		}

		$attachment_id = (int) $result;

		// Store the source URL so future syncs can find this attachment without
		// re-downloading, even if the attachment title or filename changes.
		update_post_meta( $attachment_id, '_sellmo_image_source_url', $url );

		// Bust the object cache entry so the next find_attachment_by_source_url
		// call returns the newly imported attachment.
		wp_cache_delete( 'img_' . md5( $url ), self::CACHE_GROUP );

		return $attachment_id;
	}

	/**
	 * Find an existing media library attachment by its Sellmo source URL.
	 *
	 * Results are cached in the WP object cache for 5 minutes.
	 *
	 * @since  1.0.0
	 * @param  string $url Remote image URL.
	 * @return int         Attachment post ID, or 0 if not found.
	 */
	private function find_attachment_by_source_url( string $url ): int {
		$cache_key = 'img_' . md5( $url );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$query = new WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => '_sellmo_image_source_url',
					'value' => $url,
				],
			],
		] );

		$id = ! empty( $query->posts ) ? (int) $query->posts[0] : 0;

		wp_cache_set( $cache_key, $id, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $id;
	}

	// =========================================================================
	// Lookup helpers
	// =========================================================================

	/**
	 * Find a WC product or variation post ID by its Sellmo product code.
	 *
	 * Searches both 'product' and 'product_variation' post types. Results are
	 * cached in the WP object cache for 5 minutes.
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
	 * Check whether sync is enabled for a given product or variation.
	 *
	 * For variations with no direct _sellmo_sync_enabled meta, falls back to
	 * the parent product's setting.
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

		if ( 'product_variation' === get_post_type( $product_id ) ) {
			$parent_id = (int) wp_get_post_parent_id( $product_id );
			if ( $parent_id > 0 ) {
				return 'yes' === (string) get_post_meta( $parent_id, '_sellmo_sync_enabled', true );
			}
		}

		return false;
	}

	/**
	 * Determine whether a Sellmo product passes the category filter.
	 *
	 * When sellmo_wc_sync_categories is empty, all products are allowed.
	 * Products with no category set on the Sellmo side are also allowed
	 * through rather than silently dropped.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $sellmo_product
	 * @return bool
	 */
	private function passes_category_filter( array $sellmo_product ): bool {
		$allowed = (array) Sellmo_Settings::get( 'sync_categories' );

		if ( empty( $allowed ) ) {
			return true;
		}

		// Sellmo may embed the category ID directly or under a nested object.
		$cat_id = (string) (
			$sellmo_product['category_id'] ??
			( is_array( $sellmo_product['category'] ?? null ) ? ( $sellmo_product['category']['id'] ?? '' ) : '' )
		);

		// Products with no category are not excluded.
		if ( '' === $cat_id ) {
			return true;
		}

		return in_array( $cat_id, array_map( 'strval', $allowed ), true );
	}

	// =========================================================================
	// API response parsing helpers
	// =========================================================================

	/**
	 * Extract the products array from a Sellmo API response.
	 *
	 * Handles both `{ "data": [...] }` and `{ "products": [...] }` shapes.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $response Decoded API response.
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
	 * Extract the pagination cursor from a Sellmo API response.
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

		if ( ! empty( $response['meta']['cursor'] ) && is_string( $response['meta']['cursor'] ) ) {
			return $response['meta']['cursor'];
		}

		return '';
	}
}
