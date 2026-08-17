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
	 * Retrieve a plugin option value.
	 *
	 * The stored option name is derived from the configured brand slug via
	 * POS_Brand::option() (e.g. `{slug}_wc_{key}`).
	 *
	 * @since  1.0.0
	 * @param  string $key The option key without the brand prefix.
	 * @return mixed       The stored value, or false if the option does not exist.
	 */
	public static function get( string $key ): mixed {
		return get_option( POS_Brand::option( $key ) );
	}

	/**
	 * Update a plugin option value.
	 *
	 * @since  1.0.0
	 * @param  string $key   The option key without the brand prefix.
	 * @param  mixed  $value The value to store.
	 * @return bool          True on success, false if the value is unchanged or on failure.
	 */
	public static function set( string $key, mixed $value ): bool {
		return update_option( POS_Brand::option( $key ), $value );
	}

	/**
	 * Return the complete map of plugin options with their default values.
	 *
	 * Keys match the unprefixed option names accepted by get() and set().
	 *
	 * @since  1.0.0
	 * @return array<string, mixed>
	 */
	/**
	 * Frequently used time zones, shown above the full list.
	 *
	 * Only the place name is curated — the UTC offset is always computed from
	 * the zone itself (see utc_offset_label()), so a hardcoded offset can never
	 * drift out of step with the real one or with daylight saving.
	 *
	 * @var array<string, string> IANA identifier => place name.
	 */
	private const COMMON_ZONES = [
		'Pacific/Midway'      => 'Midway Island, Samoa',
		'America/Los_Angeles' => 'Pacific Time (US & Canada)',
		'America/Denver'      => 'Mountain Time (US & Canada)',
		'America/Chicago'     => 'Central Time (US & Canada)',
		'America/New_York'    => 'Eastern Time (US & Canada)',
		'America/Sao_Paulo'   => 'Brasilia, Sao Paulo',
		'Europe/London'       => 'London, Dublin, Lisbon',
		'Europe/Paris'        => 'Paris, Berlin, Madrid, Rome',
		'Europe/Athens'       => 'Athens, Helsinki, Istanbul',
		'Asia/Dubai'          => 'Abu Dhabi, Dubai, Muscat',
		'Asia/Tehran'         => 'Iran',
		'Asia/Kabul'          => 'Afghanistan',
		'Asia/Karachi'        => 'Pakistan, Karachi, Tashkent',
		'Asia/Colombo'        => 'Sri Lanka, India',
		'Asia/Kathmandu'      => 'Nepal',
		'Asia/Dhaka'          => 'Bangladesh, Dhaka',
		'Asia/Yangon'         => 'Myanmar',
		'Asia/Bangkok'        => 'Bangkok, Hanoi, Jakarta',
		'Asia/Singapore'      => 'Singapore, Beijing, Perth',
		'Asia/Tokyo'          => 'Tokyo, Seoul',
		'Australia/Adelaide'  => 'Adelaide, Darwin (Australia)',
		'Australia/Sydney'    => 'Sydney, Melbourne, Brisbane',
		'Pacific/Auckland'    => 'Auckland, Wellington',
	];

	/**
	 * A time zone's STANDARD (non-daylight-saving) UTC offset, in seconds.
	 *
	 * Labels use standard time, not the offset in force today, so the dropdown
	 * reads the same all year — London is always "(UTC+00:00)", never "+01:00"
	 * for half the year. This matches how every other time zone picker labels
	 * its entries.
	 *
	 * @since  1.5.0
	 * @param  string $timezone_id IANA identifier.
	 * @return int|null            Offset in seconds, or null if the id is unknown.
	 */
	private static function standard_offset( string $timezone_id ): ?int {
		try {
			$zone = new \DateTimeZone( $timezone_id );
		} catch ( \Exception $e ) {
			return null;
		}

		$now = time();

		// Look ahead far enough to cross a daylight-saving boundary in either
		// hemisphere, and take the first non-DST period found.
		$transitions = $zone->getTransitions( $now, $now + ( 400 * DAY_IN_SECONDS ) );

		if ( is_array( $transitions ) ) {
			foreach ( $transitions as $transition ) {
				if ( empty( $transition['isdst'] ) ) {
					return (int) $transition['offset'];
				}
			}
		}

		// Zones on permanent daylight saving, or with no transition data.
		return $zone->getOffset( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Format a time zone's standard UTC offset as "(UTC±HH:MM)".
	 *
	 * The offset comes from the zone itself, so fractional zones are exact —
	 * Sri Lanka +05:30, Nepal +05:45, Myanmar +06:30, Afghanistan +04:30.
	 *
	 * @since  1.5.0
	 * @param  string $timezone_id IANA identifier, e.g. 'Asia/Colombo'.
	 * @return string              e.g. '(UTC+05:30)', or '' if the id is unknown.
	 */
	public static function utc_offset_label( string $timezone_id ): string {
		$offset = self::standard_offset( $timezone_id );

		if ( null === $offset ) {
			return '';
		}

		$absolute = abs( $offset );

		return sprintf(
			'(UTC%s%02d:%02d)',
			$offset < 0 ? '-' : '+',
			intdiv( $absolute, 3600 ),
			intdiv( $absolute % 3600, 60 )
		);
	}

	/**
	 * Time zone choices for the Order Time Zone dropdown.
	 *
	 * Returns two option groups — a short curated list, then every IANA zone —
	 * each sorted west to east by current offset (UTC-12:00 through UTC+14:00)
	 * and labelled offset-first: "(UTC+05:30) Sri Lanka, India".
	 *
	 * Keys are always real IANA identifiers, which is what the settings handler
	 * validates against and what Sellmo_Order_Sync hands to DateTimeZone.
	 *
	 * @since  1.5.0
	 * @return array<string, array<string, string>> group label => [ id => label ].
	 */
	public static function timezone_choices(): array {
		$build = static function ( array $zones ): array {
			$rows = [];

			foreach ( $zones as $id => $place ) {
				$offset = self::standard_offset( $id );

				if ( null === $offset ) {
					continue; // Identifier retired from the tz database.
				}

				$rows[] = [
					'id'     => $id,
					'offset' => $offset,
					'label'  => self::utc_offset_label( $id ) . ' ' . $place,
				];
			}

			// West to east, then alphabetically inside a shared offset.
			usort(
				$rows,
				static fn( array $a, array $b ): int => $a['offset'] <=> $b['offset'] ?: strcmp( $a['label'], $b['label'] )
			);

			$choices = [];
			foreach ( $rows as $row ) {
				$choices[ $row['id'] ] = $row['label'];
			}

			return $choices;
		};

		// Full list: derive the place name from the identifier itself, so it
		// stays complete and current without a lookup table to maintain.
		$all = [];
		foreach ( timezone_identifiers_list() as $id ) {
			$parts = explode( '/', $id );
			$city  = str_replace( '_', ' ', array_pop( $parts ) );
			$all[ $id ] = ! empty( $parts ) ? $city . ' (' . implode( '/', $parts ) . ')' : $city;
		}

		return [
			__( 'Common time zones', 'sellmo-woocommerce' ) => $build( self::COMMON_ZONES ),
			__( 'All time zones', 'sellmo-woocommerce' )    => $build( $all ),
		];
	}

	/**
	 * The time zone plugin dates are displayed in.
	 *
	 * The configured Order Time Zone when one is set, otherwise the WordPress
	 * time zone — the same fallback Sellmo_Order_Sync uses, so what the merchant
	 * sees on the Dashboard matches the timestamps sent to the POS.
	 *
	 * @since  1.6.0
	 * @return \DateTimeZone
	 */
	public static function display_timezone(): \DateTimeZone {
		$id = (string) ( self::get( 'order_timezone' ) ?: '' );

		if ( '' !== $id && in_array( $id, timezone_identifiers_list(), true ) ) {
			return new \DateTimeZone( $id );
		}

		return wp_timezone();
	}

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
			'order_timezone'        => '',
		];
	}
}
