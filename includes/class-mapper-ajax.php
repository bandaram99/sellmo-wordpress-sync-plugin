<?php
declare(strict_types=1);

/**
 * AJAX handlers backing the Select2 (selectWoo) dropdowns in the Product Mapper.
 *
 * Two endpoints, both paginated and searchable, so large catalogues never have
 * to be rendered into a <select> on page load:
 *
 *   - sellmo_wc_search_wc_products     : search local WooCommerce products by title or SKU.
 *   - sellmo_wc_search_sellmo_products : search staged Sellmo products in the mapper table.
 *
 * Both return the Select2 paginated JSON shape:
 *   { results: [ { id, text } ], pagination: { more: bool } }
 *
 * @package Sellmo_WooCommerce
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Mapper_Ajax
 *
 * @since 1.1.0
 */
class Sellmo_Mapper_Ajax {

	/**
	 * Results returned per Select2 page.
	 */
	private const PER_PAGE = 20;

	/**
	 * Register AJAX actions and the Select2 script enqueue.
	 *
	 * Instantiate once during plugin bootstrap (e.g. in Sellmo_Plugin::init()).
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		add_action( 'wp_ajax_sellmo_wc_search_wc_products', [ $this, 'ajax_search_wc_products' ] );
		add_action( 'wp_ajax_sellmo_wc_search_sellmo_products', [ $this, 'ajax_search_sellmo_products' ] );
		add_action( 'wp_ajax_sellmo_wc_search_wc_variations', [ $this, 'ajax_search_wc_variations' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ], 25 );
	}

	/**
	 * Enqueue selectWoo (bundled with WooCommerce) and the mapper init script.
	 *
	 * selectWoo + the select2 stylesheet ship with WooCommerce Admin, so no
	 * third-party Select2 library is needed.
	 *
	 * @since  1.1.0
	 * @param  string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, POS_Brand::page( 'product-mapper' ) ) ) {
			return;
		}

		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_style( 'select2' );

		$select2_path = SELLMO_WC_PATH . 'admin/js/sellmo-mapper-select2.js';
		wp_enqueue_script(
			'sellmo-mapper-select2',
			SELLMO_WC_URL . 'admin/js/sellmo-mapper-select2.js',
			[ 'jquery', 'selectWoo' ],
			file_exists( $select2_path ) ? filemtime( $select2_path ) : SELLMO_WC_VERSION,
			true
		);

		wp_localize_script(
			'sellmo-mapper-select2',
			'sellmoMapperSelect2',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sellmo_wc_admin' ),
				'i18n'    => [
					'searchWc'          => __( 'Search WooCommerce product by name or SKU…', 'sellmo-woocommerce' ),
					'searchSellmo'      => __( 'Search Sellmo product by name or code…', 'sellmo-woocommerce' ),
					'searchWcVariation' => __( 'Search WooCommerce variation by SKU or name…', 'sellmo-woocommerce' ),
					'noResults'         => __( 'No matches found', 'sellmo-woocommerce' ),
				],
			]
		);
	}

	/**
	 * AJAX: search WooCommerce products by title or SKU (paginated).
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function ajax_search_wc_products(): void {
		$this->guard();

		$search = sanitize_text_field( wp_unslash( $_REQUEST['q'] ?? $_REQUEST['search'] ?? '' ) );
		$page   = max( 1, absint( $_REQUEST['page'] ?? 1 ) );

		// Exclude WooCommerce products that are already mapped to a Sellmo product.
		$mapped_ids = Sellmo_Product_Mapping::get_mapped_wc_product_ids();

		// SKU-first: an exact/partial SKU match should surface, plus title search.
		$args = [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'private', 'draft' ],
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		];

		if ( ! empty( $mapped_ids ) ) {
			$args['post__not_in'] = $mapped_ids;
		}

		if ( '' !== $search ) {
			// Search titles via 's', and SKUs via a meta query, combined with OR.
			$args['s'] = $search;
			add_filter( 'posts_search', [ $this, 'extend_search_to_sku' ], 10, 2 );
			$args['_sellmo_sku_term'] = $search; // Read back inside the filter.
		}

		$query = new WP_Query( $args );

		if ( '' !== $search ) {
			remove_filter( 'posts_search', [ $this, 'extend_search_to_sku' ], 10 );
		}

		$results = [];
		foreach ( (array) $query->posts as $product_id ) {
			$product = wc_get_product( (int) $product_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$sku = (string) $product->get_sku();
			$results[] = [
				'id'   => (int) $product_id,
				'text' => sprintf(
					'#%d - %s%s',
					(int) $product_id,
					$product->get_name(),
					'' !== $sku ? ' (' . $sku . ')' : ''
				),
			];
		}

		wp_send_json( [
			'results'    => $results,
			'pagination' => [ 'more' => ( $page * self::PER_PAGE ) < (int) $query->found_posts ],
		] );
	}

	/**
	 * AJAX: search staged Sellmo products in the mapper table (paginated).
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function ajax_search_sellmo_products(): void {
		$this->guard();

		$search = sanitize_text_field( wp_unslash( $_REQUEST['q'] ?? $_REQUEST['search'] ?? '' ) );
		$page   = max( 1, absint( $_REQUEST['page'] ?? 1 ) );

		$data    = Sellmo_Product_Mapping::search( $search, $page, self::PER_PAGE );
		$results = [];

		foreach ( $data['items'] as $row ) {
			$code = (string) ( $row['pos_product_code'] ?? '' );
			$name = (string) ( $row['pos_product_name'] ?? '' );

			$results[] = [
				'id'   => (string) ( $row['pos_product_id'] ?? '' ),
				'text' => $name . ( '' !== $code ? ' (' . $code . ')' : '' ),
				'code' => $code,
				'name' => $name,
			];
		}

		wp_send_json( [
			'results'    => $results,
			'pagination' => [ 'more' => ( $page * self::PER_PAGE ) < (int) $data['total'] ],
		] );
	}

	/**
	 * AJAX: search existing WooCommerce variations by SKU or parent title.
	 *
	 * Backs the per-variant "Map to existing" dropdown in the Product Mapper.
	 *
	 * @since  1.4.0
	 * @return void
	 */
	public function ajax_search_wc_variations(): void {
		$this->guard();

		global $wpdb;

		$search = sanitize_text_field( wp_unslash( $_REQUEST['q'] ?? $_REQUEST['search'] ?? '' ) );
		$page   = max( 1, absint( $_REQUEST['page'] ?? 1 ) );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		$where  = "p.post_type = 'product_variation' AND p.post_status IN ( 'publish', 'private' )";
		$params = [];

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( sku.meta_value LIKE %s OR parent.post_title LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		// One extra row drives the Select2 "more" pagination flag.
		$params[] = self::PER_PAGE + 1;
		$params[] = $offset;

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
			LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
			WHERE {$where}
			ORDER BY p.ID DESC
			LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) );

