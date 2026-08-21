<?php
declare(strict_types=1);

/**
 * Sync activity logger for Sellmo POS Integration.
 *
 * Provides static methods for creating, writing to, reading from,
 * and dropping the custom sellmo_sync_log database table.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Logger
 *
 * All plugin logging must go through this class.
 * Never use error_log() in plugin code — use Sellmo_Logger::log() instead.
 *
 * @since 1.0.0
 */
class Sellmo_Logger {

	/**
	 * Whether we have already verified the table exists in this request.
	 *
	 * Avoids repeating the SHOW TABLES check on every log() call.
	 *
	 * @var bool
	 */
	private static bool $table_verified = false;

	/**
	 * Create the sync log table if it does not already exist.
	 *
	 * Called on plugin activation only — not on every page load.
	 * Uses dbDelta() which is idempotent but requires exact SQL formatting:
	 * two spaces between column name and type, PRIMARY KEY on its own line.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = POS_Brand::table( 'sync_log' );
		$charset = $wpdb->get_charset_collate();

		// Two spaces between column name and data type are required by dbDelta.
		$sql = "CREATE TABLE {$table} (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sync_type   VARCHAR(50) NOT NULL DEFAULT '',
  direction   VARCHAR(20) NOT NULL DEFAULT '',
  status      VARCHAR(20) NOT NULL DEFAULT '',
  message     TEXT NOT NULL,
  item_id     VARCHAR(100) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  KEY sync_type (sync_type),
  KEY status (status),
  KEY created_at (created_at)
) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * One-time migration of legacy brand-named direction values.
	 *
	 * Rows written before the neutral rename stored the sync direction as
	 * 'sellmo_to_wc' / 'wc_to_sellmo'. Rewrite them to the neutral
	 * 'pos_to_wc' / 'wc_to_pos'. Guarded by an option so it runs once.
	 *
	 * @since  1.0.1
	 * @return void
	 */
	public static function maybe_migrate_directions(): void {
		if ( get_option( POS_Brand::option( 'log_dir_migrated' ) ) ) {
			return;
		}

		global $wpdb;
		$table = POS_Brand::table( 'sync_log' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted $wpdb->prefix.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table === $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE `{$table}` SET direction = 'pos_to_wc' WHERE direction = 'sellmo_to_wc'" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE `{$table}` SET direction = 'wc_to_pos' WHERE direction = 'wc_to_sellmo'" );
		}

		update_option( POS_Brand::option( 'log_dir_migrated' ), '1', false );
	}

	/**
	 * Insert a log entry into the sync log table.
	 *
	 * @since  1.0.0
	 * @param  string $sync_type  Type of sync operation: 'product', 'stock', 'order', 'webhook'.
	 * @param  string $direction  Direction of the sync: 'pos_to_wc' or 'wc_to_pos'.
	 * @param  string $status     Outcome: 'success', 'error', or 'skipped'.
	 * @param  string $message    Human-readable description of the outcome.
	 * @param  string $item_id    Optional. WC product/order ID or Sellmo product code.
	 * @return void
	 */
	public static function log(
		string $sync_type,
		string $direction,
		string $status,
		string $message,
		string $item_id = ''
	): void {
		global $wpdb;

		// Self-heal: create the table if it was never created (e.g. dbDelta failed
		// at activation due to MySQL strict mode rejecting the original zero-date
		// default). Static flag keeps it to one SHOW TABLES check per request.
		if ( ! self::$table_verified ) {
			self::ensure_table();
			self::$table_verified = true;
		}

		$wpdb->insert(
			POS_Brand::table( 'sync_log' ),
			[
				'sync_type'  => $sync_type,
				'direction'  => $direction,
				'status'     => $status,
				'message'    => $message,
				'item_id'    => $item_id,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Verify the sync log table exists; create it if it is missing.
	 *
	 * Called once per request from log() via the static $table_verified flag.
	 * Uses a fast SHOW TABLES LIKE query rather than loading upgrade.php on
	 * every request.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function ensure_table(): void {
		global $wpdb;

		$table = POS_Brand::table( 'sync_log' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted $wpdb->prefix.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $table === $exists ) {
			return;
		}

		self::create_table();

		// Verify dbDelta actually created the table — it swallows MySQL errors silently.
		// On MySQL 8 with strict mode the old '0000-00-00' default would cause a silent
		// failure here; the schema now uses '1970-01-01 00:00:00' to avoid that.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$created = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $table !== $created ) {
			// Store the MySQL error so the admin_notices handler can surface it.
			update_option(
				POS_Brand::option( 'db_error' ),
				sprintf(
					'Failed to create sellmo_sync_log. MySQL said: %s',
					$wpdb->last_error ?: 'unknown error (check MySQL strict mode settings)'
				)
			);
		} else {
			delete_option( POS_Brand::option( 'db_error' ) );
		}
	}

	/**
	 * Retrieve log entries with optional filtering and pagination.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $args {
	 *     Optional query arguments.
	 *
	 *     @type string $type      Filter by sync_type value.
	 *     @type string $status    Filter by status value.
	 *     @type string $date_from ISO date string (YYYY-MM-DD) — inclusive lower bound on created_at.
	 *     @type string $date_to   ISO date string (YYYY-MM-DD) — inclusive upper bound on created_at.
	 *     @type int    $per_page  Number of results per page. Default 20.
	 *     @type int    $paged     1-based page number. Default 1.
	 * }
	 * @return array{ items: array<int, array<string, mixed>>, total: int }
	 */
	public static function get_logs( array $args = [] ): array {
		global $wpdb;

		$table    = POS_Brand::table( 'sync_log' );
		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;
		$per_page = max( 1, $per_page );
		$paged    = isset( $args['paged'] ) ? absint( $args['paged'] ) : 1;
		$paged    = max( 1, $paged );
		$offset   = ( $paged - 1 ) * $per_page;

		$where  = [];
		$values = [];

		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'sync_type = %s';
			$values[] = sanitize_text_field( (string) $args['type'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_text_field( (string) $args['status'] );
		}

		if ( ! empty( $args['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = $args['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = $args['date_to'] . ' 23:59:59';
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

		// Total count — prepare() accepts zero additional args when there are no placeholders.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted $wpdb->prefix.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM `{$table}`" . $where_sql,
				...$values
			)
		);

		// Paginated result set.
		$query_values   = $values;
		$query_values[] = $per_page;
		$query_values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `{$table}`" . $where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d',
				...$query_values
			),
			ARRAY_A
		);

		return [
			'items' => $items ?: [],
			'total' => $total,
		];
	}

	/**
	 * Delete all entries from the sync log table.
	 *
	 * Preserves the table structure. Called by the admin Clear All Logs action.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function clear_all(): void {
		global $wpdb;

		$table = POS_Brand::table( 'sync_log' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );
	}

	/**
	 * Drop the sync log table entirely.
	 *
	 * Called only from uninstall.php — never on deactivation.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table = POS_Brand::table( 'sync_log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted $wpdb->prefix.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
}
