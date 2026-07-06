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
$stock_sync_interval   = (string) ( Sellmo_Settings::get( 'stock_sync_interval' ) ?: 'hourly' );
$product_sync_interval = (string) ( Sellmo_Settings::get( 'product_sync_interval' ) ?: 'twicedaily' );
$webhook_secret        = (string) ( Sellmo_Settings::get( 'webhook_secret' ) ?: '' );
$webhook_url           = rest_url( 'sellmo-wc/v1/webhook' );
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
		<input type="hidden" id="sellmo_wc_terminal_key" name="sellmo_wc_terminal_key" value="<?php echo esc_attr( (string) ( Sellmo_Settings::get( 'terminal_key' ) ?: '' ) ); ?>">

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
								<button type="button" id="sellmo-test-connection" class="button" <?php echo $has_token ? '' : 'disabled'; ?>>
									<?php esc_html_e( 'Test Connection', 'sellmo-woocommerce' ); ?>
								</button>
							</div>
							<span
								id="sellmo-connection-status"
								class="sellmo-connection-badge<?php echo $has_token ? '' : ' disconnected'; ?>"
								style="<?php echo $has_token ? '' : ''; ?>"
							></span>
							<p class="description">
								<?php
								printf(
									esc_html__( 'Your %s API Bearer token. Leave blank to keep the existing token.', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</p>
							<?php if ( $has_token ) : ?>
								<p>
									<label>
										<input type="checkbox" name="sellmo_wc_remove_token" value="yes">
										<?php esc_html_e( 'Remove the saved token. This deletes all imported products, mappings, and stored stock data.', 'sellmo-woocommerce' ); ?>
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
										: esc_html__( '— Save a token first —', 'sellmo-woocommerce' ); ?>
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
									esc_html__( 'Enable order sync (WooCommerce → %s)', 'sellmo-woocommerce' ),
									esc_html( $brand_name )
								);
								?>
							</label>
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

		<?php /* ── E) Webhook Configuration ─────────────────────────────── */ ?>
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'Webhook Configuration - Under Development', 'sellmo-woocommerce' ); ?></h2>
			<!-- <table class="form-table" role="presentation">
				<tbody>

					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'sellmo-woocommerce' ); ?></th>
						<td>
							<div class="sellmo-token-wrap">
								<input
									type="text"
									id="sellmo-webhook-url"
									class="regular-text sellmo-readonly"
									readonly
									value="<?php echo esc_attr( $webhook_url ); ?>"
									onclick="this.select();"
								>
								<button type="button" id="sellmo-copy-webhook-url" class="button">
									<?php esc_html_e( 'Copy', 'sellmo-woocommerce' ); ?>
								</button>
							</div>
							<p class="description">
								<?php esc_html_e( 'Enter this URL in the Sellmo backoffice under Integrations > Webhooks.', 'sellmo-woocommerce' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sellmo_wc_webhook_secret">
								<?php esc_html_e( 'Webhook Secret', 'sellmo-woocommerce' ); ?>
							</label>
						</th>
						<td>
							<div class="sellmo-token-wrap">
								<input
									type="text"
									id="sellmo_wc_webhook_secret"
									name="sellmo_wc_webhook_secret"
									class="regular-text"
									value="<?php echo esc_attr( $webhook_secret ); ?>"
									placeholder="<?php esc_attr_e( 'No secret configured', 'sellmo-woocommerce' ); ?>"
								>
								<button type="button" id="sellmo-generate-secret" class="button">
									<?php esc_html_e( 'Generate', 'sellmo-woocommerce' ); ?>
								</button>
								<button type="button" id="sellmo-copy-webhook-secret" class="button">
									<?php esc_html_e( 'Copy', 'sellmo-woocommerce' ); ?>
								</button>
							</div>
							<p id="sellmo-secret-notice" class="description sellmo-notice-inline" style="display:none;"></p>
							<p class="description">
								<?php esc_html_e( 'Used to validate incoming webhooks via HMAC SHA256. Copy this value to the Sellmo backoffice.', 'sellmo-woocommerce' ); ?>
							</p>
						</td>
					</tr>

				</tbody>
			</table> -->
		</div>

		<?php submit_button( __( 'Save Settings', 'sellmo-woocommerce' ) ); ?>

	</form>
</div>
