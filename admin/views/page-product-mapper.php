<?php
declare(strict_types=1);

/**
 * Product Mapper page view for Sellmo POS Integration.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$mapper_stats = Sellmo_Product_Mapping::get_dashboard_stats();
?>
<div class="wrap sellmo-mapper-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Sellmo POS - Product Mapper', 'sellmo-woocommerce' ); ?></h1>
	<hr class="wp-header-end">

	<!-- <div class="sellmo-dashboard-grid sellmo-mapper-stats-grid">
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'Total Sellmo Products', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body"><strong class="sellmo-mapper-stat"><?php echo absint( $mapper_stats['total_sellmo_products'] ?? 0 ); ?></strong></div>
		</div>
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'Mapped Products', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body"><strong class="sellmo-mapper-stat"><?php echo absint( $mapper_stats['mapped_products'] ?? 0 ); ?></strong></div>
		</div>
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'Unmapped Products', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body"><strong class="sellmo-mapper-stat"><?php echo absint( $mapper_stats['unmapped_products'] ?? 0 ); ?></strong></div>
		</div>
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'WooCommerce Only Products', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body"><strong class="sellmo-mapper-stat"><?php echo absint( $mapper_stats['woocommerce_only_products'] ?? 0 ); ?></strong></div>
		</div>
	</div> -->

	<div class="sellmo-dashboard-grid sellmo-mapper-stats-grid">
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'Total Sellmo Products', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body"><strong class="sellmo-mapper-stat" id="stat-total-sellmo"><?php echo absint( $mapper_stats['total_sellmo_products'] ?? 0 ); ?></strong></div>
		</div>
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'Mapped Products', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body"><strong class="sellmo-mapper-stat" id="stat-mapped"><?php echo absint( $mapper_stats['mapped_products'] ?? 0 ); ?></strong></div>
		</div>
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'Unmapped Products', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body"><strong class="sellmo-mapper-stat" id="stat-unmapped"><?php echo absint( $mapper_stats['unmapped_products'] ?? 0 ); ?></strong></div>
		</div>
		<div class="sellmo-card">
			<h2><?php esc_html_e( 'WooCommerce Only Products', 'sellmo-woocommerce' ); ?></h2>
			<div class="sellmo-card-body"><strong class="sellmo-mapper-stat" id="stat-wc-only"><?php echo absint( $mapper_stats['woocommerce_only_products'] ?? 0 ); ?></strong></div>
		</div>
	</div>

	<div class="tablenav top">
		<div class="alignleft actions">
			<label for="sellmo-mapper-search" class="screen-reader-text"><?php esc_html_e( 'Search Sellmo products', 'sellmo-woocommerce' ); ?></label>
			<input type="search" id="sellmo-mapper-search" placeholder="<?php esc_attr_e( 'Search Sellmo product, code, category...', 'sellmo-woocommerce' ); ?>" />

			<label for="sellmo-mapper-status-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by mapping status', 'sellmo-woocommerce' ); ?></label>
			<select id="sellmo-mapper-status-filter">
				<option value=""><?php esc_html_e( 'All statuses', 'sellmo-woocommerce' ); ?></option>
				<option value="mapped"><?php esc_html_e( 'Mapped', 'sellmo-woocommerce' ); ?></option>
				<option value="not_mapped"><?php esc_html_e( 'Not Mapped', 'sellmo-woocommerce' ); ?></option>
			</select>

			<button type="button" id="sellmo-mapper-search-btn" class="button"><?php esc_html_e( 'Search', 'sellmo-woocommerce' ); ?></button>
			<button type="button" id="sellmo-mapper-refresh" class="button"><?php esc_html_e( 'Refresh', 'sellmo-woocommerce' ); ?></button>
		</div>
	</div>

	<div class="tablenav top">
		<div class="alignleft actions">
			<label for="sellmo-mapper-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Mapper bulk actions', 'sellmo-woocommerce' ); ?></label>
			<select id="sellmo-mapper-bulk-action">
				<option value=""><?php esc_html_e( 'Bulk Actions', 'sellmo-woocommerce' ); ?></option>
				<option value="auto_map_sku"><?php esc_html_e( 'Auto-map by matching SKU', 'sellmo-woocommerce' ); ?></option>
				<option value="unmap"><?php esc_html_e( 'Unmap selected', 'sellmo-woocommerce' ); ?></option>
			</select>
			<button type="button" id="sellmo-mapper-bulk-apply" class="button action"><?php esc_html_e( 'Apply', 'sellmo-woocommerce' ); ?></button>
			<span class="spinner sellmo-mapper-bulk-spinner" style="float:none;margin:0 4px;vertical-align:middle;"></span>
			<span id="sellmo-mapper-bulk-result" class="sellmo-bulk-result"></span>
		</div>
		<div class="alignright tablenav-pages" id="sellmo-mapper-top-pagination" style="display:none;">
			<span id="sellmo-mapper-count" class="displaying-num"></span>
			<span id="sellmo-mapper-pagination-top" class="pagination-links"></span>
		</div>
	</div>

	<div id="sellmo-mapper-loading" class="sellmo-products-loading">
		<span class="spinner is-active" style="float:none;margin:0;"></span>
		<span><?php esc_html_e( 'Loading Product Mapper data...', 'sellmo-woocommerce' ); ?></span>
	</div>

	<div id="sellmo-mapper-error" class="notice notice-error" style="display:none;">
		<p id="sellmo-mapper-error-text"></p>
	</div>

	<table id="sellmo-mapper-table" class="wp-list-table widefat fixed striped sellmo-mapper-table" style="display:none;">
		<thead>
			<tr>
				<td class="manage-column check-column"><input id="sellmo-mapper-select-all" type="checkbox"></td>
				<th class="manage-column column-sellmo-product" scope="col"><?php esc_html_e( 'Sellmo Product', 'sellmo-woocommerce' ); ?></th>
				<th class="manage-column column-sellmo-code" scope="col"><?php esc_html_e( 'Code', 'sellmo-woocommerce' ); ?></th>
				<th class="manage-column column-sellmo-category" scope="col"><?php esc_html_e( 'Category', 'sellmo-woocommerce' ); ?></th>
				<th class="manage-column column-sellmo-price" scope="col"><?php esc_html_e( 'Price', 'sellmo-woocommerce' ); ?></th>
				<th class="manage-column column-wc-product" scope="col"><?php esc_html_e( 'WooCommerce Product', 'sellmo-woocommerce' ); ?></th>
				<th class="manage-column column-sellmo-status" scope="col"><?php esc_html_e( 'Status', 'sellmo-woocommerce' ); ?></th>
				<th class="manage-column column-sellmo-actions" scope="col"><?php esc_html_e( 'Action', 'sellmo-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody id="sellmo-mapper-tbody"></tbody>
		<tfoot>
			<tr>
				<td class="manage-column check-column"><input type="checkbox" class="sellmo-mapper-select-all-foot"></td>
				<th colspan="7"></th>
			</tr>
		</tfoot>
	</table>

	<div id="sellmo-mapper-pagination" class="tablenav bottom" style="display:none;">
		<div class="tablenav-pages">
			<span id="sellmo-mapper-page-info" class="displaying-num"></span>
			<span id="sellmo-mapper-pagination-bottom" class="pagination-links"></span>
		</div>
	</div>
</div>
