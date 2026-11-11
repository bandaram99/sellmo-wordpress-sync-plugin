<?php
declare(strict_types=1);

/**
 * Destructive catalogue cleanup for Sellmo POS Integration.
 *
 * Shared by the token-removal purge (Sellmo_Admin::purge_sellmo_synced_data)
 * and the full uninstall routine (uninstall.php) so both remove the same
 * footprint. Every WooCommerce product carrying the plugin's meta is either
 * deleted or kept and detached:
 *
 *   - "Also delete linked products" ticked ($delete_linked): every product the
 *     plugin created or linked is deleted. Uninstall never passes it.
 *   - Otherwise a product is deleted only when the plugin CREATED it (meta
 *     _sellmo_created = 'yes'), Auto map is on, and its SKU still equals its POS
 *     product code (see sku_matches()).
 *   - Everything else is kept as an ordinary product: its Sellmo meta,
 *     including the created flag, is stripped from it and its variations.
 *   - Images the plugin imported (attachments carrying _sellmo_image_source_url)
 *     are deleted unless a kept product still uses them.
 *   - Product categories the plugin created (term meta _sellmo_created = 'yes')
 *     are deleted unless a kept product is still filed under them.
 *
 * Uses WordPress core functions only, so it also runs during uninstall when
 * WooCommerce may not be loaded.
 *
 * @package Sellmo_WooCommerce
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Cleanup
 *
 * @since 1.2.0
 */
class Sellmo_Cleanup {

	/**
	 * Meta key flagging a product or term the plugin itself created.
	 */
	private const CREATED_META = '_sellmo_created';

	/**
	 * Sellmo meta stripped from a product that is kept.
	 *
	 * @var array<int, string>
	 */
	private const LINK_META = [
		'_salesplay_pos_product_id',
		'_pos_product_code',
		'_salesplay_variant_id',
		'_sellmo_sync_enabled',
		'_sellmo_last_synced',
		'_sellmo_created',
	];

	/**
	 * Remove the plugin's entire catalogue footprint from WooCommerce.
	 *
	 * @since  1.2.0
	 * @param  bool $delete_linked Delete every created or linked product.
	 * @return int Number of products deleted.
	 */
	public static function purge_catalogue( bool $delete_linked = false ): int {
		$deleted = 0;

		foreach ( self::footprint_product_ids() as $product_id ) {
			if ( self::purge_product( $product_id, $delete_linked ) ) {
				$deleted++;
			}
		}

		self::delete_unused_images( self::imported_image_ids() );
		self::delete_unused_categories( self::created_category_ids() );

		return $deleted;
	}

	/**
	 * Whether the "Auto map" setting is on.
	 *
	 * Read straight from the option so it also works during uninstall, when the
	 * settings class is not loaded.
	 *
	 * @since  1.8.0
	 * @return bool
	 */
	public static function auto_map_enabled(): bool {
		return 'yes' === (string) get_option( POS_Brand::option( 'auto_map_enabled' ) );
	}

	/**
	 * Whether a product's or variation's SKU equals the given POS code.
	 *
	 * Compared trimmed and case-insensitively, the same way the auto-mapper
	 * matches them. An empty SKU or code never matches.
	 *
	 * @since  1.8.0
	 * @param  int    $post_id Product or variation id.
	 * @param  string $code    POS product or variant code.
	 * @return bool
	 */
	public static function sku_matches( int $post_id, string $code ): bool {
		$sku  = strtolower( trim( (string) get_post_meta( $post_id, '_sku', true ) ) );
		$code = strtolower( trim( $code ) );

		return '' !== $sku && $sku === $code;
	}

	/**
	 * Whether a token removal without the opt-in box deletes this product.
	 *
	 * Only a product the plugin created, while Auto map is on and its SKU still
	 * equals its POS product code.
	 *
	 * @since  1.8.0
	 * @param  int $product_id Product id.
	 * @return bool
	 */
	public static function is_deletable( int $product_id ): bool {
		return 'yes' === (string) get_post_meta( $product_id, self::CREATED_META, true )
			&& self::auto_map_enabled()
			&& self::sku_matches( $product_id, (string) get_post_meta( $product_id, '_pos_product_code', true ) );
	}

