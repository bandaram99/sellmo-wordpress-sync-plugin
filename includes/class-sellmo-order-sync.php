<?php
declare(strict_types=1);

/**
 * Order sync handler for Sellmo POS Integration.
 *
 * Pushes WooCommerce orders to the Sellmo API when order status changes to
 * 'processing'. Provides a manual re-sync meta box on the WC order edit
 * screen and an AJAX handler for per-order nonce-protected re-syncs.
 *
 * @package Sellmo_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sellmo_Order_Sync
 *
 * All hooks are registered in __construct(). Payload construction is isolated
 * in build_payload() so it can be unit-tested independently of the API call.
 *
 * @since 1.0.0
 */
class Sellmo_Order_Sync {

	/**
	 * Constructor.
	 *
	 * Registers the automatic status-change hook, meta box hooks, and the
	 * AJAX handler for manual per-order re-sync from the order edit screen.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Auto-sync when order moves to processing.
		add_action( 'woocommerce_order_status_processing', [ $this, 'sync_order' ], 10, 2 );

		// Meta box on the WC order edit screen (classic + HPOS).
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );

		// Enqueue the inline meta-box JS on order edit pages.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_order_scripts' ] );

		// AJAX handler for the manual re-sync button in the meta box.
		add_action( 'wp_ajax_sellmo_wc_manual_order_sync', [ $this, 'ajax_manual_order_sync' ] );
	}

	// -------------------------------------------------------------------------
	// Public hooks
	// -------------------------------------------------------------------------

	/**
	 * Auto-sync callback fired by woocommerce_order_status_processing.
	 *
	 * Skips silently when order sync is disabled or the order has already been
	 * pushed to Sellmo (duplicate protection via _sellmo_order_id meta).
	 *
	 * @since  1.0.0
	 * @param  int      $order_id WooCommerce order ID.
	 * @param  WC_Order $order    WooCommerce order object.
	 * @return void
	 */
	public function sync_order( int $order_id, WC_Order $order ): void {
		if ( 'yes' !== (string) Sellmo_Settings::get( 'order_sync_enabled' ) ) {
			return;
		}

		// Duplicate guard: skip if we already have a Sellmo order ID.
		if ( '' !== (string) $order->get_meta( '_sellmo_order_id' ) ) {
			return;
		}

		$this->push_order( $order );
	}

	/**
	 * Register the Sellmo sync meta box on the WC order edit screen.
	 *
	 * Supports both classic (shop_order) and HPOS order screens.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_meta_box(): void {
		if ( 'yes' !== (string) Sellmo_Settings::get( 'order_sync_enabled' ) ) {
			return;
		}

		$screens = [ 'shop_order' ];

		// Add the HPOS screen ID when it exists (WC 7.1+).
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$hpos = wc_get_page_screen_id( 'shop-order' );
			if ( $hpos && 'shop_order' !== $hpos ) {
				$screens[] = $hpos;
			}
		}

		foreach ( $screens as $screen ) {
			add_meta_box(
				'sellmo-order-sync',
				__( 'Sellmo POS', 'sellmo-woocommerce' ),
				[ $this, 'render_meta_box' ],
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the Sellmo sync meta box HTML.
	 *
	 * @since  1.0.0
	 * @param  WP_Post|WC_Order $post_or_order Post object (classic) or WC_Order (HPOS).
	 * @return void
	 */
	public function render_meta_box( $post_or_order ): void {
		// Resolve to a WC_Order for both classic CPT and HPOS screens.
		if ( $post_or_order instanceof WC_Order ) {
			$order = $post_or_order;
		} else {
			$order = wc_get_order( $post_or_order->ID );
		}

		if ( ! $order ) {
			return;
		}

		$order_id       = $order->get_id();
		$sellmo_id      = (string) $order->get_meta( '_sellmo_order_id' );
		$is_synced      = '' !== $sellmo_id;
		$sync_error     = (string) $order->get_meta( '_sellmo_sync_error' );
		$nonce          = wp_create_nonce( 'sellmo_wc_order_sync_' . $order_id );
		$button_label   = $is_synced
			? __( 'Re-sync to Sellmo', 'sellmo-woocommerce' )
			: __( 'Sync to Sellmo', 'sellmo-woocommerce' );
		?>
		<div class="sellmo-order-meta-box" id="sellmo-order-meta-<?php echo esc_attr( (string) $order_id ); ?>">
			<?php if ( $is_synced ) : ?>
				<p class="sellmo-sync-status synced">
					<strong><?php esc_html_e( 'Synced', 'sellmo-woocommerce' ); ?></strong><br>
					<span class="description"><?php echo esc_html( __( 'Sellmo ID:', 'sellmo-woocommerce' ) . ' ' . $sellmo_id ); ?></span>
				</p>
			<?php elseif ( '' !== $sync_error ) : ?>
				<p class="sellmo-sync-status error">
					<strong><?php esc_html_e( 'Sync failed', 'sellmo-woocommerce' ); ?></strong><br>
					<span class="description"><?php echo esc_html( $sync_error ); ?></span>
				</p>
			<?php else : ?>
				<p class="sellmo-sync-status not-synced">
					<?php esc_html_e( 'Not synced to Sellmo.', 'sellmo-woocommerce' ); ?>
				</p>
			<?php endif; ?>

			<button type="button"
				class="button sellmo-order-sync-btn"
				data-order-id="<?php echo esc_attr( (string) $order_id ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php echo esc_html( $button_label ); ?>
			</button>
			<span class="sellmo-sync-spinner spinner" style="float:none;margin:4px 0 0 4px;"></span>
			<p class="sellmo-order-sync-result" style="margin-top:6px;"></p>
		</div>
		<?php
	}

