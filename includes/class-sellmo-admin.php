<?php
declare(strict_types=1);

/**
 * Admin menus, pages, notices, and AJAX handlers for Sellmo POS Integration.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Admin
 *
 * Registers the top-level "Sellmo POS" admin menu with Dashboard, Settings,
 * Products, and Sync Log sub-pages. Handles settings form submission and
 * AJAX calls for connection testing, shop population, and category loading.
 *
 * @since 1.0.0
 */
class Sellmo_Admin {

	/**
	 * Constructor.
	 *
	 * Registers all hooks: menus, admin_post handler, admin notices,
	 * script localisation, and wp_ajax_* AJAX actions.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menus' ] );
		add_action( 'admin_menu', [ $this, 'hide_submenu_items' ], 999 );
		add_action( 'admin_post_sellmo_wc_save_settings', [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_sellmo_wc_clear_logs',    [ $this, 'handle_clear_logs' ] );
		add_action( 'admin_post_sellmo_wc_export_logs',   [ $this, 'handle_export_logs' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'localize_scripts' ], 20 );

		add_action( 'wp_ajax_sellmo_wc_test_connection',  [ $this, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_sellmo_wc_get_shops',        [ $this, 'ajax_get_shops' ] );
		add_action( 'wp_ajax_sellmo_wc_get_categories',   [ $this, 'ajax_get_categories' ] );
		add_action( 'wp_ajax_run_salesplay_sync',         [ $this, 'ajax_sync_products_now' ] );
		add_action( 'wp_ajax_sellmo_wc_sync_products_now', [ $this, 'ajax_sync_products_now' ] );
		add_action( 'wp_ajax_sellmo_wc_sync_stock_now',    [ $this, 'ajax_sync_stock_now' ] );

		add_action( 'wp_ajax_sellmo_wc_get_products_list', [ $this, 'ajax_get_products_list' ] );
		add_action( 'wp_ajax_sellmo_wc_refresh_products',  [ $this, 'ajax_refresh_products' ] );
		add_action( 'wp_ajax_sellmo_wc_import_product',    [ $this, 'ajax_import_product' ] );
		add_action( 'wp_ajax_sellmo_wc_toggle_sync',       [ $this, 'ajax_toggle_sync' ] );
		add_action( 'wp_ajax_sellmo_wc_bulk_products',     [ $this, 'ajax_bulk_products' ] );

		add_action( 'wp_ajax_sellmo_wc_get_mapper_list',    [ $this, 'ajax_get_mapper_list' ] );
		add_action( 'wp_ajax_sellmo_wc_map_product',        [ $this, 'ajax_map_product' ] );
		add_action( 'wp_ajax_sellmo_wc_mapper_import_map',  [ $this, 'ajax_mapper_import_product' ] );
		add_action( 'wp_ajax_sellmo_wc_unmap_product',      [ $this, 'ajax_unmap_product' ] );
		add_action( 'wp_ajax_sellmo_wc_bulk_mapper',        [ $this, 'ajax_bulk_mapper' ] );
		add_action( 'wp_ajax_sellmo_wc_mapper_wc_products', [ $this, 'ajax_mapper_wc_products' ] );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	/**
	 * Register the top-level admin menu and all sub-pages.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_menus(): void {
		$brand_name = POS_Brand::name();

		add_menu_page(
			esc_html( $brand_name ),
			esc_html( $brand_name ),
			'manage_woocommerce',
			'sellmo-pos',
			[ $this, 'page_dashboard' ],
			'dashicons-store',
			56
		);

		// The first add_submenu_page renames the auto-created "Sellmo POS" entry.
		add_submenu_page(
			'sellmo-pos',
			sprintf(
				/* translators: %s: Brand display name. */
				__( 'Dashboard — %s', 'sellmo-woocommerce' ),
				$brand_name
			),
			__( 'Dashboard', 'sellmo-woocommerce' ),
			'manage_woocommerce',
			'sellmo-pos',
			[ $this, 'page_dashboard' ]
		);

		add_submenu_page(
			'sellmo-pos',
			sprintf(
				/* translators: %s: Brand display name. */
				__( 'Products — %s', 'sellmo-woocommerce' ),
				$brand_name
			),
			__( 'Products', 'sellmo-woocommerce' ),
			'manage_woocommerce',
			'sellmo-products',
			[ $this, 'page_products' ]
		);

		add_submenu_page(
			'sellmo-pos',
			sprintf(
				/* translators: %s: Brand display name. */
				__( 'Product Mapper — %s', 'sellmo-woocommerce' ),
				$brand_name
			),
			__( 'Product Mapper', 'sellmo-woocommerce' ),
			'manage_woocommerce',
			'sellmo-product-mapper',
			[ $this, 'page_product_mapper' ]
		);

		add_submenu_page(
			'sellmo-pos',
			sprintf(
				/* translators: %s: Brand display name. */
				__( 'Settings — %s', 'sellmo-woocommerce' ),
				$brand_name
			),
			__( 'Settings', 'sellmo-woocommerce' ),
			'manage_woocommerce',
			'sellmo-settings',
			[ $this, 'page_settings' ]
		);

		add_submenu_page(
			'sellmo-pos',
			sprintf(
				/* translators: %s: Brand display name. */
				__( 'Log — %s', 'sellmo-woocommerce' ),
				$brand_name
			),
			__( 'Log', 'sellmo-woocommerce' ),
			'manage_woocommerce',
			'sellmo-sync-log',
			[ $this, 'page_sync_log' ]
		);
	}

	/**
	 * Hide selected sub-pages from the sidebar without unregistering them.
	 *
	 * Runs on admin_menu at a late priority so it executes after register_menus().
	 * remove_submenu_page() only removes the visible menu link — the page route
	 * (admin.php?page=sellmo-products) and all its AJAX/functionality remain intact.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function hide_submenu_items(): void {
		remove_submenu_page( 'sellmo-pos', 'sellmo-products' );
	}

	// -------------------------------------------------------------------------
	// Page callbacks
	// -------------------------------------------------------------------------

	/**
	 * Render the Dashboard page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function page_dashboard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sellmo-woocommerce' ) );
		}
		require_once SELLMO_WC_PATH . 'admin/views/page-dashboard.php';
	}

	/**
	 * Render the Settings page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function page_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sellmo-woocommerce' ) );
		}
		require_once SELLMO_WC_PATH . 'admin/views/page-settings.php';
	}

	/**
	 * Render the Products page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function page_products(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sellmo-woocommerce' ) );
		}
		require_once SELLMO_WC_PATH . 'admin/views/page-products.php';
	}

	/**
	 * Render the Sync Log page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function page_sync_log(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sellmo-woocommerce' ) );
		}
		require_once SELLMO_WC_PATH . 'admin/views/page-sync-log.php';
	}

	/**
	 * Render the Product Mapper page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function page_product_mapper(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sellmo-woocommerce' ) );
		}
		require_once SELLMO_WC_PATH . 'admin/views/page-product-mapper.php';
	}

	// -------------------------------------------------------------------------
	// Settings save handler
	// -------------------------------------------------------------------------

	/**
	 * Process the settings form submission.
	 *
	 * Verifies nonce and capability before sanitising and persisting each field.
	 * The API token is never overwritten with an empty value — leave blank to keep
	 * the existing token. Redirects back to the Settings page on completion.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_save_settings(): void {
		check_admin_referer( 'sellmo_wc_save_settings', 'sellmo_wc_settings_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'sellmo-woocommerce' ) );
		}

		// API token. A new token means a different SalesPlay account, so the
		// previously synced catalogue and its stored stock snapshots are purged
		// below. The same applies when the token is explicitly removed.
		//   - Blank field + no "remove" checkbox  -> keep the existing token.
		//   - "Remove saved token" checkbox ticked -> clear the token + purge.
		//   - New value that differs               -> replace the token + purge.
		$old_token    = (string) ( Sellmo_Settings::get( 'api_token' ) ?: '' );
		$remove_token = isset( $_POST['sellmo_wc_remove_token'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$api_token     = sanitize_text_field( wp_unslash( $_POST['sellmo_wc_api_token'] ?? '' ) );
		$token_changed = false;
		$purged_count  = -1;

		if ( $remove_token && '' !== $old_token ) {
			// Explicit removal: drop the token, reset account-scoped settings, and
			// wipe everything synced from the old account.
			Sellmo_Settings::set( 'api_token', '' );
			Sellmo_Settings::set( 'shop_id', '' );
			Sellmo_Settings::set( 'terminal_key', '' );
			Sellmo_Settings::set( 'connection_status', [] );
			$purged_count = $this->purge_sellmo_synced_data();
		} elseif ( '' !== $api_token && $api_token !== $old_token ) {
			Sellmo_Settings::set( 'api_token', $api_token );
			$token_changed = true;

			// Only purge when replacing an existing token, not on first-time setup.
			if ( '' !== $old_token ) {
				$purged_count = $this->purge_sellmo_synced_data();
			}
		}

		// Shop ID — clear product cache when the selected shop changes. Skipped when
		// the token was just removed (shop_id was already reset to empty above).
		if ( -1 === $purged_count || ! $remove_token ) {
			$old_shop_id = (string) ( Sellmo_Settings::get( 'shop_id' ) ?: '' );
			$new_shop_id = sanitize_text_field( wp_unslash( $_POST['sellmo_wc_shop_id'] ?? '' ) );
			Sellmo_Settings::set( 'shop_id', $new_shop_id );
			if ( $new_shop_id !== $old_shop_id ) {
				$this->clear_products_cache();
			}
		}

		// Feature toggles — unchecked checkboxes are absent from POST, so default to 'no'.
		Sellmo_Settings::set( 'product_sync_enabled', isset( $_POST['sellmo_wc_product_sync_enabled'] ) ? 'yes' : 'no' );
		Sellmo_Settings::set( 'stock_sync_enabled',   isset( $_POST['sellmo_wc_stock_sync_enabled'] )   ? 'yes' : 'no' );
		Sellmo_Settings::set( 'order_sync_enabled',   isset( $_POST['sellmo_wc_order_sync_enabled'] )   ? 'yes' : 'no' );

		// Sync intervals — validate against allowed values.
		$stock_interval = sanitize_key( wp_unslash( $_POST['sellmo_wc_stock_sync_interval'] ?? '' ) );
		if ( in_array( $stock_interval, [ 'hourly', 'twicedaily', 'daily' ], true ) ) {
			Sellmo_Settings::set( 'stock_sync_interval', $stock_interval );
		}

		$product_interval = sanitize_key( wp_unslash( $_POST['sellmo_wc_product_sync_interval'] ?? '' ) );
		if ( in_array( $product_interval, [ 'twicedaily', 'daily' ], true ) ) {
			Sellmo_Settings::set( 'product_sync_interval', $product_interval );
		}

		// Category filter — array of IDs (stored as strings to match API responses).
		$raw_cats      = isset( $_POST['sellmo_wc_sync_categories'] ) ? (array) $_POST['sellmo_wc_sync_categories'] : [];
		$sync_cats     = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $raw_cats ) ) ) );
		Sellmo_Settings::set( 'sync_categories', $sync_cats );

		// Terminal key — populated by JS from the selected shop's terminal list.
		// Skipped on token removal (terminal_key was already reset to empty above).
		if ( ! $remove_token || -1 === $purged_count ) {
			Sellmo_Settings::set( 'terminal_key', sanitize_text_field( wp_unslash( $_POST['sellmo_wc_terminal_key'] ?? '' ) ) );
		}

		// Webhook secret — only update if a value was submitted.
		$webhook_secret = sanitize_text_field( wp_unslash( $_POST['sellmo_wc_webhook_secret'] ?? '' ) );
		if ( '' !== $webhook_secret ) {
			Sellmo_Settings::set( 'webhook_secret', $webhook_secret );
		}

		// Auto-test the connection whenever a new API token is provided.
		$conn_status = '';
		if ( $token_changed ) {
			$api  = new Sellmo_API();
			$test = $api->get_shops();
			if ( is_wp_error( $test ) ) {
				Sellmo_Settings::set( 'connection_status', [
					'success' => false,
					'time'    => time(),
					'message' => $test->get_error_message(),
				] );
				$conn_status = 'fail';
			} else {
				Sellmo_Settings::set( 'connection_status', [
					'success' => true,
					'time'    => time(),
					'message' => '',
				] );
				$conn_status = 'ok';
			}
		}

		$redirect_args = [
			'page'         => 'sellmo-settings',
			'sellmo_saved' => '1',
		];
		if ( '' !== $conn_status ) {
			$redirect_args['sellmo_conn'] = $conn_status;
		}
		if ( $purged_count >= 0 ) {
			$redirect_args['sellmo_purged'] = (string) $purged_count;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle the Clear All Logs form submission.
	 *
	 * Truncates the sync log table and redirects back to the Sync Log page
	 * with a success notice query arg.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_clear_logs(): void {
		check_admin_referer( 'sellmo_wc_clear_logs', 'sellmo_wc_log_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to clear the sync log.', 'sellmo-woocommerce' ) );
		}

		Sellmo_Logger::clear_all();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'               => 'sellmo-sync-log',
					'sellmo_log_cleared' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the Export as CSV form submission.
	 *
	 * Fetches all log entries matching the submitted filters (no pagination),
	 * outputs CSV headers and content, then exits — no redirect.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_export_logs(): void {
		check_admin_referer( 'sellmo_wc_export_logs', 'sellmo_wc_export_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export the sync log.', 'sellmo-woocommerce' ) );
		}

		$filter_type   = sanitize_key(        wp_unslash( $_POST['filter_type']   ?? '' ) );
		$filter_status = sanitize_key(        wp_unslash( $_POST['filter_status'] ?? '' ) );
		$filter_from   = sanitize_text_field( wp_unslash( $_POST['filter_from']   ?? '' ) );
		$filter_to     = sanitize_text_field( wp_unslash( $_POST['filter_to']     ?? '' ) );

		$filter_from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_from ) ? $filter_from : '';
		$filter_to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_to )   ? $filter_to   : '';

		$result = Sellmo_Logger::get_logs( [
			'type'      => $filter_type,
			'status'    => $filter_status,
			'date_from' => $filter_from,
			'date_to'   => $filter_to,
			'per_page'  => 100000,
			'paged'     => 1,
		] );

		$filename = 'sellmo-sync-log-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );

		// BOM for Excel UTF-8 compatibility.
		fputs( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		fputcsv( $out, [ 'ID', 'Date / Time (UTC)', 'Type', 'Direction', 'Status', 'Item', 'Message' ] );

		foreach ( $result['items'] as $row ) {
			fputcsv( $out, [
				(string) ( $row['id']         ?? '' ),
				(string) ( $row['created_at'] ?? '' ),
				(string) ( $row['sync_type']  ?? '' ),
				(string) ( $row['direction']  ?? '' ),
				(string) ( $row['status']     ?? '' ),
				(string) ( $row['item_id']    ?? '' ),
				(string) ( $row['message']    ?? '' ),
			] );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	/**
	 * Display success or error admin notices on Sellmo pages.
	 *
	 * Reads ?sellmo_saved=1 for a success message. Only renders on pages
	 * belonging to this plugin (hook ID contains 'sellmo').
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function admin_notices(): void {
		$screen = get_current_screen();
		if ( null === $screen || ! str_contains( $screen->id, 'sellmo' ) ) {
			return;
		}

		$brand_name = POS_Brand::name();

		// DB error (e.g. sync log table creation failed due to MySQL strict mode).
		$db_error = get_option( POS_Brand::option( 'db_error' ) );
		if ( $db_error ) {
			echo '<div class="notice notice-error"><p>' .
				'<strong>' . esc_html( sprintf( __( '%s — Database error:', 'sellmo-woocommerce' ), $brand_name ) ) . '</strong> ' .
				esc_html( (string) $db_error ) .
				'</p></div>';
		}

		// Persistent warning: no API token configured.
		if ( '' === (string) Sellmo_Settings::get( 'api_token' ) ) {
			$settings_url = admin_url( 'admin.php?page=sellmo-settings' );
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: 1: Brand display name, 2: link to the Settings page */
				wp_kses(
					__( '%1$s: No API token configured. <a href="%2$s">Go to Settings</a> to add your token.', 'sellmo-woocommerce' ),
					[ 'a' => [ 'href' => [] ] ]
				),
				esc_html( $brand_name ),
				esc_url( $settings_url )
			);
			echo '</p></div>';
		}

		// Warning: webhook secret is shorter than 32 characters.
		$wh_secret = (string) ( Sellmo_Settings::get( 'webhook_secret' ) ?: '' );
		if ( '' !== $wh_secret && mb_strlen( $wh_secret ) < 32 ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				esc_html( sprintf( __( '%s: Your webhook secret is shorter than 32 characters. Use the Generate button on the Settings page to create a secure secret.', 'sellmo-woocommerce' ), $brand_name ) ) .
				'</p></div>';
		}

		if ( '1' === sanitize_key( $_GET['sellmo_saved'] ?? '' ) ) {
			$conn = sanitize_key( $_GET['sellmo_conn'] ?? '' );

			if ( 'ok' === $conn ) {
				echo '<div class="notice notice-success is-dismissible"><p>' .
					esc_html__( 'Settings saved. API connection verified successfully.', 'sellmo-woocommerce' ) .
					'</p></div>';
			} elseif ( 'fail' === $conn ) {
				$conn_data = (array) ( Sellmo_Settings::get( 'connection_status' ) ?: [] );
				$err_msg   = (string) ( $conn_data['message'] ?? '' );
				echo '<div class="notice notice-warning is-dismissible"><p>' .
					esc_html__( 'Settings saved, but the API connection test failed.', 'sellmo-woocommerce' );
				if ( '' !== $err_msg ) {
					echo ' ' . esc_html( $err_msg );
				}
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' .
					esc_html__( 'Settings saved.', 'sellmo-woocommerce' ) .
					'</p></div>';
			}
		}

		if ( isset( $_GET['sellmo_purged'] ) ) {
			$purged = absint( $_GET['sellmo_purged'] );
			echo '<div class="notice notice-warning is-dismissible"><p>';
			printf(
				/* translators: %d: number of WooCommerce products removed */
				esc_html(
					_n(
						'API token changed. Removed %d Sellmo-linked product and cleared all stored stock counts and mappings.',
						'API token changed. Removed %d Sellmo-linked products and cleared all stored stock counts and mappings.',
						$purged,
						'sellmo-woocommerce'
					)
				),
				$purged
			);
			echo '</p></div>';
		}

		if ( '1' === sanitize_key( $_GET['sellmo_log_cleared'] ?? '' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Sync log cleared.', 'sellmo-woocommerce' ) .
				'</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// Script localisation
	// -------------------------------------------------------------------------

	/**
	 * Localise admin JS with AJAX URL, nonce, and saved state.
	 *
	 * Runs at priority 20 — after Sellmo_Plugin::admin_assets() enqueues the
	 * script at the default priority 10.
	 *
	 * @since  1.0.0
	 * @param  string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function localize_scripts( string $hook ): void {
		if ( ! str_contains( $hook, 'sellmo' ) ) {
			return;
		}

		$brand_name = POS_Brand::name();
		$saved_cats = (array) ( Sellmo_Settings::get( 'sync_categories' ) ?: [] );

		wp_localize_script(
			'sellmo-admin',
			'sellmoAdmin',
			[
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'sellmo_wc_admin' ),
				'brandName'       => $brand_name,
				'hasToken'        => '' !== (string) Sellmo_Settings::get( 'api_token' ),
				'savedShopId'     => (string) ( Sellmo_Settings::get( 'shop_id' ) ?: '' ),
				'savedCategories' => array_map( 'strval', $saved_cats ),
				'currencySymbol'  => function_exists( 'get_woocommerce_currency_symbol' )
					? get_woocommerce_currency_symbol()
					: '',
				'i18n'            => [
					'show'              => __( 'Show', 'sellmo-woocommerce' ),
					'hide'              => __( 'Hide', 'sellmo-woocommerce' ),
					'testBtn'           => __( 'Test Connection', 'sellmo-woocommerce' ),
					'testing'           => __( 'Testing…', 'sellmo-woocommerce' ),
					'connected'         => __( 'Connected', 'sellmo-woocommerce' ),
					'disconnected'      => __( 'Not connected', 'sellmo-woocommerce' ),
					'secretGenerated'   => __( 'New secret generated. Save settings to apply.', 'sellmo-woocommerce' ),
					'loadingShops'      => __( 'Loading shops…', 'sellmo-woocommerce' ),
					'loadingCats'       => __( 'Loading categories…', 'sellmo-woocommerce' ),
					'selectShop'        => sprintf(
						/* translators: %s: Brand display name. */
						__( 'Select %s shop', 'sellmo-woocommerce' ),
						$brand_name
					),
					'syncing'           => __( 'Syncing…', 'sellmo-woocommerce' ),
					'syncProducts'      => __( 'Sync Products Now', 'sellmo-woocommerce' ),
					'syncStock'         => __( 'Sync Stock Now', 'sellmo-woocommerce' ),
					'syncError'         => __( 'Sync failed. Please try again.', 'sellmo-woocommerce' ),
					// Products page.
					'variants'          => __( 'variants', 'sellmo-woocommerce' ),
					'wcLinked'          => __( 'Linked', 'sellmo-woocommerce' ),
					'wcSyncDisabled'    => __( 'Sync disabled', 'sellmo-woocommerce' ),
					'wcNotImported'     => __( 'Not imported', 'sellmo-woocommerce' ),
					'importProduct'     => __( 'Import to WooCommerce', 'sellmo-woocommerce' ),
					'importing'         => __( 'Importing…', 'sellmo-woocommerce' ),
					'importError'       => __( 'Import failed. Please try again.', 'sellmo-woocommerce' ),
					'viewInWC'          => __( 'View in WC', 'sellmo-woocommerce' ),
					'edit'              => __( 'Edit', 'sellmo-woocommerce' ),
					'toggleSync'        => __( 'Toggle sync', 'sellmo-woocommerce' ),
					'importFirst'       => __( 'Import this product first to enable sync', 'sellmo-woocommerce' ),
					'loadError'         => __( 'Failed to load products. Please try again.', 'sellmo-woocommerce' ),
					'noProducts'        => __( 'No products found.', 'sellmo-woocommerce' ),
					'bulkError'         => __( 'Bulk action failed. Please try again.', 'sellmo-woocommerce' ),
					'selectBulkAction'  => __( 'Please select a bulk action.', 'sellmo-woocommerce' ),
					'selectProducts'    => __( 'Please select at least one product.', 'sellmo-woocommerce' ),
					'of'                => __( 'of', 'sellmo-woocommerce' ),
					// Product mapper page.
					'mapped'            => __( 'Mapped', 'sellmo-woocommerce' ),
					'notMapped'         => __( 'Not Mapped', 'sellmo-woocommerce' ),
					'woocommerceOnly'   => __( 'WooCommerce Only', 'sellmo-woocommerce' ),
					'map'               => __( 'Map', 'sellmo-woocommerce' ),
					'change'            => __( 'Change', 'sellmo-woocommerce' ),
					'unmap'             => __( 'Unmap', 'sellmo-woocommerce' ),
					'mapperNoRows'      => __( 'No products found.', 'sellmo-woocommerce' ),
					'mapperLoadError'   => __( 'Failed to load mapper data.', 'sellmo-woocommerce' ),
					'mapperActionError' => __( 'Mapping action failed. Please try again.', 'sellmo-woocommerce' ),
					'mapperChooseWc'     => __( 'Select a WooCommerce product first.', 'sellmo-woocommerce' ),
					'mapperChooseSellmo' => sprintf(
						/* translators: %s: Brand display name. */
						__( 'Select a %s product first.', 'sellmo-woocommerce' ),
						$brand_name
					),
					'mapperBulkHint'     => __( 'Please select a mapper bulk action.', 'sellmo-woocommerce' ),
					'mapperSelectRows'  => __( 'Please select at least one row.', 'sellmo-woocommerce' ),
				],
			]
		);
	}

	/**
	 * AJAX: return mapper table data.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_get_mapper_list(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$page        = max( 1, absint( $_POST['page'] ?? 1 ) );
		$per_page    = 25;
		$search      = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$status      = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		$status      = in_array( $status, [ 'mapped', 'not_mapped' ], true ) ? $status : '';
		$cat_filter  = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );
		// Source the Sellmo product list from the local mapping table (staged by the
		// dashboard "Sync Products Now" action) instead of the live API, so the list
		// reflects stored data and is empty once that data is cleared.
		$db_rows      = Sellmo_Product_Mapping::get_all_rows();
		$deduped_rows = [];

		foreach ( $db_rows as $product ) {
			$sellmo_id   = (string) ( $product['sellmo_product_id'] ?? '' );
			$sellmo_code = (string) ( $product['sellmo_product_code'] ?? '' );
			$name        = (string) ( $product['sellmo_product_name'] ?? '' );
			$category    = (string) ( $product['category'] ?? '' );

			$is_mapped     = 1 === (int) ( $product['map_status'] ?? 0 );
			$wc_product_id = $is_mapped ? absint( $product['wc_product_id'] ?? 0 ) : 0;
			$wc_label      = '';
			$wc_edit_url   = '';

			if ( $wc_product_id > 0 ) {
				$wc_product = wc_get_product( $wc_product_id );
				if ( $wc_product instanceof WC_Product ) {
					$wc_label = sprintf(
						'#%d - %s',
						$wc_product_id,
						$wc_product->get_name()
					);
					$wc_edit_url = (string) get_edit_post_link( $wc_product_id, 'raw' );
				} else {
					// The mapped WooCommerce product no longer exists — show as unmapped.
					$is_mapped     = false;
					$wc_product_id = 0;
				}
			}

			$row = [
				'sellmo_id'       => $sellmo_id,
				'sellmo_name'     => $name,
				'sellmo_code'     => $sellmo_code,
				'category'        => $category,
				'price'           => (float) ( $product['price'] ?? 0 ),
				'wc_product_id'   => $wc_product_id,
				'wc_product_label'=> $wc_label,
				'wc_edit_url'     => $wc_edit_url,
				'status'          => $is_mapped ? 'mapped' : 'not_mapped',
			];

			// Only one mapper row can exist per product code.
			$dedupe_key = '' !== $sellmo_code ? $sellmo_code : $sellmo_id;
			if ( '' === $dedupe_key ) {
				continue;
			}

			if ( ! isset( $deduped_rows[ $dedupe_key ] ) ) {
				$deduped_rows[ $dedupe_key ] = $row;
				continue;
			}

			$existing = $deduped_rows[ $dedupe_key ];
			if ( 'mapped' === $row['status'] && 'mapped' !== (string) ( $existing['status'] ?? '' ) ) {
				$deduped_rows[ $dedupe_key ] = $row;
			}
		}

		$rows = [];
		foreach ( array_values( $deduped_rows ) as $row ) {
			if ( 'mapped' === $status && 'mapped' !== (string) $row['status'] ) {
				continue;
			}

			if ( 'not_mapped' === $status && 'not_mapped' !== (string) $row['status'] ) {
				continue;
			}

			// Category filter. The API returns category as a flat name string,
			// matched exactly — same behaviour as the Products page.
			if ( '' !== $cat_filter && (string) ( $row['category'] ?? '' ) !== $cat_filter ) {
				continue;
			}

			if ( '' !== $search ) {
				$haystack = mb_strtolower(
					(string) ( $row['sellmo_name'] ?? '' ) . ' ' .
					(string) ( $row['sellmo_code'] ?? '' ) . ' ' .
					(string) ( $row['category'] ?? '' )
				);

				if ( ! str_contains( $haystack, mb_strtolower( $search ) ) ) {
					continue;
				}
			}

			$rows[] = $row;
		}

		// Build Sellmo product list for WC-only row dropdowns (all deduped, before pagination).
		$sellmo_products_list = [];
		foreach ( $deduped_rows as $s_row ) {
			if ( '' === (string) ( $s_row['sellmo_id'] ?? '' ) ) {
				continue;
			}
			// Only unmapped Sellmo products appear in the selector dropdown.
			if ( 'mapped' === (string) ( $s_row['status'] ?? '' ) ) {
				continue;
			}
			$sellmo_products_list[] = [
				'id'   => (string) $s_row['sellmo_id'],
				'name' => (string) $s_row['sellmo_name'],
				'code' => (string) $s_row['sellmo_code'],
			];
		}

		$wc_products   = $this->get_wc_products_for_mapper_selector();
		$mapped_wc_ids = Sellmo_Product_Mapping::get_mapped_wc_product_ids();

		// Append WC-only rows: WC products that exist in WooCommerce but have no Sellmo mapping.
		if ( 'mapped' !== $status ) {
			foreach ( $wc_products as $wc_item ) {
				$wc_id = (int) ( $wc_item['id'] ?? 0 );
				if ( $wc_id <= 0 || in_array( $wc_id, $mapped_wc_ids, true ) ) {
					continue;
				}

				$wc_product = wc_get_product( $wc_id );
				if ( ! $wc_product instanceof WC_Product ) {
					continue;
				}

				$wc_price    = (float) $wc_product->get_price();
				$terms       = get_the_terms( $wc_id, 'product_cat' );
				$wc_cat_names = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : [];
				$wc_cats     = implode( ', ', $wc_cat_names );
				$wc_edit_url = (string) get_edit_post_link( $wc_id, 'raw' );

				// Category filter: keep WC-only rows only when the selected
				// category is one of the product's WooCommerce categories.
				if ( '' !== $cat_filter && ! in_array( $cat_filter, $wc_cat_names, true ) ) {
					continue;
				}

				if ( '' !== $search ) {
					$haystack = mb_strtolower( $wc_product->get_name() . ' ' . $wc_product->get_sku() . ' ' . $wc_cats );
					if ( ! str_contains( $haystack, mb_strtolower( $search ) ) ) {
						continue;
					}
				}

				$rows[] = [
					'row_type'         => 'wc_only',
					'sellmo_id'        => '',
					'sellmo_name'      => '',
					'sellmo_code'      => '',
					'category'         => $wc_cats,
					'price'            => $wc_price,
					'wc_product_id'    => $wc_id,
					'wc_product_label' => (string) ( $wc_item['label'] ?? '' ),
					'wc_edit_url'      => $wc_edit_url,
					'status'           => 'wc_only',
				];
			}
		}

		$total       = count( $rows );
		$total_pages = (int) ceil( $total / $per_page );
		$offset      = ( $page - 1 ) * $per_page;

		// Only send unmapped WC products for the selector dropdown.
		$wc_products_for_selector = array_values( array_filter(
			$wc_products,
			static function( array $item ) use ( $mapped_wc_ids ): bool {
				return ! in_array( (int) ( $item['id'] ?? 0 ), $mapped_wc_ids, true );
			}
		) );

		// Compute live stats from the full (unfiltered, unpaginated) data.
		$sellmo_total   = count( $deduped_rows );
		$sellmo_mapped  = 0;
		foreach ( $deduped_rows as $r ) {
			if ( 'mapped' === (string) ( $r['status'] ?? '' ) ) {
				$sellmo_mapped++;
			}
		}
		$wc_only_count = count( $wc_products ) - count( $mapped_wc_ids );

		wp_send_json_success( [
			'rows'            => array_values( array_slice( $rows, $offset, $per_page ) ),
			'total'           => $total,
			'pages'           => max( 1, $total_pages ),
			'current_page'    => $page,
			'per_page'        => $per_page,
			'wc_products'     => $wc_products_for_selector,
			'sellmo_products' => $sellmo_products_list,
			'stats'           => [
				'total_sellmo_products'   => $sellmo_total,
				'mapped_products'         => $sellmo_mapped,
				'unmapped_products'       => $sellmo_total - $sellmo_mapped,
				'woocommerce_only_products' => max( 0, $wc_only_count ),
			],
		] );
	}

	/**
	 * AJAX: map or change a Sellmo product mapping.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_map_product(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$sellmo_id   = sanitize_text_field( wp_unslash( $_POST['sellmo_id'] ?? '' ) );
		$sellmo_code = sanitize_text_field( wp_unslash( $_POST['sellmo_code'] ?? '' ) );
		$sellmo_name = sanitize_text_field( wp_unslash( $_POST['sellmo_name'] ?? '' ) );
		$wc_product_id = absint( $_POST['wc_product_id'] ?? 0 );

		if ( '' === $sellmo_id || $wc_product_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid mapping payload.', 'sellmo-woocommerce' ) ] );
		}

		$wc_product = wc_get_product( $wc_product_id );
		if ( ! $wc_product instanceof WC_Product ) {
			wp_send_json_error( [ 'message' => __( 'WooCommerce product does not exist.', 'sellmo-woocommerce' ) ] );
		}

		$was_mapped = Sellmo_Product_Mapping::get_mapping_by_sellmo_id( $sellmo_id );

		if ( ! Sellmo_Product_Mapping::upsert_mapping( $sellmo_id, $sellmo_code, $sellmo_name, $wc_product_id ) ) {
			wp_send_json_error( [
				'message' => __( 'Unable to save mapping. This WooCommerce product may already be mapped.', 'sellmo-woocommerce' ),
			] );
		}

		// Push the Sellmo catalogue image onto the mapped WooCommerce product
		// when one is available and the product has no featured image yet.
		$this->push_sellmo_image_to_wc( $sellmo_id, $sellmo_code, $wc_product_id );

		Sellmo_Logger::log(
			'product',
			'sellmo_to_wc',
			'skipped',
			sprintf(
				is_array( $was_mapped )
					? 'Mapping changed: %s -> WooCommerce product #%d'
					: 'Mapping created: %s -> WooCommerce product #%d',
				$sellmo_name,
				$wc_product_id
			),
			$sellmo_code
		);

		wp_send_json_success( [
			'message' => __( 'Mapping saved.', 'sellmo-woocommerce' ),
		] );
	}

	/**
	 * AJAX: import a Sellmo product into WooCommerce and auto-map it.
	 *
	 * Backs the "Import to WooCommerce" button on the Product Mapper page. Finds
	 * the Sellmo product in the cached catalogue, creates the WooCommerce product
	 * via Sellmo_Product_Sync::import_product(), then links the two with a mapping
	 * row so the product immediately shows as Mapped.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function ajax_mapper_import_product(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$sellmo_id   = sanitize_text_field( wp_unslash( $_POST['sellmo_id'] ?? '' ) );
		$sellmo_code = sanitize_text_field( wp_unslash( $_POST['sellmo_code'] ?? '' ) );

		if ( '' === $sellmo_id && '' === $sellmo_code ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product reference.', 'sellmo-woocommerce' ) ] );
		}

		// Refuse if this Sellmo product is already mapped.
		$existing = '' !== $sellmo_id ? Sellmo_Product_Mapping::get_mapping_by_sellmo_id( $sellmo_id ) : null;
		if ( null === $existing && '' !== $sellmo_code ) {
			$existing = Sellmo_Product_Mapping::get_mapping_by_sellmo_code( $sellmo_code );
		}
		if ( is_array( $existing ) ) {
			wp_send_json_error( [ 'message' => __( 'Product is already mapped.', 'sellmo-woocommerce' ) ] );
		}

		// Locate the Sellmo product payload in the cached catalogue.
		$all_products = $this->get_all_sellmo_products();
		if ( is_wp_error( $all_products ) ) {
			wp_send_json_error( [ 'message' => $all_products->get_error_message() ] );
		}

		$sellmo_product = null;
		foreach ( $all_products as $p ) {
			$p_id   = (string) ( $p['id'] ?? '' );
			$p_code = (string) ( $p['product_code'] ?? '' );

			if ( ( '' !== $sellmo_id && $p_id === $sellmo_id ) || ( '' !== $sellmo_code && $p_code === $sellmo_code ) ) {
				$sellmo_product = $p;
				break;
			}
		}

		if ( null === $sellmo_product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found in Sellmo catalogue.', 'sellmo-woocommerce' ) ] );
		}

		// Create / update the WooCommerce product.
		$sync   = new Sellmo_Product_Sync();
		$new_id = $sync->import_product( $sellmo_product );

		if ( $new_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Import failed. Check the Sync Log for details.', 'sellmo-woocommerce' ) ] );
		}

		// Auto-map the Sellmo product to the freshly imported WooCommerce product.
		$map_id   = (string) ( $sellmo_product['id'] ?? $sellmo_id );
		$map_code = (string) ( $sellmo_product['product_code'] ?? $sellmo_code );
		$map_name = (string) ( $sellmo_product['product_name'] ?? '' );

		if ( ! Sellmo_Product_Mapping::upsert_mapping( $map_id, $map_code, $map_name, $new_id ) ) {
			wp_send_json_error( [
				'message' => __( 'Product imported, but automatic mapping failed (the WooCommerce product may already be mapped to another Sellmo product).', 'sellmo-woocommerce' ),
				'wc_id'   => $new_id,
			] );
		}

		// Populate stock from /inventory so the imported product (and, for variable
		// products, its variations) carries current stock immediately — the
		// /products payload used by import has no stock data.
		( new Sellmo_Stock_Sync() )->sync_stock_for_wc_product( $new_id );

		Sellmo_Logger::log(
			'product',
			'sellmo_to_wc',
			'success',
			sprintf( 'Imported and auto-mapped "%s" to WooCommerce product #%d', $map_name, $new_id ),
			$map_code
		);

		wp_send_json_success( [
			'wc_id'    => $new_id,
			'edit_url' => (string) get_edit_post_link( $new_id, 'raw' ),
			'message'  => __( 'Product imported and mapped.', 'sellmo-woocommerce' ),
		] );
	}

	/**
	 * AJAX: remove a Sellmo mapping.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_unmap_product(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$sellmo_id   = sanitize_text_field( wp_unslash( $_POST['sellmo_id'] ?? '' ) );
		$sellmo_name = sanitize_text_field( wp_unslash( $_POST['sellmo_name'] ?? '' ) );
		$sellmo_code = sanitize_text_field( wp_unslash( $_POST['sellmo_code'] ?? '' ) );

		if ( '' === $sellmo_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid Sellmo product id.', 'sellmo-woocommerce' ) ] );
		}

		if ( ! Sellmo_Product_Mapping::remove_mapping_by_sellmo_id( $sellmo_id ) ) {
			wp_send_json_error( [ 'message' => __( 'No mapping found to remove.', 'sellmo-woocommerce' ) ] );
		}

		Sellmo_Logger::log(
			'product',
			'sellmo_to_wc',
			'skipped',
			sprintf( 'Mapping removed: %s', $sellmo_name ),
			$sellmo_code
		);

		wp_send_json_success( [
			'message' => __( 'Mapping removed.', 'sellmo-woocommerce' ),
		] );
	}

	/**
	 * AJAX: apply bulk mapper actions.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_bulk_mapper(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) );
		$rows   = isset( $_POST['rows'] ) ? (array) $_POST['rows'] : [];

		if ( ! in_array( $action, [ 'unmap', 'auto_map_sku' ], true ) || empty( $rows ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid bulk mapper request.', 'sellmo-woocommerce' ) ] );
		}

		$processed = 0;
		$errors    = 0;

		foreach ( $rows as $row ) {
			$row_data = is_array( $row ) ? $row : [];
			$sellmo_id   = sanitize_text_field( (string) ( $row_data['sellmo_id'] ?? '' ) );
			$sellmo_code = sanitize_text_field( (string) ( $row_data['sellmo_code'] ?? '' ) );
			$sellmo_name = sanitize_text_field( (string) ( $row_data['sellmo_name'] ?? '' ) );

			if ( '' === $sellmo_id ) {
				$errors++;
				continue;
			}

			if ( 'unmap' === $action ) {
				if ( Sellmo_Product_Mapping::remove_mapping_by_sellmo_id( $sellmo_id ) ) {
					$processed++;
				} else {
					$errors++;
				}
				continue;
			}

			$wc_id = (int) wc_get_product_id_by_sku( $sellmo_code );
			if ( $wc_id <= 0 ) {
				$errors++;
				continue;
			}

			if ( Sellmo_Product_Mapping::upsert_mapping( $sellmo_id, $sellmo_code, $sellmo_name, $wc_id ) ) {
				$this->push_sellmo_image_to_wc( $sellmo_id, $sellmo_code, $wc_id );
				$processed++;
			} else {
				$errors++;
			}
		}

		wp_send_json_success( [
			'processed' => $processed,
			'errors'    => $errors,
			'message'   => sprintf(
				/* translators: %d: processed rows count */
				_n( '%d row processed.', '%d rows processed.', $processed, 'sellmo-woocommerce' ),
				$processed
			),
		] );
	}

	/**
	 * AJAX: provide WooCommerce product options for mapper selectors.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_mapper_wc_products(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		wp_send_json_success( $this->get_wc_products_for_mapper_selector( $search ) );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: test the saved API token by calling GET /shops.
	 *
	 * Returns JSON success with a confirmation message, or JSON error with the
	 * API error message. The token is never included in the response.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$api    = new Sellmo_API();
		$result = $api->get_shops();

		if ( is_wp_error( $result ) ) {
			Sellmo_Settings::set( 'connection_status', [
				'success' => false,
				'time'    => time(),
				'message' => $result->get_error_message(),
			] );
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		Sellmo_Settings::set( 'connection_status', [
			'success' => true,
			'time'    => time(),
			'message' => '',
		] );
		wp_send_json_success( [ 'message' => __( 'Connection successful.', 'sellmo-woocommerce' ) ] );
	}

	/**
	 * AJAX: fetch shops from the Sellmo API for the shop dropdown.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_get_shops(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$api    = new Sellmo_API();
		$result = $api->get_shops();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// Normalise to a flat array; include the first terminal's pos_key so the
		// JS can keep the hidden sellmo_wc_terminal_key input in sync.
		$shops = [];
		foreach ( (array) ( $result['shops'] ?? [] ) as $shop ) {
			$terminal_list = isset( $shop['terminal_list'] ) && is_array( $shop['terminal_list'] )
				? $shop['terminal_list']
				: [];
			$shops[] = [
				'id'           => (string) ( $shop['id'] ?? '' ),
				'name'         => (string) ( $shop['shop_name'] ?? $shop['id'] ?? '' ),
				'terminal_key' => (string) ( $terminal_list[0]['pos_key'] ?? '' ),
			];
		}

		wp_send_json_success( $shops );
	}

	/**
	 * AJAX: fetch categories from the Sellmo API for the category multi-select.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_get_categories(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		// Derive unique category names from the cached products list.
		// The Sellmo API returns category as a flat string on each product.
		$all_products = $this->get_all_sellmo_products();

		if ( is_wp_error( $all_products ) ) {
			wp_send_json_error( [ 'message' => $all_products->get_error_message() ] );
		}

		$seen = [];

		foreach ( $all_products as $product ) {
			$cat = (string) ( $product['category'] ?? '' );

			if ( '' !== $cat ) {
				$seen[ $cat ] = true;
			}
		}

		ksort( $seen );

		$categories = array_map(
			fn( string $name ): array => [ 'name' => $name ],
			array_keys( $seen )
		);

		wp_send_json_success( $categories );
	}

	/**
	 * AJAX: trigger a manual product sync.
	 *
	 * Delegates to Sellmo_Product_Sync::run_manual_sync(), persists the result
	 * as sellmo_wc_last_product_sync, and returns JSON with a summary message.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_sync_products_now(): void {
		$sync_started_at = time();
		$sync_completed  = false;

		register_shutdown_function(
			static function () use ( &$sync_completed, $sync_started_at ): void {
				if ( $sync_completed ) {
					return;
				}

				$last_error = error_get_last();
				if ( is_array( $last_error ) && isset( $last_error['type'], $last_error['message'] ) ) {
					$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
					if ( in_array( (int) $last_error['type'], $fatal_types, true ) ) {
						Sellmo_Logger::log(
							'product',
							'sellmo_to_wc',
							'error',
							sprintf(
								'Manual product sync terminated by fatal error after %ds: %s',
								max( 0, time() - $sync_started_at ),
								(string) $last_error['message']
							)
						);
					}
				}
			}
		);

		Sellmo_Logger::log(
			'product',
			'sellmo_to_wc',
			'skipped',
			'Manual product sync started from dashboard.'
		);

		if ( false === check_ajax_referer( 'sellmo_wc_admin', 'nonce', false ) ) {
			Sellmo_Logger::log(
				'product',
				'sellmo_to_wc',
				'error',
				'Manual product sync blocked: invalid or expired AJAX nonce.'
			);
			$sync_completed = true;

			wp_send_json_error( [
				'message' => __( 'Security check failed. Please refresh the page and try again.', 'sellmo-woocommerce' ),
			], 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			Sellmo_Logger::log(
				'product',
				'sellmo_to_wc',
				'error',
				'Manual product sync blocked: permission denied for current user.'
			);
			$sync_completed = true;

			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		try {
			$sync   = new Sellmo_Product_Mapper_Sync();
			$result = $sync->run_incremental_sync();
		} catch ( \Throwable $e ) {
			Sellmo_Logger::log(
				'product',
				'sellmo_to_wc',
				'error',
				sprintf( 'Manual product sync crashed: %s', $e->getMessage() )
			);
			$sync_completed = true;

			wp_send_json_error( [
				'message' => __( 'Product sync failed due to a server error. Check Sync Log for details.', 'sellmo-woocommerce' ),
			], 500 );
		}

		$meta = [
			'time'   => time(),
			'status' => $result['success'] ? 'success' : 'error',
			'count'  => (int) ( $result['count'] ?? 0 ),
		];
		Sellmo_Settings::set( 'last_product_sync', $meta );

		if ( $result['success'] ) {
			$sync_completed = true;
			wp_send_json_success( [
				'message' => sprintf(
					/* translators: %d: number of products synced */
					__( 'Product sync complete. %d products synced.', 'sellmo-woocommerce' ),
					$meta['count']
				),
				'trace'   => isset( $result['trace'] ) && is_array( $result['trace'] ) ? $result['trace'] : [],
			] );
		}

		$failed_message = (string) ( $result['message'] ?? '' );
		if ( '' === $failed_message ) {
			$failed_message = __( 'Product sync failed.', 'sellmo-woocommerce' );
		}

		Sellmo_Logger::log(
			'product',
			'sellmo_to_wc',
			'error',
			'Manual product sync failed: ' . $failed_message
		);
		$sync_completed = true;

		wp_send_json_error( [
			'message' => $failed_message,
			'trace'   => isset( $result['trace'] ) && is_array( $result['trace'] ) ? $result['trace'] : [],
			'error'   => isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : [],
		] );
	}

	/**
	 * AJAX: trigger a manual stock sync.
	 *
	 * Delegates to Sellmo_Stock_Sync::run_manual_sync(), persists the result
	 * as sellmo_wc_last_stock_sync, and returns JSON with a summary message.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_sync_stock_now(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$sync   = new Sellmo_Stock_Sync_Pagination();
		$result = $sync->run();

		$meta = [
			'time'   => time(),
			'status' => $result['success'] ? 'success' : 'error',
			'count'  => (int) ( $result['count'] ?? 0 ),
		];
		Sellmo_Settings::set( 'last_stock_sync', $meta );

		if ( $result['success'] ) {
			wp_send_json_success( [
				'message' => sprintf(
					/* translators: %d: number of stock items synced */
					__( 'Stock sync complete. %d items synced.', 'sellmo-woocommerce' ),
					$meta['count']
				),
			] );
		}

		wp_send_json_error( [
			'message' => (string) ( $result['message'] ?? __( 'Stock sync failed.', 'sellmo-woocommerce' ) ),
		] );
	}

	/**
	 * AJAX: return a paginated, WC-enriched slice of the Sellmo product catalogue.
	 *
	 * Fetches all products from the API (or from a 5-minute transient cache),
	 * applies an optional category filter, then returns 25 products per page
	 * with WC link status, sync toggle state, and edit URL for each product.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_get_products_list(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$page       = max( 1, absint( $_POST['page'] ?? 1 ) );
		$per_page   = 25;
		$cat_filter = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );

		$all_products = $this->get_all_sellmo_products();

		if ( is_wp_error( $all_products ) ) {
			wp_send_json_error( [ 'message' => $all_products->get_error_message() ] );
		}

		// Apply category filter. The API returns category as a flat name string.
		if ( '' !== $cat_filter ) {
			$all_products = array_values(
				array_filter(
					$all_products,
					function ( array $p ) use ( $cat_filter ): bool {
						return (string) ( $p['category'] ?? '' ) === $cat_filter;
					}
				)
			);
		}

		$total       = count( $all_products );
		$total_pages = (int) ceil( $total / $per_page );
		$offset      = ( $page - 1 ) * $per_page;
		$page_slice  = array_slice( $all_products, $offset, $per_page );

		$wc_map   = $this->get_wc_product_map();
		$enriched = [];

		foreach ( $page_slice as $product ) {
			$code = (string) ( $product['product_code'] ?? '' );
			$wc   = $wc_map[ $code ] ?? null;

			// category is a flat string (e.g. "Rocket Fuel").
			$category = (string) ( $product['category'] ?? '' );

			// Price lives inside the shops array; use the first shop entry.
			$shops = isset( $product['shops'] ) && is_array( $product['shops'] ) ? $product['shops'] : [];
			$price = (float) ( $shops[0]['price'] ?? 0 );

			// image_url is the actual API field (empty string when no image).
			$image_url = (string) ( $product['image_url'] ?? '' );

			$variant_count = isset( $product['variants'] ) && is_array( $product['variants'] )
				? count( $product['variants'] )
				: 0;

			$wc_status = 'not_imported';
			$wc_id     = 0;
			$edit_url  = '';

			if ( $wc ) {
				$wc_id     = $wc['id'];
				$edit_url  = (string) get_edit_post_link( $wc_id, 'raw' );
				$wc_status = 'yes' === $wc['sync_enabled'] ? 'linked' : 'sync_disabled';
			}

			$enriched[] = [
				'product_code'  => $code,
				'product_name'  => (string) ( $product['product_name'] ?? '' ),
				'category'      => $category,
				'price'         => $price,
				'image_url'     => $image_url,
				'variant_count' => $variant_count,
				'wc_id'         => $wc_id,
				'wc_status'     => $wc_status,
				'wc_edit_url'   => $edit_url,
				'sync_enabled'  => $wc && 'yes' === $wc['sync_enabled'],
			];
		}

		wp_send_json_success( [
			'products'     => $enriched,
			'total'        => $total,
			'pages'        => max( 1, $total_pages ),
			'current_page' => $page,
			'per_page'     => $per_page,
		] );
	}

	/**
	 * AJAX: clear the products transient cache and return a success message.
	 *
	 * Deletes both the admin full-catalogue cache and all per-cursor page
	 * caches stored by Sellmo_API::get_products() so the next products list
	 * request fetches fresh data from the Sellmo API.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_refresh_products(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$this->clear_products_cache();

		wp_send_json_success( [ 'message' => __( 'Cache cleared. Reloading products…', 'sellmo-woocommerce' ) ] );
	}

	/**
	 * AJAX: import a single Sellmo product into WooCommerce.
	 *
	 * Finds the product in the cached catalogue by product_code, delegates to
	 * Sellmo_Product_Sync::import_product(), and returns the new WC product ID
	 * and edit URL so the JS can update the table row inline.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_import_product(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$product_code = sanitize_text_field( wp_unslash( $_POST['product_code'] ?? '' ) );

		if ( '' === $product_code ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product code.', 'sellmo-woocommerce' ) ] );
		}

		if ( $this->find_wc_product_id_by_code( $product_code ) > 0 ) {
			wp_send_json_error( [ 'message' => __( 'Product is already imported.', 'sellmo-woocommerce' ) ] );
		}

		$all_products = $this->get_all_sellmo_products();

		if ( is_wp_error( $all_products ) ) {
			wp_send_json_error( [ 'message' => $all_products->get_error_message() ] );
		}

		$sellmo_product = null;
		foreach ( $all_products as $p ) {
			if ( (string) ( $p['product_code'] ?? '' ) === $product_code ) {
				$sellmo_product = $p;
				break;
			}
		}

		if ( null === $sellmo_product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found in Sellmo catalogue.', 'sellmo-woocommerce' ) ] );
		}

		$sync   = new Sellmo_Product_Sync();
		$new_id = $sync->import_product( $sellmo_product );

		if ( 0 === $new_id ) {
			wp_send_json_error( [
				'message' => __( 'Import failed. Check the Sync Log for details.', 'sellmo-woocommerce' ),
			] );
		}

		wp_send_json_success( [
			'wc_id'    => $new_id,
			'edit_url' => (string) get_edit_post_link( $new_id, 'raw' ),
			'message'  => __( 'Product imported successfully.', 'sellmo-woocommerce' ),
		] );
	}

	/**
	 * AJAX: set or clear the _sellmo_sync_enabled meta on a linked WC product.
	 *
	 * Only operates on products that already have a matching _sellmo_product_code
	 * meta value. Toggling a product that has not been imported returns an error.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_toggle_sync(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$product_code = sanitize_text_field( wp_unslash( $_POST['product_code'] ?? '' ) );
		$enabled      = filter_var( wp_unslash( $_POST['enabled'] ?? '' ), FILTER_VALIDATE_BOOLEAN );

		if ( '' === $product_code ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product code.', 'sellmo-woocommerce' ) ] );
		}

		$product_id = $this->find_wc_product_id_by_code( $product_code );

		if ( 0 === $product_id ) {
			wp_send_json_error( [
				'message' => __( 'No linked WooCommerce product found for this code.', 'sellmo-woocommerce' ),
			] );
		}

		update_post_meta( $product_id, '_sellmo_sync_enabled', $enabled ? 'yes' : 'no' );

		wp_send_json_success( [ 'sync_enabled' => $enabled ] );
	}

	/**
	 * AJAX: apply a bulk action to a set of Sellmo product codes.
	 *
	 * Accepted actions:
	 *   enable_sync  — set _sellmo_sync_enabled = 'yes' on matched WC products.
	 *   disable_sync — set _sellmo_sync_enabled = 'no' on matched WC products.
	 *   import       — call Sellmo_Product_Sync::import_product() for each code
	 *                  that does not yet have a matching WC product.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_bulk_products(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) );
		$raw    = isset( $_POST['codes'] ) ? (array) $_POST['codes'] : [];
		$codes  = array_values(
			array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $raw ) ) )
		);

		if ( '' === $action || empty( $codes ) ) {
			wp_send_json_error( [ 'message' => __( 'No action or products selected.', 'sellmo-woocommerce' ) ] );
		}

		$processed = 0;
		$errors    = 0;

		if ( 'enable_sync' === $action || 'disable_sync' === $action ) {
			$value = 'enable_sync' === $action ? 'yes' : 'no';

			foreach ( $codes as $code ) {
				$product_id = $this->find_wc_product_id_by_code( $code );
				if ( $product_id > 0 ) {
					update_post_meta( $product_id, '_sellmo_sync_enabled', $value );
					$processed++;
				}
			}
		} elseif ( 'import' === $action ) {
			set_time_limit( 120 );

			$all_products = $this->get_all_sellmo_products();

			if ( is_wp_error( $all_products ) ) {
				wp_send_json_error( [ 'message' => $all_products->get_error_message() ] );
			}

			// Index the full catalogue by product_code for O(1) lookup.
			$indexed = [];
			foreach ( $all_products as $p ) {
				$c = (string) ( $p['product_code'] ?? '' );
				if ( '' !== $c ) {
					$indexed[ $c ] = $p;
				}
			}

			$sync = new Sellmo_Product_Sync();

			foreach ( $codes as $code ) {
				if ( $this->find_wc_product_id_by_code( $code ) > 0 ) {
					// Already imported — count as processed, not error.
					$processed++;
					continue;
				}

				if ( ! isset( $indexed[ $code ] ) ) {
					$errors++;
					continue;
				}

				$new_id = $sync->import_product( $indexed[ $code ] );
				if ( $new_id > 0 ) {
					$processed++;
				} else {
					$errors++;
				}
			}
		}

		wp_send_json_success( [
			'processed' => $processed,
			'errors'    => $errors,
			'message'   => sprintf(
				/* translators: %d: number of products processed */
				_n(
					'%d product processed.',
					'%d products processed.',
					$processed,
					'sellmo-woocommerce'
				),
				$processed
			),
		] );
	}

	// -------------------------------------------------------------------------
	// Private helpers (products page)
	// -------------------------------------------------------------------------

	/**
	 * Push a mapped Sellmo product's catalogue image onto its WooCommerce product.
	 *
	 * Looks up the Sellmo product in the cached catalogue by id (then code) to
	 * read its image_url, then sideloads it as the WooCommerce product's featured
	 * image via Sellmo_Product_Sync. An existing featured image is preserved so a
	 * merchant's manually chosen image is never overwritten. Failures are silent —
	 * mapping must still succeed even if the image cannot be fetched.
	 *
	 * @since  1.0.0
	 * @param  string $sellmo_id     Sellmo product id.
	 * @param  string $sellmo_code   Sellmo product code.
	 * @param  int    $wc_product_id Mapped WooCommerce product ID.
	 * @return void
	 */
	private function push_sellmo_image_to_wc( string $sellmo_id, string $sellmo_code, int $wc_product_id ): void {
		if ( $wc_product_id <= 0 || ( '' === $sellmo_id && '' === $sellmo_code ) ) {
			return;
		}

		$all_products = $this->get_all_sellmo_products();
		if ( is_wp_error( $all_products ) ) {
			return;
		}

		$image_url = '';
		foreach ( $all_products as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}

			$p_id   = (string) ( $p['id'] ?? '' );
			$p_code = (string) ( $p['product_code'] ?? '' );

			if ( ( '' !== $sellmo_id && $p_id === $sellmo_id ) || ( '' !== $sellmo_code && $p_code === $sellmo_code ) ) {
				$image_url = (string) ( $p['image_url'] ?? $p['product_image_url'] ?? '' );
				break;
			}
		}

		if ( '' === $image_url ) {
			return;
		}

		( new Sellmo_Product_Sync() )->set_product_image_from_url( $wc_product_id, $image_url );
	}

	/**
	 * Fetch and cache the complete Sellmo product catalogue.
	 *
	 * Pages through Sellmo_API::get_products() until all products are collected
	 * (max 20 pages × 250 items = 5,000 products). The flat array is cached in
	 * the 'sellmo_wc_products_cache' transient for 5 minutes.
	 *
	 * @since  1.0.0
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function get_all_sellmo_products(): array|\WP_Error {
		$cached = get_transient( POS_Brand::transient( 'products_cache' ) );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$api       = new Sellmo_API();
		$cursor    = '';
		$page      = 0;
		$max_pages = 20;
		$products  = [];

		do {
			$page++;
			$response = $api->get_products( $cursor );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$items = $this->extract_items_from_response( $response );

			if ( empty( $items ) ) {
				break;
			}

			$products = array_merge( $products, $items );

			$cursor = $this->extract_cursor_from_response( $response );

			if ( '' === $cursor ) {
				break;
			}

		} while ( $page < $max_pages );

		set_transient( POS_Brand::transient( 'products_cache' ), $products, 5 * MINUTE_IN_SECONDS );

		return $products;
	}

	/**
	 * Build a map of Sellmo product_code → WC product data.
	 *
	 * Uses a single JOIN query to retrieve both the product code and sync status
	 * for all WC products (not variations) linked to Sellmo. This avoids N+1
	 * queries when enriching a page of products.
	 *
	 * @since  1.0.0
	 * @return array<string, array{ id: int, sync_enabled: string }>
	 */
	private function get_wc_product_map(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID,
						pm.meta_value AS sellmo_code,
						sku.meta_value AS sku_code,
						ps.meta_value AS sync_enabled
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm
				        ON pm.post_id = p.ID AND pm.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} sku
				        ON sku.post_id = p.ID AND sku.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} ps
				        ON ps.post_id = p.ID AND ps.meta_key = %s
				 WHERE p.post_type = 'product'
				   AND p.post_status IN ('publish', 'private')
				   AND (pm.meta_value IS NOT NULL OR sku.meta_value IS NOT NULL)",
				'_sellmo_product_code',
				'_sku',
				'_sellmo_sync_enabled'
			),
			ARRAY_A
		);

		$map = [];

		foreach ( (array) $rows as $row ) {
			$sellmo_code = (string) ( $row['sellmo_code'] ?? '' );
			$sku_code    = (string) ( $row['sku_code'] ?? '' );
			$code        = '' !== $sellmo_code ? $sellmo_code : $sku_code;

			if ( '' !== $code ) {
				if ( isset( $map[ $code ] ) && '' === $sellmo_code ) {
					continue;
				}

				$map[ $code ] = [
					'id'           => (int) $row['ID'],
					'sync_enabled' => (string) ( $row['sync_enabled'] ?? '' ),
				];
			}
		}

		return $map;
	}

	/**
	 * Find a WC product post ID by Sellmo product_code meta value.
	 *
	 * Searches both 'product' and 'product_variation' post types so that
	 * variant codes can also be resolved (e.g. for the toggle_sync handler).
	 *
	 * @since  1.0.0
	 * @param  string $code Sellmo product_code.
	 * @return int          WC post ID, or 0 if not found.
	 */
	private function find_wc_product_id_by_code( string $code ): int {
		if ( '' === $code ) {
			return 0;
		}

		$query = new WP_Query( [
			'post_type'      => [ 'product', 'product_variation' ],
			'post_status'    => [ 'publish', 'private' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => '_sellmo_product_code',
					'value' => $code,
				],
			],
		] );

		$id = ! empty( $query->posts ) ? (int) $query->posts[0] : 0;

		if ( 0 === $id ) {
			$sku_id = (int) wc_get_product_id_by_sku( $code );

			if ( $sku_id > 0 ) {
				$post_type = get_post_type( $sku_id );
				if ( in_array( $post_type, [ 'product', 'product_variation' ], true ) ) {
					$id = $sku_id;
					update_post_meta( $id, '_sellmo_product_code', $code );
				}
			}
		}

		return $id;
	}

	/**
	 * Remove every trace of the previously synced Sellmo catalogue.
	 *
	 * Called when the API token changes (a new token = a different SalesPlay
	 * account). Deletes all WooCommerce products and variations created or linked
	 * from Sellmo, empties the mapping/staging and variant tables (which hold the
	 * stored stock counts and saved product details), clears the catalogue cache,
	 * and resets the persisted sync summaries.
	 *
	 * @since  1.0.0
	 * @return int Number of WooCommerce products removed.
	 */
	private function purge_sellmo_synced_data(): int {
		global $wpdb;

		// WooCommerce products linked to Sellmo carry one of these meta keys.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'product'
				   AND pm.meta_key IN ( %s, %s )
				   AND pm.meta_value <> ''",
				'_sellmo_product_code',
				'_salesplay_pos_product_id'
			)
		);

		$removed = 0;

		foreach ( (array) $product_ids as $product_id ) {
			$product = wc_get_product( (int) $product_id );

			if ( $product instanceof WC_Product ) {
				// Force-delete variations first, then the parent product.
				foreach ( $product->get_children() as $child_id ) {
					wp_delete_post( (int) $child_id, true );
				}
				$product->delete( true );
			} else {
				wp_delete_post( (int) $product_id, true );
			}

			$removed++;
		}

		// Empty the mapping/staging and variant tables (stock counts + saved
		// details). The staged Product Mapper rows live in sellmo_product_mapping
		// as map_status = 0, so delete_all_rows() clears them too.
		Sellmo_Product_Mapping::delete_all_rows();
		Sellmo_Product_Variant_Store::delete_all_rows();

		// Drop the pre-migration legacy mapper table if a copy still lingers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from trusted prefix.
		$legacy_mapper = POS_Brand::table( 'product_mapper' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$legacy_mapper}`" );

		// Clear cached catalogue and reset stored sync summaries.
		$this->clear_products_cache();
		delete_option( POS_Brand::option( 'last_product_sync' ) );
		delete_option( POS_Brand::option( 'last_stock_sync' ) );
		delete_option( POS_Brand::option( 'last_order_sync' ) );

		Sellmo_Logger::log(
			'product',
			'sellmo_to_wc',
			'skipped',
			sprintf(
				'API token changed — removed %d Sellmo-linked product(s) and cleared all stored stock counts, mappings, and variant data.',
				$removed
			)
		);

		return $removed;
	}

	/**
	 * Delete the full products transient cache and all per-cursor page caches.
	 *
	 * Called when the selected shop changes (so stale shop-scoped prices are not
	 * shown) and by the "Refresh" button on the Products page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function clear_products_cache(): void {
		global $wpdb;

		delete_transient( POS_Brand::transient( 'products_cache' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_sellmo_wc_products_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_sellmo_wc_products_' ) . '%'
			)
		);
	}

	/**
	 * Extract the products array from a Sellmo API response.
	 *
	 * Handles both `{ "data": [...] }` and `{ "products": [...] }` shapes.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $response
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_items_from_response( array $response ): array {
		if ( isset( $response['products'] ) && is_array( $response['products'] ) ) {
			return $response['products'];
		}
		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			return $response['data'];
		}
		return [];
	}

	/**
	 * Extract the pagination cursor from a Sellmo API response.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $response
	 * @return string Empty string when there are no further pages.
	 */
	private function extract_cursor_from_response( array $response ): string {
		foreach ( [ 'cursor', 'next_cursor', 'next_page_cursor' ] as $key ) {
			if ( ! empty( $response[ $key ] ) && is_string( $response[ $key ] ) ) {
				return $response[ $key ];
			}
		}

		if ( ! empty( $response['meta']['cursor'] ) && is_string( $response['meta']['cursor'] ) ) {
			return $response['meta']['cursor'];
		}

		return '';
	}

	/**
	 * Build a lightweight list of WooCommerce products for the mapper selector.
	 *
	 * @since  1.0.0
	 * @param  string $search Optional search query.
	 * @return array<int, array{ id: int, label: string }>
	 */
	private function get_wc_products_for_mapper_selector( string $search = '' ): array {
		$args = [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'private', 'draft' ],
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$rows  = [];

		foreach ( (array) $query->posts as $product_id ) {
			$product = wc_get_product( (int) $product_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$sku = (string) $product->get_sku();
			$rows[] = [
				'id'    => (int) $product_id,
				'label' => sprintf(
					'#%d - %s%s',
					(int) $product_id,
					$product->get_name(),
					'' !== $sku ? ' (' . $sku . ')' : ''
				),
			];
		}

		return $rows;
	}
}
