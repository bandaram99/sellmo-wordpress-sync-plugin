<?php
declare(strict_types=1);

/**
 * White-label brand configuration.
 *
 * Single source of truth for every brand-dependent value in the plugin.
 * Loads branding from a `.env` file in the plugin root (falling back to
 * wp-config constants, then neutral defaults) and exposes typed accessors.
 *
 * Two tiers of value live here (see .env.example for the full explanation):
 *
 *   - DISPLAY values  — name(), url(), email(), log_prefix() — safe to change
 *     at any time; read live on every request.
 *   - IDENTITY values — slug(), and everything derived from it: table(),
 *     option(), transient(), meta_key(), cron_hook(). Changing the slug on a
 *     live install requires a migration (see POS_Brand_Migrator); it is NOT a
 *     silent switch.
 *
 * This class has NO dependency on any brand-named constant so that it remains
 * valid regardless of the configured brand.
 *
 * @package POS_Integration
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class POS_Brand
 *
 * @since 1.1.0
 */
final class POS_Brand {

	/**
	 * Parsed configuration cache. Null until first access.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $config = null;

	/**
	 * Fallbacks used when a key is absent from BOTH .env and wp-config.
	 *
	 * These deliberately mirror the plugin's HISTORICAL (pre-white-label)
	 * identity — slug `sellmo`, name `Sellmo POS`, etc. Rationale: an existing
	 * install that upgrades to the white-label build WITHOUT yet creating a
	 * `.env` must behave identically to before — same table/option/meta keys,
	 * same UI — so nothing breaks and the migrator performs no work. Dropping a
	 * `.env` in place is then the single, deliberate act that rebrands the
	 * plugin (and triggers a one-time data migration; see POS_Brand_Migrator).
	 *
	 * This is the ONE place the legacy brand is named, and only as a safety
	 * fallback — never referenced by business logic.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULTS = [
		'name'        => 'Sellmo POS',
		'slug'        => 'sellmo',
		'namespace'   => 'Sellmo',
		'text_domain' => 'sellmo-woocommerce',
		'log_prefix'  => 'Sellmo',
		'logo'        => '',
		'website'     => 'https://sellmo.com/',
		'support'     => 'https://sellmo.com/',
		'docs'        => 'https://sellmo.com/',
		'email'       => '',
	];

	/**
	 * Absolute path to the `.env` file. Resolved relative to this file so the
	 * class carries no brand-named path constant.
	 *
	 * @return string
	 */
	private static function env_path(): string {
		return dirname( __DIR__ ) . '/.env';
	}

	/**
	 * Lazily load and cache the merged configuration.
	 *
	 * Precedence (highest first): .env file, wp-config constant, default.
	 *
	 * @return array<string, string>
	 */
	private static function config(): array {
		if ( null !== self::$config ) {
			return self::$config;
		}

		$env = self::parse_env( self::env_path() );

		$merged = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			$const = 'BRAND_' . strtoupper( $key );
			if ( isset( $env[ $key ] ) && '' !== $env[ $key ] ) {
				$value = $env[ $key ];
			} elseif ( defined( $const ) ) {
				$value = (string) constant( $const );
			} else {
				$value = $default;
			}
			$merged[ $key ] = $value;
		}

		// Slug is a data-identity key: hard-sanitise to [a-z0-9_].
		$merged['slug'] = self::sanitize_slug( $merged['slug'], self::DEFAULTS['slug'] );

