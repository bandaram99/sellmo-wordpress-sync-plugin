<?php
declare(strict_types=1);

/**
 * Products page view for Sellmo POS Integration.
 *
 * The products table is populated via AJAX on page load.
 * All table rows are built in JS from the sellmo_wc_get_products_list response.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$brand_name = POS_Brand::name();
?>
<div class="wrap sellmo-products-wrap">

	<h1 class="wp-heading-inline">
		<?php
		printf(
			esc_html__( '%s — Products', 'sellmo-woocommerce' ),
			esc_html( $brand_name )
		);
		?>
	</h1>
	<button type="button" id="sellmo-refresh-products" class="page-title-action">
		<?php
		printf(
			esc_html__( 'Refresh from %s', 'sellmo-woocommerce' ),
			esc_html( $brand_name )
		);
		?>
	</button>
	<hr class="wp-header-end">

	<!-- ── Top bar: category filter + bulk actions ──────────────────────────── -->
	<div class="tablenav top">
		<div class="alignleft actions">

			<label for="sellmo-category-filter" class="screen-reader-text">
				<?php esc_html_e( 'Filter by category', 'sellmo-woocommerce' ); ?>
			</label>
			<select id="sellmo-category-filter">
				<option value=""><?php esc_html_e( '— All categories —', 'sellmo-woocommerce' ); ?></option>
			</select>

			<label for="sellmo-bulk-action-select" class="screen-reader-text">
				<?php esc_html_e( 'Bulk actions', 'sellmo-woocommerce' ); ?>
			</label>
			<select id="sellmo-bulk-action-select">
				<option value="-1"><?php esc_html_e( 'Bulk Actions', 'sellmo-woocommerce' ); ?></option>
				<option value="enable_sync"><?php esc_html_e( 'Enable sync', 'sellmo-woocommerce' ); ?></option>
				<option value="disable_sync"><?php esc_html_e( 'Disable sync', 'sellmo-woocommerce' ); ?></option>
				<option value="import"><?php esc_html_e( 'Import to WooCommerce', 'sellmo-woocommerce' ); ?></option>
			</select>
			<button type="button" id="sellmo-bulk-apply" class="button action">
				<?php esc_html_e( 'Apply', 'sellmo-woocommerce' ); ?>
			</button>
			<span class="spinner sellmo-bulk-spinner" style="float:none;margin:0 4px;vertical-align:middle;"></span>
			<span id="sellmo-bulk-result" class="sellmo-bulk-result"></span>

		</div><!-- .alignleft -->

		<div class="alignright tablenav-pages" id="sellmo-top-pagination" style="display:none">
			<span id="sellmo-products-count" class="displaying-num"></span>
			<span id="sellmo-pagination-top" class="pagination-links"></span>
		</div>
	</div><!-- .tablenav.top -->

	<!-- ── Loading indicator ────────────────────────────────────────────────── -->
	<div id="sellmo-products-loading" class="sellmo-products-loading">
		<span class="spinner is-active" style="float:none;margin:0;"></span>
		<span>
			<?php
			printf(
				esc_html__( 'Loading products from %s…', 'sellmo-woocommerce' ),
				esc_html( $brand_name )
			);
			?>
		</span>
	</div>

	<!-- ── Error state ──────────────────────────────────────────────────────── -->
	<div id="sellmo-products-error" class="notice notice-error" style="display:none;">
		<p id="sellmo-products-error-text"></p>
	</div>

	<!-- ── Products table ───────────────────────────────────────────────────── -->
	<form id="sellmo-products-form">
		<table
			id="sellmo-products-table"
			class="wp-list-table widefat fixed striped sellmo-products-table"
			style="display:none"
		>
			<thead>
				<tr>
					<td class="manage-column check-column">
						<input id="sellmo-select-all" type="checkbox">
					</td>
					<th class="manage-column column-image" scope="col">
						<?php esc_html_e( 'Image', 'sellmo-woocommerce' ); ?>
					</th>
					<th class="manage-column column-name column-primary" scope="col">
						<?php esc_html_e( 'Product Name', 'sellmo-woocommerce' ); ?>
					</th>
					<th class="manage-column column-code" scope="col">
						<?php esc_html_e( 'Code', 'sellmo-woocommerce' ); ?>
					</th>
					<th class="manage-column column-category" scope="col">
						<?php esc_html_e( 'Category', 'sellmo-woocommerce' ); ?>
					</th>
					<th class="manage-column column-price" scope="col">
						<?php esc_html_e( 'Price', 'sellmo-woocommerce' ); ?>
					</th>
					<th class="manage-column column-variants" scope="col">
						<?php esc_html_e( 'Variants', 'sellmo-woocommerce' ); ?>
					</th>
					<th class="manage-column column-wc-status" scope="col">
						<?php esc_html_e( 'WC Status', 'sellmo-woocommerce' ); ?>
					</th>
					<th class="manage-column column-sync" scope="col">
						<?php esc_html_e( 'Sync', 'sellmo-woocommerce' ); ?>
					</th>
					<th class="manage-column column-actions" scope="col">
						<?php esc_html_e( 'Actions', 'sellmo-woocommerce' ); ?>
					</th>
				</tr>
			</thead>

			<tbody id="sellmo-products-tbody"></tbody>

			<tfoot>
				<tr>
					<td class="manage-column check-column">
						<input type="checkbox" class="sellmo-select-all-foot">
					</td>
					<th colspan="9"></th>
				</tr>
			</tfoot>
		</table>
	</form>

	<!-- ── Bottom pagination ────────────────────────────────────────────────── -->
	<div id="sellmo-products-pagination" class="tablenav bottom" style="display:none">
		<div class="tablenav-pages">
			<span id="sellmo-page-info" class="displaying-num"></span>
			<span id="sellmo-pagination-bottom" class="pagination-links"></span>
		</div>
	</div>

</div><!-- .wrap -->
