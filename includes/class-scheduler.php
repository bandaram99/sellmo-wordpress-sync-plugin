<?php
declare(strict_types=1);

/**
 * WP-Cron schedule manager for Sellmo POS Integration.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Scheduler
 *
 * Registers and manages recurring WP-Cron events for stock sync
 * (sellmo_wc_stock_sync) and product sync (sellmo_wc_product_sync).
 *
 * Hook names match what the deactivation callback in sellmo-woocommerce.php
 * passes to wp_clear_scheduled_hook().
 *
 * Rescheduling happens automatically when the relevant settings options change
 * via the update_option_{option_name} action hooks wired up in __construct().
 *
 * @since 1.0.0
 */
class Sellmo_Scheduler {

	/**
	 * WP-Cron hook name for stock sync (derived from the brand slug).
	 *
	 * @var string
	 */
	private string $stock_hook;

	/**
	 * WP-Cron hook name for product sync (derived from the brand slug).
	 *
	 * @var string
	 */
	private string $product_hook;

	/**
	 * WP-Cron hook name for the order-status pull (derived from the brand slug).
	 *
	 * @var string
	 */
	private string $order_hook;

	/**
	 * Extra cron intervals this plugin registers, in minutes.
	 *
	 * WordPress ships only hourly / twicedaily / daily. Order statuses change
	 * while a customer waits, so the order pull needs minute-level choices.
	 *
	 * @since 1.6.0
	 * @var array<int, int>
	 */
	private const MINUTE_INTERVALS = [ 5, 10, 15, 20, 30 ];

	/**
	 * Cron schedule name for an interval in minutes, e.g. "sellmo_wc_every_5min".
	 *
	 * @since  1.6.0
	 * @param  int $minutes Interval length.
	 * @return string
	 */
	public static function minute_schedule( int $minutes ): string {
		return POS_Brand::cron_hook( 'every_' . $minutes . 'min' );
	}

	/**
	 * Every schedule the order sync may be set to: name => human label.
	 *
	 * Used by the settings dropdown and by the save handler to validate what
	 * comes back, so the two can never drift apart.
	 *
	 * @since  1.6.0
	 * @return array<string, string>
	 */
	public static function order_interval_choices(): array {
		$choices = [];

		foreach ( self::MINUTE_INTERVALS as $minutes ) {
			$choices[ self::minute_schedule( $minutes ) ] = sprintf(
				/* translators: %d: number of minutes */
				_n( 'Every %d minute', 'Every %d minutes', $minutes, 'sellmo-woocommerce' ),
				$minutes
			);
		}

		$choices['hourly']     = __( 'Hourly', 'sellmo-woocommerce' );
		$choices['twicedaily'] = __( 'Twice Daily', 'sellmo-woocommerce' );
		$choices['daily']      = __( 'Daily', 'sellmo-woocommerce' );

		return $choices;
	}