		$has_more = count( $ids ) > self::PER_PAGE;
		if ( $has_more ) {
			array_pop( $ids );
		}

		$results = [];
		foreach ( $ids as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$sku       = (string) $variation->get_sku();
			$results[] = [
				'id'   => (int) $variation_id,
				'text' => sprintf(
					'#%d - %s%s',
					(int) $variation_id,
					$variation->get_name(),
					'' !== $sku ? ' (' . $sku . ')' : ''
				),
			];
		}

		wp_send_json( [
			'results'    => $results,
			'pagination' => [ 'more' => $has_more ],
		] );
	}

	/**
	 * Broaden a product title search to also match the _sku meta value.
	 *
	 * Hooked transiently onto posts_search only for the WC product query.
	 *
	 * @since  1.1.0
	 * @param  string   $search   Existing search SQL.
	 * @param  WP_Query $wp_query Current query.
	 * @return string
	 */
	public function extend_search_to_sku( string $search, WP_Query $wp_query ): string {
		global $wpdb;

		$term = (string) $wp_query->get( '_sellmo_sku_term' );
		if ( '' === $term ) {
			return $search;
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		$sku_clause = $wpdb->prepare(
			" OR ( {$wpdb->posts}.ID IN (
				SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_sku' AND meta_value LIKE %s
			) ) ",
			$like
		);

		// Inject the SKU OR-clause inside the existing ( ... ) search group.
		if ( '' !== $search ) {
			// $search looks like " AND (((wp_posts.post_title LIKE ...))) ".
			return preg_replace( '/\)\s*\)\s*$/', $sku_clause . ') )', $search, 1 ) ?? $search;
		}

		return " AND ( 1=0 {$sku_clause} ) ";
	}

	/**
	 * Shared nonce + capability guard for both endpoints.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	private function guard(): void {
		check_ajax_referer( 'sellmo_wc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json( [ 'results' => [], 'pagination' => [ 'more' => false ] ] );
		}
	}
}
