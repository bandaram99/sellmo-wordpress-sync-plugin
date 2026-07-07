<?php
declare(strict_types=1);

/**
 * Plugin Name:       POS Integration
 * Plugin URI:        https://salesplay.com/
 * Description:       Integrates WooCommerce with POS — product sync, stock sync, and order sync between the two platforms.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            SalesPay
 * Author URI:        https://salesplay.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       salespay-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 *
 * @package Sellmo_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
define( 'SELLMO_WC_VERSION',  '1.0.1' );
define( 'SELLMO_WC_PATH',     plugin_dir_path( __FILE__ ) );
define( 'SELLMO_WC_URL',      plugin_dir_url( __FILE__ ) );
define( 'SELLMO_WC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Activation callback.
 *
 * Runs when the plugin is activated. Explicitly requires the logger class so
 * the table can be created before the normal plugins_loaded bootstrap runs.
 *
 * @since 1.0.0
 */
function sellmo_wc_activate(): void {
	require_once SELLMO_WC_PATH . 'includes/class-pos-brand.php';
	require_once SELLMO_WC_PATH . 'includes/class-pos-brand-migrator.php';
	require_once SELLMO_WC_PATH . 'includes/class-sellmo-logger.php';
	require_once SELLMO_WC_PATH . 'includes/class-sellmo-product-mapping.php';
	require_once SELLMO_WC_PATH . 'includes/class-sellmo-product-variant-store.php';

	Sellmo_Product_Variant_Store::create_table();
	Sellmo_Logger::create_table();
	Sellmo_Product_Mapping::create_table();
	update_option( POS_Brand::option( 'db_version' ), SELLMO_WC_VERSION );

	// Record the brand slug this install's data is stored under, so a later
	// slug change is detected as a migration rather than a fresh install.
	POS_Brand_Migrator::set_baseline();
}
register_activation_hook( __FILE__, 'sellmo_wc_activate' );

/**
 * Deactivation callback.
 *
 * Clears all scheduled cron events registered by this plugin.
 * Does NOT drop the sync log table — that only happens on full uninstall.
 *
 * @since 1.0.0
 */
function sellmo_wc_deactivate(): void {
	require_once SELLMO_WC_PATH . 'includes/class-pos-brand.php';
	wp_clear_scheduled_hook( POS_Brand::cron_hook( 'stock_sync' ) );
	wp_clear_scheduled_hook( POS_Brand::cron_hook( 'product_sync' ) );
}
register_deactivation_hook( __FILE__, 'sellmo_wc_deactivate' );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 *
 * Must run on before_woocommerce_init — calling FeaturesUtil outside this
 * hook triggers a doing_it_wrong notice in WooCommerce 8+.
 *
 * @since 1.0.0
 */
add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Bootstrap the plugin after all plugins have loaded.
 *
 * Checks for WooCommerce first. If WC is absent, registers an admin notice
 * and halts — no plugin classes are loaded in that case.
 *
 * @since 1.0.0
 */
add_action(
	'plugins_loaded',
	function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function (): void {
					?>
					<div class="notice notice-error">
						<p>
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: WooCommerce plugin link */
									__( '<strong>Sellmo POS Integration</strong> requires %s to be installed and active.', 'sellmo-woocommerce' ),
									'<a href="https://woocommerce.com/" target="_blank" rel="noopener noreferrer">WooCommerce</a>'
								),
								[
									'strong' => [],
									'a'      => [
										'href'   => [],
										'target' => [],
										'rel'    => [],
									],
								]
							);
							?>
						</p>
					</div>
					<?php
				}
			);
			return;
		}

		// Load all classes in dependency order.
		require_once SELLMO_WC_PATH . 'includes/class-pos-brand.php';
			require_once SELLMO_WC_PATH . 'includes/class-pos-brand-migrator.php';

			// If the configured brand slug changed since data was last written,
			// migrate tables/options/meta/cron in place before the app runs.
			add_action( 'admin_init', [ 'POS_Brand_Migrator', 'maybe_migrate' ] );

			require_once SELLMO_WC_PATH . 'includes/class-sellmo-settings.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-logger.php';

			// One-time rewrite of legacy brand-named sync-log direction values.
			add_action( 'admin_init', [ 'Sellmo_Logger', 'maybe_migrate_directions' ] );
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-product-mapping.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-api.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-admin.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-product-sync.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-stock-sync.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-order-sync.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-webhook.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-scheduler.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-plugin.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-sync-guard.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-product-variant-store.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-product-mapper-sync.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-product-variant-sync.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-mapper-ajax.php';
		require_once SELLMO_WC_PATH . 'includes/class-sellmo-stock-sync-pagination.php';

		Sellmo_Plugin::get_instance();
	}
);
