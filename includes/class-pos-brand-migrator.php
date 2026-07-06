<?php
declare(strict_types=1);

/**
 * Brand-slug migration.
 *
 * The data-identity slug (POS_Brand::slug()) drives every table, option,
 * transient, meta key, and cron hook name. If an operator changes BRAND_SLUG
 * in .env on a live install, all existing data still lives under the OLD slug.
 * This class detects that change and renames the data in place, so nothing is
 * orphaned.
 *
 * The "current" slug is remembered in a fixed, brand-NEUTRAL option
 * (self::FINGERPRINT_OPTION) — it must not itself be brand-namespaced, or we
 * could never find it after a rename. This is the one deliberately
 * un-branded storage key in the plugin.
 *
 * Run maybe_migrate() early on every admin request (e.g. admin_init).
 *
 * @package POS_Integration
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class POS_Brand_Migrator
 *
 * @since 1.1.0
 */
final class POS_Brand_Migrator {

	/**
	 * Neutral option storing the slug the data was last written under.
	 * Deliberately NOT brand-namespaced.
	 */
	private const FINGERPRINT_OPTION = 'pos_integration_data_slug';

	/**
	 * The plugin's original slug, used to detect and adopt data created before
	 * the white-label system existed (no fingerprint recorded yet).
	 */
	private const LEGACY_SLUG = 'sellmo';

	/**
	 * Table suffixes owned by the plugin (without slug prefix).
	 *
	 * @var string[]
	 */
	private const TABLE_SUFFIXES = [ 'sync_log', 'product_mapping', 'product_variants' ];

	/**
	 * Option suffixes owned by the plugin (the part after {slug}_wc_).
	 *
	 * @var string[]
	 */
	private const OPTION_SUFFIXES = [
		'db_version',
		'db_error',
		'last_product_sync',
		'last_stock_sync',
		'last_order_sync',
		'mapper_merged',
		'api_token',
		'shop_id',
		'webhook_secret',
		'product_sync_enabled',
		'stock_sync_enabled',
		'order_sync_enabled',
		'stock_sync_interval',
		'product_sync_interval',
		'sync_categories',
		'terminal_key',
	];

	/**
	 * Cron hook suffixes owned by the plugin.
	 *
	 * @var string[]
	 */
	private const CRON_SUFFIXES = [ 'stock_sync', 'product_sync' ];

	/**
	 * Meta-key suffixes owned by the plugin (postmeta), stored as _{slug}_{suffix}.
	 *
	 * @var string[]
	 */
	private const META_SUFFIXES = [
		'product_code',
		'sync_enabled',
		'last_synced',
		'image_source_url',
		'order_id',
		'sync_error',
	];

	/**
	 * Record the current slug as the baseline. Call on plugin activation so a
	 * fresh install never triggers a spurious migration.
	 *
	 * @return void
	 */
	public static function set_baseline(): void {
		update_option( self::FINGERPRINT_OPTION, POS_Brand::slug(), false );
	}

	/**
	 * Detect a slug change and migrate data if needed. Idempotent and cheap
	 * when nothing changed (a single option read).
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		$stored  = (string) get_option( self::FINGERPRINT_OPTION, '' );
		$current = POS_Brand::slug();

		if ( '' === $stored ) {
			// No fingerprint yet: either a fresh install (activation should have
			// set a baseline) or an install that predates the white-label system.
			// Probe for legacy data to decide whether a migration is due, so the
			// result is correct regardless of whether .env was created before or
			// after this upgrade ran.
			$stored = self::detect_existing_slug( $current );
		}

		if ( $stored === $current ) {
			update_option( self::FINGERPRINT_OPTION, $current, false );
			return;
		}

		self::migrate( $stored, $current );
		update_option( self::FINGERPRINT_OPTION, $current, false );
	}

	/**
	 * Determine which slug existing data lives under when no fingerprint is set.
	 *
	 * Checks for the legacy sync-log table; if present (and the configured slug
	 * differs), the data belongs to the legacy slug and must be migrated.
	 * Otherwise this is effectively a fresh install under the current slug.
	 *
	 * @param  string $current The currently configured slug.
	 * @return string          The slug existing data is stored under.
	 */
	private static function detect_existing_slug( string $current ): string {
		global $wpdb;

		$legacy_table = $wpdb->prefix . self::LEGACY_SLUG . '_sync_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier from a class constant.
		$legacy_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$legacy_table}'" ) === $legacy_table;

		if ( $legacy_exists && self::LEGACY_SLUG !== $current ) {
			return self::LEGACY_SLUG;
		}

		return $current;
	}

	/**
	 * Rename all owned data from $old slug to $new slug.
	 *
	 * @param  string $old Previous slug.
	 * @param  string $new New slug.
	 * @return void
	 */
	private static function migrate( string $old, string $new ): void {
		global $wpdb;

		// 1. Tables — RENAME TABLE is atomic and preserves all rows/indexes.
		foreach ( self::TABLE_SUFFIXES as $suffix ) {
			$from = $wpdb->prefix . $old . '_' . $suffix;
			$to   = $wpdb->prefix . $new . '_' . $suffix;
			// Only rename if source exists and target does not.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from trusted, sanitised slug.
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$from}'" ) === $from
				&& $wpdb->get_var( "SHOW TABLES LIKE '{$to}'" ) !== $to ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from trusted, sanitised slug.
				$wpdb->query( "RENAME TABLE `{$from}` TO `{$to}`" );
			}
		}

		// 2. Options — copy value under new key, delete old.
		foreach ( self::OPTION_SUFFIXES as $suffix ) {
			$from = $old . '_wc_' . $suffix;
			$to   = $new . '_wc_' . $suffix;
			$val  = get_option( $from, null );
			if ( null !== $val ) {
				update_option( $to, $val );
				delete_option( $from );
			}
		}

		// 3. Post meta — bulk key rename.
		foreach ( self::META_SUFFIXES as $suffix ) {
			$from = '_' . $old . '_' . $suffix;
			$to   = '_' . $new . '_' . $suffix;
			$wpdb->update( $wpdb->postmeta, [ 'meta_key' => $to ], [ 'meta_key' => $from ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		}

		// 4. Cron — clear old hooks; the scheduler reschedules under new names.
		foreach ( self::CRON_SUFFIXES as $suffix ) {
			wp_clear_scheduled_hook( $old . '_wc_' . $suffix );
		}

		// 5. Transients are ephemeral — let the stale ones expire on their own.
	}
}
