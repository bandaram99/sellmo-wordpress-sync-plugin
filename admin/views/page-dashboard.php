<?php
declare(strict_types=1);

/**
 * Dashboard page view for Sellmo POS Integration.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$brand_name = POS_Brand::name();

// ── Connection status ─────────────────────────────────────────────────────────
$api_token    = (string) ( Sellmo_Settings::get( 'api_token' ) ?: '' );
$has_token    = '' !== $api_token;
$conn_status  = (array) ( Sellmo_Settings::get( 'connection_status' ) ?: [] );
$is_connected = $has_token && ! empty( $conn_status['success'] );

// ── Sync metadata ─────────────────────────────────────────────────────────────
$product_meta      = (array) ( Sellmo_Settings::get( 'last_product_sync' ) ?: [] );
$stock_meta        = (array) ( Sellmo_Settings::get( 'last_stock_sync' ) ?: [] );
$order_meta        = (array) ( Sellmo_Settings::get( 'last_order_sync' ) ?: [] );
$order_status_meta = (array) ( Sellmo_Settings::get( 'last_order_status_sync' ) ?: [] );
$mapper_stats      = Sellmo_Product_Mapping::get_dashboard_stats();

// ── Feature toggles ───────────────────────────────────────────────────────────
$product_enabled = 'yes' === (string) Sellmo_Settings::get( 'product_sync_enabled' );
$stock_enabled   = 'yes' === (string) Sellmo_Settings::get( 'stock_sync_enabled' );
$order_enabled   = 'yes' === (string) Sellmo_Settings::get( 'order_sync_enabled' );

// ── Next scheduled stock sync ─────────────────────────────────────────────────
$next_stock        = wp_next_scheduled( POS_Brand::cron_hook( 'stock_sync' ) );
$next_order_status = wp_next_scheduled( Sellmo_Scheduler::order_hook() );

// ── Recent activity ───────────────────────────────────────────────────────────
$log_data  = Sellmo_Logger::get_logs( [ 'per_page' => 10, 'paged' => 1 ] );
$log_items = $log_data['items'];

/**
 * Format a UTC Unix timestamp for display in the configured time zone.
 *
 * Every date on this page is rendered in the time zone chosen in Settings
 * (falling back to the WordPress one), so the Dashboard reads in the same
 * clock the shop is operated in.
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
		return (string) wp_date( $fmt, $timestamp, Sellmo_Settings::display_timezone() );
	}
}

/**
 * Format a UTC MySQL DATETIME string for display in the configured time zone.
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
		return (string) wp_date( $fmt, $ts, Sellmo_Settings::display_timezone() );
	}
}
?>
<div class="wrap sellmo-dashboard-wrap">
	<h1>
		<?php
		printf(
			esc_html__( '%s — Dashboard', 'sellmo-woocommerce' ),
			esc_html( $brand_name )
		);
		?>
	</h1>

	<!-- ── Connection Status Card ──────────────────────────────────────────── -->
	<div class="sellmo-card">
		<h2><?php esc_html_e( 'Connection Status', 'sellmo-woocommerce' ); ?></h2>
		<div class="sellmo-card-body sellmo-connection-row">
			<span class="sellmo-connection-badge <?php echo esc_attr( $is_connected ? 'connected' : 'disconnected' ); ?>">
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
							esc_url( admin_url( 'admin.php?page=' . POS_Brand::page( 'settings' ) ) )
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
							esc_url( admin_url( 'admin.php?page=' . POS_Brand::page( 'settings' ) ) )
						),
						[ 'a' => [ 'href' => [] ] ]
					);
					?>
				<?php else : ?>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: settings page URL */
							__( 'Connected to %1$s. <a href="%2$s">Manage settings</a>.', 'sellmo-woocommerce' ),
							esc_html( $brand_name ),
							esc_url( admin_url( 'admin.php?page=' . POS_Brand::page( 'settings' ) ) )
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

		<!-- Product Mapping Stats -->
		<div class="sellmo-card sellmo-sync-card">
			<h2><?php esc_html_e( 'Product Mapping', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body">
				<table class="sellmo-meta-table">
					<tr>
						<th>
							<?php
							printf(
								esc_html__( 'Total %s', 'sellmo-woocommerce' ),
								esc_html( $brand_name )
							);
							?>
						</th>
						<td><?php echo absint( $mapper_stats['total_sellmo_products'] ?? 0 ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Mapped', 'sellmo-woocommerce' ); ?></th>
						<td>
							<span class="sellmo-result-badge success">
								<?php echo absint( $mapper_stats['mapped_products'] ?? 0 ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Unmapped', 'sellmo-woocommerce' ); ?></th>
						<td>
							<span class="sellmo-result-badge skipped">
								<?php echo absint( $mapper_stats['unmapped_products'] ?? 0 ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'WooCommerce Only', 'sellmo-woocommerce' ); ?></th>
						<td>
							<span class="sellmo-toggle-badge disabled">
								<?php echo absint( $mapper_stats['woocommerce_only_products'] ?? 0 ); ?>
							</span>
						</td>
					</tr>
				</table>
				<div class="sellmo-sync-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . POS_Brand::page( 'product-mapper' ) ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Open Product Mapper', 'sellmo-woocommerce' ); ?>
					</a>
				</div>
			</div>
		</div>

		<!-- Product Sync -->
		<div class="sellmo-card sellmo-sync-card">
			<h2><?php esc_html_e( 'Product Sync', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body">
				<table class="sellmo-meta-table">
					<tr>
						<th><?php esc_html_e( 'Status', 'sellmo-woocommerce' ); ?></th>
						<td>
							<span class="sellmo-toggle-badge <?php echo esc_attr( $product_enabled ? 'enabled' : 'disabled' ); ?>">
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
							<?php
							$p_status = (string) ( $product_meta['status'] ?? '' );
							$p_count  = absint( $product_meta['count'] ?? 0 );

							if ( 'error' === $p_status ) : ?>
								<span class="sellmo-result-badge error">
									<?php printf( esc_html__( 'Error — %d synced before failure', 'sellmo-woocommerce' ), $p_count ); ?>
								</span>
							<?php else : ?>
								<span class="sellmo-result-badge success">
									<?php printf( esc_html__( 'Success (%d synced)', 'sellmo-woocommerce' ), $p_count ); ?>
								</span>
							<?php endif;
							?>
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
							<span class="sellmo-toggle-badge <?php echo esc_attr( $stock_enabled ? 'enabled' : 'disabled' ); ?>">
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
							<span class="sellmo-toggle-badge <?php echo esc_attr( $order_enabled ? 'enabled' : 'disabled' ); ?>">
								<?php echo $order_enabled
									? esc_html__( 'Enabled', 'sellmo-woocommerce' )
									: esc_html__( 'Disabled', 'sellmo-woocommerce' );
								?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Direction', 'sellmo-woocommerce' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: %s: Brand display name. */
								esc_html__( 'WooCommerce ↔ %s (orders out, status back)', 'sellmo-woocommerce' ),
								esc_html( $brand_name )
							);
							?>
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
					<tr>
						<th><?php esc_html_e( 'Last status pull', 'sellmo-woocommerce' ); ?></th>
						<td><?php echo esc_html( sellmo_dashboard_format_ts( (int) ( $order_status_meta['time'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Next scheduled', 'sellmo-woocommerce' ); ?></th>
						<td>
							<?php
							echo esc_html(
								$next_order_status
									? sellmo_dashboard_format_ts( (int) $next_order_status )
									: __( 'Not scheduled', 'sellmo-woocommerce' )
							);
							?>
						</td>
					</tr>
					<?php if ( ! empty( $order_status_meta['status'] ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Last result', 'sellmo-woocommerce' ); ?></th>
						<td>
							<?php
							$o_status = (string) $order_status_meta['status'];
							$o_count  = absint( $order_status_meta['count'] ?? 0 );

							if ( 'error' === $o_status ) : ?>
								<span class="sellmo-result-badge error">
									<?php esc_html_e( 'Error — see the sync log', 'sellmo-woocommerce' ); ?>
								</span>
							<?php else : ?>
								<span class="sellmo-result-badge success">
									<?php printf( esc_html__( 'Success (%d updated)', 'sellmo-woocommerce' ), $o_count ); ?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>
				</table>
				<div class="sellmo-sync-actions">
					<button type="button" id="sellmo-sync-order-status" class="button button-secondary">
						<?php esc_html_e( 'Sync Order Status Now', 'sellmo-woocommerce' ); ?>
					</button>
					<span class="sellmo-sync-spinner spinner" style="float:none;margin:0 4px 0 0;"></span>
					<span class="sellmo-sync-result" id="sellmo-sync-order-status-result"></span>
				</div>
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
						'pos_to_wc' => sprintf(
							/* translators: %s: Brand display name. */
							__( '%s → WC', 'sellmo-woocommerce' ),
							$brand_name
						),
						'wc_to_pos' => sprintf(
							/* translators: %s: Brand display name. */
							__( 'WC → %s', 'sellmo-woocommerce' ),
							$brand_name
						),
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
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . POS_Brand::page( 'sync-log' ) ) ); ?>">
					<?php esc_html_e( 'View full sync log →', 'sellmo-woocommerce' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>

</div><!-- .wrap -->