	/**
	 * Enqueue the inline meta-box JS on WC order edit pages.
	 *
	 * Attaches to the existing sellmo-admin CSS handle (already enqueued on
	 * Sellmo admin pages) and adds a small inline script bound to jQuery so
	 * we do not need a separate JS file.
	 *
	 * @since  1.0.0
	 * @param  string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_order_scripts( string $hook ): void {
		$order_screens = [ 'post.php', 'post-new.php' ];

		// HPOS uses a different hook suffix — check via screen object after load.
		$is_order_edit = in_array( $hook, $order_screens, true );

		if ( ! $is_order_edit ) {
			// Check HPOS screen.
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen ) {
				return;
			}
			$hpos_id = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : '';
			if ( $screen->id !== $hpos_id && 'shop_order' !== $screen->id ) {
				return;
			}
		} else {
			global $post;
			// On classic screens, only load on shop_order post type.
			if ( ! isset( $post ) || 'shop_order' !== $post->post_type ) {
				return;
			}
		}

		// Ensure the admin CSS is loaded on order edit pages.
		if ( ! wp_style_is( 'sellmo-admin', 'enqueued' ) ) {
			wp_enqueue_style(
				'sellmo-admin',
				SELLMO_WC_URL . 'admin/css/sellmo-admin.css',
				[],
				SELLMO_WC_VERSION
			);
		}

		wp_add_inline_script( 'jquery', $this->build_order_sync_js() );
	}

	/**
	 * AJAX handler for the meta-box "Sync to Sellmo" / "Re-sync" button.
	 *
	 * Uses a per-order nonce so each re-sync button is scoped to its order.
	 * Bypasses the duplicate-ID guard so admins can always force a re-push.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_manual_order_sync(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 );

		if ( 0 === $order_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid order ID.', 'sellmo-woocommerce' ) ] );
		}

		check_ajax_referer( 'sellmo_wc_order_sync_' . $order_id, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sellmo-woocommerce' ) ] );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found.', 'sellmo-woocommerce' ) ] );
		}

		// Clear any stale error from a previous attempt before pushing.
		// Both calls ensure deletion works regardless of HPOS configuration.
		delete_post_meta( $order_id, '_sellmo_sync_error' );
		$order->delete_meta_data( '_sellmo_sync_error' );
		$order->save();

		// Manual re-sync always pushes, even if _sellmo_order_id is already set.
		$result = $this->push_order( $order, true );

		if ( $result['success'] ) {
			wp_send_json_success( [
				'message'   => __( 'Synced successfully.', 'sellmo-woocommerce' ),
				'sellmo_id' => $result['sellmo_id'],
			] );
		} else {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
	}

	// -------------------------------------------------------------------------
	// Core sync logic
	// -------------------------------------------------------------------------

	/**
	 * Build the order payload, call the Sellmo API, and persist the result.
	 *
	 * Returns an array describing the outcome so callers can act on success or
	 * failure without parsing log entries.
	 *
	 * @since  1.0.0
	 * @param  WC_Order $order     WooCommerce order to push.
	 * @param  bool     $is_manual True when triggered by the meta-box button.
	 * @return array{ success: bool, sellmo_id: string, message: string }
	 */
	private function push_order( WC_Order $order, bool $is_manual = false ): array {
		$order_id = $order->get_id();
		$payload  = $this->build_payload( $order );

		$api    = new Sellmo_API();
		$result = $api->create_order( $payload );

		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();

			// Persist error so the meta box can surface it.
			$order->update_meta_data( '_sellmo_sync_error', $message );
			$order->save();

			$note_prefix = $is_manual ? __( 'Manual Sellmo sync failed:', 'sellmo-woocommerce' ) : __( 'Sellmo sync failed:', 'sellmo-woocommerce' );
			$order->add_order_note( $note_prefix . ' ' . $message );

			Sellmo_Logger::log(
				'order',
				'wc_to_pos',
				'error',
				$message,
				(string) $order_id
			);

			return [ 'success' => false, 'sellmo_id' => '', 'message' => $message ];
		}

