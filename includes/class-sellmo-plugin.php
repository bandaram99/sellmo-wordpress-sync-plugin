<?php
declare(strict_types=1);

/**
 * Main plugin class for Sellmo POS Integration.
 *
 * Singleton that bootstraps all plugin modules and registers
 * the admin asset enqueue hook.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Plugin
 *
 * Single entry point for the plugin runtime. Instantiates all module
 * classes and wires up shared hooks.
 *
 * @since 1.0.0
 */
class Sellmo_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Admin module.
	 *
	 * @var Sellmo_Admin
	 */
	private Sellmo_Admin $admin;

	/**
	 * Product sync module.
	 *
	 * @var Sellmo_Product_Sync
	 */
	private Sellmo_Product_Sync $product_sync;

	/**
	 * Stock sync module.
	 *
	 * @var Sellmo_Stock_Sync
	 */
	private Sellmo_Stock_Sync $stock_sync;

	/**
	 * Order sync module.
	 *
	 * @var Sellmo_Order_Sync
	 */
	private Sellmo_Order_Sync $order_sync;

	/**
	 * Webhook handler module.
	 *
	 * @var Sellmo_Webhook
	 */
	private Sellmo_Webhook $webhook;

	/**
	 * Cron scheduler module.
	 *
	 * @var Sellmo_Scheduler
	 */
	private Sellmo_Scheduler $scheduler;

	/**
	 * Mapper AJAX module.
	 *
	 * @var Sellmo_Mapper_Ajax
	 */
	private Sellmo_Mapper_Ajax $mapper_ajax;

	/**
	 * Retrieve or create the singleton instance.
	 *
	 * @since  1.0.0
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Prevent cloning of the singleton.
	 *
	 * @since 1.0.0
	 */
	public function __clone(): void {}

	/**
	 * Prevent unserialization of the singleton.
	 *
	 * @since 1.0.0
	 */
	public function __wakeup(): void {}

	/**
	 * Instantiate all module classes and register shared hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function init(): void {
		$this->admin        = new Sellmo_Admin();
		$this->product_sync = new Sellmo_Product_Sync();
		$this->stock_sync   = new Sellmo_Stock_Sync();
		$this->order_sync   = new Sellmo_Order_Sync();
		$this->webhook      = new Sellmo_Webhook();
		$this->scheduler    = new Sellmo_Scheduler();
		$this->mapper_ajax  = new Sellmo_Mapper_Ajax();

		add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
	}

	/**
	 * Enqueue admin CSS and JS on plugin pages only.
	 *
	 * The $hook parameter contains the full page suffix (e.g.
	 * 'toplevel_page_sellmo-pos'). All plugin-registered pages include
	 * 'sellmo' in their hook suffix, so a single str_contains check suffices.
	 *
	 * @since  1.0.0
	 * @param  string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function admin_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'sellmo' ) ) {
			return;
		}

		wp_enqueue_style(
			'sellmo-admin',
			SELLMO_WC_URL . 'admin/css/sellmo-admin.css',
			[],
			SELLMO_WC_VERSION
		);

		wp_enqueue_script(
			'sellmo-admin',
			SELLMO_WC_URL . 'admin/js/sellmo-admin.js',
			[ 'jquery' ],
			SELLMO_WC_VERSION,
			true
		);
	}
}
