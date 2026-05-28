<?php
declare(strict_types=1);

/**
 * Settings helper for Sellmo POS Integration.
 *
 * Provides static get/set wrappers that automatically apply the
 * sellmo_wc_ option prefix, plus a defaults map used during setup.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Settings
 *
 * Static helper for reading and writing plugin options.
 * All options are stored with the prefix `sellmo_wc_`.
 *
 * @since 1.0.0
 */
class Sellmo_Settings {

	/**
	 * Option name prefix applied to every key.
	 */
	private const PREFIX = 'sellmo_wc_';

	/**
	 * Retrieve a plugin option value.
	 *
	 * @since  1.0.0
	 * @param  string $key The option key without the sellmo_wc_ prefix.
	 * @return mixed       The stored value, or false if the option does not exist.
	 */
	public static function get( string $key ): mixed {
		return get_option( self::PREFIX . $key );
	}

	/**
	 * Update a plugin option value.
	 *
	 * @since  1.0.0
	 * @param  string $key   The option key without the sellmo_wc_ prefix.
	 * @param  mixed  $value The value to store.
	 * @return bool          True on success, false if the value is unchanged or on failure.
	 */
	public static function set( string $key, mixed $value ): bool {
		return update_option( self::PREFIX . $key, $value );
	}

	/**
	 * Return the complete map of plugin options with their default values.
	 *
	 * Keys match the unprefixed option names accepted by get() and set().
	 *
	 * @since  1.0.0
	 * @return array<string, mixed>
	 */
	public static function get_all_defaults(): array {
		return [
			'api_token'             => '',
			'shop_id'               => '',
			'webhook_secret'        => '',
			'product_sync_enabled'  => 'no',
			'stock_sync_enabled'    => 'no',
			'order_sync_enabled'    => 'no',
			'stock_sync_interval'   => 'hourly',
			'product_sync_interval' => 'twicedaily',
			'sync_categories'       => [],
			'terminal_key'          => '',
		];
	}
}
