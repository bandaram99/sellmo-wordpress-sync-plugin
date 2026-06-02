<?php
declare(strict_types=1);

/**
 * Product mapping storage for Sellmo POS Integration.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Product_Mapping
 *
 * Stores and retrieves manual mappings between Sellmo products and
 * WooCommerce products.
 *
 * @since 1.0.0
 */
class Sellmo_Product_Mapping {

	/**
	 * Whether the table existence check has run in this request.
	 *
	 * @var bool
	 */
	private static bool $table_verified = false;

	/**
	 * Return the fully qualified mapping table name.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'sellmo_product_mapping';
	}

	/**
	 * Create the mapping table if it does not exist.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sellmo_product_id   VARCHAR(100) NOT NULL DEFAULT '',
  sellmo_product_code VARCHAR(191) NOT NULL DEFAULT '',
  sellmo_product_name VARCHAR(255) NOT NULL DEFAULT '',
  wc_product_id       BIGINT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_at          DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_sellmo_product_id (sellmo_product_id),
  UNIQUE KEY uniq_wc_product_id (wc_product_id),
  KEY sellmo_product_code (sellmo_product_code),
  KEY updated_at (updated_at)
) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Drop the mapping table.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from trusted prefix.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	/**
	 * Ensure mapping table exists for runtime calls.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function ensure_table(): void {
		if ( self::$table_verified ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from trusted prefix.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $table !== $exists ) {
			self::create_table();
		}

		self::$table_verified = true;
	}

	/**
	 * Insert or update a mapping row by Sellmo product id.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_id Sellmo product ID.
	 * @param  string $sellmo_code       Sellmo product code/SKU.
	 * @param  string $sellmo_name       Sellmo product name.
	 * @param  int    $wc_product_id     WooCommerce product ID.
	 * @return bool
	 */
	public static function upsert_mapping(
		string $sellmo_product_id,
		string $sellmo_code,
		string $sellmo_name,
		int $wc_product_id
	): bool {
		self::ensure_table();

		global $wpdb;

		$sellmo_product_id = trim( $sellmo_product_id );
		$sellmo_code       = trim( $sellmo_code );
		$sellmo_name       = trim( $sellmo_name );

		if ( '' === $sellmo_product_id || $wc_product_id <= 0 ) {
			return false;
		}

		$existing_for_wc = self::get_mapping_by_wc_product_id( $wc_product_id );
		if ( is_array( $existing_for_wc ) && (string) $existing_for_wc['sellmo_product_id'] !== $sellmo_product_id ) {
			return false;
		}

		$now      = current_time( 'mysql', true );
		$existing = self::get_mapping_by_sellmo_id( $sellmo_product_id );
		if ( ! is_array( $existing ) && '' !== $sellmo_code ) {
			$existing = self::get_mapping_by_sellmo_code( $sellmo_code );
		}
		$table    = self::table_name();

		if ( is_array( $existing ) ) {
			return false !== $wpdb->update(
				$table,
				[
					'sellmo_product_code' => $sellmo_code,
					'sellmo_product_name' => $sellmo_name,
					'wc_product_id'       => $wc_product_id,
					'updated_at'          => $now,
				],
				[ 'id' => (int) $existing['id'] ],
				[ '%s', '%s', '%d', '%s' ],
				[ '%d' ]
			);
		}

		return false !== $wpdb->insert(
			$table,
			[
				'sellmo_product_id'   => $sellmo_product_id,
				'sellmo_product_code' => $sellmo_code,
				'sellmo_product_name' => $sellmo_name,
				'wc_product_id'       => $wc_product_id,
				'created_at'          => $now,
				'updated_at'          => $now,
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Remove a mapping by Sellmo product id.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_id Sellmo product ID.
	 * @return bool
	 */
	public static function remove_mapping_by_sellmo_id( string $sellmo_product_id ): bool {
		self::ensure_table();

		global $wpdb;

		if ( '' === $sellmo_product_id ) {
			return false;
		}

		$table = self::table_name();
		return false !== $wpdb->delete(
			$table,
			[ 'sellmo_product_id' => $sellmo_product_id ],
			[ '%s' ]
		);
	}

	/**
	 * Get a mapping row by Sellmo product id.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_id Sellmo product ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_mapping_by_sellmo_id( string $sellmo_product_id ): ?array {
		self::ensure_table();

		global $wpdb;

		if ( '' === $sellmo_product_id ) {
			return null;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE sellmo_product_id = %s LIMIT 1",
				$sellmo_product_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get a mapping row by Sellmo product code.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_code Sellmo product code.
	 * @return array<string, mixed>|null
	 */
	public static function get_mapping_by_sellmo_code( string $sellmo_product_code ): ?array {
		self::ensure_table();

		global $wpdb;

		if ( '' === $sellmo_product_code ) {
			return null;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE sellmo_product_code = %s LIMIT 1",
				$sellmo_product_code
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get a mapping row by WooCommerce product id.
	 *
	 * @since  1.0.0
	 * @param  int $wc_product_id WooCommerce product ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_mapping_by_wc_product_id( int $wc_product_id ): ?array {
		self::ensure_table();

		global $wpdb;

		if ( $wc_product_id <= 0 ) {
			return null;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE wc_product_id = %d LIMIT 1",
				$wc_product_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Resolve mapping for a Sellmo product payload.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $sellmo_product Sellmo payload.
	 * @return array<string, mixed>|null
	 */
	public static function get_mapping_for_sellmo_product( array $sellmo_product ): ?array {
		$by_id = self::get_mapping_by_sellmo_id( (string) ( $sellmo_product['id'] ?? '' ) );
		if ( null !== $by_id ) {
			return $by_id;
		}

		return self::get_mapping_by_sellmo_code( (string) ( $sellmo_product['product_code'] ?? '' ) );
	}

	/**
	 * Check whether a Sellmo product payload is mapped.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $sellmo_product Sellmo product payload.
	 * @return bool
	 */
	public static function is_sellmo_product_mapped( array $sellmo_product ): bool {
		return null !== self::get_mapping_for_sellmo_product( $sellmo_product );
	}

	/**
	 * Count mapped Sellmo products.
	 *
	 * @since  1.0.0
	 * @return int
	 */
	public static function count_mapped_products(): int {
		self::ensure_table();

		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		return (int) $count;
	}

	/**
	 * Return mapped WC product IDs.
	 *
	 * @since  1.0.0
	 * @return array<int, int>
	 */
	public static function get_mapped_wc_product_ids(): array {
		self::ensure_table();

		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT wc_product_id FROM `{$table}`" );

		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Return all mappings as lookup arrays.
	 *
	 * @since  1.0.0
	 * @return array{ by_id: array<string, array<string, mixed>>, by_code: array<string, array<string, mixed>> }
	 */
	public static function get_all_mappings_indexed(): array {
		self::ensure_table();

		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );

		$indexed = [
			'by_id'   => [],
			'by_code' => [],
		];

		foreach ( (array) $rows as $row ) {
			$sellmo_id   = (string) ( $row['sellmo_product_id'] ?? '' );
			$sellmo_code = (string) ( $row['sellmo_product_code'] ?? '' );

			if ( '' !== $sellmo_id ) {
				$indexed['by_id'][ $sellmo_id ] = $row;
			}

			if ( '' !== $sellmo_code ) {
				$indexed['by_code'][ $sellmo_code ] = $row;
			}
		}

		return $indexed;
	}

	/**
	 * Count WooCommerce products that are not linked to Sellmo.
	 *
	 * A product is considered WooCommerce-only when:
	 * - It is not manually mapped.
	 * - It has no _sellmo_product_code meta.
	 * - It has no _salesplay_pos_product_id meta.
	 *
	 * @since  1.0.0
	 * @return int
	 */
	public static function count_woocommerce_only_products(): int {
		global $wpdb;

		$mapped_wc_ids = self::get_mapped_wc_product_ids();
		$mapped_sql    = '';

		if ( ! empty( $mapped_wc_ids ) ) {
			$mapped_sql = ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $mapped_wc_ids ) ) . ')';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query = "SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} spc
				ON spc.post_id = p.ID AND spc.meta_key = '_sellmo_product_code'
			LEFT JOIN {$wpdb->postmeta} spi
				ON spi.post_id = p.ID AND spi.meta_key = '_salesplay_pos_product_id'
			WHERE p.post_type = 'product'
			AND p.post_status IN ('publish', 'private', 'draft')
			AND (spc.meta_value IS NULL OR spc.meta_value = '')
			AND (spi.meta_value IS NULL OR spi.meta_value = '')
			{$mapped_sql}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $query );

		return (int) $count;
	}

	/**
	 * Build dashboard mapping statistics.
	 *
	 * @since  1.0.0
	 * @return array{ total_sellmo_products: int, mapped_products: int, unmapped_products: int, woocommerce_only_products: int }
	 */
	public static function get_dashboard_stats(): array {
		$sellmo_products = self::dedupe_sellmo_products_by_code( self::fetch_all_sellmo_products() );

		$total_sellmo = count( $sellmo_products );
		$mapped       = 0;

		foreach ( $sellmo_products as $product ) {
			if ( self::is_sellmo_product_mapped( $product ) ) {
				$mapped++;
			}
		}

		return [
			'total_sellmo_products'     => $total_sellmo,
			'mapped_products'           => $mapped,
			'unmapped_products'         => max( 0, $total_sellmo - $mapped ),
			'woocommerce_only_products' => self::count_woocommerce_only_products(),
		];
	}

	/**
	 * Collapse Sellmo product rows so each product code appears once.
	 *
	 * @since  1.0.0
	 * @param  array<int, array<string, mixed>> $products Raw Sellmo products.
	 * @return array<int, array<string, mixed>>
	 */
	private static function dedupe_sellmo_products_by_code( array $products ): array {
		$deduped = [];

		foreach ( $products as $product ) {
			$code = (string) ( $product['product_code'] ?? '' );
			$id   = (string) ( $product['id'] ?? '' );

			$key = '' !== $code ? $code : $id;
			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $deduped[ $key ] ) ) {
				$deduped[ $key ] = $product;
			}
		}

		return array_values( $deduped );
	}

	/**
	 * Fetch all Sellmo products for mapper statistics.
	 *
	 * Uses the same cache key as the Products page to avoid duplicate API calls.
	 *
	 * @since  1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetch_all_sellmo_products(): array {
		$cached = get_transient( 'sellmo_wc_products_cache' );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$api       = new Sellmo_API();
		$cursor    = '';
		$page      = 0;
		$max_pages = 20;
		$products  = [];

		do {
			$page++;
			$response = $api->get_products( $cursor );

			if ( is_wp_error( $response ) ) {
				return [];
			}

			$items = [];
			if ( isset( $response['products'] ) && is_array( $response['products'] ) ) {
				$items = $response['products'];
			} elseif ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
				$items = $response['data'];
			}

			if ( empty( $items ) ) {
				break;
			}

			$products = array_merge( $products, $items );

			$cursor = '';
			foreach ( [ 'cursor', 'next_cursor', 'next_page_cursor' ] as $key ) {
				if ( ! empty( $response[ $key ] ) && is_string( $response[ $key ] ) ) {
					$cursor = $response[ $key ];
					break;
				}
			}

			if ( '' === $cursor && ! empty( $response['meta']['cursor'] ) && is_string( $response['meta']['cursor'] ) ) {
				$cursor = $response['meta']['cursor'];
			}

			if ( '' === $cursor ) {
				break;
			}
		} while ( $page < $max_pages );

		set_transient( 'sellmo_wc_products_cache', $products, 5 * MINUTE_IN_SECONDS );

		return $products;
	}
}
