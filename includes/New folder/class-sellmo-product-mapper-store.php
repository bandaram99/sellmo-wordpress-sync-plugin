<?php
declare(strict_types=1);

/**
 * Sellmo Product Mapper staging store.
 *
 * Holds products pulled from the Sellmo API that have NOT yet been pushed to
 * WooCommerce. This is the intermediate table in the workflow:
 *
 *     Sellmo API  ->  Product Mapper table  ->  (user maps in UI)  ->  WooCommerce
 *
 * It is deliberately separate from {prefix}sellmo_product_mapping (which links a
 * Sellmo product to an existing WC product). This table only stages the raw
 * Sellmo catalogue so the delta sync has somewhere to insert NEW rows without
 * ever touching WooCommerce.
 *
 * @package Sellmo_WooCommerce
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Product_Mapper_Store
 *
 * @since 1.1.0
 */
class Sellmo_Product_Mapper_Store {

	/**
	 * Cached table-existence flag for this request.
	 *
	 * @var bool
	 */
	private static bool $table_verified = false;

	/**
	 * Fully qualified mapper staging table name.
	 *
	 * @since  1.1.0
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'sellmo_product_mapper';
	}

	/**
	 * Create the staging table if it does not exist.
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
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sellmo_product_id   VARCHAR(100) NOT NULL DEFAULT '',
  sellmo_product_code VARCHAR(191) NOT NULL DEFAULT '',
  sellmo_product_name VARCHAR(255) NOT NULL DEFAULT '',
  category            VARCHAR(191) NOT NULL DEFAULT '',
  price               DECIMAL(20,4) NOT NULL DEFAULT 0,
  variant_count       INT UNSIGNED NOT NULL DEFAULT 0,
  raw_payload         LONGTEXT NULL,
  imported_at         DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_at          DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_sellmo_product_id (sellmo_product_id),
  KEY sellmo_product_code (sellmo_product_code),
  KEY sellmo_product_name (sellmo_product_name)
) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Drop the staging table. Called only on uninstall.
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
	 * Whether a Sellmo product id already exists in the staging table.
	 *
	 * @since  1.1.0
	 * @param  string $sellmo_product_id Sellmo product id.
	 * @return bool
	 */
	public static function exists( string $sellmo_product_id ): bool {
		self::ensure_table();
		global $wpdb;

		$sellmo_product_id = trim( $sellmo_product_id );
		if ( '' === $sellmo_product_id ) {
			return false;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE sellmo_product_id = %s LIMIT 1",
				$sellmo_product_id
			)
		);

		return null !== $found;
	}

	/**
	 * Return a set of all sellmo_product_id values already staged.
	 *
	 * Used by the delta sync to test membership in O(1) without a query per row.
	 *
	 * @since  1.1.0
	 * @return array<string, true>
	 */
	public static function get_existing_id_set(): array {
		self::ensure_table();
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT sellmo_product_id FROM `{$table}`" );

		$set = [];
		foreach ( (array) $ids as $id ) {
			$set[ (string) $id ] = true;
		}

		return $set;
	}

	/**
	 * Insert a brand-new staging row. Never overwrites an existing row.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $fields {
	 *     @type string $sellmo_product_id
	 *     @type string $sellmo_product_code
	 *     @type string $sellmo_product_name
	 *     @type string $category
	 *     @type float  $price
	 *     @type int    $variant_count
	 *     @type array  $raw_payload
	 * }
	 * @return bool True when a new row was inserted.
	 */
	public static function insert_new( array $fields ): bool {
		self::ensure_table();
		global $wpdb;

		$sellmo_product_id = trim( (string) ( $fields['sellmo_product_id'] ?? '' ) );
		if ( '' === $sellmo_product_id ) {
			return false;
		}

		// Hard guard against duplication / overwrite.
		if ( self::exists( $sellmo_product_id ) ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		return false !== $wpdb->insert(
			self::table_name(),
			[
				'sellmo_product_id'   => $sellmo_product_id,
				'sellmo_product_code' => (string) ( $fields['sellmo_product_code'] ?? '' ),
				'sellmo_product_name' => (string) ( $fields['sellmo_product_name'] ?? '' ),
				'category'            => (string) ( $fields['category'] ?? '' ),
				'price'               => (float) ( $fields['price'] ?? 0 ),
				'variant_count'       => (int) ( $fields['variant_count'] ?? 0 ),
				'raw_payload'         => isset( $fields['raw_payload'] ) ? wp_json_encode( $fields['raw_payload'] ) : null,
				'imported_at'         => $now,
				'updated_at'          => $now,
			],
			[ '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Paginated, searchable list of staged Sellmo products (for Select2).
	 *
	 * @since  1.1.0
	 * @param  string $search   Search term (matched against name and code).
	 * @param  int    $page     1-based page number.
	 * @param  int    $per_page Results per page.
	 * @return array{ items: array<int, array<string, mixed>>, total: int }
	 */
	public static function search( string $search, int $page = 1, int $per_page = 20 ): array {
		self::ensure_table();
		global $wpdb;

		$table    = self::table_name();
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = '';
		$params = [];

		$search = trim( $search );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where  = ' WHERE sellmo_product_name LIKE %s OR sellmo_product_code LIKE %s';
			$params = [ $like, $like ];
		}

		// Total count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}`" . $where, ...$params )
				: "SELECT COUNT(*) FROM `{$table}`"
		);

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sellmo_product_id, sellmo_product_code, sellmo_product_name, category, price
				 FROM `{$table}`" . $where . ' ORDER BY sellmo_product_name ASC LIMIT %d OFFSET %d',
				...$query_params
			),
			ARRAY_A
		);

		return [
			'items' => $rows ?: [],
			'total' => $total,
		];
	}

	/**
	 * Total number of staged rows.
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