	/**
	 * Register the plugin's minute-level intervals with WP-Cron.
	 *
	 * Filter callback for `cron_schedules`; without it wp_schedule_event()
	 * silently refuses an unknown recurrence and nothing is ever scheduled.
	 *
	 * @since  1.6.0
	 * @param  array<string, array{interval:int, display:string}> $schedules Existing schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public function register_cron_intervals( array $schedules ): array {
		foreach ( self::MINUTE_INTERVALS as $minutes ) {
			$schedules[ self::minute_schedule( $minutes ) ] = [
				'interval' => $minutes * MINUTE_IN_SECONDS,
				'display'  => sprintf(
					/* translators: %d: number of minutes */
					_n( 'Every %d minute', 'Every %d minutes', $minutes, 'sellmo-woocommerce' ),
					$minutes
				),
			];
		}

		return $schedules;
	}

	/**
	 * Constructor.
	 *
	 * Registers cron callback hooks and settings-change hooks, then ensures
	 * both sync events are scheduled if the matching feature is enabled.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->stock_hook   = POS_Brand::cron_hook( 'stock_sync' );
		$this->product_hook = POS_Brand::cron_hook( 'product_sync' );
		$this->order_hook   = POS_Brand::cron_hook( 'order_status_sync' );

		// Minute-level recurrences must exist before anything is scheduled on one.
		add_filter( 'cron_schedules', [ $this, 'register_cron_intervals' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

		// Register the actual cron callbacks that WordPress will invoke.
		add_action( $this->stock_hook,   [ $this, 'run_stock_sync' ] );
		add_action( $this->product_hook, [ $this, 'run_product_sync' ] );
		add_action( $this->order_hook,   [ $this, 'run_order_status_sync' ] );

		// Reschedule whenever the relevant settings options change.
		add_action( 'update_option_' . POS_Brand::option( 'stock_sync_enabled' ),    [ $this, 'reschedule_stock' ] );
		add_action( 'update_option_' . POS_Brand::option( 'stock_sync_interval' ),   [ $this, 'reschedule_stock' ] );
		add_action( 'update_option_' . POS_Brand::option( 'product_sync_enabled' ),  [ $this, 'reschedule_product' ] );
		add_action( 'update_option_' . POS_Brand::option( 'product_sync_interval' ), [ $this, 'reschedule_product' ] );
		add_action( 'update_option_' . POS_Brand::option( 'order_sync_enabled' ),    [ $this, 'reschedule_order' ] );
		add_action( 'update_option_' . POS_Brand::option( 'order_sync_interval' ),   [ $this, 'reschedule_order' ] );

		// On every request, ensure events are scheduled if they should be but aren't.
		// This recovers from deactivation/reactivation cycles and fresh installs.
		$this->register_sync_schedules();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Schedule both sync events if not already scheduled and features are enabled.
	 *
	 * Safe to call repeatedly — uses wp_next_scheduled() to guard against
	 * duplicate event registration.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_sync_schedules(): void {
		$this->maybe_schedule_stock();
		$this->maybe_schedule_product();
		$this->maybe_schedule_order();
	}

	/**
	 * The order-status cron hook name.
	 *
	 * @since  1.6.0
	 * @return string
	 */
	public static function order_hook(): string {
		return POS_Brand::cron_hook( 'order_status_sync' );
	}

	/**
	 * The configured order-status interval, falling back to every 15 minutes.
	 *
	 * @since  1.6.0
	 * @return string
	 */
	public static function order_interval(): string {
		$interval = (string) ( Sellmo_Settings::get( 'order_sync_interval' ) ?: '' );

		return array_key_exists( $interval, self::order_interval_choices() )
			? $interval
			: self::minute_schedule( 15 );
	}

	/**
	 * Clear all scheduled cron events owned by this plugin.
	 *
	 * Called externally (e.g. from a test or future admin tool). Deactivation
	 * uses the direct wp_clear_scheduled_hook() calls in sellmo-woocommerce.php.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function clear_sync_schedules(): void {
		wp_clear_scheduled_hook( $this->stock_hook );
		wp_clear_scheduled_hook( $this->product_hook );
		wp_clear_scheduled_hook( $this->order_hook );
	}

	/**
	 * Whether a sync should run: its toggle is on AND an API token is saved.
	 *
	 * Without a token every run would just log a 401, so removing the token
	 * unschedules all three events on the next request.
	 *
	 * @since  1.8.0
	 * @param  string $type 'stock' | 'product' | 'order'.
	 * @return bool
	 */
	public static function enabled( string $type ): bool {
		return 'yes' === (string) Sellmo_Settings::get( $type . '_sync_enabled' )
			&& '' !== (string) Sellmo_Settings::get( 'api_token' );
	}

	// -------------------------------------------------------------------------
	// Reschedule callbacks (fired by update_option_* actions)
	// -------------------------------------------------------------------------

	/**
	 * Reschedule the stock sync event after a settings change.
	 *
	 * Clears any existing event and creates a new one if stock sync is enabled,
	 * so the next run reflects the current interval setting immediately.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function reschedule_stock(): void {
		wp_clear_scheduled_hook( $this->stock_hook );

		if ( self::enabled( 'stock' ) ) {
			$interval = (string) ( Sellmo_Settings::get( 'stock_sync_interval' ) ?: 'hourly' );
			wp_schedule_event( time() + 60, $interval, $this->stock_hook );
		}
	}

	/**
	 * Reschedule the product sync event after a settings change.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function reschedule_product(): void {
		wp_clear_scheduled_hook( $this->product_hook );

		if ( self::enabled( 'product' ) ) {
			$interval = (string) ( Sellmo_Settings::get( 'product_sync_interval' ) ?: 'twicedaily' );
			wp_schedule_event( time() + 60, $interval, $this->product_hook );
		}
	}

	/**
	 * Reschedule the order-status pull after a settings change.
	 *
	 * @since  1.6.0
	 * @return void
	 */
	public function reschedule_order(): void {
		wp_clear_scheduled_hook( $this->order_hook );

		if ( self::enabled( 'order' ) ) {
			wp_schedule_event( time() + 60, self::order_interval(), $this->order_hook );
		}
	}

	// -------------------------------------------------------------------------
	// Cron callbacks
	// -------------------------------------------------------------------------

	/**
	 * WP-Cron callback for stock sync.
	 *
	 * Runs the exact same implementation as the "Sync Stock Now" button
	 * (Sellmo_Stock_Sync_Pagination) so scheduled runs are shop-scoped and pull
	 * fresh inventory, identical to the manual action. Gated by the enabled
	 * setting so a disabled sync is a no-op.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function run_stock_sync(): void {
		if ( ! self::enabled( 'stock' ) ) {
			return;
		}

		( new Sellmo_Stock_Sync_Pagination() )->run();
	}

	/**
	 * WP-Cron callback for product sync.
	 *
	 * Runs the incremental mapper sync: new POS products are staged into the
	 * Product Mapper and deletions reconciled, but nothing is pushed to
	 * WooCommerce — products only reach WooCommerce once explicitly mapped or
	 * imported from the Product Mapper.
	 *
	 * Matches the "Sync Products Now" button by forcing a fresh catalogue fetch
	 * rather than reading the cached /products transient.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function run_product_sync(): void {
		if ( ! self::enabled( 'product' ) ) {
			return;
		}

		Sellmo_API::set_force_fresh( true );

		( new Sellmo_Product_Mapper_Sync() )->run_incremental_sync();
	}

	/**
	 * WP-Cron callback for the order-status pull.
	 *
	 * Runs the same code as the "Sync Order Status Now" button, so scheduled and
	 * manual runs cannot drift. Gated by the order sync toggle.
	 *
	 * @since  1.6.0
	 * @return void
	 */
	public function run_order_status_sync(): void {
		if ( ! self::enabled( 'order' ) ) {
			return;
		}

		( new Sellmo_Order_Sync() )->sync_order_statuses();
	}

	// -------------------------------------------------------------------------
	// Private scheduling helpers
	// -------------------------------------------------------------------------

	/**
	 * Schedule the stock sync event if it should be running but isn't yet.
	 *
	 * Also clears the event if stock sync has been disabled since the last
	 * schedule registration.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function maybe_schedule_stock(): void {
		$enabled    = self::enabled( 'stock' );
		$scheduled  = (bool) wp_next_scheduled( $this->stock_hook );

		if ( $enabled && ! $scheduled ) {
			$interval = (string) ( Sellmo_Settings::get( 'stock_sync_interval' ) ?: 'hourly' );
			wp_schedule_event( time() + 60, $interval, $this->stock_hook );
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( $this->stock_hook );
		}
	}

	/**
	 * Schedule the product sync event if it should be running but isn't yet.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function maybe_schedule_product(): void {
		$enabled   = self::enabled( 'product' );
		$scheduled = (bool) wp_next_scheduled( $this->product_hook );

		if ( $enabled && ! $scheduled ) {
			$interval = (string) ( Sellmo_Settings::get( 'product_sync_interval' ) ?: 'twicedaily' );
			wp_schedule_event( time() + 60, $interval, $this->product_hook );
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( $this->product_hook );
		}
	}

	/**
	 * Schedule the order-status pull if it should be running but isn't yet.
	 *
	 * @since  1.6.0
	 * @return void
	 */
	private function maybe_schedule_order(): void {
		$enabled   = self::enabled( 'order' );
		$scheduled = (bool) wp_next_scheduled( $this->order_hook );

		if ( $enabled && ! $scheduled ) {
			wp_schedule_event( time() + 60, self::order_interval(), $this->order_hook );
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( $this->order_hook );
		}
	}
}