		$sellmo_id = $this->extract_order_id( $result );

		$order->update_meta_data( '_sellmo_order_id', $sellmo_id );
		$order->delete_meta_data( '_sellmo_sync_error' );
		$order->save();

		$note_prefix = $is_manual ? __( 'Manually synced to Sellmo.', 'sellmo-woocommerce' ) : __( 'Synced to Sellmo.', 'sellmo-woocommerce' );
		/* translators: %s: Sellmo order ID */
		$order->add_order_note( $note_prefix . ( $sellmo_id ? ' ' . sprintf( __( 'Sellmo order ID: %s', 'sellmo-woocommerce' ), $sellmo_id ) : '' ) );

		Sellmo_Logger::log(
			'order',
			'wc_to_pos',
			'success',
			sprintf( 'Order #%d pushed to Sellmo. Sellmo ID: %s', $order_id, $sellmo_id ?: 'n/a' ),
			(string) $order_id
		);

		// Update the last-synced order option for the dashboard.
		Sellmo_Settings::set( 'last_order_sync', [
			'time'     => time(),
			'order_id' => $order_id,
		] );

		return [ 'success' => true, 'sellmo_id' => $sellmo_id, 'message' => '' ];
	}

	/**
	 * Build the Sellmo API payload from a WooCommerce order.
	 *
	 * Maps WC billing fields and line items to the structure expected by the
	 * POST /online_orders endpoint. Items missing _pos_product_code get a
	 * warning order note but are still included (Sellmo may match by name).
	 *
	 * @since  1.0.0
	 * @param  WC_Order $order WooCommerce order.
	 * @return array<string, mixed>
	 */
	private function build_payload( WC_Order $order ): array {
		$shop_id = (string) Sellmo_Settings::get( 'shop_id' );

		$items        = [];
		$missing_code = [];

		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$lookup_id    = $variation_id > 0 ? $variation_id : $product_id;

			// Load the WC product object — may be null if the product was deleted.
			$product = $lookup_id > 0 ? wc_get_product( $lookup_id ) : null;

			// Product code — prefer Sellmo meta, fall back to WC ID string.
			$product_code = $lookup_id > 0
				? (string) get_post_meta( $lookup_id, '_pos_product_code', true )
				: '';

			if ( '' === $product_code ) {
				$missing_code[] = $item->get_name();
				$product_code   = $lookup_id > 0 ? (string) $lookup_id : '';
			}

			// Name — always from the order line item so it is never empty,
			// even if the product has since been deleted or renamed.
			$name = $item->get_name();
			if ( '' === $name ) {
				$name = $product ? $product->get_name() : sprintf( 'Product #%d', $lookup_id );
			}

			// Prices — use order item subtotals; fall back to 0 if unavailable.
			$unit_price = (float) $order->get_item_subtotal( $item, false, false );
			$total      = (float) $order->get_line_subtotal( $item, false, false );

			$items[] = [
				'product_code'       => $product_code,
				'product_name'       => $name,
				'product_qty'        => $item->get_quantity(),
				'product_unit_price' => round( $unit_price, 2 ),
				'product_price'      => round( $total, 2 ),
				'product_cost'       => 0,
				'product_remark'     => '',
				'product_modifiers'  => [],
				'product_taxes'      => [],
				'product_discounts'  => [],
			];
		}

		if ( ! empty( $missing_code ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: comma-separated product names */
					__( 'Sellmo sync warning: the following items have no Sellmo product code and may not match in Sellmo POS: %s', 'sellmo-woocommerce' ),
					implode( ', ', $missing_code )
				)
			);
		}

		// Order times go out as local wall-clock strings, so they follow the
		// WooCommerce site time zone unless the Order Time Zone setting overrides
		// it. Clone before re-zoning: get_date_created() hands back the order's
		// own object and setTimezone() mutates in place.
		$date_created = $order->get_date_created();
		$timezone     = (string) ( Sellmo_Settings::get( 'order_timezone' ) ?: '' );

		if ( $date_created && in_array( $timezone, timezone_identifiers_list(), true ) ) {
			$date_created = ( clone $date_created )->setTimezone( new DateTimeZone( $timezone ) );
		}

		$order_date      = $date_created ? $date_created->date( 'Y-m-d' ) : '';
		$order_date_time = $date_created ? $date_created->date( 'Y-m-d H:i:s' ) : '';

		return [
			'shop_id'                    => $shop_id,
			'order_type'                 => '',
			'order_date'                 => $order_date,
			'order_date_time'            => $order_date_time,
			'order_reference_id'         => (string) $order->get_id(),
			'order_reference_number'     => (string) $order->get_order_number(),
			'order_total'                => round( (float) $order->get_total(), 2 ),
			'channel_order_status_id'    => '0',
			'channel_order_status_name'  => 'Pending',
			'table_code'                 => '',
			'target_terminal'            => (string) ( Sellmo_Settings::get( 'terminal_key' ) ?: '' ),
			'order_comment'              => $order->get_customer_note() ?: '',
			'customer_first_name'        => $order->get_billing_first_name(),
			'customer_last_name'         => $order->get_billing_last_name(),
			'customer_phone'             => $order->get_billing_phone(),
			'customer_email'             => $order->get_billing_email(),
			'order_items'                => $items,
			'fixed_charges'              => [],
			'order_total_discounts'      => [],
			'order_payments'             => [],
		];
	}

	/**
	 * Extract the Sellmo order ID from a successful API response.
	 *
	 * Handles multiple response shapes: `{ "id": "..." }`, `{ "order_id": "..." }`,
	 * and nested `{ "data": { "id": "..." } }`.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $result Decoded API response body.
	 * @return string Sellmo order ID, or empty string if not present.
	 */
	private function extract_order_id( array $result ): string {
		foreach ( [ 'id', 'order_id' ] as $key ) {
			if ( ! empty( $result[ $key ] ) ) {
				return (string) $result[ $key ];
			}
		}

		if ( ! empty( $result['data']['id'] ) ) {
			return (string) $result['data']['id'];
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Meta-box JS
	// -------------------------------------------------------------------------

	/**
	 * Return the inline JavaScript for the order edit meta-box button.
	 *
	 * The script is attached to the jQuery handle so it runs after jQuery is
	 * available. It reads data attributes on the button so no global vars are
	 * needed beyond the WordPress ajaxurl.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private function build_order_sync_js(): string {
		return <<<'JS'
( function ( $ ) {
	$( document ).on( 'click', '.sellmo-order-sync-btn', function () {
		var $btn     = $( this );
		var $box     = $btn.closest( '.sellmo-order-meta-box' );
		var $spinner = $box.find( '.sellmo-sync-spinner' );
		var $result  = $box.find( '.sellmo-order-sync-result' );
		var orderId  = $btn.data( 'order-id' );
		var nonce    = $btn.data( 'nonce' );
		var origText = $btn.text();

		$btn.prop( 'disabled', true ).text( 'Syncing…' );
		$spinner.addClass( 'is-active' );
		$result.text( '' ).css( 'color', '' );

		$.post( ajaxurl, {
			action   : 'sellmo_wc_manual_order_sync',
			order_id : orderId,
			nonce    : nonce
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$result.css( 'color', '#0a7d48' ).text( res.data.message || 'Synced.' );
				$btn.text( 'Re-sync to Sellmo' );
				$box.find( '.sellmo-sync-status' )
					.removeClass( 'not-synced error' )
					.addClass( 'synced' )
					.html( '<strong>Synced<\/strong>' + ( res.data.sellmo_id ? '<br><span class="description">Sellmo ID: ' + res.data.sellmo_id + '<\/span>' : '' ) );
			} else {
				$result.css( 'color', '#d63638' ).text( ( res.data && res.data.message ) || 'Sync failed.' );
				$btn.text( origText );
			}
		} )
		.fail( function () {
			$result.css( 'color', '#d63638' ).text( 'Sync failed. Please try again.' );
			$btn.text( origText );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
			$spinner.removeClass( 'is-active' );
		} );
	} );
} )( jQuery );
JS;
	}
}
