<?php
declare(strict_types=1);

/**
 * Dashboard page view for Sellmo POS Integration.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ── Connection status ─────────────────────────────────────────────────────────
$api_token    = (string) ( Sellmo_Settings::get( 'api_token' ) ?: '' );
$has_token    = '' !== $api_token;
$conn_status  = (array) ( Sellmo_Settings::get( 'connection_status' ) ?: [] );
$is_connected = $has_token && ! empty( $conn_status['success'] );

// ── Sync metadata ─────────────────────────────────────────────────────────────
$product_meta = (array) ( Sellmo_Settings::get( 'last_product_sync' ) ?: [] );
$stock_meta   = (array) ( Sellmo_Settings::get( 'last_stock_sync' ) ?: [] );
$order_meta   = (array) ( Sellmo_Settings::get( 'last_order_sync' ) ?: [] );

// ── Feature toggles ───────────────────────────────────────────────────────────
$product_enabled = 'yes' === (string) Sellmo_Settings::get( 'product_sync_enabled' );
$stock_enabled   = 'yes' === (string) Sellmo_Settings::get( 'stock_sync_enabled' );
$order_enabled   = 'yes' === (string) Sellmo_Settings::get( 'order_sync_enabled' );

// ── Next scheduled stock sync ─────────────────────────────────────────────────
$next_stock = wp_next_scheduled( 'sellmo_wc_stock_sync' );

// ── Recent activity ───────────────────────────────────────────────────────────
$log_data  = Sellmo_Logger::get_logs( [ 'per_page' => 10, 'paged' => 1 ] );
$log_items = $log_data['items'];

/**
 * Format a UTC Unix timestamp for display using the site's timezone and formats.
 *
 * @param  int $timestamp Unix timestamp (UTC).
 * @return string
 */
if ( ! function_exists( 'sellmo_dashboard_format_ts' ) ) {
	function sellmo_dashboard_format_ts( int $timestamp ): string {
		if ( 0 === $timestamp ) {
			return '—';
		}
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return (string) wp_date( $fmt, $timestamp );
	}
}

/**
 * Format a UTC MySQL DATETIME string for display using the site's timezone.
 *
 * @param  string $mysql_datetime MySQL DATETIME in UTC.
 * @return string
 */