		self::$config = $merged;
		return self::$config;
	}

	/**
	 * Minimal, dependency-free `.env` parser (KEY=VALUE, # comments, quotes).
	 * Keys are lower-cased and stripped of the leading `BRAND_`.
	 *
	 * @param  string $path Absolute path to the env file.
	 * @return array<string, string>
	 */
	private static function parse_env( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return [];
		}

		$out   = [];
		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $lines ) {
			return [];
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) || ! str_contains( $line, '=' ) ) {
				continue;
			}
			[ $raw_key, $raw_val ] = explode( '=', $line, 2 );
			$key = strtolower( trim( $raw_key ) );
			$key = str_starts_with( $key, 'brand_' ) ? substr( $key, 6 ) : $key;
			$val = trim( trim( $raw_val ), "\"'" );
			$out[ $key ] = $val;
		}

		return $out;
	}

	/**
	 * Force a slug to a safe identifier. Invalid input falls back to $fallback.
	 *
	 * @param  string $slug     Raw slug.
	 * @param  string $fallback Default if the sanitised slug is empty.
	 * @return string
	 */
	private static function sanitize_slug( string $slug, string $fallback ): string {
		$slug = strtolower( $slug );
		$slug = preg_replace( '/[^a-z0-9_]/', '_', $slug ) ?? '';
		$slug = trim( $slug, '_' );
		return '' === $slug ? $fallback : $slug;
	}

	/* ---------------------------------------------------------------------
	 * DISPLAY accessors — safe to change anytime.
	 * ------------------------------------------------------------------- */

	/**
	 * Visible brand name, e.g. "SalesPay".
	 *
	 * @return string
	 */
	public static function name(): string {
		return self::config()['name'];
	}

	/**
	 * Class-name prefix for NEW code / log tags, e.g. "SalesPay".
	 *
	 * @return string
	 */
	public static function namespace(): string {
		return self::config()['namespace'];
	}

	/**
	 * Log line prefix without brackets, e.g. "SalesPay".
	 *
	 * @return string
	 */
	public static function log_prefix(): string {
		return self::config()['log_prefix'];
	}

	/**
	 * Admin menu logo, as configured in `.env` (BRAND_LOGO).
	 *
	 * May be a path relative to the plugin root, a full URL, or a data URI.
	 * Returns '' when unset, in which case callers fall back to a dashicon.
	 * Relative paths are resolved to a URL by the caller (this class is
	 * brand-neutral and has no dependency on WordPress URL helpers).
	 *
	 * @return string
	 */
	public static function logo(): string {
		return self::config()['logo'];
	}

	/**
	 * A configured URL by key: 'website' | 'support' | 'docs'.
	 *
	 * @param  string $which URL key.
	 * @return string        The URL, or '' if unset.
	 */
	public static function url( string $which ): string {
		return self::config()[ $which ] ?? '';
	}

	/**
	 * Support / contact email address.
	 *
	 * @return string
	 */
	public static function email(): string {
		return self::config()['email'];
	}

	/* ---------------------------------------------------------------------
	 * IDENTITY accessors — derived from the slug. Changing the slug on a
	 * live install requires POS_Brand_Migrator; it is not a silent switch.
	 * ------------------------------------------------------------------- */

	/**
	 * Data-identity slug, e.g. "salespay".
	 *
	 * @return string
	 */
	public static function slug(): string {
		return self::config()['slug'];
	}

	/**
	 * Admin page-slug base: the identity slug with underscores converted to
	 * hyphens (e.g. "salespay", or "sales-pay" for a slug "sales_pay").
	 *
	 * WordPress admin menu slugs conventionally use hyphens, while the data
	 * identity slug uses [a-z0-9_]. This is the single source for both the
	 * menu/URL slugs (see page()) and for detecting plugin admin screens by
	 * hook suffix / screen id (every plugin page's hook contains this string).
	 *
	 * @return string
	 */
	public static function page_base(): string {
		return str_replace( '_', '-', self::slug() );
	}

	/**
	 * Admin menu / page slug used in admin.php?page=… URLs.
	 *
	 * Builds {page_base}-{suffix}. With no suffix it returns the top-level
	 * dashboard slug, e.g. "salespay-pos". Examples:
	 *   page()                  -> "salespay-pos"
	 *   page( 'settings' )      -> "salespay-settings"
	 *   page( 'sync-log' )      -> "salespay-sync-log"
	 *   page( 'product-mapper' )-> "salespay-product-mapper"
	 *
	 * @param  string $suffix Page suffix; defaults to 'pos' (the top-level page).
	 * @return string
	 */
	public static function page( string $suffix = 'pos' ): string {
		return self::page_base() . '-' . $suffix;
	}

	/**
	 * Fully-qualified custom table name: {wpdb->prefix}{slug}_{$suffix}.
	 *
	 * @param  string $suffix Table suffix, e.g. 'sync_log'.
	 * @return string
	 */
	public static function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . self::slug() . '_' . $suffix;
	}

	/**
	 * Option name: {slug}_wc_{$suffix}. Mirrors the historic sellmo_wc_ scheme.
	 *
	 * @param  string $suffix Option suffix, e.g. 'db_version'.
	 * @return string
	 */
	public static function option( string $suffix ): string {
		return self::slug() . '_wc_' . $suffix;
	}

	/**
	 * Transient name: {slug}_wc_{$suffix}.
	 *
	 * @param  string $suffix Transient suffix.
	 * @return string
	 */
	public static function transient( string $suffix ): string {
		return self::slug() . '_wc_' . $suffix;
	}

	/**
	 * Post/term meta key: _{slug}_{$suffix}, e.g. _salespay_product_code.
	 *
	 * @param  string $suffix Meta suffix, e.g. 'product_code'.
	 * @return string
	 */
	public static function meta_key( string $suffix ): string {
		return '_' . self::slug() . '_' . $suffix;
	}

	/**
	 * Cron hook name: {slug}_wc_{$suffix}, e.g. salespay_wc_stock_sync.
	 *
	 * @param  string $suffix Hook suffix, e.g. 'stock_sync'.
	 * @return string
	 */
	public static function cron_hook( string $suffix ): string {
		return self::slug() . '_wc_' . $suffix;
	}

	/**
	 * The fixed, brand-neutral text domain literal.
	 *
	 * Provided for reference only. Do NOT pass this to __()/_e() — i18n tooling
	 * requires a string literal there. See README "WordPress limitations".
	 *
	 * @return string
	 */
	public static function text_domain(): string {
		return self::config()['text_domain'];
	}

	/**
	 * Reset the in-memory cache. Test helper / call after writing .env.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$config = null;
	}
}
