<?php
declare(strict_types=1);

/**
 * Destructive catalogue cleanup for Sellmo POS Integration.
 *
 * Shared by the token-removal purge (Sellmo_Admin::purge_sellmo_synced_data)
 * and the full uninstall routine (uninstall.php) so both remove the same
 * footprint:
 *
 *   - Products the plugin CREATED (meta _sellmo_created = 'yes') are deleted
 *     outright, together with their variations.
 *   - Products the plugin only LINKED to a pre-existing WooCommerce product are
 *     left in place; their Sellmo join-key meta is stripped so they are no
 *     longer treated as synced. A merchant's own product is never deleted.
 *   - Images the plugin imported (attachments carrying _sellmo_image_source_url)
 *     are deleted from the media library.
 *   - Product categories the plugin created (term meta _sellmo_created = 'yes')
 *     are deleted.
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
	 * Sellmo join-key meta stripped from linked (merchant-owned) products.
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
	 * @return int Number of products deleted.
	 */
	public static function purge_catalogue(): int {
		$deleted = self::delete_created_products();
		self::unlink_merchant_products();
		self::delete_imported_images();
		self::delete_created_categories();

		return $deleted;
	}

	/**
	 * Delete every product the plugin created, and its variations.
	 *
	 * @since  1.2.0
	 * @return int Number of parent products deleted.
	 */
	private static function delete_created_products(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'yes'",
				self::CREATED_META
			)
		);

		$count = 0;

		foreach ( (array) $ids as $product_id ) {
			$product_id = (int) $product_id;

			foreach ( self::child_variation_ids( $product_id ) as $child_id ) {
				wp_delete_post( $child_id, true );
			}

			if ( null !== wp_delete_post( $product_id, true ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Strip Sellmo join-key meta from products the plugin only linked.
	 *
	 * These are merchant-owned products mapped to a Sellmo product; they keep
	 * all of their own data and are simply detached from the integration.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	private static function unlink_merchant_products(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key IN ( '_pos_product_code', '_salesplay_pos_product_id' )
				   AND post_id NOT IN (
				       SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'yes'
				   )",
				self::CREATED_META
			)
		);

		foreach ( (array) $ids as $product_id ) {
			$product_id = (int) $product_id;

			foreach ( self::child_variation_ids( $product_id ) as $child_id ) {
				foreach ( self::LINK_META as $key ) {
					delete_post_meta( $child_id, $key );
				}
			}

			foreach ( self::LINK_META as $key ) {
				delete_post_meta( $product_id, $key );
			}
		}
	}

	/**
	 * Delete all media-library attachments the plugin imported.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	private static function delete_imported_images(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sellmo_image_source_url'"
		);

		foreach ( (array) $ids as $attachment_id ) {
			// force = true also removes the underlying files and clears any
			// dangling _thumbnail_id references on other posts.
			wp_delete_attachment( (int) $attachment_id, true );
		}
	}

	/**
	 * Delete product categories the plugin created.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	private static function delete_created_categories(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = 'yes'",
				self::CREATED_META
			)
		);

		foreach ( (array) $term_ids as $term_id ) {
			wp_delete_term( (int) $term_id, 'product_cat' );
		}
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