	/**
	 * Number of products a default purge (opt-in box unticked) would delete.
	 *
	 * @since  1.8.0
	 * @return int
	 */
	public static function count_deletable(): int {
		return count( array_filter( self::footprint_product_ids(), [ self::class, 'is_deletable' ] ) );
	}

	/**
	 * Delete one product (with its variations) or keep it detached.
	 *
	 * @since  1.8.0
	 * @param  int  $product_id    Product id.
	 * @param  bool $delete_linked Delete it whatever its origin.
	 * @return bool True when the product was deleted.
	 */
	public static function purge_product( int $product_id, bool $delete_linked ): bool {
		$children = self::child_variation_ids( $product_id );

		if ( $delete_linked || self::is_deletable( $product_id ) ) {
			foreach ( $children as $child_id ) {
				wp_delete_post( $child_id, true );
			}

			return null !== wp_delete_post( $product_id, true );
		}

		foreach ( array_merge( [ $product_id ], $children ) as $post_id ) {
			foreach ( self::LINK_META as $key ) {
				delete_post_meta( $post_id, $key );
			}
		}

		return false;
	}

	/**
	 * Delete imported attachments that no remaining product uses.
	 *
	 * A used one is kept and its import meta stripped, so it becomes an
	 * ordinary media-library item.
	 *
	 * @since  1.8.0
	 * @param  array<int, int> $attachment_ids Imported attachment ids.
	 * @return void
	 */
	public static function delete_unused_images( array $attachment_ids ): void {
		global $wpdb;

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$in_use = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta}
					 WHERE ( meta_key = '_thumbnail_id' AND meta_value = %s )
					    OR ( meta_key = '_product_image_gallery' AND FIND_IN_SET( %s, meta_value ) )",
					(string) $attachment_id,
					(string) $attachment_id
				)
			);

			if ( $in_use > 0 ) {
				delete_post_meta( $attachment_id, '_sellmo_image_source_url' );
				continue;
			}

			// force = true also removes the underlying files.
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Delete created product categories that no remaining product is filed under.
	 *
	 * A category is in use when it, or any category below it, still holds a
	 * product. A used one is kept and its created flag stripped.
	 *
	 * @since  1.8.0
	 * @param  array<int, int> $term_ids Created category term ids.
	 * @return void
	 */
	public static function delete_unused_categories( array $term_ids ): void {
		global $wpdb;

		foreach ( $term_ids as $term_id ) {
			$term_id  = (int) $term_id;
			$children = get_term_children( $term_id, 'product_cat' );
			$family   = array_map( 'intval', array_merge( [ $term_id ], is_array( $children ) ? $children : [] ) );
			$holders  = implode( ',', array_fill( 0, count( $family ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
			$in_use = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
					 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					 WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ( {$holders} )",
					...$family
				)
			);

			if ( $in_use > 0 ) {
				delete_term_meta( $term_id, self::CREATED_META );
				continue;
			}

			wp_delete_term( $term_id, 'product_cat' );
		}
	}

	/**
	 * Parent products carrying any of the plugin's meta.
	 *
	 * Linked variations under an unlinked parent are reached through their
	 * parent, so only `product` posts are listed.
	 *
	 * @since  1.8.0
	 * @return array<int, int>
	 */
	private static function footprint_product_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			"SELECT DISTINCT IF( p.post_type = 'product_variation', p.post_parent, p.ID )
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type IN ( 'product', 'product_variation' )
			   AND pm.meta_key IN ( '_sellmo_created', '_pos_product_code', '_salesplay_pos_product_id', '_salesplay_variant_id' )"
		);

		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Attachments the plugin imported.
	 *
	 * @since  1.8.0
	 * @return array<int, int>
	 */
	private static function imported_image_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sellmo_image_source_url'"
		) );
	}

	/**
	 * Product categories the plugin created.
	 *
	 * @since  1.8.0
	 * @return array<int, int>
	 */
	private static function created_category_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = 'yes'",
				self::CREATED_META
			)
		) );
	}

	/**
	 * Return the variation post IDs for a parent product.
	 *
	 * @since  1.2.0
	 * @param  int $product_id Parent product ID.
	 * @return array<int, int>
	 */
	private static function child_variation_ids( int $product_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$children = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
				$product_id
			)
		);

		return array_map( 'intval', (array) $children );
	}
}