if ( ! function_exists( 'sellmo_dashboard_format_dt' ) ) {
	function sellmo_dashboard_format_dt( string $mysql_datetime ): string {
		if ( '' === $mysql_datetime || '0000-00-00 00:00:00' === $mysql_datetime ) {
			return '—';
		}
		$ts = strtotime( $mysql_datetime . ' +0000' );
		if ( false === $ts ) {
			return esc_html( $mysql_datetime );
		}
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return (string) wp_date( $fmt, $ts );
	}
}
?>
<div class="wrap sellmo-dashboard-wrap">
	<h1><?php esc_html_e( 'Sellmo POS — Dashboard', 'sellmo-woocommerce' ); ?></h1>

	<!-- ── Connection Status Card ──────────────────────────────────────────── -->
	<div class="sellmo-card">
		<h2><?php esc_html_e( 'Connection Status', 'sellmo-woocommerce' ); ?></h2>
		<div class="sellmo-card-body sellmo-connection-row">
			<span class="sellmo-connection-badge <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
				<?php
				echo $is_connected
					? esc_html__( 'Connected', 'sellmo-woocommerce' )
					: esc_html__( 'Not Connected', 'sellmo-woocommerce' );
				?>
			</span>
			<span class="sellmo-connection-hint">
				<?php if ( ! $has_token ) : ?>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: settings page URL */
							__( 'No API token set. <a href="%s">Configure your settings</a> to connect.', 'sellmo-woocommerce' ),
							esc_url( admin_url( 'admin.php?page=sellmo-settings' ) )
						),
						[ 'a' => [ 'href' => [] ] ]
					);
					?>
				<?php elseif ( ! $is_connected ) : ?>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: settings page URL */
							__( 'API token set but connection has not been tested or last test failed. <a href="%s">Test the connection</a>.', 'sellmo-woocommerce' ),
							esc_url( admin_url( 'admin.php?page=sellmo-settings' ) )
						),
						[ 'a' => [ 'href' => [] ] ]
					);
					?>
				<?php else : ?>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: settings page URL */
							__( 'Connected to Sellmo POS. <a href="%s">Manage settings</a>.', 'sellmo-woocommerce' ),
							esc_url( admin_url( 'admin.php?page=sellmo-settings' ) )
						),
						[ 'a' => [ 'href' => [] ] ]
					);
					?>
				<?php endif; ?>
			</span>
		</div>
	</div>

	<!-- ── Sync Status Cards ───────────────────────────────────────────────── -->
	<div class="sellmo-dashboard-grid">

		<!-- Product Sync -->
		<div class="sellmo-card sellmo-sync-card">
			<h2><?php esc_html_e( 'Product Sync', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body">
				<table class="sellmo-meta-table">
					<tr>
						<th><?php esc_html_e( 'Status', 'sellmo-woocommerce' ); ?></th>
						<td>
							<span class="sellmo-toggle-badge <?php echo $product_enabled ? 'enabled' : 'disabled'; ?>">
								<?php echo $product_enabled
									? esc_html__( 'Enabled', 'sellmo-woocommerce' )
									: esc_html__( 'Disabled', 'sellmo-woocommerce' );
								?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last run', 'sellmo-woocommerce' ); ?></th>
						<td><?php echo esc_html( sellmo_dashboard_format_ts( (int) ( $product_meta['time'] ?? 0 ) ) ); ?></td>
					</tr>
					<?php if ( ! empty( $product_meta['status'] ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Last result', 'sellmo-woocommerce' ); ?></th>
						<td>
							<span class="sellmo-result-badge <?php echo 'success' === $product_meta['status'] ? 'success' : 'error'; ?>">
								<?php
								if ( 'success' === $product_meta['status'] ) {
									printf(
										/* translators: %d: synced product count */
										esc_html__( 'Success (%d synced)', 'sellmo-woocommerce' ),
										absint( $product_meta['count'] ?? 0 )
									);
								} else {
									printf(
										/* translators: %d: failed count */
										esc_html__( 'Error (%d failed)', 'sellmo-woocommerce' ),
										absint( $product_meta['count'] ?? 0 )
									);
								}
								?>
							</span>
						</td>
					</tr>
					<?php endif; ?>
				</table>
				<div class="sellmo-sync-actions">
					<button type="button" id="sellmo-sync-products" class="button button-secondary">
						<?php esc_html_e( 'Sync Products Now', 'sellmo-woocommerce' ); ?>
					</button>
					<span class="sellmo-sync-spinner spinner" style="float:none;margin:0 4px 0 0;"></span>
					<span class="sellmo-sync-result" id="sellmo-sync-products-result"></span>
				</div>
			</div>
		</div>

		<!-- Stock Sync -->
		<div class="sellmo-card sellmo-sync-card">
			<h2><?php esc_html_e( 'Stock Sync', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body">
				<table class="sellmo-meta-table">
					<tr>
						<th><?php esc_html_e( 'Status', 'sellmo-woocommerce' ); ?></th>
						<td>
							<span class="sellmo-toggle-badge <?php echo $stock_enabled ? 'enabled' : 'disabled'; ?>">
								<?php echo $stock_enabled
									? esc_html__( 'Enabled', 'sellmo-woocommerce' )
									: esc_html__( 'Disabled', 'sellmo-woocommerce' );
								?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last run', 'sellmo-woocommerce' ); ?></th>
						<td><?php echo esc_html( sellmo_dashboard_format_ts( (int) ( $stock_meta['time'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Next scheduled', 'sellmo-woocommerce' ); ?></th>
						<td>
							<?php
							echo esc_html(
								$next_stock
									? sellmo_dashboard_format_ts( (int) $next_stock )
									: __( 'Not scheduled', 'sellmo-woocommerce' )
							);
							?>
						</td>
					</tr>
					<?php if ( ! empty( $stock_meta['status'] ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Last result', 'sellmo-woocommerce' ); ?></th>
						<td>
							<span class="sellmo-result-badge <?php echo 'success' === $stock_meta['status'] ? 'success' : 'error'; ?>">
								<?php
								if ( 'success' === $stock_meta['status'] ) {
									printf(
										/* translators: %d: synced item count */
										esc_html__( 'Success (%d synced)', 'sellmo-woocommerce' ),
										absint( $stock_meta['count'] ?? 0 )
									);
								} else {
									printf(
										/* translators: %d: failed count */
										esc_html__( 'Error (%d failed)', 'sellmo-woocommerce' ),
										absint( $stock_meta['count'] ?? 0 )
									);
								}
								?>
							</span>
						</td>
					</tr>
					<?php endif; ?>
				</table>
				<div class="sellmo-sync-actions">
					<button type="button" id="sellmo-sync-stock" class="button button-secondary">
						<?php esc_html_e( 'Sync Stock Now', 'sellmo-woocommerce' ); ?>
					</button>
					<span class="sellmo-sync-spinner spinner" style="float:none;margin:0 4px 0 0;"></span>
					<span class="sellmo-sync-result" id="sellmo-sync-stock-result"></span>
				</div>
			</div>
		</div>

		<!-- Order Sync -->
		<div class="sellmo-card sellmo-sync-card">
			<h2><?php esc_html_e( 'Order Sync', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body">
				<table class="sellmo-meta-table">
					<tr>
						<th><?php esc_html_e( 'Status', 'sellmo-woocommerce' ); ?></th>
						<td>
							<span class="sellmo-toggle-badge <?php echo $order_enabled ? 'enabled' : 'disabled'; ?>">
								<?php echo $order_enabled
									? esc_html__( 'Enabled', 'sellmo-woocommerce' )
									: esc_html__( 'Disabled', 'sellmo-woocommerce' );
								?>
							</span>
						</td>
					</tr>
					<?php if ( ! empty( $order_meta['time'] ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Last order', 'sellmo-woocommerce' ); ?></th>
						<td>
							<strong>#<?php echo absint( $order_meta['order_id'] ?? 0 ); ?></strong>
							<span class="sellmo-meta-time">
								<?php echo esc_html( sellmo_dashboard_format_ts( (int) $order_meta['time'] ) ); ?>
							</span>
						</td>
					</tr>
					<?php else : ?>
					<tr>
						<th><?php esc_html_e( 'Last order', 'sellmo-woocommerce' ); ?></th>
						<td><em><?php esc_html_e( 'No orders synced yet.', 'sellmo-woocommerce' ); ?></em></td>
					</tr>
					<?php endif; ?>
				</table>
			</div>
		</div>

	</div><!-- .sellmo-dashboard-grid -->

	<!-- ── Recent Activity ─────────────────────────────────────────────────── -->
	<div class="sellmo-card">
		<h2><?php esc_html_e( 'Recent Activity', 'sellmo-woocommerce' ); ?></h2>

		<?php if ( empty( $log_items ) ) : ?>
			<div class="sellmo-card-body">
				<p><?php esc_html_e( 'No sync activity recorded yet.', 'sellmo-woocommerce' ); ?></p>
			</div>
		<?php else : ?>
			<table class="widefat striped sellmo-activity-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'sellmo-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Type', 'sellmo-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Direction', 'sellmo-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'sellmo-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Message', 'sellmo-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$direction_labels = [
						'sellmo_to_wc' => __( 'Sellmo → WC', 'sellmo-woocommerce' ),
						'wc_to_sellmo' => __( 'WC → Sellmo', 'sellmo-woocommerce' ),
					];
					foreach ( $log_items as $entry ) :
						$dir_key = (string) ( $entry['direction'] ?? '' );
						$dir_label = $direction_labels[ $dir_key ] ?? $dir_key;
						$status    = (string) ( $entry['status'] ?? '' );
					?>
					<tr>
						<td class="sellmo-log-time">
							<?php echo esc_html( sellmo_dashboard_format_dt( (string) ( $entry['created_at'] ?? '' ) ) ); ?>
						</td>
						<td>
							<span class="sellmo-type-badge">
								<?php echo esc_html( ucfirst( (string) ( $entry['sync_type'] ?? '' ) ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $dir_label ); ?></td>
						<td>
							<span class="sellmo-result-badge <?php echo esc_attr( $status ); ?>">
								<?php echo esc_html( ucfirst( $status ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div class="sellmo-card-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sellmo-sync-log' ) ); ?>">
					<?php esc_html_e( 'View full sync log →', 'sellmo-woocommerce' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>

</div><!-- .wrap -->
