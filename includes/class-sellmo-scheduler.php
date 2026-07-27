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

		// Register the actual cron callbacks that WordPress will invoke.
		add_action( $this->stock_hook,   [ $this, 'run_stock_sync' ] );
		add_action( $this->product_hook, [ $this, 'run_product_sync' ] );

		// Reschedule whenever the relevant settings options change.
		add_action( 'update_option_' . POS_Brand::option( 'stock_sync_enabled' ),    [ $this, 'reschedule_stock' ] );
		add_action( 'update_option_' . POS_Brand::option( 'stock_sync_interval' ),   [ $this, 'reschedule_stock' ] );
		add_action( 'update_option_' . POS_Brand::option( 'product_sync_enabled' ),  [ $this, 'reschedule_product' ] );
		add_action( 'update_option_' . POS_Brand::option( 'product_sync_interval' ), [ $this, 'reschedule_product' ] );

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

		if ( 'yes' === (string) Sellmo_Settings::get( 'stock_sync_enabled' ) ) {
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

		if ( 'yes' === (string) Sellmo_Settings::get( 'product_sync_enabled' ) ) {
			$interval = (string) ( Sellmo_Settings::get( 'product_sync_interval' ) ?: 'twicedaily' );
			wp_schedule_event( time() + 60, $interval, $this->product_hook );
		}
	}

	// -------------------------------------------------------------------------
	// Cron callbacks
	// -------------------------------------------------------------------------

	/**
	 * WP-Cron callback for stock sync.
	 *
	 * Instantiates Sellmo_Stock_Sync and delegates to run_sync(), which re-checks
	 * the enabled setting before doing any work.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function run_stock_sync(): void {
		$sync = new Sellmo_Stock_Sync();
		$sync->run_sync();
	}

	/**
	 * WP-Cron callback for product sync.
	 *
	 * Runs the incremental mapper sync: new POS products are staged into the
	 * Product Mapper and deletions reconciled, but nothing is pushed to
	 * WooCommerce — products only reach WooCommerce once explicitly mapped or
	 * imported from the Product Mapper.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function run_product_sync(): void {
		if ( 'yes' !== (string) Sellmo_Settings::get( 'product_sync_enabled' ) ) {
			return;
		}

		( new Sellmo_Product_Mapper_Sync() )->run_incremental_sync();
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
		$enabled    = 'yes' === (string) Sellmo_Settings::get( 'stock_sync_enabled' );
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
		$enabled   = 'yes' === (string) Sellmo_Settings::get( 'product_sync_enabled' );
		$scheduled = (bool) wp_next_scheduled( $this->product_hook );

		if ( $enabled && ! $scheduled ) {
			$interval = (string) ( Sellmo_Settings::get( 'product_sync_interval' ) ?: 'twicedaily' );
			wp_schedule_event( time() + 60, $interval, $this->product_hook );
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( $this->product_hook );
		}
	}
}
