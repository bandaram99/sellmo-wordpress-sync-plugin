<?php
declare(strict_types=1);

/**
 * Product mapping + staging storage for Sellmo POS Integration.
 *
 * Single source of truth for Sellmo products in the plugin. Each row is a Sellmo
 * product; map_status records whether it is mapped to a WooCommerce product:
 *   map_status = 1  -> mapped   (wc_product_id set)
 *   map_status = 0  -> staged   (awaiting mapping; wc_product_id NULL)
 *
 * This table absorbed the former {prefix}sellmo_product_mapper staging table; the
 * legacy table is migrated into here automatically on first load (see
 * maybe_migrate_legacy_mapper) and then dropped.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Product_Mapping
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
	 * Return the fully qualified table name.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function table_name(): string {
		return POS_Brand::table( 'product_mapping' );
	}

	/**
	 * Create the table if it does not exist.
	 *
	 * wc_product_id is nullable so staged (unmapped) rows can exist while the
	 * UNIQUE index still enforces one WooCommerce product per mapping (MySQL
	 * allows multiple NULLs in a UNIQUE index).
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
  pos_product_id   VARCHAR(100) NOT NULL DEFAULT '',
  pos_product_code VARCHAR(191) NOT NULL DEFAULT '',
  pos_product_name VARCHAR(255) NOT NULL DEFAULT '',
  category            VARCHAR(191) NOT NULL DEFAULT '',
  price               DECIMAL(20,4) NOT NULL DEFAULT 0,
  variant_count       INT UNSIGNED NOT NULL DEFAULT 0,
  wc_product_id       BIGINT UNSIGNED NULL DEFAULT NULL,
  map_status          TINYINT(1) NOT NULL DEFAULT 0,
  stock_count         INT NOT NULL DEFAULT 0,
  stock_status        TINYINT(1) NOT NULL DEFAULT 0,
  raw_payload         LONGTEXT NULL,
  imported_at         DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
  created_at          DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_at          DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_pos_product_id (pos_product_id),
  UNIQUE KEY uniq_wc_product_id (wc_product_id),
  KEY pos_product_code (pos_product_code),
  KEY pos_product_name (pos_product_name),
  KEY map_status (map_status),
  KEY updated_at (updated_at)
) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Delete every row, keeping the table structure intact.
	 *
	 * Used when the API token changes and the previously synced catalogue —
	 * including its mapping links and stored stock snapshots — is discarded.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function delete_all_rows(): void {
		self::ensure_table();

		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from trusted prefix.
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );
	}

	/**
	 * Reconcile the table against the live POS catalogue: for every row whose
	 * pos_product_id is NOT in $live_pos_ids (i.e. the product was deleted in the
	 * backoffice), remove it.
	 *
	 *   - Mapped row   -> delete the linked WooCommerce product (and its
	 *                     variations), then delete the mapper row.
	 *   - Unmapped row -> delete the mapper row only.
	 *
	 * Rows without a pos_product_id (code-only staged rows) are never pruned;
	 * they can't be reconciled by id.
	 *
	 * Callers MUST pass a COMPLETE catalogue snapshot (all API pages fetched) —
	 * reconciling against a partial list would delete valid products. An empty
	 * snapshot is treated as "unknown" and changes nothing, so an anomalous empty
	 * API response never wipes the table.
	 *
	 * @since  1.2.0
	 * @param  array<int, string> $live_pos_ids POS ids currently present upstream.
	 * @return int Number of rows removed.
	 */
	public static function reconcile_absent( array $live_pos_ids ): int {
		self::ensure_table();

		global $wpdb;

		$live = array_values( array_unique( array_filter(
			array_map( 'strval', $live_pos_ids ),
			static fn( string $v ): bool => '' !== $v
		) ) );

		if ( empty( $live ) ) {
			return 0; // Nothing to reconcile against — refuse to prune.
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $live ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted prefix; ids are prepared.
		$absent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, wc_product_id FROM `{$table}` WHERE pos_product_id <> '' AND pos_product_id NOT IN ( {$placeholders} )",
				...$live
			),
			ARRAY_A
		);

		if ( empty( $absent ) ) {
			return 0;
		}

		$removed = 0;
		foreach ( $absent as $row ) {
			$wc_id = (int) ( $row['wc_product_id'] ?? 0 );

			// Mapped row: delete the linked WooCommerce product (variations first).
			if ( $wc_id > 0 ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $wc_id ) : null;
				if ( $product instanceof WC_Product ) {
					foreach ( $product->get_children() as $child_id ) {
						wp_delete_post( (int) $child_id, true );
					}
					$product->delete( true );
				} else {
					wp_delete_post( $wc_id, true );
				}
			}

			$wpdb->delete( $table, [ 'id' => (int) $row['id'] ], [ '%d' ] );
			$removed++;
		}

		return $removed;
	}

	/**
	 * Drop the table.
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
	 * Ensure the table exists, has all columns, and legacy data is migrated.
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
		} else {
			self::ensure_columns();
		}

		self::maybe_migrate_legacy_mapper();

		self::$table_verified = true;
	}

	/**
	 * Add any columns missing from an older install, and (first time only) make
	 * wc_product_id nullable and backfill map_status for existing mappings.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function ensure_columns(): void {
		global $wpdb;

		$table   = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ) );

		// One-time rename of the legacy brand-named columns to neutral names.
		// Older installs carry sellmo_product_* columns; the plugin now uses
		// pos_product_* so the schema no longer embeds a specific brand.
		$rename = [
			'sellmo_product_id'   => "pos_product_id VARCHAR(100) NOT NULL DEFAULT ''",
			'sellmo_product_code' => "pos_product_code VARCHAR(191) NOT NULL DEFAULT ''",
			'sellmo_product_name' => "pos_product_name VARCHAR(255) NOT NULL DEFAULT ''",
		];
		foreach ( $rename as $old => $ddl ) {
			if ( in_array( $old, $columns, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table/column names are trusted literals.
				$wpdb->query( "ALTER TABLE `{$table}` CHANGE COLUMN `{$old}` {$ddl}" );
			}
		}

		// Rename the matching indexes so dbDelta does not later create duplicates.
		// Guarded by the OLD index names (not the columns) so this self-heals even
		// after the columns have already been renamed. DROP+ADD is used instead of
		// "RENAME INDEX", which needs MySQL 5.7+ / MariaDB 10.5.2+.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = array_map( 'strval', (array) $wpdb->get_col( "SHOW INDEX FROM `{$table}`", 2 ) );
		$rename_idx = [
			'uniq_sellmo_product_id' => 'ADD UNIQUE KEY uniq_pos_product_id (pos_product_id)',
			'sellmo_product_code'    => 'ADD KEY pos_product_code (pos_product_code)',
			'sellmo_product_name'    => 'ADD KEY pos_product_name (pos_product_name)',
		];
		foreach ( $rename_idx as $old => $add_ddl ) {
			if ( in_array( $old, $indexes, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted literal identifiers.
				$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$old}`, {$add_ddl}" );
			}
		}

		$add = static function ( string $name, string $ddl ) use ( $columns, $table, $wpdb ): void {
			if ( ! in_array( $name, $columns, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$ddl}" );
			}
		};

		$add( 'stock_count', "stock_count INT NOT NULL DEFAULT 0" );
		$add( 'stock_status', "stock_status TINYINT(1) NOT NULL DEFAULT 0" );
		$add( 'category', "category VARCHAR(191) NOT NULL DEFAULT ''" );
		$add( 'price', "price DECIMAL(20,4) NOT NULL DEFAULT 0" );
		$add( 'variant_count', "variant_count INT UNSIGNED NOT NULL DEFAULT 0" );
		$add( 'raw_payload', "raw_payload LONGTEXT NULL" );
		$add( 'imported_at', "imported_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'" );

		// First-time consolidation: add map_status, make wc_product_id nullable,
		// and mark every pre-existing row (all were mappings) as mapped.
		if ( ! in_array( 'map_status', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN map_status TINYINT(1) NOT NULL DEFAULT 0" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` MODIFY wc_product_id BIGINT UNSIGNED NULL DEFAULT NULL" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE `{$table}` SET map_status = 1 WHERE wc_product_id IS NOT NULL AND wc_product_id > 0" );
		}
	}

	/**
	 * One-time migration of the legacy {prefix}sellmo_product_mapper table.
	 *
	 * Staged (unmapped) rows are inserted as map_status = 0; rows that already
	 * exist here (mapped) are enriched with the catalogue fields. The legacy
	 * table is dropped afterwards. Guarded by an option so it runs once.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function maybe_migrate_legacy_mapper(): void {
		if ( get_option( POS_Brand::option( 'mapper_merged' ) ) ) {
			return;
		}

		global $wpdb;
		$legacy = POS_Brand::table( 'product_mapper' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$legacy_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) );

		if ( $legacy !== $legacy_exists ) {
			update_option( POS_Brand::option( 'mapper_merged' ), '1' );
			return;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$legacy}`", ARRAY_A );

		foreach ( (array) $rows as $r ) {
			$sid = (string) ( $r['pos_product_id'] ?? '' );
			if ( '' === $sid ) {
				continue;
			}

			$catalogue = [
				'category'      => (string) ( $r['category'] ?? '' ),
				'price'         => (float) ( $r['price'] ?? 0 ),
				'variant_count' => (int) ( $r['variant_count'] ?? 0 ),
				'raw_payload'   => $r['raw_payload'] ?? null,
				'imported_at'   => (string) ( $r['imported_at'] ?? current_time( 'mysql', true ) ),
			];

			$existing = self::find_row_by_sellmo_id( $sid );

			if ( is_array( $existing ) ) {
				$wpdb->update( $table, $catalogue, [ 'id' => (int) $existing['id'] ] );
				continue;
			}

			$now = current_time( 'mysql', true );
			$wpdb->insert(
				$table,
				array_merge(
					$catalogue,
					[
						'pos_product_id'   => $sid,
						'pos_product_code' => (string) ( $r['pos_product_code'] ?? '' ),
						'pos_product_name' => (string) ( $r['pos_product_name'] ?? '' ),
						'stock_count'         => (int) ( $r['stock_count'] ?? 0 ),
						'stock_status'        => (int) ( $r['stock_status'] ?? 0 ),
						'wc_product_id'       => null,
						'map_status'          => 0,
						'created_at'          => $now,
						'updated_at'          => $now,
					]
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$legacy}`" );
		update_option( POS_Brand::option( 'mapper_merged' ), '1' );
	}

	// =========================================================================
	// Internal row lookups (no map_status filter)
	// =========================================================================

	/**
	 * Find a row by Sellmo product id, regardless of map_status.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_id Sellmo product id.
	 * @return array<string, mixed>|null
	 */
	private static function find_row_by_sellmo_id( string $sellmo_product_id ): ?array {
		global $wpdb;

		$sellmo_product_id = trim( $sellmo_product_id );
		if ( '' === $sellmo_product_id ) {
			return null;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE pos_product_id = %s LIMIT 1", $sellmo_product_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find a row by Sellmo product code, regardless of map_status.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_code Sellmo product code.
	 * @return array<string, mixed>|null
	 */
	private static function find_row_by_sellmo_code( string $sellmo_product_code ): ?array {
		global $wpdb;

		$sellmo_product_code = trim( $sellmo_product_code );
		if ( '' === $sellmo_product_code ) {
			return null;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE pos_product_code = %s LIMIT 1", $sellmo_product_code ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	// =========================================================================
	// Catalogue fields (price / category / variant_count)
	// =========================================================================

	/**
	 * Refresh the cached catalogue columns on any row matching the Sellmo product.
	 *
	 * Keeps the Product Mapper list in step with the live product after a sync.
	 * Matches by pos_product_id first, then pos_product_code. A null price leaves
	 * the stored price untouched.
	 *
	 * @since  1.2.0
	 * @param  string     $sellmo_product_id Sellmo master product id.
	 * @param  string     $product_code      Sellmo product code (fallback match).
	 * @param  float|null $price             New price, or null to leave unchanged.
	 * @param  string     $category          New category name.
	 * @param  int        $variant_count     New variant count.
	 * @return int                           Number of rows updated.
	 */
	public static function update_catalogue_fields( string $sellmo_product_id, string $product_code, ?float $price, string $category, int $variant_count ): int {
		self::ensure_table();
		global $wpdb;

		$sellmo_product_id = trim( $sellmo_product_id );
		$product_code      = trim( $product_code );

		$conditions = [];
		$params     = [];

		if ( '' !== $sellmo_product_id ) {
			$conditions[] = 'pos_product_id = %s';
			$params[]     = $sellmo_product_id;
		}
		if ( '' !== $product_code ) {
			$conditions[] = 'pos_product_code = %s';
			$params[]     = $product_code;
		}

		if ( empty( $conditions ) ) {
			return 0;
		}

		$set    = [ 'category = %s', 'variant_count = %d', 'updated_at = %s' ];
		$values = [ $category, $variant_count, current_time( 'mysql', true ) ];

		if ( null !== $price ) {
			array_unshift( $set, 'price = %f' );
			array_unshift( $values, $price );
		}

		$table = self::table_name();
		$where = implode( ' OR ', $conditions );
		$set_sql = implode( ', ', $set );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `{$table}` SET {$set_sql} WHERE {$where}",
				...array_merge( $values, $params )
			)
		);
	}

	// =========================================================================
	// Stock
	// =========================================================================

	/**
	 * Store the latest Sellmo in_stock count against any matching row.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_id Sellmo master product id.
	 * @param  string $variant_id        Sellmo variant id (may be empty).
	 * @param  string $product_code      Sellmo product code (may be empty).
	 * @param  int    $in_stock          Stock count from the /inventory endpoint.
	 * @return int                       Number of rows updated.
	 */
	public static function update_stock( string $sellmo_product_id, string $variant_id, string $product_code, int $in_stock ): int {
		self::ensure_table();
		global $wpdb;

		$sellmo_product_id = trim( $sellmo_product_id );
		$variant_id        = trim( $variant_id );
		$product_code      = trim( $product_code );

		$conditions = [];
		$params     = [];

		if ( '' !== $sellmo_product_id ) {
			$conditions[] = 'pos_product_id = %s';
			$params[]     = $sellmo_product_id;
		}

		if ( '' !== $variant_id && $variant_id !== $sellmo_product_id ) {
			$conditions[] = 'pos_product_id = %s';
			$params[]     = $variant_id;
		}

		if ( '' !== $product_code ) {
			$conditions[] = 'pos_product_code = %s';
			$params[]     = $product_code;
		}

		if ( empty( $conditions ) ) {
			return 0;
		}

		$table        = self::table_name();
		$where        = implode( ' OR ', $conditions );
		$stock_status = $in_stock > 0 ? 1 : 0;
		$now          = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `{$table}` SET stock_count = %d, stock_status = %d, updated_at = %s WHERE {$where}",
				$in_stock,
				$stock_status,
				$now,
				...$params
			)
		);
	}

	// =========================================================================
	// Mapping (map_status = 1)
	// =========================================================================

	/**
	 * Map a Sellmo product to a WooCommerce product (insert or update the row).
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

		// Refuse if this WooCommerce product is already mapped to a different Sellmo product.
		$existing_for_wc = self::get_mapping_by_wc_product_id( $wc_product_id );
		if ( is_array( $existing_for_wc ) && (string) $existing_for_wc['pos_product_id'] !== $sellmo_product_id ) {
			return false;
		}

		$now      = current_time( 'mysql', true );
		$existing = self::find_row_by_sellmo_id( $sellmo_product_id );
		if ( ! is_array( $existing ) && '' !== $sellmo_code ) {
			$existing = self::find_row_by_sellmo_code( $sellmo_code );
		}
		$table = self::table_name();

		if ( is_array( $existing ) ) {
			return false !== $wpdb->update(
				$table,
				[
					'pos_product_code' => $sellmo_code,
					'pos_product_name' => $sellmo_name,
					'wc_product_id'       => $wc_product_id,
					'map_status'          => 1,
					'updated_at'          => $now,
				],
				[ 'id' => (int) $existing['id'] ],
				[ '%s', '%s', '%d', '%d', '%s' ],
				[ '%d' ]
			);
		}

		return false !== $wpdb->insert(
			$table,
			[
				'pos_product_id'   => $sellmo_product_id,
				'pos_product_code' => $sellmo_code,
				'pos_product_name' => $sellmo_name,
				'wc_product_id'       => $wc_product_id,
				'map_status'          => 1,
				'created_at'          => $now,
				'updated_at'          => $now,
			],
			[ '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Unmap a Sellmo product: clear the WooCommerce link but keep the row as
	 * a staged (unmapped) product so it can be re-mapped later.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_id Sellmo product ID.
	 * @return bool
	 */
	public static function remove_mapping_by_sellmo_id( string $sellmo_product_id ): bool {
		self::ensure_table();

		global $wpdb;

		$sellmo_product_id = trim( $sellmo_product_id );
		if ( '' === $sellmo_product_id ) {
			return false;
		}

		$existing = self::find_row_by_sellmo_id( $sellmo_product_id );
		if ( ! is_array( $existing ) || 1 !== (int) ( $existing['map_status'] ?? 0 ) ) {
			return false;
		}

		$table = self::table_name();
		return false !== $wpdb->update(
			$table,
			[
				'wc_product_id' => null,
				'map_status'    => 0,
				'updated_at'    => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $existing['id'] ],
			[ '%d', '%d', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Get a mapped row by Sellmo product id.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_id Sellmo product ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_mapping_by_sellmo_id( string $sellmo_product_id ): ?array {
		self::ensure_table();

		global $wpdb;

		if ( '' === trim( $sellmo_product_id ) ) {
			return null;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE pos_product_id = %s AND map_status = 1 LIMIT 1",
				$sellmo_product_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get a mapped row by Sellmo product code.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_code Sellmo product code.
	 * @return array<string, mixed>|null
	 */
	public static function get_mapping_by_sellmo_code( string $sellmo_product_code ): ?array {
		self::ensure_table();

		global $wpdb;

		if ( '' === trim( $sellmo_product_code ) ) {
			return null;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE pos_product_code = %s AND map_status = 1 LIMIT 1",
				$sellmo_product_code
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get a mapped row by WooCommerce product id.
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
				"SELECT * FROM `{$table}` WHERE wc_product_id = %d AND map_status = 1 LIMIT 1",
				$wc_product_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Resolve the mapped row for a Sellmo product payload.
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
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE map_status = 1" );
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
		$ids = $wpdb->get_col( "SELECT wc_product_id FROM `{$table}` WHERE map_status = 1 AND wc_product_id IS NOT NULL" );

		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Return every row (mapped and staged) ordered by product name.
	 *
	 * Backs the Product Mapper list, which reads its catalogue from this table
	 * rather than the live API so the list reflects stored data.
	 *
	 * @since  1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all_rows(): array {
		self::ensure_table();

		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY pos_product_name ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Return all mapped rows as lookup arrays.
	 *
	 * @since  1.0.0
	 * @return array{ by_id: array<string, array<string, mixed>>, by_code: array<string, array<string, mixed>> }
	 */
	public static function get_all_mappings_indexed(): array {
		self::ensure_table();

		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE map_status = 1", ARRAY_A );

		$indexed = [
			'by_id'   => [],
			'by_code' => [],
		];

		foreach ( (array) $rows as $row ) {
			$sellmo_id   = (string) ( $row['pos_product_id'] ?? '' );
			$sellmo_code = (string) ( $row['pos_product_code'] ?? '' );

			if ( '' !== $sellmo_id ) {
				$indexed['by_id'][ $sellmo_id ] = $row;
			}

			if ( '' !== $sellmo_code ) {
				$indexed['by_code'][ $sellmo_code ] = $row;
			}
		}

		return $indexed;
	}

	// =========================================================================
	// Staging (map_status = 0) — formerly Sellmo_Product_Mapper_Store
	// =========================================================================

	/**
	 * Whether a Sellmo product id already exists in the table (any status).
	 *
	 * @since  1.0.0
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
			$wpdb->prepare( "SELECT id FROM `{$table}` WHERE pos_product_id = %s LIMIT 1", $sellmo_product_id )
		);

		return null !== $found;
	}

	/**
	 * Return the row id for a Sellmo product (mapped or staged), or 0 if absent.
	 *
	 * Used to resolve the parent foreign key for wp_sellmo_product_variants.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_product_id   Sellmo product id.
	 * @param  string $sellmo_product_code Sellmo product code (fallback).
	 * @return int
	 */
	public static function get_id_by_sellmo( string $sellmo_product_id, string $sellmo_product_code = '' ): int {
		self::ensure_table();

		$row = self::find_row_by_sellmo_id( $sellmo_product_id );
		if ( ! is_array( $row ) && '' !== trim( $sellmo_product_code ) ) {
			$row = self::find_row_by_sellmo_code( $sellmo_product_code );
		}

		return is_array( $row ) ? (int) $row['id'] : 0;
	}

	/**
	 * Return a set of all pos_product_id values present (any status).
	 *
	 * @since  1.0.0
	 * @return array<string, true>
	 */
	public static function get_existing_id_set(): array {
		self::ensure_table();
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT pos_product_id FROM `{$table}`" );

		$set = [];
		foreach ( (array) $ids as $id ) {
			$set[ (string) $id ] = true;
		}

		return $set;
	}

	/**
	 * Insert a brand-new staged (unmapped) row. Never overwrites an existing row.
	 *
	 * @since  1.0.0
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

		$sellmo_product_id = trim( (string) ( $fields['pos_product_id'] ?? '' ) );
		if ( '' === $sellmo_product_id || self::exists( $sellmo_product_id ) ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		return false !== $wpdb->insert(
			self::table_name(),
			[
				'pos_product_id'   => $sellmo_product_id,
				'pos_product_code' => (string) ( $fields['pos_product_code'] ?? '' ),
				'pos_product_name' => (string) ( $fields['pos_product_name'] ?? '' ),
				'category'            => (string) ( $fields['category'] ?? '' ),
				'price'               => (float) ( $fields['price'] ?? 0 ),
				'variant_count'       => (int) ( $fields['variant_count'] ?? 0 ),
				'wc_product_id'       => null,
				'map_status'          => 0,
				'raw_payload'         => isset( $fields['raw_payload'] ) ? wp_json_encode( $fields['raw_payload'] ) : null,
				'imported_at'         => $now,
				'created_at'          => $now,
				'updated_at'          => $now,
			],
			[ '%s', '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Paginated, searchable list of staged (unmapped) Sellmo products (for Select2).
	 *
	 * @since  1.0.0
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

		$where  = ' WHERE map_status = 0';
		$params = [];

		$search = trim( $search );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where  .= ' AND ( pos_product_name LIKE %s OR pos_product_code LIKE %s )';
			$params  = [ $like, $like ];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}`" . $where, ...$params )
				: "SELECT COUNT(*) FROM `{$table}`" . $where
		);

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pos_product_id, pos_product_code, pos_product_name, category, price
				 FROM `{$table}`" . $where . ' ORDER BY pos_product_name ASC LIMIT %d OFFSET %d',
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
	 * Total number of rows (any status).
	 *
	 * @since  1.0.0
	 * @return int
	 */
	public static function count_all(): int {
		self::ensure_table();
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	// =========================================================================
	// Dashboard stats
	// =========================================================================

	/**
	 * Count WooCommerce products that are not linked to Sellmo.
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
				ON spc.post_id = p.ID AND spc.meta_key = '_pos_product_code'
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
	 * Build dashboard mapping statistics from the stored mapping table.
	 *
	 * Counts come straight from this table (populated by the dashboard
	 * "Sync Products Now" action) rather than the live API, so the figures
	 * reflect stored data and drop to zero once that data is cleared.
	 *
	 * @since  1.0.0
	 * @return array{ total_sellmo_products: int, mapped_products: int, unmapped_products: int, woocommerce_only_products: int }
	 */
	public static function get_dashboard_stats(): array {
		$total_sellmo = self::count_all();
		$mapped       = self::count_mapped_products();

		return [
			'total_sellmo_products'     => $total_sellmo,
			'mapped_products'           => $mapped,
			'unmapped_products'         => max( 0, $total_sellmo - $mapped ),
			'woocommerce_only_products' => self::count_woocommerce_only_products(),
		];
	}
}
