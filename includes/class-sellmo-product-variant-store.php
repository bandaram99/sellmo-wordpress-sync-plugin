<?php
declare(strict_types=1);

/**
 * Sellmo variant store.
 *
 * Stores per-variant data pulled from the Sellmo /products endpoint: the parent
 * product id (logical foreign key), the variant identifiers, option values, cost,
 * the shops the variant belongs to, and its latest stock snapshot.
 *
 * One row per Sellmo variant (keyed uniquely by variant_id). The parent product
 * is referenced by product_id, which is the {prefix}sellmo_product_mapping.id
 * primary key of the mapped parent product (a foreign key).
 *
 * @package Sellmo_WooCommerce
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Product_Variant_Store
 *
 * @since 1.1.0
 */
class Sellmo_Product_Variant_Store {

	/**
	 * Cached table-existence flag for this request.
	 *
	 * @var bool
	 */
	private static bool $table_verified = false;

	/**
	 * Fully qualified variant table name.
	 *
	 * @since  1.1.0
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'sellmo_product_variants';
	}

	/**
	 * Create the variant table if it does not exist.
	 *
	 * product_id is the wp_sellmo_product_mapping.id of the mapped parent product
	 * (a foreign key, indexed rather than a hard SQL constraint — dbDelta does not
	 * support FK constraints).
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// Two spaces between column and type, PRIMARY KEY on its own line — dbDelta rules.
		$sql = "CREATE TABLE {$table} (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  variant_id    VARCHAR(100) NOT NULL DEFAULT '',
  product_code  VARCHAR(191) NOT NULL DEFAULT '',
  default_cost  DECIMAL(20,4) NOT NULL DEFAULT 0,
  option1_value VARCHAR(191) NOT NULL DEFAULT '',
  option2_value VARCHAR(191) NOT NULL DEFAULT '',
  option3_value VARCHAR(191) NOT NULL DEFAULT '',
  shop_ids      LONGTEXT NULL,
  stock_count   INT NOT NULL DEFAULT 0,
  stock_status  TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_at    DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_variant_id (variant_id),
  KEY product_id (product_id),
  KEY product_code (product_code)
) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Drop the variant table. Called only on uninstall.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from trusted prefix.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	/**
	 * Lazily ensure the table exists at runtime.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	private static function ensure_table(): void {
		if ( self::$table_verified ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $table !== $exists ) {
			self::create_table();
		}

		self::$table_verified = true;
	}

	/**
	 * Insert or update a variant row, keyed by variant_id.
	 *
	 * shop_ids may be passed as an array (stored as JSON) or a pre-encoded string.
	 * stock_status is derived from stock_count when not supplied explicitly.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $fields {
	 *     @type string            $product_id    Parent Sellmo product id (foreign key).
	 *     @type string            $variant_id    Sellmo variant id (required, unique).
	 *     @type string            $product_code  Variant product code.
	 *     @type float             $default_cost  Variant default cost.
	 *     @type string            $option1_value Option 1 value.
	 *     @type string            $option2_value Option 2 value.
	 *     @type string            $option3_value Option 3 value.
	 *     @type array|string      $shop_ids      Shop ids the variant belongs to.
	 *     @type int               $stock_count   Latest in_stock count.
	 *     @type int|null          $stock_status  1/0; derived from stock_count if omitted.
	 * }
	 * @return bool True on a successful insert or update.
	 */
	public static function upsert_variant( array $fields ): bool {
		self::ensure_table();
		global $wpdb;

		$variant_id = trim( (string) ( $fields['variant_id'] ?? '' ) );
		if ( '' === $variant_id ) {
			return false;
		}

		$shop_ids = $fields['shop_ids'] ?? [];
		if ( is_array( $shop_ids ) ) {
			$shop_ids = wp_json_encode( array_values( $shop_ids ) );
		}

		$stock_count  = (int) ( $fields['stock_count'] ?? 0 );
		$stock_status = isset( $fields['stock_status'] )
			? (int) $fields['stock_status']
			: ( $stock_count > 0 ? 1 : 0 );

		$now  = current_time( 'mysql', true );
		$data = [
			'product_id'    => (int) ( $fields['product_id'] ?? 0 ),
			'variant_id'    => $variant_id,
			'product_code'  => (string) ( $fields['product_code'] ?? '' ),
			'default_cost'  => (float) ( $fields['default_cost'] ?? 0 ),
			'option1_value' => (string) ( $fields['option1_value'] ?? '' ),
			'option2_value' => (string) ( $fields['option2_value'] ?? '' ),
			'option3_value' => (string) ( $fields['option3_value'] ?? '' ),
			'shop_ids'      => is_string( $shop_ids ) ? $shop_ids : null,
			'stock_count'   => $stock_count,
			'stock_status'  => $stock_status,
			'updated_at'    => $now,
		];
		$formats = [ '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ];

		$table    = self::table_name();
		$existing = self::get_by_variant_id( $variant_id );

		if ( is_array( $existing ) ) {
			return false !== $wpdb->update( $table, $data, [ 'id' => (int) $existing['id'] ], $formats, [ '%d' ] );
		}

		$data['created_at'] = $now;
		$formats[]          = '%s';

		return false !== $wpdb->insert( $table, $data, $formats );
	}

	/**
	 * Update only the stock columns for a variant.
	 *
	 * @since  1.1.0
	 * @param  string $variant_id Sellmo variant id.
	 * @param  int    $in_stock   Latest in_stock count.
	 * @return int                Rows updated.
	 */
	public static function update_stock( string $variant_id, int $in_stock ): int {
		self::ensure_table();
		global $wpdb;

		$variant_id = trim( $variant_id );
		if ( '' === $variant_id ) {
			return 0;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `{$table}` SET stock_count = %d, stock_status = %d, updated_at = %s WHERE variant_id = %s",
				$in_stock,
				$in_stock > 0 ? 1 : 0,
				current_time( 'mysql', true ),
				$variant_id
			)
		);
	}

	/**
	 * Fetch a single variant row by variant id.
	 *
	 * @since  1.1.0
	 * @param  string $variant_id Sellmo variant id.
	 * @return array<string, mixed>|null
	 */
	public static function get_by_variant_id( string $variant_id ): ?array {
		self::ensure_table();
		global $wpdb;

		$variant_id = trim( $variant_id );
		if ( '' === $variant_id ) {
			return null;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE variant_id = %s LIMIT 1", $variant_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch all variant rows for a parent product id.
	 *
	 * @since  1.1.0
	 * @param  int $product_id Parent mapping row id (wp_sellmo_product_mapping.id).
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_variants_for_product( int $product_id ): array {
		self::ensure_table();
		global $wpdb;

		if ( $product_id <= 0 ) {
			return [];
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE product_id = %d ORDER BY product_code ASC", $product_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Total number of stored variant rows.
	 *
	 * @since  1.1.0
	 * @return int
	 */
	public static function count_all(): int {
		self::ensure_table();
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}
}
