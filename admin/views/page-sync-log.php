<?php
declare(strict_types=1);

/**
 * Sync Log page view for Sellmo POS Integration.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ── Query params ──────────────────────────────────────────────────────────────
$filter_type   = sanitize_key(        wp_unslash( $_GET['filter_type']   ?? '' ) );
$filter_status = sanitize_key(        wp_unslash( $_GET['filter_status'] ?? '' ) );
$filter_from   = sanitize_text_field( wp_unslash( $_GET['filter_from']   ?? '' ) );
$filter_to     = sanitize_text_field( wp_unslash( $_GET['filter_to']     ?? '' ) );
$paged         = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page      = 20;

// Validate YYYY-MM-DD.
$filter_from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_from ) ? $filter_from : '';
$filter_to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_to )   ? $filter_to   : '';

// Validate allowed type / status values.
$allowed_types    = [ 'product', 'stock', 'order', 'webhook' ];
$allowed_statuses = [ 'success', 'error', 'skipped' ];
$filter_type   = in_array( $filter_type,   $allowed_types,    true ) ? $filter_type   : '';
$filter_status = in_array( $filter_status, $allowed_statuses, true ) ? $filter_status : '';

// ── Fetch logs ────────────────────────────────────────────────────────────────
$result = Sellmo_Logger::get_logs( [
	'type'      => $filter_type,
	'status'    => $filter_status,
	'date_from' => $filter_from,
	'date_to'   => $filter_to,
	'per_page'  => $per_page,
	'paged'     => $paged,
] );

$logs        = $result['items'];
$total       = $result['total'];
$total_pages = max( 1, (int) ceil( $total / $per_page ) );

// ── Pagination URL builder ────────────────────────────────────────────────────
$page_base   = admin_url( 'admin.php?page=sellmo-sync-log' );
$filter_args = array_filter( [
	'filter_type'   => $filter_type,
	'filter_status' => $filter_status,
	'filter_from'   => $filter_from,
	'filter_to'     => $filter_to,
] );

$paged_url = static function ( int $n ) use ( $page_base, $filter_args ): string {
	$args = $filter_args;
	if ( $n > 1 ) {
		$args['paged'] = $n;
	}
	return add_query_arg( $args, $page_base );
};

// Showing X–Y of Z.
$from_row = $total > 0 ? ( $paged - 1 ) * $per_page + 1 : 0;
$to_row   = min( $total, $paged * $per_page );

// ── Label maps ────────────────────────────────────────────────────────────────
$type_labels = [
	'product' => __( 'Product', 'sellmo-woocommerce' ),
	'stock'   => __( 'Stock',   'sellmo-woocommerce' ),
	'order'   => __( 'Order',   'sellmo-woocommerce' ),
	'webhook' => __( 'Webhook', 'sellmo-woocommerce' ),
];
$direction_labels = [
	'sellmo_to_wc' => __( 'Sellmo → WC', 'sellmo-woocommerce' ),
	'wc_to_sellmo' => __( 'WC → Sellmo', 'sellmo-woocommerce' ),
];
$status_labels = [
	'success' => __( 'Success', 'sellmo-woocommerce' ),
	'error'   => __( 'Error',   'sellmo-woocommerce' ),
	'skipped' => __( 'Skipped', 'sellmo-woocommerce' ),
];

// ── Pagination HTML helper ────────────────────────────────────────────────────
$pagination_html = static function () use ( $paged, $total_pages, $paged_url ): string {
	if ( $total_pages <= 1 ) {
		return '';
	}

	$prev_disabled = 1 === $paged;
	$next_disabled = $paged >= $total_pages;
	$first = $prev_disabled
		? '<span class="first-page button disabled" aria-hidden="true">«</span>'
		: '<a class="first-page button" href="' . esc_url( $paged_url( 1 ) ) . '">«</a>';
	$prev = $prev_disabled
		? '<span class="prev-page button disabled" aria-hidden="true">‹</span>'
		: '<a class="prev-page button" href="' . esc_url( $paged_url( $paged - 1 ) ) . '">‹</a>';
	$next = $next_disabled
		? '<span class="next-page button disabled" aria-hidden="true">›</span>'
		: '<a class="next-page button" href="' . esc_url( $paged_url( $paged + 1 ) ) . '">›</a>';
	$last = $next_disabled
		? '<span class="last-page button disabled" aria-hidden="true">»</span>'
		: '<a class="last-page button" href="' . esc_url( $paged_url( $total_pages ) ) . '">»</a>';

	$current = '<span class="tablenav-paging-text">'
		. number_format_i18n( $paged ) . ' of <strong>' . number_format_i18n( $total_pages ) . '</strong>'
		. '</span>';

	return '<span class="pagination-links">'
		. $first . $prev
		. '<span class="paging-input">' . $current . '</span>'
		. $next . $last
		. '</span>';
};
?>
<div class="wrap sellmo-log-wrap">

	<h1><?php esc_html_e( 'Sellmo POS — Sync Log', 'sellmo-woocommerce' ); ?></h1>

	<?php /* ── Filter form ───────────────────────────────────────────────── */ ?>
	<div class="sellmo-card">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="sellmo-log-filters">
			<input type="hidden" name="page" value="sellmo-sync-log">

			<label for="sellmo-filter-type" class="screen-reader-text">
				<?php esc_html_e( 'Filter by type', 'sellmo-woocommerce' ); ?>
			</label>
			<select id="sellmo-filter-type" name="filter_type">
				<option value=""><?php esc_html_e( '— All types —', 'sellmo-woocommerce' ); ?></option>
				<?php foreach ( $type_labels as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_type, $val ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="sellmo-filter-status" class="screen-reader-text">
				<?php esc_html_e( 'Filter by status', 'sellmo-woocommerce' ); ?>
			</label>
			<select id="sellmo-filter-status" name="filter_status">
				<option value=""><?php esc_html_e( '— All statuses —', 'sellmo-woocommerce' ); ?></option>
				<?php foreach ( $status_labels as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_status, $val ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="sellmo-filter-from"><?php esc_html_e( 'From', 'sellmo-woocommerce' ); ?></label>
			<input
				type="date"
				id="sellmo-filter-from"
				name="filter_from"
				value="<?php echo esc_attr( $filter_from ); ?>"
			>

			<label for="sellmo-filter-to"><?php esc_html_e( 'to', 'sellmo-woocommerce' ); ?></label>
			<input
				type="date"
				id="sellmo-filter-to"
				name="filter_to"
				value="<?php echo esc_attr( $filter_to ); ?>"
			>

			<button type="submit" class="button action">
				<?php esc_html_e( 'Filter', 'sellmo-woocommerce' ); ?>
			</button>

			<?php if ( $filter_args ) : ?>
				<a href="<?php echo esc_url( $page_base ); ?>" class="sellmo-log-clear-filters">
					<?php esc_html_e( '× Clear filters', 'sellmo-woocommerce' ); ?>
				</a>
			<?php endif; ?>
		</form>
	</div>

	<?php /* ── Top tablenav ──────────────────────────────────────────────── */ ?>
	<div class="tablenav top">

		<div class="alignleft">
			<label class="sellmo-autoreload-label">
				<input type="checkbox" id="sellmo-autoreload">
				<?php esc_html_e( 'Auto-refresh every 30 s', 'sellmo-woocommerce' ); ?>
			</label>
		</div>

		<div class="alignright tablenav-pages" <?php echo 0 === $total ? 'style="display:none"' : ''; ?>>
			<?php if ( $total > 0 ) : ?>
				<span class="displaying-num">
					<?php
					printf(
						/* translators: 1: first row, 2: last row, 3: total */
						esc_html__( 'Showing %1$s–%2$s of %3$s', 'sellmo-woocommerce' ),
						esc_html( number_format_i18n( $from_row ) ),
						esc_html( number_format_i18n( $to_row ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
			<?php endif; ?>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper
			echo $pagination_html();
			?>
		</div>

	</div><!-- .tablenav.top -->

	<?php /* ── Log table ────────────────────────────────────────────────── */ ?>
	<table class="wp-list-table widefat fixed striped sellmo-log-table">
		<thead>
			<tr>
				<th class="manage-column column-date" scope="col">
					<?php esc_html_e( 'Date / Time', 'sellmo-woocommerce' ); ?>
				</th>
				<th class="manage-column column-type" scope="col">
					<?php esc_html_e( 'Type', 'sellmo-woocommerce' ); ?>
				</th>
				<th class="manage-column column-direction" scope="col">
					<?php esc_html_e( 'Direction', 'sellmo-woocommerce' ); ?>
				</th>
				<th class="manage-column column-status" scope="col">
					<?php esc_html_e( 'Status', 'sellmo-woocommerce' ); ?>
				</th>
				<th class="manage-column column-item" scope="col">
					<?php esc_html_e( 'Item', 'sellmo-woocommerce' ); ?>
				</th>
				<th class="manage-column column-message" scope="col">
					<?php esc_html_e( 'Message', 'sellmo-woocommerce' ); ?>
				</th>
			</tr>
		</thead>

		<tbody>
		<?php if ( empty( $logs ) ) : ?>
			<tr>
				<td colspan="6" class="sellmo-log-empty">
					<?php esc_html_e( 'No log entries found.', 'sellmo-woocommerce' ); ?>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $logs as $row ) :
				$row_type      = (string) ( $row['sync_type']  ?? '' );
				$row_direction = (string) ( $row['direction']  ?? '' );
				$row_status    = (string) ( $row['status']     ?? '' );
				$row_item      = (string) ( $row['item_id']    ?? '' );
				$row_message   = (string) ( $row['message']    ?? '' );
				$row_date      = (string) ( $row['created_at'] ?? '' );

				// Convert stored UTC datetime to site local time.
				$display_date = '' !== $row_date
					? esc_html( get_date_from_gmt( $row_date, 'd M Y H:i' ) )
					: '—';

				// Type badge.
				$type_label = esc_html( $type_labels[ $row_type ] ?? $row_type );

				// Direction.
				$dir_label = esc_html( $direction_labels[ $row_direction ] ?? str_replace( '_', ' ', $row_direction ) );

				// Status badge.
				$status_css   = in_array( $row_status, $allowed_statuses, true ) ? $row_status : '';
				$status_label = esc_html( $status_labels[ $row_status ] ?? $row_status );

				// Item: link to WC order edit when type = 'order' and item_id is numeric.
				if ( '' === $row_item ) {
					$item_html = '<span class="sellmo-log-empty-cell">—</span>';
				} elseif ( 'order' === $row_type && ctype_digit( $row_item ) ) {
					$item_html = '<a href="' . esc_url( admin_url( 'post.php?post=' . $row_item . '&action=edit' ) ) . '">#' . esc_html( $row_item ) . '</a>';
				} else {
					$item_html = '<code>' . esc_html( $row_item ) . '</code>';
				}

				// Message: 100-char truncation with full text in title tooltip.
				$short_msg = mb_strlen( $row_message ) > 100
					? mb_substr( $row_message, 0, 100 ) . '…'
					: $row_message;
				$is_truncated = $short_msg !== $row_message;
			?>
			<tr>
				<td class="column-date sellmo-log-time"><?php echo $display_date; // pre-escaped ?></td>
				<td class="column-type">
					<span class="sellmo-type-badge <?php echo esc_attr( $row_type ); ?>"><?php echo $type_label; ?></span>
				</td>
				<td class="column-direction"><?php echo $dir_label; ?></td>
				<td class="column-status">
					<span class="sellmo-result-badge <?php echo esc_attr( $status_css ); ?>"><?php echo $status_label; ?></span>
				</td>
				<td class="column-item"><?php echo $item_html; // pre-escaped ?></td>
				<td class="column-message">
					<?php if ( $is_truncated ) : ?>
						<span class="sellmo-log-msg-truncated" title="<?php echo esc_attr( $row_message ); ?>">
							<?php echo esc_html( $short_msg ); ?>
						</span>
					<?php else : ?>
						<?php echo esc_html( $row_message ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php /* ── Bottom tablenav ─────────────────────────────────────────── */ ?>
	<div class="tablenav bottom">

		<div class="alignleft actions sellmo-log-actions">

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="sellmo_wc_clear_logs">
				<?php wp_nonce_field( 'sellmo_wc_clear_logs', 'sellmo_wc_log_nonce' ); ?>
				<button
					type="submit"
					class="button sellmo-log-clear-btn"
					onclick="return confirm( <?php echo wp_json_encode( __( 'Delete all sync log entries? This cannot be undone.', 'sellmo-woocommerce' ) ); ?> );"
				>
					<?php esc_html_e( 'Clear All Logs', 'sellmo-woocommerce' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:6px;">
				<input type="hidden" name="action"        value="sellmo_wc_export_logs">
				<input type="hidden" name="filter_type"   value="<?php echo esc_attr( $filter_type ); ?>">
				<input type="hidden" name="filter_status" value="<?php echo esc_attr( $filter_status ); ?>">
				<input type="hidden" name="filter_from"   value="<?php echo esc_attr( $filter_from ); ?>">
				<input type="hidden" name="filter_to"     value="<?php echo esc_attr( $filter_to ); ?>">
				<?php wp_nonce_field( 'sellmo_wc_export_logs', 'sellmo_wc_export_nonce' ); ?>
				<button type="submit" class="button">
					<?php esc_html_e( 'Export as CSV', 'sellmo-woocommerce' ); ?>
				</button>
			</form>

		</div>

		<div class="alignright tablenav-pages" <?php echo $total_pages <= 1 ? 'style="display:none"' : ''; ?>>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper
			echo $pagination_html();
			?>
		</div>

	</div><!-- .tablenav.bottom -->

</div><!-- .wrap -->

<?php /* ── Auto-refresh ─────────────────────────────────────────────────── */ ?>
<script>
( function () {
	'use strict';
	var KEY  = 'sellmo_autoreload';
	var chk  = document.getElementById( 'sellmo-autoreload' );
	var tid  = null;

	if ( ! chk ) { return; }

	function arm() {
		if ( tid ) { return; }
		tid = setTimeout( function () { window.location.reload(); }, 30000 );
	}
	function disarm() {
		if ( tid ) { clearTimeout( tid ); tid = null; }
	}

	if ( localStorage.getItem( KEY ) === '1' ) {
		chk.checked = true;
		arm();
	}

	chk.addEventListener( 'change', function () {
		if ( chk.checked ) {
			localStorage.setItem( KEY, '1' );
			arm();
		} else {
			localStorage.setItem( KEY, '0' );
			disarm();
		}
	} );
}() );
</script>
