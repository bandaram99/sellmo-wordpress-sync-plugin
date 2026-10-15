<?php
/**
 * Auto map: link unmapped POS products to existing WooCommerce products by SKU.
 *
 * Runs at the end of "Sync Products Now" (and the scheduled product sync) when
 * the Auto map setting is on. A POS product_code is linked to the WooCommerce
 * product/variation whose SKU equals it — but only when the match is
 * unambiguous on BOTH sides: exactly one POS item carries the code and exactly
 * one WooCommerce item carries the SKU. Anything else is left for the merchant
 * to map by hand.
 *
 *   - Simple POS product   → a WooCommerce simple product.
 *   - Variable POS product → EVERY in-shop variant must match a variation, all
 *                            under one variable parent; then the parent and all
 *                            variants are linked. A partial match maps nothing.
 *
 * Linking is the same as the manual "Map" button: the mapper row, the variant
 * store and the join-key meta are written. Name, description, price and SKU of
 * the WooCommerce product are not touched here.
 *
 * @package Sellmo_WooCommerce
 * @since   1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Auto_Mapper
 *
 * @since 1.7.0
 */
class Sellmo_Auto_Mapper {

	/**
	 * Max ambiguous codes listed in the summary log line.
	 *
	 * ponytail: a flat cap keeps a catalogue full of duplicate SKUs from flooding
	 * the Sync Log on every sync. Raise it if merchants need the full list.
	 */
	private const MAX_LOGGED = 20;

	/** @var array<string, array<int, array<string, mixed>>> lower(SKU) → WooCommerce items. */
	private array $skus = [];

	/** @var array<string, int> lower(POS code) → how many POS items carry it. */
	private array $codes = [];

	/** @var array<int, bool> WooCommerce ids already linked to a POS item. */
	private array $linked = [];

	/** @var array<int, string> Codes skipped because they matched more than one item. */
	private array $ambiguous = [];

	/**
	 * Auto-map every unmapped, in-shop POS product that has an unambiguous match.
	 *
	 * @since  1.7.0
	 * @return array{ mapped: int, ambiguous: int }
	 */
	public function run(): array {
		$this->build_indexes();

		$mapped = 0;
		$sync   = new Sellmo_Product_Sync();

		foreach ( $this->unmapped_rows() as $row ) {
			if ( ! Sellmo_Product_Mapping::is_in_selected_shop( (string) ( $row['shop_id'] ?? '' ) ) ) {
				continue;
			}

			$payload = json_decode( (string) ( $row['raw_payload'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$variants = isset( $payload['variants'] ) && is_array( $payload['variants'] ) ? $payload['variants'] : [];

			$ok = empty( $variants )
				? $this->map_simple( $row, $payload, $sync )
				: $this->map_variable( $row, $payload, $variants, $sync );

			if ( $ok ) {
				$mapped++;
			}
		}

		if ( ! empty( $this->ambiguous ) ) {
			$list = array_unique( $this->ambiguous );
			Sellmo_Logger::log(
				'product',
				'pos_to_wc',
				'skipped',
				sprintf(
					'Auto map skipped %d product(s) — code/SKU matched more than one item: %s.',
					count( $list ),
					implode( ', ', array_slice( $list, 0, self::MAX_LOGGED ) ) . ( count( $list ) > self::MAX_LOGGED ? ', …' : '' )
				)
			);
		}

		return [
			'mapped'    => $mapped,
			'ambiguous' => count( array_unique( $this->ambiguous ) ),
		];
	}

	/**
	 * Simple POS product → WooCommerce simple product with SKU == product_code.
	 *
	 * @param  array<string, mixed> $row     Mapper row.
	 * @param  array<string, mixed> $payload POS product payload.
	 * @param  Sellmo_Product_Sync  $sync    Product sync (for the meta stamping).
	 * @return bool                          True when mapped.
	 */
	private function map_simple( array $row, array $payload, Sellmo_Product_Sync $sync ): bool {
		$code  = (string) ( $row['pos_product_code'] ?? '' );
		$match = $this->unique_match( $code );
		if ( null === $match || 'product' !== $match['type'] || isset( $this->linked[ $match['id'] ] ) ) {
			return false;
		}

		$product = wc_get_product( $match['id'] );
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) ) {
			return false;
		}

		return $this->link_parent( $row, $payload, $match['id'], $sync );
	}

	/**
	 * Variable POS product → one WooCommerce variable product, every in-shop
	 * variant matched to one of its variations by SKU.
	 *
	 * @param  array<string, mixed>             $row      Mapper row.
	 * @param  array<string, mixed>             $payload  POS product payload.
	 * @param  array<int, array<string, mixed>> $variants POS variants.
	 * @param  Sellmo_Product_Sync              $sync     Product sync.
	 * @return bool                                       True when mapped.
	 */
	private function map_variable( array $row, array $payload, array $variants, Sellmo_Product_Sync $sync ): bool {
		$pairs     = []; // variant_id => WooCommerce variation id.
		$parent_id = 0;

		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) || ! $sync->variant_in_selected_shop( $variant ) ) {
				continue;
			}

