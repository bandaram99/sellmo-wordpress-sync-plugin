<?php
declare(strict_types=1);

/**
 * Log page view for Sellmo POS Integration.
 *
 * Three tabs: Product logs | Stock logs | Errors (all types).
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$brand_name = POS_Brand::name();

// ── Active tab ────────────────────────────────────────────────────────────────
$active_tab   = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
$allowed_tabs = [ '', 'product', 'stock', 'errors' ];
$active_tab   = in_array( $active_tab, $allowed_tabs, true ) ? $active_tab : '';

// ── Per-tab filter: errors tab forces status=error; others filter by type ─────
$query_type   = '';
$query_status = '';

if ( 'product' === $active_tab ) {
	$query_type = 'product';
} elseif ( 'stock' === $active_tab ) {
	$query_type = 'stock';
} elseif ( 'errors' === $active_tab ) {
	$query_status = 'error';
}

// ── Date range filter (shared across all tabs) ────────────────────────────────
$filter_from = sanitize_text_field( wp_unslash( $_GET['filter_from'] ?? '' ) );
$filter_to   = sanitize_text_field( wp_unslash( $_GET['filter_to']   ?? '' ) );
$filter_from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_from ) ? $filter_from : '';
$filter_to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_to )   ? $filter_to   : '';

$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page = 25;

// ── Fetch logs ────────────────────────────────────────────────────────────────
$result = Sellmo_Logger::get_logs( [
	'type'      => $query_type,
	'status'    => $query_status,
	'date_from' => $filter_from,
	'date_to'   => $filter_to,
	'per_page'  => $per_page,
	'paged'     => $paged,
] );

$logs        = $result['items'];
$total       = $result['total'];
$total_pages = max( 1, (int) ceil( $total / $per_page ) );
$from_row    = $total > 0 ? ( $paged - 1 ) * $per_page + 1 : 0;
$to_row      = min( $total, $paged * $per_page );

// ── URL helpers ───────────────────────────────────────────────────────────────
$page_base = admin_url( 'admin.php?page=' . POS_Brand::page( 'sync-log' ) );

$tab_url = static function ( string $tab ) use ( $page_base, $filter_from, $filter_to ): string {
	$args = array_filter( [
		'tab'         => $tab,
		'filter_from' => $filter_from,
		'filter_to'   => $filter_to,
	] );
	return add_query_arg( $args, $page_base );
};

$filter_args = array_filter( [
	'tab'         => $active_tab,
	'filter_from' => $filter_from,
	'filter_to'   => $filter_to,
] );

$paged_url = static function ( int $n ) use ( $page_base, $filter_args ): string {
	$args = $filter_args;
	if ( $n > 1 ) {
		$args['paged'] = $n;
	}
	return add_query_arg( $args, $page_base );
};

// ── Label maps ────────────────────────────────────────────────────────────────
$type_labels = [
	'product' => __( 'Product', 'sellmo-woocommerce' ),
	'stock'   => __( 'Stock',   'sellmo-woocommerce' ),
	'order'   => __( 'Order',   'sellmo-woocommerce' ),
	'webhook' => __( 'Webhook', 'sellmo-woocommerce' ),
];
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
$status_labels = [
	'success' => __( 'Success', 'sellmo-woocommerce' ),
	'error'   => __( 'Error',   'sellmo-woocommerce' ),
	'skipped' => __( 'Skipped', 'sellmo-woocommerce' ),
];

// ── Pagination HTML ───────────────────────────────────────────────────────────
$pagination_html = static function () use ( $paged, $total_pages, $paged_url ): string {
	if ( $total_pages <= 1 ) {
		return '';
	}
	$pd = $paged;
	$tp = $total_pages;

	$prev_off = 1 === $pd;
	$next_off = $pd >= $tp;

	$f = $prev_off ? '<span class="first-page button disabled">«</span>'
	              : '<a class="first-page button" href="' . esc_url( $paged_url( 1 ) ) . '">«</a>';
	$p = $prev_off ? '<span class="prev-page button disabled">‹</span>'
	              : '<a class="prev-page button" href="' . esc_url( $paged_url( $pd - 1 ) ) . '">‹</a>';
	$n = $next_off ? '<span class="next-page button disabled">›</span>'
	              : '<a class="next-page button" href="' . esc_url( $paged_url( $pd + 1 ) ) . '">›</a>';
	$l = $next_off ? '<span class="last-page button disabled">»</span>'
	              : '<a class="last-page button" href="' . esc_url( $paged_url( $tp ) ) . '">»</a>';

	return '<span class="pagination-links">'
		. $f . $p
		. '<span class="paging-input"><span class="tablenav-paging-text">'
		. number_format_i18n( $pd ) . ' of <strong>' . number_format_i18n( $tp ) . '</strong>'
		. '</span></span>'
		. $n . $l
		. '</span>';
};
?>
<div class="wrap sellmo-log-wrap">

	<h1>
		<?php
		printf(
			esc_html__( '%s — Log', 'sellmo-woocommerce' ),
			esc_html( $brand_name )
		);
		?>
	</h1>

	<?php /* ── Tabs ───────────────────────────────────────────────────────── */ ?>
	<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:0;">
		<a href="<?php echo esc_url( $tab_url( '' ) ); ?>"
		   class="nav-tab <?php echo '' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'All Logs', 'sellmo-woocommerce' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_url( 'product' ) ); ?>"
		   class="nav-tab <?php echo 'product' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Product', 'sellmo-woocommerce' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_url( 'stock' ) ); ?>"
		   class="nav-tab <?php echo 'stock' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Stock', 'sellmo-woocommerce' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_url( 'errors' ) ); ?>"
		   class="nav-tab <?php echo 'errors' === $active_tab ? 'nav-tab-active' : ''; ?>"
		   style="color:#d63638;">
			<?php esc_html_e( 'Errors', 'sellmo-woocommerce' ); ?>
		</a>
	</nav>

	<?php /* ── Date range filter ────────────────────────────────────────── */ ?>
	<div class="sellmo-card" style="border-top-left-radius:0;margin-top:0;">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="sellmo-log-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( POS_Brand::page( 'sync-log' ) ); ?>">
			<?php if ( $active_tab ) : ?>
				<input type="hidden" name="tab" value="<?php echo esc_attr( $active_tab ); ?>">
			<?php endif; ?>

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

			<?php if ( $filter_from || $filter_to ) : ?>
				<a href="<?php echo esc_url( $tab_url( $active_tab ) ); ?>" class="sellmo-log-clear-filters">
					<?php esc_html_e( '× Clear', 'sellmo-woocommerce' ); ?>
				</a>
			<?php endif; ?>

			<label class="sellmo-autoreload-label" style="margin-left:12px;">
				<input type="checkbox" id="sellmo-autoreload">
				<?php esc_html_e( 'Auto-refresh every 30 s', 'sellmo-woocommerce' ); ?>
			</label>
		</form>
	</div>

	<?php /* ── Top tablenav ──────────────────────────────────────────────── */ ?>
	<div class="tablenav top">
		<div class="tablenav-pages" style="float:right;" <?php echo 0 === $total ? 'style="display:none"' : ''; ?>>
			<?php if ( $total > 0 ) : ?>
				<span class="displaying-num">
					<?php
					printf(
						/* translators: 1: first, 2: last, 3: total */
						esc_html__( 'Showing %1$s–%2$s of %3$s', 'sellmo-woocommerce' ),
						esc_html( number_format_i18n( $from_row ) ),
						esc_html( number_format_i18n( $to_row ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
			<?php endif; ?>
			<?php
			echo wp_kses_post( $pagination_html() );
			?>
		</div>
	</div>

	<?php /* ── Log table ────────────────────────────────────────────────── */ ?>
	<table class="wp-list-table widefat fixed striped sellmo-log-table">
		<thead>
			<tr>
				<th class="manage-column column-date" scope="col">
					<?php esc_html_e( 'Date / Time', 'sellmo-woocommerce' ); ?>
				</th>
				<?php if ( '' === $active_tab || 'errors' === $active_tab ) : ?>
				<th class="manage-column column-type" scope="col">
					<?php esc_html_e( 'Type', 'sellmo-woocommerce' ); ?>
				</th>
				<?php endif; ?>
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
				<td colspan="<?php echo ( '' === $active_tab || 'errors' === $active_tab ) ? 5 : 4; ?>" class="sellmo-log-empty">
					<?php esc_html_e( 'No log entries found.', 'sellmo-woocommerce' ); ?>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $logs as $row ) :
				$row_type      = (string) ( $row['sync_type']  ?? '' );
				$row_status    = (string) ( $row['status']     ?? '' );
				$row_item      = (string) ( $row['item_id']    ?? '' );
				$row_message   = (string) ( $row['message']    ?? '' );
				$row_date      = (string) ( $row['created_at'] ?? '' );

				$display_date = '' !== $row_date
					? esc_html( get_date_from_gmt( $row_date, 'd M Y H:i:s' ) )
					: '—';

				$type_label   = esc_html( $type_labels[ $row_type ] ?? $row_type );
				$status_css   = in_array( $row_status, [ 'success', 'error', 'skipped' ], true ) ? $row_status : '';
				$status_label = esc_html( $status_labels[ $row_status ] ?? $row_status );

				if ( '' === $row_item ) {
					$item_html = '<span class="sellmo-log-empty-cell">—</span>';
				} elseif ( 'order' === $row_type && ctype_digit( $row_item ) ) {
					$item_html = '<a href="' . esc_url( admin_url( 'post.php?post=' . $row_item . '&action=edit' ) ) . '">#' . esc_html( $row_item ) . '</a>';
				} else {
					$item_html = '<code>' . esc_html( $row_item ) . '</code>';
				}

				// Errors tab: show full message. Other tabs: 120-char truncation.
				if ( 'errors' === $active_tab || 'error' === $row_status ) {
					$msg_html = '<span class="sellmo-log-error-msg">' . esc_html( $row_message ) . '</span>';
				} else {
					$short = mb_strlen( $row_message ) > 120
						? mb_substr( $row_message, 0, 120 ) . '…'
						: $row_message;
					$msg_html = $short !== $row_message
						? '<span class="sellmo-log-msg-truncated" title="' . esc_attr( $row_message ) . '">' . esc_html( $short ) . '</span>'
						: esc_html( $row_message );
				}

				// Highlight error rows. A CSS class rather than an inline style, so the
				// value can be escaped as an attribute instead of echoed as raw markup.
				$row_class = 'error' === $row_status ? 'sellmo-log-row-error' : '';
			?>
			<tr class="<?php echo esc_attr( $row_class ); ?>">
				<td class="column-date sellmo-log-time"><?php echo wp_kses_post( $display_date ); ?></td>
				<?php if ( '' === $active_tab || 'errors' === $active_tab ) : ?>
				<td class="column-type">
					<span class="sellmo-type-badge <?php echo esc_attr( $row_type ); ?>"><?php echo wp_kses_post( $type_label ); ?></span>
				</td>
				<?php endif; ?>
				<td class="column-status">
					<span class="sellmo-result-badge <?php echo esc_attr( $status_css ); ?>"><?php echo wp_kses_post( $status_label ); ?></span>
				</td>
				<td class="column-item"><?php echo wp_kses_post( $item_html ); ?></td>
				<td class="column-message"><?php echo wp_kses_post( $msg_html ); ?></td>
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
					onclick="return confirm( <?php echo wp_json_encode( __( 'Delete all log entries? This cannot be undone.', 'sellmo-woocommerce' ) ); ?> );"
				>
					<?php esc_html_e( 'Clear All Logs', 'sellmo-woocommerce' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:6px;">
				<input type="hidden" name="action"        value="sellmo_wc_export_logs">
				<input type="hidden" name="filter_type"   value="<?php echo esc_attr( $query_type ); ?>">
				<input type="hidden" name="filter_status" value="<?php echo esc_attr( $query_status ); ?>">
				<input type="hidden" name="filter_from"   value="<?php echo esc_attr( $filter_from ); ?>">
				<input type="hidden" name="filter_to"     value="<?php echo esc_attr( $filter_to ); ?>">
				<?php wp_nonce_field( 'sellmo_wc_export_logs', 'sellmo_wc_export_nonce' ); ?>
				<button type="submit" class="button">
					<?php esc_html_e( 'Export as CSV', 'sellmo-woocommerce' ); ?>
				</button>
			</form>

		</div>

		<div class="alignright tablenav-pages<?php echo esc_attr( $total_pages <= 1 ? ' sellmo-hidden' : '' ); ?>">
			<?php
			echo wp_kses_post( $pagination_html() );
			?>
		</div>

	</div>

</div><!-- .wrap -->

<?php /* ── Error message expanded display ───────────────────────────────── */ ?>
<style>
.sellmo-log-error-msg {
	display: block;
	font-family: monospace;
	font-size: 12px;
	color: #991b1b;
	white-space: pre-wrap;
	word-break: break-all;
	background: #fff5f5;
	padding: 4px 6px;
	border-radius: 3px;
	border-left: 3px solid #d63638;
}
.nav-tab-wrapper .nav-tab:last-child {
	border-right: 1px solid #c3c4c7;
}
</style>

<?php /* ── Auto-refresh ─────────────────────────────────────────────────── */ ?>
<script>
( function () {
	'use strict';
	var KEY = 'sellmo_autoreload';
	var chk = document.getElementById( 'sellmo-autoreload' );
	var tid = null;
	if ( ! chk ) { return; }
	function arm() {
		if ( tid ) { return; }
		tid = setTimeout( function () { window.location.reload(); }, 30000 );
	}
	function disarm() {
		if ( tid ) { clearTimeout( tid ); tid = null; }
	}
	if ( localStorage.getItem( KEY ) === '1' ) { chk.checked = true; arm(); }
	chk.addEventListener( 'change', function () {
		if ( chk.checked ) { localStorage.setItem( KEY, '1' ); arm(); }
		else               { localStorage.setItem( KEY, '0' ); disarm(); }
	} );
}() );
</script>
