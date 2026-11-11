<?php
declare(strict_types=1);

/**
 * Settings page view for Sellmo POS Integration.
 *
 * Included by Sellmo_Admin::page_settings(). Capability check already
 * performed by the caller.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$brand_name            = POS_Brand::name();
$has_token             = '' !== (string) Sellmo_Settings::get( 'api_token' );
$shop_id               = (string) ( Sellmo_Settings::get( 'shop_id' ) ?: '' );
$product_sync_enabled  = 'yes' === (string) Sellmo_Settings::get( 'product_sync_enabled' );
$stock_sync_enabled    = 'yes' === (string) Sellmo_Settings::get( 'stock_sync_enabled' );
$order_sync_enabled    = 'yes' === (string) Sellmo_Settings::get( 'order_sync_enabled' );
$auto_map_enabled      = 'yes' === (string) Sellmo_Settings::get( 'auto_map_enabled' );
$stock_sync_interval   = (string) ( Sellmo_Settings::get( 'stock_sync_interval' ) ?: 'hourly' );
$product_sync_interval = (string) ( Sellmo_Settings::get( 'product_sync_interval' ) ?: 'twicedaily' );
$order_sync_interval   = Sellmo_Scheduler::order_interval();
$order_timezone        = (string) ( Sellmo_Settings::get( 'order_timezone' ) ?: '' );
?>
<div class="wrap sellmo-settings-wrap">

	<h1>
		<?php
		printf(
			esc_html__( '%s — Settings', 'sellmo-woocommerce' ),
			esc_html( $brand_name )
		);
		?>
	</h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

		<?php wp_nonce_field( 'sellmo_wc_save_settings', 'sellmo_wc_settings_nonce' ); ?>
		<input type="hidden" name="action" value="sellmo_wc_save_settings">

		<?php /* ── A) API Connection ──────────────────────────────────────── */ ?>
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'API Connection', 'sellmo-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>

					<tr>
						<th scope="row">
							<label for="sellmo_wc_api_token">
								<?php esc_html_e( 'API Token', 'sellmo-woocommerce' ); ?>
							</label>
						</th>
						<td>
							<div class="sellmo-token-wrap">
								<input
									type="password"
									id="sellmo_wc_api_token"
									name="sellmo_wc_api_token"
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo $has_token
										? esc_attr__( 'Token saved — enter a new value to replace', 'sellmo-woocommerce' )
										: esc_attr(
											sprintf(
												/* translators: %s: Brand display name. */
												__( 'Enter your %s API token', 'sellmo-woocommerce' ),
												$brand_name
											)
										); ?>"
								>
								<button type="button" id="sellmo-toggle-token" class="button">
									<?php esc_html_e( 'Show', 'sellmo-woocommerce' ); ?>
								</button>
								<button type="button" id="sellmo-test-connection" class="button">
									<?php esc_html_e( 'Connect', 'sellmo-woocommerce' ); ?>
								</button>
							</div>
							<span
								id="sellmo-connection-status"
								class="sellmo-connection-badge<?php echo esc_attr( $has_token ? '' : ' disconnected' ); ?>"
							></span>
							<p class="description">
								<?php
								printf(
									esc_html__( 'Your %s API Bearer token. Click Connect to load your shops, then Save once. Leave blank to keep the existing token.', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</p>
							<?php
							if ( $has_token ) :
								// Shown by JS once a different token is typed; required by the save handler.
								$linked_count  = Sellmo_Product_Mapping::count_mapped_products();
								$created_count = Sellmo_Cleanup::count_deletable();
								?>
								<p id="sellmo-confirm-token-change" class="notice notice-warning inline" style="display:none">
									<label>
										<input type="checkbox" name="sellmo_wc_confirm_token_change" value="yes">
										<?php
										printf(
											/* translators: 1: number of imported products that will be deleted, 2: mapped product count */
											esc_html__( 'I understand: a new token deletes the %1$d imported product(s), removes %2$d mapping(s) and clears stored stock.', 'sellmo-woocommerce' ),
											$created_count,
											$linked_count
										);
										?>
									</label>
								</p>
							<?php endif; ?>
							<?php if ( $has_token ) : ?>
								<p>
									<label>
										<input type="checkbox" name="sellmo_wc_remove_token" value="yes">
										<?php esc_html_e( 'Remove the saved token. This clears mappings and stored stock data. Imported products are deleted only when Auto map is on and their SKU matches the POS product code; all others are kept as ordinary products.', 'sellmo-woocommerce' ); ?>
									</label>
								</p>
								<p>
									<label>
										<input type="checkbox" name="sellmo_wc_delete_linked" value="yes">
										<?php esc_html_e( 'Also delete your existing WooCommerce products that were linked to the POS. Leave unticked to keep them as ordinary products.', 'sellmo-woocommerce' ); ?>
									</label>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sellmo_wc_shop_id">
								<?php esc_html_e( 'Shop', 'sellmo-woocommerce' ); ?>
							</label>
						</th>
						<td>
							<select
								id="sellmo_wc_shop_id"
								name="sellmo_wc_shop_id"
								data-saved="<?php echo esc_attr( $shop_id ); ?>"
							>
								<option value="">
									<?php echo $has_token
										? esc_html__( '— Loading shops…', 'sellmo-woocommerce' )
										: esc_html__( '— Enter your token and click Connect —', 'sellmo-woocommerce' ); ?>
								</option>
							</select>
							<p class="description">
								<?php
								printf(
									esc_html__( 'Select the %s shop to sync with this WooCommerce store.', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sellmo_wc_terminal_key">
								<?php esc_html_e( 'Terminal', 'sellmo-woocommerce' ); ?>
							</label>
						</th>
						<td>
							<select
								id="sellmo_wc_terminal_key"
								name="sellmo_wc_terminal_key"
								data-saved="<?php echo esc_attr( (string) ( Sellmo_Settings::get( 'terminal_key' ) ?: '' ) ); ?>"
							>
								<option value=""><?php esc_html_e( '— Select a shop first —', 'sellmo-woocommerce' ); ?></option>
							</select>
							<p class="description">
								<?php
								printf(
									/* translators: %s: Brand display name. */
									esc_html__( 'Online orders are sent to this %s terminal.', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</p>
						</td>
					</tr>

				</tbody>
			</table>
		</div>

		<?php /* ── B) Sync Features ─────────────────────────────────────── */ ?>
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'Sync Features', 'sellmo-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>

					<tr>
						<th scope="row"><?php esc_html_e( 'Product Sync', 'sellmo-woocommerce' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="sellmo_wc_product_sync_enabled"
									value="yes"
									<?php checked( $product_sync_enabled ); ?>
								>
								<?php
								printf(
									esc_html__( 'Enable product sync (%s → WooCommerce)', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Stock Sync', 'sellmo-woocommerce' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="sellmo_wc_stock_sync_enabled"
									value="yes"
									<?php checked( $stock_sync_enabled ); ?>
								>
								<?php
								printf(
									esc_html__( 'Enable stock sync (%s → WooCommerce)', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Order Sync', 'sellmo-woocommerce' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="sellmo_wc_order_sync_enabled"
									value="yes"
									<?php checked( $order_sync_enabled ); ?>
								>
								<?php
								printf(
									esc_html__( 'Enable order sync (WooCommerce ↔ %s)', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: Brand display name. */
									esc_html__( 'Orders are pushed to %s when they reach Processing, and their status is pulled back on the schedule below.', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Auto Map', 'sellmo-woocommerce' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="sellmo_wc_auto_map_enabled"
									value="yes"
									autocomplete="off"<?php /* Opt-in only: stop the browser restoring a ticked state on reload. */ ?>
									<?php checked( $auto_map_enabled ); ?>
								>
								<?php esc_html_e( 'Auto map products by SKU on every product sync', 'sellmo-woocommerce' ); ?>
							</label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: Brand display name. */
									esc_html__( 'An unmapped %s product is linked to the existing WooCommerce product whose SKU equals its product code. If a code or SKU matches more than one product, nothing is mapped. A variable product is mapped only when every variant matches a variation of the same WooCommerce product. Once mapped, price, category and stock come from the POS.', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</p>
							<p class="description">
								<?php esc_html_e( 'When on, a WooCommerce product or variation whose POS product is deleted or leaves the selected shop is deleted. When off, it is kept as an unmapped product.', 'sellmo-woocommerce' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sellmo_wc_order_timezone">
								<?php esc_html_e( 'Order Time Zone', 'sellmo-woocommerce' ); ?>
							</label>
						</th>
						<td>
							<select id="sellmo_wc_order_timezone" name="sellmo_wc_order_timezone">
								<option value="">
									<?php
									printf(
										/* translators: %s: the site's current timezone, e.g. "Asia/Colombo" or "+05:30". */
										esc_html__( 'Use WordPress time zone (%s)', 'sellmo-woocommerce' ),
										esc_html( wp_timezone_string() )
									);
									?>
								</option>
								<?php
								// Offset-first labels — "(UTC+05:30) Sri Lanka, India" — sorted west
								// to east. Offsets are computed from each zone, so fractional ones
								// (+05:30, +05:45, +06:30) are exact and daylight saving is followed.
								// Values stay real IANA identifiers, which is what the save handler
								// validates and what Sellmo_Order_Sync hands to DateTimeZone.
								foreach ( Sellmo_Settings::timezone_choices() as $tz_group => $tz_options ) :
									?>
									<optgroup label="<?php echo esc_attr( $tz_group ); ?>">
										<?php foreach ( $tz_options as $tz_id => $tz_label ) : ?>
											<option value="<?php echo esc_attr( $tz_id ); ?>" <?php selected( $order_timezone, $tz_id ); ?>>
												<?php echo esc_html( $tz_label ); ?>
											</option>
										<?php endforeach; ?>
									</optgroup>
									<?php
								endforeach;
								?>
							</select>
							<p class="description">
								<?php
								printf(
									/* translators: %s: Brand display name. */
									esc_html__( 'Time zone used for the order date and time sent to %s, and for the dates shown on the Dashboard. Leave on the WordPress time zone unless your shop is in a different one.', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</p>
						</td>
					</tr>

				</tbody>
			</table>
		</div>

		<?php /* ── C) Sync Schedule ─────────────────────────────────────── */ ?>
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'Sync Schedule', 'sellmo-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>

					<tr>
						<th scope="row">
							<label for="sellmo_wc_stock_sync_interval">
								<?php esc_html_e( 'Stock Sync Interval', 'sellmo-woocommerce' ); ?>
							</label>
						</th>
						<td>
							<select id="sellmo_wc_stock_sync_interval" name="sellmo_wc_stock_sync_interval">
								<option value="hourly"    <?php selected( $stock_sync_interval, 'hourly' ); ?>>
									<?php esc_html_e( 'Hourly', 'sellmo-woocommerce' ); ?>
								</option>
								<option value="twicedaily" <?php selected( $stock_sync_interval, 'twicedaily' ); ?>>
									<?php esc_html_e( 'Twice Daily', 'sellmo-woocommerce' ); ?>
								</option>
								<option value="daily"     <?php selected( $stock_sync_interval, 'daily' ); ?>>
									<?php esc_html_e( 'Daily', 'sellmo-woocommerce' ); ?>
								</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sellmo_wc_product_sync_interval">
								<?php esc_html_e( 'Product Sync Interval', 'sellmo-woocommerce' ); ?>
							</label>
						</th>
						<td>
							<select id="sellmo_wc_product_sync_interval" name="sellmo_wc_product_sync_interval">
								<option value="twicedaily" <?php selected( $product_sync_interval, 'twicedaily' ); ?>>
									<?php esc_html_e( 'Twice Daily', 'sellmo-woocommerce' ); ?>
								</option>
								<option value="daily"      <?php selected( $product_sync_interval, 'daily' ); ?>>
									<?php esc_html_e( 'Daily', 'sellmo-woocommerce' ); ?>
								</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sellmo_wc_order_sync_interval">
								<?php esc_html_e( 'Order Status Interval', 'sellmo-woocommerce' ); ?>
							</label>
						</th>
						<td>
							<select id="sellmo_wc_order_sync_interval" name="sellmo_wc_order_sync_interval">
								<?php foreach ( Sellmo_Scheduler::order_interval_choices() as $interval_key => $interval_label ) : ?>
									<option value="<?php echo esc_attr( $interval_key ); ?>" <?php selected( $order_sync_interval, $interval_key ); ?>>
										<?php echo esc_html( $interval_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php
								printf(
									/* translators: %s: Brand display name. */
									esc_html__( 'How often to ask %s what happened to the orders sent from WooCommerce (Completed, Rejected, …).', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</p>
						</td>
					</tr>

				</tbody>
			</table>
		</div>

		<?php /* ── D) Category Filter ───────────────────────────────────── */ ?>
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'Category Filter', 'sellmo-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>

					<tr>
						<th scope="row">
							<label for="sellmo_wc_sync_categories">
								<?php esc_html_e( 'Sync Categories', 'sellmo-woocommerce' ); ?>
							</label>
						</th>
						<td>
							<select
								id="sellmo_wc_sync_categories"
								name="sellmo_wc_sync_categories[]"
								multiple
								class="sellmo-categories-select"
							>
								<?php /* Options injected by sellmo-admin.js via AJAX */ ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Hold Ctrl / Cmd to select multiple. Leave empty to sync all categories.', 'sellmo-woocommerce' ); ?>
							</p>
						</td>
					</tr>

				</tbody>
			</table>
		</div>

		<?php submit_button( __( 'Save Settings', 'sellmo-woocommerce' ) ); ?>

	</form>
</div>