			$variant_id = (string) ( $variant['variant_id'] ?? '' );
			$match      = $this->unique_match( (string) ( $variant['product_code'] ?? '' ) );

			// Every variant must match — a partial match maps nothing.
			if ( '' === $variant_id || null === $match || 'product_variation' !== $match['type'] || isset( $this->linked[ $match['id'] ] ) ) {
				return false;
			}

			// All variations must hang off the same WooCommerce parent.
			if ( 0 !== $parent_id && $parent_id !== $match['parent'] ) {
				$this->ambiguous[] = (string) ( $row['pos_product_code'] ?? '' );
				return false;
			}

			$parent_id            = $match['parent'];
			$pairs[ $variant_id ] = $match['id'];
		}

		if ( $parent_id <= 0 || isset( $this->linked[ $parent_id ] ) ) {
			return false;
		}

		// If the parent code is also a SKU, it must name the same parent.
		$parent_code = strtolower( trim( (string) ( $row['pos_product_code'] ?? '' ) ) );
		$by_sku      = $this->skus[ $parent_code ] ?? [];
		if ( '' !== $parent_code && ! empty( $by_sku ) && ( count( $by_sku ) > 1 || $by_sku[0]['id'] !== $parent_id ) ) {
			$this->ambiguous[] = (string) $row['pos_product_code'];
			return false;
		}

		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof WC_Product || ! $parent->is_type( 'variable' ) ) {
			return false;
		}

		if ( ! $this->link_parent( $row, $payload, $parent_id, $sync ) ) {
			return false;
		}

		foreach ( $pairs as $variant_id => $variation_id ) {
			Sellmo_Product_Variant_Store::set_wc_variation_id( (string) $variant_id, $variation_id );
			update_post_meta( $variation_id, '_salesplay_variant_id', (string) $variant_id );
			$this->linked[ $variation_id ] = true;
		}

		return true;
	}

	/**
	 * Link a POS product to a WooCommerce product, as the manual Map button does.
	 *
	 * @param  array<string, mixed> $row     Mapper row.
	 * @param  array<string, mixed> $payload POS product payload.
	 * @param  int                  $wc_id   WooCommerce product id.
	 * @param  Sellmo_Product_Sync  $sync    Product sync.
	 * @return bool
	 */
	private function link_parent( array $row, array $payload, int $wc_id, Sellmo_Product_Sync $sync ): bool {
		$code = (string) ( $row['pos_product_code'] ?? '' );

		if ( ! Sellmo_Product_Mapping::upsert_mapping( (string) $row['pos_product_id'], $code, (string) ( $row['pos_product_name'] ?? '' ), $wc_id ) ) {
			return false;
		}

		// Join-key meta (product + SKU-matched variations) for the stock/order syncs.
		$sync->link_wc_product_meta( $wc_id, $payload );
		$this->linked[ $wc_id ] = true;

		Sellmo_Logger::log(
			'product',
			'pos_to_wc',
			'success',
			sprintf( 'Auto-mapped "%s" to WooCommerce product #%d by SKU.', (string) ( $row['pos_product_name'] ?? '' ), $wc_id ),
			$code
		);

		return true;
	}

	/**
	 * The single WooCommerce item whose SKU equals $code, when exactly one POS item
	 * and exactly one WooCommerce item carry it. Records ambiguous codes.
	 *
	 * @param  string $code POS product code.
	 * @return array{ id: int, type: string, parent: int }|null
	 */
	private function unique_match( string $code ): ?array {
		$key = strtolower( trim( $code ) );
		if ( '' === $key || empty( $this->skus[ $key ] ) ) {
			return null;
		}

		if ( 1 !== ( $this->codes[ $key ] ?? 0 ) || 1 !== count( $this->skus[ $key ] ) ) {
			$this->ambiguous[] = $code;
			return null;
		}

		return $this->skus[ $key ][0];
	}

	/**
	 * Load the SKU index, the POS code counts and the already-linked ids.
	 *
	 * Comparison is trimmed and case-insensitive, like WooCommerce's SKU lookup.
	 *
	 * @return void
	 */
	private function build_indexes(): void {
		global $wpdb;

		$map_table     = Sellmo_Product_Mapping::table_name();
		$variant_table = Sellmo_Product_Variant_Store::table_name();

		// Every non-trashed product/variation with a SKU — mapped ones included, so
		// a SKU shared with a mapped product still counts as a duplicate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT p.ID AS id, p.post_type AS type, p.post_parent AS parent, pm.meta_value AS sku
			 FROM `{$wpdb->postmeta}` pm
			 INNER JOIN `{$wpdb->posts}` p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_sku' AND pm.meta_value <> ''
			   AND p.post_type IN ( 'product', 'product_variation' )
			   AND p.post_status NOT IN ( 'trash', 'auto-draft' )",
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$this->skus[ strtolower( trim( (string) $r['sku'] ) ) ][] = [
				'id'     => (int) $r['id'],
				'type'   => (string) $r['type'],
				'parent' => (int) $r['parent'],
			];
		}

		// Every POS code: product rows (simple + variable parents) and variants.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$codes = array_merge(
			(array) $wpdb->get_col( "SELECT pos_product_code FROM `{$map_table}` WHERE pos_product_code <> ''" ),
			(array) $wpdb->get_col( "SELECT product_code FROM `{$variant_table}` WHERE product_code <> ''" )
		);
		foreach ( $codes as $code ) {
			$key                 = strtolower( trim( (string) $code ) );
			$this->codes[ $key ] = ( $this->codes[ $key ] ?? 0 ) + 1;
		}

		// ponytail: "linked" = recorded in the mapper/variant tables. Leftover join
		// meta from a manual unmap does not block auto map — the link rewrites it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$linked = array_merge(
			(array) $wpdb->get_col( "SELECT wc_product_id FROM `{$map_table}` WHERE map_status = 1 AND wc_product_id IS NOT NULL" ),
			(array) $wpdb->get_col( "SELECT wc_variation_id FROM `{$variant_table}` WHERE wc_variation_id IS NOT NULL" )
		);
		foreach ( $linked as $id ) {
			$this->linked[ (int) $id ] = true;
		}
	}

	/**
	 * Unmapped mapper rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function unmapped_rows(): array {
		global $wpdb;
		$table = Sellmo_Product_Mapping::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			"SELECT pos_product_id, pos_product_code, pos_product_name, shop_id, raw_payload
			 FROM `{$table}` WHERE map_status = 0 AND pos_product_id <> ''",
			ARRAY_A
		);
	}
}
