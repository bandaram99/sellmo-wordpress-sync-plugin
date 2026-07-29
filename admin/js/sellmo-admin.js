/**
 * Sellmo POS Integration — Admin JS
 *
 * Handles: API token show/hide, connection test, shop dropdown population,
 * category multi-select population, and webhook secret generation.
 *
 * Requires: jQuery (loaded by WordPress admin), sellmoAdmin global (wp_localize_script).
 */
/* global sellmoAdmin */
( function ( $ ) {
	'use strict';

	var cfg     = window.sellmoAdmin || {};
	var ajaxUrl = cfg.ajaxUrl || '';
	var nonce   = cfg.nonce  || '';
	var i18n    = cfg.i18n  || {};
	var brand   = cfg.brandName || 'Sellmo';

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Extract the items array from an API response.
	 * Handles both `{ data: [...] }` and a bare `[...]` shape.
	 *
	 * @param  {*} payload - res.data from wp_send_json_success().
	 * @return {Array}
	 */
	function extractItems( payload ) {
		if ( Array.isArray( payload ) ) {
			return payload;
		}
		if ( payload && Array.isArray( payload.data ) ) {
			return payload.data;
		}
		return [];
	}

	// ── Show / hide API token ─────────────────────────────────────────────────

	$( '#sellmo-toggle-token' ).on( 'click', function () {
		var $field  = $( '#sellmo_wc_api_token' );
		var isPass  = 'password' === $field.attr( 'type' );
		$field.attr( 'type', isPass ? 'text' : 'password' );
		$( this ).text( isPass ? ( i18n.hide || 'Hide' ) : ( i18n.show || 'Show' ) );
	} );

	// ── Test connection ───────────────────────────────────────────────────────

	$( '#sellmo-test-connection' ).on( 'click', function () {
		var $btn    = $( this );
		var $status = $( '#sellmo-connection-status' );

		$btn.prop( 'disabled', true ).text( i18n.testing || 'Testing…' );
		$status.removeClass( 'connected disconnected' ).text( '' );

		$.post( ajaxUrl, {
			action : 'sellmo_wc_test_connection',
			nonce  : nonce
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$status
					.addClass( 'connected' )
					.text( i18n.connected || 'Connected' );
				// Refresh dependent dropdowns now that the connection is confirmed.
				loadShops();
				loadCategories();
			} else {
				$status
					.addClass( 'disconnected' )
					.text( ( res.data && res.data.message ) || ( i18n.disconnected || 'Not connected' ) );
			}
		} )
		.fail( function () {
			$status
				.addClass( 'disconnected' )
				.text( i18n.disconnected || 'Not connected' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( i18n.testBtn || 'Test Connection' );
		} );
	} );

	// ── Shop dropdown ─────────────────────────────────────────────────────────

	/**
	 * Fetch shops from the API and populate the shop <select>.
	 * Pre-selects the previously saved shop ID (data-saved attribute).
	 */
	function loadShops() {
		var $sel    = $( '#sellmo_wc_shop_id' );
		var savedId = $sel.data( 'saved' ) || cfg.savedShopId || '';

		$sel.prop( 'disabled', true );

		$.post( ajaxUrl, {
			action : 'sellmo_wc_get_shops',
			nonce  : nonce
		} )
		.done( function ( res ) {
			if ( ! res.success ) {
				return;
			}

			var shops = extractItems( res.data );

			$sel.empty().append(
				$( '<option>' ).val( '' ).text( '— ' + ( i18n.selectShop || 'Select shop' ) + ' —' )
			);

			$.each( shops, function ( _i, shop ) {
				var id          = String( shop.id           || '' );
				var name        = shop.name                 || id;
				var terminalKey = String( shop.terminal_key || '' );
				$sel.append(
					$( '<option>' )
						.val( id )
						.text( name )
						.data( 'terminal-key', terminalKey )
						.prop( 'selected', id === String( savedId ) )
				);
			} );

			// Sync hidden terminal-key input with the currently selected shop.
			$( '#sellmo_wc_terminal_key' ).val(
				$sel.find( 'option:selected' ).data( 'terminal-key' ) || ''
			);
		} )
		.always( function () {
			$sel.prop( 'disabled', false );
		} );
	}

	// Update terminal key whenever the shop dropdown selection changes.
	$( '#sellmo_wc_shop_id' ).on( 'change', function () {
		$( '#sellmo_wc_terminal_key' ).val(
			$( this ).find( 'option:selected' ).data( 'terminal-key' ) || ''
		);
	} );

	// ── Category multi-select ─────────────────────────────────────────────────

	/**
	 * Fetch categories from the API and populate the categories <select multiple>.
	 * Pre-selects any IDs stored in sellmoAdmin.savedCategories.
	 */
	function loadCategories() {
		var $sel   = $( '#sellmo_wc_sync_categories' );
		var saved  = cfg.savedCategories || [];

		$sel.prop( 'disabled', true );

		$.post( ajaxUrl, {
			action : 'sellmo_wc_get_categories',
			nonce  : nonce
		} )
		.done( function ( res ) {
			if ( ! res.success ) {
				return;
			}

			var cats = extractItems( res.data );

			$sel.empty();

			$.each( cats, function ( _i, cat ) {
				var id   = String( cat.id   || cat.category_id   || '' );
				var name = cat.name || cat.category_name || id;
				$sel.append(
					$( '<option>' )
						.val( id )
						.text( name )
						.prop( 'selected', saved.indexOf( id ) !== -1 )
				);
			} );
		} )
		.always( function () {
			$sel.prop( 'disabled', false );
		} );
	}

	// ── Auto-generate webhook secret ─────────────────────────────────────────

	$( '#sellmo-generate-secret' ).on( 'click', function () {
		var array  = new Uint8Array( 24 );
		window.crypto.getRandomValues( array );
		var secret = Array.from( array, function ( b ) {
			return ( '0' + b.toString( 16 ) ).slice( -2 );
		} ).join( '' );

		$( '#sellmo_wc_webhook_secret' ).val( secret );

		var $notice = $( '#sellmo-secret-notice' );
		$notice.text( i18n.secretGenerated || 'New secret generated. Save settings to apply.' ).show();
	} );

	// ── Copy webhook URL / secret ─────────────────────────────────────────────

	/**
	 * Copy text to the clipboard, with a legacy execCommand fallback.
	 * Briefly changes the button label to "Copied!" as confirmation.
	 *
	 * @param {string} text - The string to copy.
	 * @param {jQuery} $btn - The button that was clicked.
	 */
	function copyToClipboard( text, $btn ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text );
		} else {
			var $tmp = $( '<textarea>' )
				.val( text )
				.css( { position: 'fixed', top: -9999, left: -9999 } )
				.appendTo( 'body' );
			$tmp[ 0 ].select();
			document.execCommand( 'copy' );
			$tmp.remove();
		}
		var orig = $btn.text();
		$btn.text( 'Copied!' ).prop( 'disabled', true );
		setTimeout( function () { $btn.text( orig ).prop( 'disabled', false ); }, 1500 );
	}

	$( '#sellmo-copy-webhook-url' ).on( 'click', function () {
		copyToClipboard( $( '#sellmo-webhook-url' ).val(), $( this ) );
	} );

	$( '#sellmo-copy-webhook-secret' ).on( 'click', function () {
		copyToClipboard( $( '#sellmo_wc_webhook_secret' ).val(), $( this ) );
	} );

	// ── Manual sync buttons ───────────────────────────────────────────────────

	/**
	 * Fire a manual sync AJAX request and update the button/spinner/result UI.
	 *
	 * @param {string} action   - wp_ajax action name.
	 * @param {jQuery} $btn     - The clicked button element.
	 * @param {jQuery} $result  - The result <span> element.
	 */
	function runManualSync( action, $btn, $result ) {
		var originalText = $btn.text();
		var isSalesPlaySync = 'run_salesplay_sync' === action || 'sellmo_wc_sync_products_now' === action;

		if ( isSalesPlaySync ) {
			console.log( 'SalesPlay Sync Started...' );
		}

		$btn.prop( 'disabled', true ).text( i18n.syncing || 'Syncing…' );
		$btn.siblings( '.sellmo-sync-spinner' ).addClass( 'is-active' );
		$result.text( '' ).removeClass( 'success error' );

		$.post( ajaxUrl, { action: action, nonce: nonce } )
			.done( function ( res ) {
				if ( isSalesPlaySync ) {
					console.log( 'SalesPlay Sync AJAX Response:' );
					console.dir( res && res.data ? res.data : res );
				}

				if ( res.success ) {
					if ( isSalesPlaySync && res.data && res.data.error ) {
						console.error( 'WooCommerce Variation Registration Failed for SKU:', ( res.data.error && res.data.error.product_code ) || '' );
						console.dir( res.data.error );
						if ( res.data.trace && res.data.trace.failures ) {
							console.dir( res.data.trace.failures );
						}
					}

					$result
						.addClass( 'success' )
						.text( ( res.data && res.data.message ) || '' );
				} else {
					if ( isSalesPlaySync ) {
						console.error( 'WooCommerce Variation Registration Failed for SKU:', ( res.data && res.data.error && res.data.error.product_code ) || '' );
						console.dir( {
							message: ( res.data && res.data.message ) || ( i18n.syncError || 'Sync failed.' ),
							error: ( res.data && res.data.error ) ? res.data.error : {},
							trace: ( res.data && res.data.trace ) ? res.data.trace : []
						} );
					}

					$result
						.addClass( 'error' )
						.text( ( res.data && res.data.message ) || ( i18n.syncError || 'Sync failed.' ) );
				}
			} )
			.fail( function ( xhr ) {
				var failMessage = i18n.syncError || 'Sync failed. Please try again.';

				if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					failMessage = xhr.responseJSON.data.message;
				} else if ( xhr && '-1' === String( xhr.responseText || '' ).trim() ) {
					failMessage = 'Security check failed. Please refresh the page and try again.';
				}

				if ( isSalesPlaySync ) {
					console.error( 'SalesPlay Sync AJAX Failed:' );
					console.dir( {
						status: xhr ? xhr.status : 0,
						responseText: xhr ? xhr.responseText : ''
					} );
				}

				$result
					.addClass( 'error' )
					.text( failMessage );
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).text( originalText );
				$btn.siblings( '.sellmo-sync-spinner' ).removeClass( 'is-active' );
			} );
	}

	$( '#sellmo-sync-products' ).on( 'click', function () {
		runManualSync(
			'run_salesplay_sync',
			$( this ),
			$( '#sellmo-sync-products-result' )
		);
	} );

	$( '#sellmo-sync-stock' ).on( 'click', function () {
		runManualSync(
			'sellmo_wc_sync_stock_now',
			$( this ),
			$( '#sellmo-sync-stock-result' )
		);
	} );

	// ── On page load ─────────────────────────────────────────────────────────

	// If a token is already saved, populate the shop dropdown and categories
	// immediately so the user sees the correct values without an extra click.
	if ( cfg.hasToken ) {
		loadShops();
		loadCategories();
	}

	// ── Shared utility ────────────────────────────────────────────────────────

	/**
	 * Escape a string for safe insertion into HTML.
	 *
	 * @param  {*} str - Value to escape.
	 * @return {string}
	 */
	function escHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

	// ── Products page ─────────────────────────────────────────────────────────
	// Guard: only execute when the products table container is present.
	if ( $( '#sellmo-products-tbody' ).length ) {

		var currentPage     = 1;
		var currentCategory = '';
		var isLoading       = false;

		// Populate the category filter, then load the first page of products.
		loadProductCategories();
		loadProducts( 1, '' );

		// ── Category filter ───────────────────────────────────────────────────

		$( '#sellmo-category-filter' ).on( 'change', function () {
			currentCategory = $( this ).val() || '';
			loadProducts( 1, currentCategory );
		} );

		// ── Refresh button ────────────────────────────────────────────────────

		$( '#sellmo-refresh-products' ).on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true );

			$.post( ajaxUrl, { action: 'sellmo_wc_refresh_products', nonce: nonce } )
				.always( function () {
					$btn.prop( 'disabled', false );
					loadProducts( 1, currentCategory );
				} );
		} );

		// ── Pagination (delegated — links are injected dynamically) ───────────

		$( document ).on( 'click', '.sellmo-page-link', function ( e ) {
			e.preventDefault();
			var page = parseInt( $( this ).data( 'page' ), 10 );
			if ( page && ! isLoading ) {
				loadProducts( page, currentCategory );
			}
		} );

		// ── Select-all checkboxes ─────────────────────────────────────────────

		$( '#sellmo-select-all' ).on( 'change', function () {
			var checked = $( this ).is( ':checked' );
			$( '.sellmo-product-checkbox' ).prop( 'checked', checked );
			$( '.sellmo-select-all-foot' ).prop( 'checked', checked );
		} );

		$( document ).on( 'change', '.sellmo-select-all-foot', function () {
			var checked = $( this ).is( ':checked' );
			$( '.sellmo-product-checkbox' ).prop( 'checked', checked );
			$( '#sellmo-select-all' ).prop( 'checked', checked );
		} );

		// ── Sync toggle ───────────────────────────────────────────────────────

		$( document ).on( 'change', '.sellmo-sync-checkbox', function () {
			var $cb     = $( this );
			var code    = String( $cb.data( 'code' ) || '' );
			var enabled = $cb.is( ':checked' );

			$cb.prop( 'disabled', true );

			$.post( ajaxUrl, {
				action       : 'sellmo_wc_toggle_sync',
				nonce        : nonce,
				product_code : code,
				enabled      : enabled ? '1' : '0'
			} )
			.fail( function () {
				// Revert the toggle on network / server error.
				$cb.prop( 'checked', ! enabled );
			} )
			.always( function () {
				$cb.prop( 'disabled', false );
			} );
		} );

		// ── Import single product ─────────────────────────────────────────────

		$( document ).on( 'click', '.sellmo-import-btn', function () {
			var $btn = $( this );
			var code = String( $btn.data( 'code' ) || '' );
			var originalText = $btn.text();

			$btn.prop( 'disabled', true ).text( i18n.importing || 'Importing…' );

			$.post( ajaxUrl, {
				action       : 'sellmo_wc_import_product',
				nonce        : nonce,
				product_code : code
			} )
			.done( function ( res ) {
				if ( res.success ) {
					updateRowAfterImport( $( '[data-code="' + escHtml( code ) + '"]' ), res.data );
				} else {
					$btn.prop( 'disabled', false ).text( originalText );
					// eslint-disable-next-line no-alert
					window.alert( ( res.data && res.data.message ) || ( i18n.importError || 'Import failed.' ) );
				}
			} )
			.fail( function () {
				$btn.prop( 'disabled', false ).text( originalText );
				// eslint-disable-next-line no-alert
				window.alert( i18n.importError || 'Import failed. Please try again.' );
			} );
		} );

		// ── Bulk actions ──────────────────────────────────────────────────────

		$( '#sellmo-bulk-apply' ).on( 'click', function () {
			var action  = $( '#sellmo-bulk-action-select' ).val() || '';
			var codes   = [];

			$( '.sellmo-product-checkbox:checked' ).each( function () {
				codes.push( String( $( this ).val() ) );
			} );

			if ( '-1' === action || '' === action ) {
				// eslint-disable-next-line no-alert
				window.alert( i18n.selectBulkAction || 'Please select a bulk action.' );
				return;
			}

			if ( 0 === codes.length ) {
				// eslint-disable-next-line no-alert
				window.alert( i18n.selectProducts || 'Please select at least one product.' );
				return;
			}

			var $btn    = $( this );
			var $result = $( '#sellmo-bulk-result' );

			$btn.prop( 'disabled', true );
			$( '.sellmo-bulk-spinner' ).addClass( 'is-active' );
			$result.text( '' ).removeClass( 'success error' );

			$.post( ajaxUrl, {
				action      : 'sellmo_wc_bulk_products',
				nonce       : nonce,
				bulk_action : action,
				codes       : codes
			} )
			.done( function ( res ) {
				if ( res.success ) {
					$result.addClass( 'success' ).text( ( res.data && res.data.message ) || '' );
					loadProducts( currentPage, currentCategory );
				} else {
					$result.addClass( 'error' )
						.text( ( res.data && res.data.message ) || ( i18n.bulkError || 'Bulk action failed.' ) );
				}
			} )
			.fail( function () {
				$result.addClass( 'error' ).text( i18n.bulkError || 'Bulk action failed. Please try again.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
				$( '.sellmo-bulk-spinner' ).removeClass( 'is-active' );
			} );
		} );

		// ── Core functions ────────────────────────────────────────────────────

		/** Fetch categories and populate the filter dropdown.
		 *  Uses the category name as the option value because the products API
		 *  returns category as a flat name string, not a category ID.
		 */
		function loadProductCategories() {
			$.post( ajaxUrl, { action: 'sellmo_wc_get_categories', nonce: nonce } )
				.done( function ( res ) {
					if ( ! res.success ) { return; }
					var $sel = $( '#sellmo-category-filter' );
					$.each( extractItems( res.data ), function ( _i, cat ) {
						var name = cat.name || cat.category_name || String( cat.id || cat.category_id || '' );
						$sel.append( $( '<option>' ).val( name ).text( name ) );
					} );
				} );
		}

		/**
		 * Request a page of products from the server and render the table.
		 *
		 * @param {number} page     - 1-based page number.
		 * @param {string} category - Sellmo category ID to filter by, or '' for all.
		 */
		function loadProducts( page, category ) {
			if ( isLoading ) { return; }
			isLoading    = true;
			currentPage  = page;

			$( '#sellmo-products-loading' ).show();
			$( '#sellmo-products-table' ).hide();
			$( '#sellmo-products-error' ).hide();
			$( '#sellmo-products-pagination' ).hide();
			$( '#sellmo-top-pagination' ).hide();
			$( '#sellmo-refresh-products' ).prop( 'disabled', true );

			$.post( ajaxUrl, {
				action   : 'sellmo_wc_get_products_list',
				nonce    : nonce,
				page     : page,
				category : category || ''
			} )
			.done( function ( res ) {
				if ( ! res.success ) {
					showProductsError(
						( res.data && res.data.message ) || ( i18n.loadError || 'Failed to load products.' )
					);
					return;
				}
				renderProducts( res.data );
			} )
			.fail( function () {
				showProductsError( i18n.loadError || 'Failed to load products. Please try again.' );
			} )
			.always( function () {
				isLoading = false;
				$( '#sellmo-products-loading' ).hide();
				$( '#sellmo-refresh-products' ).prop( 'disabled', false );
			} );
		}

		/**
		 * Render all products from a server response into the table.
		 *
		 * @param {{ products: Array, total: number, pages: number, current_page: number, per_page: number }} data
		 */
		function renderProducts( data ) {
			var products = data.products    || [];
			var total    = parseInt( data.total        || 0,  10 );
			var pages    = parseInt( data.pages        || 1,  10 );
			var page     = parseInt( data.current_page || 1,  10 );
			var perPage  = parseInt( data.per_page     || 25, 10 );

			var $tbody = $( '#sellmo-products-tbody' ).empty();

			if ( 0 === products.length ) {
				$tbody.append(
					'<tr><td colspan="10" style="text-align:center;padding:24px 0;">'
					+ escHtml( i18n.noProducts || 'No products found.' )
					+ '</td></tr>'
				);
			} else {
				$.each( products, function ( _i, product ) {
					$tbody.append( buildProductRow( product ) );
				} );
			}

			// "Showing X–Y of Z" display.
			var from      = ( page - 1 ) * perPage + 1;
			var to        = Math.min( page * perPage, total );
			var countText = total > 0
				? from + '–' + to + ' ' + ( i18n.of || 'of' ) + ' ' + total
				: '0';

			$( '#sellmo-products-count' ).text( countText );
			$( '#sellmo-page-info' ).text( countText );

			// Pagination controls.
			var paginationHtml = buildPagination( page, pages );
			$( '#sellmo-pagination-top, #sellmo-pagination-bottom' ).html( paginationHtml );

			$( '#sellmo-products-table' ).show();

			if ( total > perPage ) {
				$( '#sellmo-products-pagination, #sellmo-top-pagination' ).show();
			}

			// Reset select-all state.
			$( '#sellmo-select-all, .sellmo-select-all-foot' ).prop( 'checked', false );
		}

		/** Display the error panel with a message. */
		function showProductsError( message ) {
			$( '#sellmo-products-error-text' ).text( message );
			$( '#sellmo-products-error' ).show();
		}

		/**
		 * Build an HTML string for a single product table row.
		 *
		 * All dynamic string values are passed through escHtml() before
		 * insertion so there is no risk of XSS from API-supplied data.
		 *
		 * @param  {Object} product - Enriched product object from the server.
		 * @return {string}         HTML <tr> string.
		 */
		function buildProductRow( product ) {
			var code         = String( product.product_code  || '' );
			var codeEsc      = escHtml( code );
			var nameEsc      = escHtml( product.product_name || '' );
			var catEsc       = escHtml( product.category     || '—' );
			var imageUrl     = String( product.image_url     || '' );
			var variantCount = parseInt( product.variant_count || 0, 10 );
			var wcStatus     = String( product.wc_status       || 'not_imported' );
			var syncEnabled  = !! product.sync_enabled;
			var wcEditUrl    = String( product.wc_edit_url     || '' );
			var isImported   = parseInt( product.wc_id || 0, 10 ) > 0;

			// ── Image ─────────────────────────────────────────────────────────
			var imageTd = imageUrl
				? '<img src="' + escHtml( imageUrl ) + '" class="sellmo-product-thumb" width="48" height="48" alt="" loading="lazy">'
				: '<span class="sellmo-no-image">—</span>';

			// ── Price — single price value from shops[0].price ─────────────────
			var price   = parseFloat( product.price || 0 );
			var priceTd = price > 0 ? escHtml( price.toFixed( 2 ) ) : '—';

			// ── Variants ───────────────────────────────────────────────────────
			var variantsTd = variantCount > 0
				? escHtml( String( variantCount ) ) + ' ' + escHtml( i18n.variants || 'variants' )
				: '—';

			// ── WC Status ──────────────────────────────────────────────────────
			var wcStatusTd;
			var editLink = wcEditUrl
				? ' <a href="' + escHtml( wcEditUrl ) + '" target="_blank">' + escHtml( i18n.edit || 'Edit' ) + '</a>'
				: '';

			if ( 'linked' === wcStatus ) {
				wcStatusTd = '<span class="sellmo-result-badge success">'
				           + escHtml( i18n.wcLinked || 'Linked' ) + '</span>' + editLink;
			} else if ( 'sync_disabled' === wcStatus ) {
				wcStatusTd = '<span class="sellmo-result-badge skipped">'
				           + escHtml( i18n.wcSyncDisabled || 'Sync disabled' ) + '</span>' + editLink;
			} else {
				wcStatusTd = '<span class="sellmo-toggle-badge disabled">'
				           + escHtml( i18n.wcNotImported || 'Not imported' ) + '</span>';
			}

			// ── Sync toggle ────────────────────────────────────────────────────
			var toggleTitle = isImported
				? escHtml( i18n.toggleSync  || 'Toggle sync' )
				: escHtml( i18n.importFirst || 'Import this product first to enable sync' );

			var toggleTd = '<label class="sellmo-switch' + ( isImported ? '' : ' sellmo-switch--disabled' ) + '"'
			             + ' title="' + toggleTitle + '">'
			             + '<input type="checkbox" class="sellmo-sync-checkbox" data-code="' + codeEsc + '"'
			             + ( syncEnabled  ? ' checked'  : '' )
			             + ( isImported   ? ''          : ' disabled' )
			             + '>'
			             + '<span class="sellmo-switch-slider"></span>'
			             + '</label>';

			// ── Actions ────────────────────────────────────────────────────────
			var actionsTd;
			if ( ! isImported ) {
				actionsTd = '<button type="button" class="button button-small sellmo-import-btn" data-code="' + codeEsc + '">'
				          + escHtml( i18n.importProduct || 'Import to WooCommerce' )
				          + '</button>';
			} else {
				actionsTd = wcEditUrl
					? '<a href="' + escHtml( wcEditUrl ) + '" class="button button-small" target="_blank">'
					  + escHtml( i18n.viewInWC || 'View in WC' ) + '</a>'
					: '';
			}

			return '<tr data-code="' + codeEsc + '">'
			     + '<th class="check-column"><input type="checkbox" class="sellmo-product-checkbox" value="' + codeEsc + '"></th>'
			     + '<td class="column-image">' + imageTd + '</td>'
			     + '<td class="column-name"><strong>' + nameEsc + '</strong></td>'
			     + '<td class="column-code"><code>' + codeEsc + '</code></td>'
			     + '<td class="column-category">' + catEsc + '</td>'
			     + '<td class="column-price">' + priceTd + '</td>'
			     + '<td class="column-variants">' + variantsTd + '</td>'
			     + '<td class="column-wc-status">' + wcStatusTd + '</td>'
			     + '<td class="column-sync">' + toggleTd + '</td>'
			     + '<td class="column-actions">' + actionsTd + '</td>'
			     + '</tr>';
		}

		/**
		 * Update a table row in-place after a successful import.
		 *
		 * Replaces the WC Status cell, enables the sync toggle, and replaces
		 * the Import button with a View in WC link — without a full table reload.
		 *
		 * @param {jQuery} $row - The <tr> element for the imported product.
		 * @param {{ wc_id: number, edit_url: string }} data - Server response data.
		 */
		function updateRowAfterImport( $row, data ) {
			var editUrl  = String( data.edit_url || '' );
			var code     = String( $row.data( 'code' ) || '' );
			var editLink = editUrl
				? ' <a href="' + escHtml( editUrl ) + '" target="_blank">' + escHtml( i18n.edit || 'Edit' ) + '</a>'
				: '';

			$row.find( '.column-wc-status' ).html(
				'<span class="sellmo-result-badge success">'
				+ escHtml( i18n.wcLinked || 'Linked' ) + '</span>' + editLink
			);

			$row.find( '.column-sync' ).html(
				'<label class="sellmo-switch" title="' + escHtml( i18n.toggleSync || 'Toggle sync' ) + '">'
				+ '<input type="checkbox" class="sellmo-sync-checkbox" data-code="' + escHtml( code ) + '" checked>'
				+ '<span class="sellmo-switch-slider"></span>'
				+ '</label>'
			);

			$row.find( '.column-actions' ).html(
				editUrl
					? '<a href="' + escHtml( editUrl ) + '" class="button button-small" target="_blank">'
					  + escHtml( i18n.viewInWC || 'View in WC' ) + '</a>'
					: ''
			);
		}

		/**
		 * Build pagination link HTML for the given page and total.
		 *
		 * Shows up to 5 page numbers around the current page, with ellipses
		 * and first/last links when the range does not reach the boundaries.
		 *
		 * @param  {number} currentPage
		 * @param  {number} totalPages
		 * @return {string} HTML string of .button links.
		 */
		function buildPagination( currentPage, totalPages ) {
			if ( totalPages <= 1 ) { return ''; }

			var html  = '';
			var start = Math.max( 1, currentPage - 2 );
			var end   = Math.min( totalPages, currentPage + 2 );

			if ( currentPage > 1 ) {
				html += '<a class="button sellmo-page-link" data-page="' + ( currentPage - 1 ) + '">‹</a> ';
			}

			if ( start > 1 ) {
				html += '<a class="button sellmo-page-link" data-page="1">1</a>';
				html += start > 2 ? ' <span class="sellmo-pag-ellipsis">…</span> ' : ' ';
			}

			for ( var p = start; p <= end; p++ ) {
				if ( p === currentPage ) {
					html += '<span class="button button-primary sellmo-page-current">' + p + '</span> ';
				} else {
					html += '<a class="button sellmo-page-link" data-page="' + p + '">' + p + '</a> ';
				}
			}

			if ( end < totalPages ) {
				html += end < ( totalPages - 1 ) ? '<span class="sellmo-pag-ellipsis">…</span> ' : '';
				html += '<a class="button sellmo-page-link" data-page="' + totalPages + '">' + totalPages + '</a>';
			}

			if ( currentPage < totalPages ) {
				html += ' <a class="button sellmo-page-link" data-page="' + ( currentPage + 1 ) + '">›</a>';
			}

			return html;
		}

	} // end products page guard

	// ── Product Mapper page ────────────────────────────────────────────────
	if ( $( '#sellmo-mapper-tbody' ).length ) {
		var mapperPage           = 1;
		var mapperSearch         = '';
		var mapperStatus         = '';
		var mapperCategory       = '';
		var mapperLoading        = false;
		var mapperWcProducts     = [];
		var mapperSellmoProducts = [];

		// Populate the category filter, then load the first page of mapper data.
		loadMapperCategories();
		loadMapperList( 1 );

		$( '#sellmo-mapper-search-btn' ).on( 'click', function () {
			mapperSearch   = String( $( '#sellmo-mapper-search' ).val() || '' );
			mapperStatus   = String( $( '#sellmo-mapper-status-filter' ).val() || '' );
			mapperCategory = String( $( '#sellmo-mapper-category-filter' ).val() || '' );
			loadMapperList( 1 );
		} );

		// Refresh pulls the latest catalogue from Sellmo into the mapper table
		// (the list is read from local storage, not the live API), then reloads.
		$( '#sellmo-mapper-refresh' ).on( 'click', function () {
			var $btn = $( this );
			if ( $btn.prop( 'disabled' ) ) {
				return;
			}

			mapperSearch   = String( $( '#sellmo-mapper-search' ).val() || '' );
			mapperStatus   = String( $( '#sellmo-mapper-status-filter' ).val() || '' );
			mapperCategory = String( $( '#sellmo-mapper-category-filter' ).val() || '' );

			$btn.prop( 'disabled', true );
			$( '#sellmo-mapper-loading' ).show();
			$( '#sellmo-mapper-table, #sellmo-mapper-pagination, #sellmo-mapper-top-pagination' ).hide();
			$( '#sellmo-mapper-error' ).hide();

			$.post( ajaxUrl, { action: 'sellmo_wc_sync_products_now', nonce: nonce } )
				.always( function () {
					$btn.prop( 'disabled', false );
					loadMapperList( 1 );
				} );
		} );

		$( '#sellmo-mapper-status-filter' ).on( 'change', function () {
			mapperStatus = String( $( this ).val() || '' );
			loadMapperList( 1 );
		} );

		$( '#sellmo-mapper-category-filter' ).on( 'change', function () {
			mapperCategory = String( $( this ).val() || '' );
			loadMapperList( 1 );
		} );

		/** Fetch categories and populate the mapper filter dropdown.
		 *  Uses the category name as the option value because the products API
		 *  returns category as a flat name string, not a category ID.
		 */
		function loadMapperCategories() {
			$.post( ajaxUrl, { action: 'sellmo_wc_get_categories', nonce: nonce } )
				.done( function ( res ) {
					if ( ! res.success ) { return; }
					var $sel = $( '#sellmo-mapper-category-filter' );
					$.each( extractItems( res.data ), function ( _i, cat ) {
						var name = cat.name || cat.category_name || String( cat.id || cat.category_id || '' );
						$sel.append( $( '<option>' ).val( name ).text( name ) );
					} );
				} );
		}

		// ── Custom WC product searchable dropdown ─────────────────────────────────

		// Open / close the dropdown panel when the trigger button is clicked.
		$( document ).on( 'click', '.sellmo-wc-custom-trigger', function ( e ) {
			e.stopPropagation();
			var $wrap  = $( this ).closest( '.sellmo-wc-custom-select' );
			var $panel = $wrap.find( '.sellmo-wc-custom-dropdown' );
			var isOpen = $panel.is( ':visible' );

			// Close every other open dropdown first.
			$( '.sellmo-wc-custom-dropdown' ).hide();

			if ( ! isOpen ) {
				$panel.show();
				// Show all options (clear any previous search filter).
				$panel.find( '.sellmo-wc-custom-option' ).show();
				var $search = $panel.find( '.sellmo-wc-custom-search' );
				$search.val( '' ).focus();
				// Scroll the currently active option into view.
				var $active = $panel.find( '.sellmo-wc-option-active' );
				if ( $active.length ) {
					var $opts = $panel.find( '.sellmo-wc-custom-options' );
					$opts.scrollTop( $active.offset().top - $opts.offset().top + $opts.scrollTop() - 4 );
				}
			}
		} );

		// Close all dropdowns when clicking anywhere outside.
		$( document ).on( 'click', function () {
			$( '.sellmo-wc-custom-dropdown' ).hide();
		} );

		// Prevent clicks inside the panel from bubbling up and closing it.
		$( document ).on( 'click', '.sellmo-wc-custom-dropdown', function ( e ) {
			e.stopPropagation();
		} );

		// Filter the visible options as the user types in the search box.
		$( document ).on( 'input', '.sellmo-wc-custom-search', function () {
			var term     = $( this ).val().toLowerCase();
			var $options = $( this ).closest( '.sellmo-wc-custom-dropdown' ).find( '.sellmo-wc-custom-option' );

			$options.each( function () {
				var label = $( this ).text().toLowerCase();
				$( this ).toggle( '' === term || label.indexOf( term ) !== -1 );
			} );
		} );

		// When the user clicks an option item: update the hidden select,
		// update the trigger button label, mark it active, and close the panel.
		$( document ).on( 'click', '.sellmo-wc-custom-option', function () {
			var $option = $( this );
			var $wrap   = $option.closest( '.sellmo-wc-custom-select' );
			var value   = $option.data( 'value' );
			var label   = $option.text();

			// Update the hidden real <select> so the Map button reads the right value.
			$wrap.prev( '.sellmo-mapper-wc-select' ).val( value );

			// Update the trigger label span, title attribute, and the data attribute.
			var $trigger = $wrap.find( '.sellmo-wc-custom-trigger' );
			$trigger.find( '.sellmo-wc-trigger-label' ).text( label );
			$trigger.attr( 'title', label );
			$wrap.attr( 'data-selected-id', value );

			// Highlight the selected option.
			$wrap.find( '.sellmo-wc-custom-option' ).removeClass( 'sellmo-wc-option-active' );
			$option.addClass( 'sellmo-wc-option-active' );

			// Close the panel.
			$wrap.find( '.sellmo-wc-custom-dropdown' ).hide();
		} );

		$( document ).on( 'click', '.sellmo-mapper-page-link', function ( e ) {
			e.preventDefault();
			var nextPage = parseInt( $( this ).data( 'page' ), 10 );
			if ( nextPage && ! mapperLoading ) {
				loadMapperList( nextPage );
			}
		} );

		$( '#sellmo-mapper-select-all' ).on( 'change', function () {
			var checked = $( this ).is( ':checked' );
			$( '.sellmo-mapper-checkbox' ).prop( 'checked', checked );
			$( '.sellmo-mapper-select-all-foot' ).prop( 'checked', checked );
		} );

		$( document ).on( 'change', '.sellmo-mapper-select-all-foot', function () {
			var checked = $( this ).is( ':checked' );
			$( '.sellmo-mapper-checkbox' ).prop( 'checked', checked );
			$( '#sellmo-mapper-select-all' ).prop( 'checked', checked );
		} );

		$( document ).on( 'click', '.sellmo-mapper-map-btn', function () {
			var $btn    = $( this );
			var $row    = $btn.closest( 'tr' );
			var rowType = String( $row.data( 'row-type' ) || 'sellmo' );

			var sellmoId, sellmoCode, sellmoName, wcProduct;

			if ( 'wc_only' === rowType ) {
				var $sellmoSel = $row.find( '.sellmo-mapper-sellmo-select' );
				sellmoId = String( $sellmoSel.val() || '' );
				if ( ! sellmoId ) {
					// eslint-disable-next-line no-alert
					window.alert( i18n.mapperChooseSellmo || ( 'Select a ' + brand + ' product first.' ) );
					return;
				}
				sellmoCode = String( $row.data( 'sellmo-code' ) || '' );
				sellmoName = String( $row.data( 'sellmo-name' ) || '' );
				wcProduct  = parseInt( $row.data( 'wc-product-id' ) || 0, 10 );
			} else {
				wcProduct = parseInt( $row.find( '.sellmo-mapper-wc-select' ).val() || 0, 10 );
				if ( ! wcProduct ) {
					// eslint-disable-next-line no-alert
					window.alert( i18n.mapperChooseWc || 'Select a WooCommerce product first.' );
					return;
				}
				sellmoId   = String( $row.data( 'sellmo-id' ) || '' );
				sellmoCode = String( $row.data( 'sellmo-code' ) || '' );
				sellmoName = String( $row.data( 'sellmo-name' ) || '' );
			}

			$btn.prop( 'disabled', true );

			$.post( ajaxUrl, {
				action        : 'sellmo_wc_map_product',
				nonce         : nonce,
				sellmo_id     : sellmoId,
				sellmo_code   : sellmoCode,
				sellmo_name   : sellmoName,
				wc_product_id : wcProduct
			} )
			.done( function ( res ) {
				if ( res.success ) {
					loadMapperList( mapperPage );
				} else {
					// eslint-disable-next-line no-alert
					window.alert( ( res.data && res.data.message ) || ( i18n.mapperActionError || 'Mapping action failed.' ) );
				}
			} )
			.fail( function () {
				// eslint-disable-next-line no-alert
				window.alert( i18n.mapperActionError || 'Mapping action failed. Please try again.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		$( document ).on( 'click', '.sellmo-mapper-import-btn', function () {
			var $btn = $( this );
			var $row = $btn.closest( 'tr' );
			var sellmoId   = String( $row.data( 'sellmo-id' ) || '' );
			var sellmoCode = String( $row.data( 'sellmo-code' ) || '' );

			if ( ! sellmoId && ! sellmoCode ) {
				return;
			}

			var origLabel = $btn.text();
			$btn.prop( 'disabled', true ).text( i18n.importing || 'Importing…' );

			$.post( ajaxUrl, {
				action      : 'sellmo_wc_mapper_import_map',
				nonce       : nonce,
				sellmo_id   : sellmoId,
				sellmo_code : sellmoCode
			} )
			.done( function ( res ) {
				if ( res && res.success ) {
					// Row is rebuilt by the reload; it will now show as Mapped.
					loadMapperList( mapperPage );
				} else {
					// eslint-disable-next-line no-alert
					window.alert( ( res.data && res.data.message ) || ( i18n.importError || 'Import failed.' ) );
					$btn.prop( 'disabled', false ).text( origLabel );
				}
			} )
			.fail( function () {
				// eslint-disable-next-line no-alert
				window.alert( i18n.importError || 'Import failed. Please try again.' );
				$btn.prop( 'disabled', false ).text( origLabel );
			} );
		} );

		$( document ).on( 'click', '.sellmo-mapper-unmap-btn', function () {
			var $btn = $( this );
			var $row = $btn.closest( 'tr' );

			$btn.prop( 'disabled', true );

			$.post( ajaxUrl, {
				action      : 'sellmo_wc_unmap_product',
				nonce       : nonce,
				sellmo_id   : String( $row.data( 'sellmo-id' ) || '' ),
				sellmo_code : String( $row.data( 'sellmo-code' ) || '' ),
				sellmo_name : String( $row.data( 'sellmo-name' ) || '' )
			} )
			.done( function ( res ) {
				if ( res.success ) {
					loadMapperList( mapperPage );
				} else {
					// eslint-disable-next-line no-alert
					window.alert( ( res.data && res.data.message ) || ( i18n.mapperActionError || 'Mapping action failed.' ) );
				}
			} )
			.fail( function () {
				// eslint-disable-next-line no-alert
				window.alert( i18n.mapperActionError || 'Mapping action failed. Please try again.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		// Expand/collapse a variable product's variant sub-rows.
		$( document ).on( 'click', '.sellmo-variant-toggle', function () {
			var $btn      = $( this );
			var $parent   = $btn.closest( 'tr' );
			var $subs     = $parent.nextUntil( ':not(.sellmo-variant-row)' );
			var expanded  = 'true' === String( $btn.attr( 'aria-expanded' ) );

			$btn.attr( 'aria-expanded', expanded ? 'false' : 'true' );
			$btn.find( '.sellmo-variant-caret' )
				.toggleClass( 'dashicons-arrow-right-alt2', expanded )
				.toggleClass( 'dashicons-arrow-down-alt2', ! expanded );
			$subs.toggle( ! expanded );
		} );

		// Import a single variant onto the parent's WooCommerce variable product.
		$( document ).on( 'click', '.sellmo-variant-import-btn', function () {
			var $btn      = $( this );
			var $row      = $btn.closest( 'tr' );
			var mappingId = parseInt( $row.data( 'mapping-id' ) || 0, 10 );
			var variantId = String( $row.data( 'variant-id' ) || '' );

			if ( ! mappingId || ! variantId ) {
				return;
			}

			var origLabel = $btn.text();
			$btn.prop( 'disabled', true ).text( i18n.importing || 'Importing…' );

			$.post( ajaxUrl, {
				action     : 'sellmo_wc_mapper_import_variant',
				nonce      : nonce,
				mapping_id : mappingId,
				variant_id : variantId
			} )
			.done( function ( res ) {
				if ( res && res.success ) {
					loadMapperList( mapperPage );
				} else {
					// eslint-disable-next-line no-alert
					window.alert( ( res.data && res.data.message ) || ( i18n.importError || 'Import failed.' ) );
					$btn.prop( 'disabled', false ).text( origLabel );
				}
			} )
			.fail( function () {
				// eslint-disable-next-line no-alert
				window.alert( i18n.importError || 'Import failed. Please try again.' );
				$btn.prop( 'disabled', false ).text( origLabel );
			} );
		} );

		// Import every in-shop variant of a parent as WooCommerce variations.
		$( document ).on( 'click', '.sellmo-mapper-import-all-btn', function () {
			var $btn      = $( this );
			var $row      = $btn.closest( 'tr' );
			var mappingId = parseInt( $row.data( 'mapping-id' ) || 0, 10 );

			var origLabel = $btn.text();
			$btn.prop( 'disabled', true ).text( i18n.importing || 'Importing…' );

			$.post( ajaxUrl, {
				action      : 'sellmo_wc_mapper_import_all_variants',
				nonce       : nonce,
				mapping_id  : mappingId,
				sellmo_id   : String( $row.data( 'sellmo-id' ) || '' ),
				sellmo_code : String( $row.data( 'sellmo-code' ) || '' )
			} )
			.done( function ( res ) {
				if ( res && res.success ) {
					loadMapperList( mapperPage );
				} else {
					// eslint-disable-next-line no-alert
					window.alert( ( res.data && res.data.message ) || ( i18n.importError || 'Import failed.' ) );
					$btn.prop( 'disabled', false ).text( origLabel );
				}
			} )
			.fail( function () {
				// eslint-disable-next-line no-alert
				window.alert( i18n.importError || 'Import failed. Please try again.' );
				$btn.prop( 'disabled', false ).text( origLabel );
			} );
		} );

		// Map a variant to an already-existing WooCommerce variation.
		$( document ).on( 'click', '.sellmo-variant-map-btn', function () {
			var $btn          = $( this );
			var $row          = $btn.closest( 'tr' );
			var mappingId     = parseInt( $row.data( 'mapping-id' ) || 0, 10 );
			var variantId     = String( $row.data( 'variant-id' ) || '' );
			var wcVariationId = parseInt( $row.find( '.sellmo-variant-wc-select' ).val() || 0, 10 );

			if ( ! wcVariationId ) {
				// eslint-disable-next-line no-alert
				window.alert( i18n.mapperChooseWc || 'Select a WooCommerce product first.' );
				return;
			}

			$btn.prop( 'disabled', true );

			$.post( ajaxUrl, {
				action          : 'sellmo_wc_map_variant',
				nonce           : nonce,
				mapping_id      : mappingId,
				variant_id      : variantId,
				wc_variation_id : wcVariationId
			} )
			.done( function ( res ) {
				if ( res && res.success ) {
					loadMapperList( mapperPage );
				} else {
					// eslint-disable-next-line no-alert
					window.alert( ( res.data && res.data.message ) || ( i18n.mapperActionError || 'Mapping action failed.' ) );
				}
			} )
			.fail( function () {
				// eslint-disable-next-line no-alert
				window.alert( i18n.mapperActionError || 'Mapping action failed. Please try again.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		$( '#sellmo-mapper-bulk-apply' ).on( 'click', function () {
			var bulkAction = String( $( '#sellmo-mapper-bulk-action' ).val() || '' );
			var selected   = [];

			if ( '' === bulkAction ) {
				// eslint-disable-next-line no-alert
				window.alert( i18n.mapperBulkHint || 'Please select a mapper bulk action.' );
				return;
			}

			$( '.sellmo-mapper-checkbox:checked' ).each( function () {
				var $row = $( this ).closest( 'tr' );
				selected.push( {
					sellmo_id   : String( $row.data( 'sellmo-id' ) || '' ),
					sellmo_code : String( $row.data( 'sellmo-code' ) || '' ),
					sellmo_name : String( $row.data( 'sellmo-name' ) || '' )
				} );
			} );

			if ( 0 === selected.length ) {
				// eslint-disable-next-line no-alert
				window.alert( i18n.mapperSelectRows || 'Please select at least one row.' );
				return;
			}

			$( '#sellmo-mapper-bulk-apply' ).prop( 'disabled', true );
			$( '.sellmo-mapper-bulk-spinner' ).addClass( 'is-active' );
			$( '#sellmo-mapper-bulk-result' ).text( '' ).removeClass( 'success error' );

			$.post( ajaxUrl, {
				action      : 'sellmo_wc_bulk_mapper',
				nonce       : nonce,
				bulk_action : bulkAction,
				rows        : selected
			} )
			.done( function ( res ) {
				if ( res.success ) {
					$( '#sellmo-mapper-bulk-result' ).addClass( 'success' ).text( ( res.data && res.data.message ) || '' );
					loadMapperList( mapperPage );
				} else {
					$( '#sellmo-mapper-bulk-result' )
						.addClass( 'error' )
						.text( ( res.data && res.data.message ) || ( i18n.mapperActionError || 'Mapping action failed.' ) );
				}
			} )
			.fail( function () {
				$( '#sellmo-mapper-bulk-result' )
					.addClass( 'error' )
					.text( i18n.mapperActionError || 'Mapping action failed. Please try again.' );
			} )
			.always( function () {
				$( '#sellmo-mapper-bulk-apply' ).prop( 'disabled', false );
				$( '.sellmo-mapper-bulk-spinner' ).removeClass( 'is-active' );
			} );
		} );

		function loadMapperList( page ) {
			if ( mapperLoading ) {
				return;
			}

			mapperLoading = true;
			mapperPage    = page;

			$( '#sellmo-mapper-loading' ).show();
			$( '#sellmo-mapper-table, #sellmo-mapper-pagination, #sellmo-mapper-top-pagination' ).hide();
			$( '#sellmo-mapper-error' ).hide();

			$.post( ajaxUrl, {
				action   : 'sellmo_wc_get_mapper_list',
				nonce    : nonce,
				page     : page,
				search   : mapperSearch,
				status   : mapperStatus,
				category : mapperCategory
			} )
			.done( function ( res ) {
				if ( ! res.success ) {
					showMapperError( ( res.data && res.data.message ) || ( i18n.mapperLoadError || 'Failed to load mapper data.' ) );
					return;
				}

				mapperWcProducts     = Array.isArray( res.data.wc_products )     ? res.data.wc_products     : [];
				mapperSellmoProducts = Array.isArray( res.data.sellmo_products ) ? res.data.sellmo_products : [];
				renderMapperRows( res.data );
			} )
			.fail( function () {
				showMapperError( i18n.mapperLoadError || 'Failed to load mapper data.' );
			} )
			.always( function () {
				mapperLoading = false;
				$( '#sellmo-mapper-loading' ).hide();
			} );
		}

		function renderMapperRows( data ) {
			var rows    = data.rows || [];
			var total   = parseInt( data.total || 0, 10 );
			var pages   = parseInt( data.pages || 1, 10 );
			var current = parseInt( data.current_page || 1, 10 );
			var perPage = parseInt( data.per_page || 25, 10 );

			// Update DOM Stats if provided by the backend
			if ( data.stats ) {
				$( '#stat-total-sellmo' ).text( data.stats.total_sellmo_products || 0 );
				$( '#stat-mapped' ).text( data.stats.mapped_products || 0 );
				$( '#stat-unmapped' ).text( data.stats.unmapped_products || 0 );
				$( '#stat-wc-only' ).text( data.stats.woocommerce_only_products || 0 );
			}

			var $tbody = $( '#sellmo-mapper-tbody' ).empty();

			if ( 0 === rows.length ) {
				$tbody.append( '<tr><td colspan="8" style="text-align:center;padding:24px 0;">' + escHtml( i18n.mapperNoRows || 'No products found.' ) + '</td></tr>' );
			} else {
				$.each( rows, function ( _idx, row ) {
					$tbody.append( buildMapperRow( row ) );
				} );
				$( document ).trigger( 'sellmo:mapper-rendered', [ mapperSellmoProducts ] );
			}

			var from = total > 0 ? ( ( current - 1 ) * perPage + 1 ) : 0;
			var to   = Math.min( current * perPage, total );
			var text = total > 0 ? ( from + '–' + to + ' ' + ( i18n.of || 'of' ) + ' ' + total ) : '0';

			$( '#sellmo-mapper-count, #sellmo-mapper-page-info' ).text( text );
			var pagination = buildMapperPagination( current, pages );
			$( '#sellmo-mapper-pagination-top, #sellmo-mapper-pagination-bottom' ).html( pagination );

			$( '#sellmo-mapper-table' ).show();
			if ( total > perPage ) {
				$( '#sellmo-mapper-pagination, #sellmo-mapper-top-pagination' ).show();
			}

			$( '#sellmo-mapper-select-all, .sellmo-mapper-select-all-foot' ).prop( 'checked', false );
		}

		function buildMapperRow( row ) {
			var sellmoId      = escHtml( String( row.sellmo_id || '' ) );
			var sellmoName    = escHtml( String( row.sellmo_name || '' ) );
			var sellmoCode    = escHtml( String( row.sellmo_code || '' ) );
			var category      = escHtml( String( row.category || '—' ) );
			var price         = parseFloat( row.price || 0 );
			var status        = String( row.status || 'not_mapped' );
			var wcProductId   = parseInt( row.wc_product_id || 0, 10 );
			var wcEditUrl     = String( row.wc_edit_url || '' );

			// WC-only row: WooCommerce product with no Sellmo mapping yet.
			if ( 'wc_only' === status ) {
				var wcOnlyLabel = escHtml( String( row.wc_product_label || '' ) );
				var wcOnlyEdit  = String( row.wc_edit_url || '' );

var sellmoSel = '<select class="sellmo-sellmo-select2 sellmo-mapper-sellmo-select" style="width:100%"></select>';

				var wcOnlyBadge   = '<span class="sellmo-result-badge wc-only">' + escHtml( i18n.woocommerceOnly || 'WC Only' ) + '</span>';
				var wcOnlyActions = '<button type="button" class="button button-small sellmo-mapper-map-btn">' + escHtml( i18n.map || 'Map' ) + '</button>';
				if ( wcOnlyEdit ) {
					wcOnlyActions += ' <a class="button button-small" target="_blank" href="' + escHtml( wcOnlyEdit ) + '">' + escHtml( i18n.viewInWC || 'View in WC' ) + '</a>';
				}

				return '<tr data-row-type="wc_only" data-wc-product-id="' + wcProductId + '">'
					+ '<th class="check-column"><input type="checkbox" class="sellmo-mapper-checkbox" disabled></th>'
					+ '<td class="sellmo-mapper-product-cell">' + sellmoSel + '</td>'
					+ '<td class="sellmo-mapper-code-cell">—</td>'
					+ '<td class="sellmo-mapper-category-cell">' + category + '</td>'
					+ '<td class="sellmo-mapper-price-cell">' + ( price > 0 ? escHtml( price.toFixed( 2 ) ) : '—' ) + '</td>'
					+ '<td class="sellmo-mapper-wc-cell"><strong>' + wcOnlyLabel + '</strong></td>'
					+ '<td class="sellmo-mapper-status-cell">' + wcOnlyBadge + '</td>'
					+ '<td class="sellmo-mapper-actions-cell"><div class="sellmo-mapper-actions-wrap">' + wcOnlyActions + '</div></td>'
					+ '</tr>';
			}

			// Out-of-shop row: product belongs to a shop other than the selected one.
			// It stays listed for reference but cannot be mapped or imported here.
			if ( false === row.in_selected_shop ) {
				var otherShopName = escHtml( String( row.shop_name || '' ) );
				var otherBadge    = '<span class="sellmo-result-badge skipped">'
					+ escHtml( i18n.anotherShop || 'Another shop' )
					+ ( otherShopName ? ' (' + otherShopName + ')' : '' )
					+ '</span>';

				return '<tr data-sellmo-id="' + sellmoId + '" data-sellmo-code="' + sellmoCode + '" data-sellmo-name="' + sellmoName + '">'
					+ '<th class="check-column"><input type="checkbox" class="sellmo-mapper-checkbox" disabled></th>'
					+ '<td class="sellmo-mapper-product-cell"><strong>' + sellmoName + '</strong></td>'
					+ '<td class="sellmo-mapper-code-cell"><code>' + sellmoCode + '</code></td>'
					+ '<td class="sellmo-mapper-category-cell">' + category + '</td>'
					+ '<td class="sellmo-mapper-price-cell">' + ( price > 0 ? escHtml( price.toFixed( 2 ) ) : '—' ) + '</td>'
					+ '<td class="sellmo-mapper-wc-cell">—</td>'
					+ '<td class="sellmo-mapper-status-cell">' + otherBadge + '</td>'
					+ '<td class="sellmo-mapper-actions-cell">—</td>'
					+ '</tr>';
			}

			// Build the WC product list for the custom searchable dropdown.
			// Start with a blank "—" option.
			var wcOptions = [ { id: 0, label: '—' } ];

			// Add all unmapped WC products (mapped ones are filtered server-side).
			$.each( mapperWcProducts, function ( _i, option ) {
				wcOptions.push( { id: parseInt( option.id || 0, 10 ), label: String( option.label || '' ) } );
			} );

			// Determine the currently selected WC product.
			// For a mapped row the server provides wc_product_id + wc_product_label so we
			// can show the correct label even though the mapped product may not appear in
			// the unmapped list.
			var selectedWcId    = wcProductId > 0 ? wcProductId : 0;
			var selectedWcLabel = '—';

			if ( selectedWcId > 0 ) {
				// Use the server-supplied label if available.
				var serverLabel = String( row.wc_product_label || '' );
				selectedWcLabel = serverLabel || ( '#' + selectedWcId );

				// Also make sure the mapped product exists in the options list so
				// the hidden <select> has a matching <option>.
				var alreadyInList = false;
				$.each( wcOptions, function ( _i, opt ) {
					if ( opt.id === selectedWcId ) {
						alreadyInList = true;
						selectedWcLabel = opt.label; // prefer the list label if present
						return false;
					}
				} );
				if ( ! alreadyInList ) {
					wcOptions.splice( 1, 0, { id: selectedWcId, label: selectedWcLabel } );
				}
			}

var wcOption = selectedWcId > 0
				? '<option value="' + selectedWcId + '" selected>' + escHtml( selectedWcLabel ) + '</option>'
				: '';
			var wcSelect = '<select class="sellmo-wc-select2 sellmo-mapper-wc-select" style="width:100%">' + wcOption + '</select>';

			var statusBadge = 'mapped' === status
				? '<span class="sellmo-result-badge success">' + escHtml( i18n.mapped || 'Mapped' ) + '</span>'
				: '<span class="sellmo-result-badge skipped">' + escHtml( i18n.notMapped || 'Not Mapped' ) + '</span>';

			var mappingId   = parseInt( row.mapping_id || 0, 10 );
			var variants    = Array.isArray( row.variants ) ? row.variants : [];
			var hasVariants = true === row.has_variants && variants.length > 0;

			var actionLabel = 'mapped' === status ? ( i18n.change || 'Change' ) : ( i18n.map || 'Map' );
			var actions     = '<button type="button" class="button button-small sellmo-mapper-map-btn">' + escHtml( actionLabel ) + '</button>';
			if ( 'mapped' === status ) {
				actions += ' <button type="button" class="button button-small sellmo-mapper-unmap-btn">' + escHtml( i18n.unmap || 'Unmap' ) + '</button>';
			} else if ( ! hasVariants ) {
				// Simple product: one click creates the WooCommerce product and maps it.
				actions += ' <button type="button" class="button button-small sellmo-mapper-import-btn">' + escHtml( i18n.importProduct || 'Import to WooCommerce' ) + '</button>';
			}
			// Variable product: import every in-shop variant as variations in one click
			// (shown for mapped parents too, so remaining variants can be added later).
			if ( hasVariants ) {
				actions += ' <button type="button" class="button button-small sellmo-mapper-import-all-btn">' + escHtml( i18n.importAll || 'Import all to WooCommerce' ) + '</button>';
			}
			if ( wcEditUrl ) {
				actions += ' <a class="button button-small" target="_blank" href="' + escHtml( wcEditUrl ) + '">' + escHtml( i18n.viewInWC || 'View in WC' ) + '</a>';
			}

			// Expander toggle in the product cell for variable products.
			var productCell = '<strong>' + sellmoName + '</strong>';
			if ( hasVariants ) {
				productCell = '<button type="button" class="button-link sellmo-variant-toggle" aria-expanded="false" title="' + escHtml( i18n.showVariants || 'Show variants' ) + '">'
					+ '<span class="sellmo-variant-caret dashicons dashicons-arrow-right-alt2"></span></button> '
					+ productCell
					+ ' <span class="sellmo-variant-count">(' + variants.length + ')</span>';
			}

			var parentTr = '<tr data-sellmo-id="' + sellmoId + '" data-sellmo-code="' + sellmoCode + '" data-sellmo-name="' + sellmoName + '" data-mapping-id="' + mappingId + '"' + ( hasVariants ? ' data-has-variants="1"' : '' ) + '>'
				+ '<th class="check-column"><input type="checkbox" class="sellmo-mapper-checkbox"></th>'
				+ '<td class="sellmo-mapper-product-cell">' + productCell + '</td>'
				+ '<td class="sellmo-mapper-code-cell"><code>' + sellmoCode + '</code></td>'
				+ '<td class="sellmo-mapper-category-cell">' + category + '</td>'
				+ '<td class="sellmo-mapper-price-cell">' + ( price > 0 ? escHtml( price.toFixed( 2 ) ) : '—' ) + '</td>'
				+ '<td class="sellmo-mapper-wc-cell">' + wcSelect + '</td>'
				+ '<td class="sellmo-mapper-status-cell">' + statusBadge + '</td>'
				+ '<td class="sellmo-mapper-actions-cell"><div class="sellmo-mapper-actions-wrap">' + actions + '</div></td>'
				+ '</tr>';

			if ( ! hasVariants ) {
				return parentTr;
			}

			var subRows = '';
			$.each( variants, function ( _i, v ) {
				subRows += buildVariantRow( v, mappingId );
			} );

			return parentTr + subRows;
		}

		// Build a single variant sub-row (hidden until its parent is expanded).
		function buildVariantRow( v, mappingId ) {
			var variantId  = escHtml( String( v.variant_id || '' ) );
			var code       = escHtml( String( v.code || '' ) );
			var options    = escHtml( String( v.options || '—' ) );
			var price      = parseFloat( v.price || 0 );
			var stock      = parseInt( v.stock || 0, 10 );
			var shopName   = escHtml( String( v.shop_name || '' ) );
			var status     = String( v.status || 'not_mapped' );
			var wcEditUrl  = String( v.wc_edit_url || '' );

			var badge = 'mapped' === status
				? '<span class="sellmo-result-badge success">' + escHtml( i18n.mapped || 'Mapped' ) + '</span>'
				: '<span class="sellmo-result-badge skipped">' + escHtml( i18n.notMapped || 'Not Mapped' ) + '</span>';

			// Map-to-existing dropdown (populated on demand by the select2 module).
			var wcSelect = '<select class="sellmo-variant-wc-select" style="width:100%"></select>';

			var actions = '<button type="button" class="button button-small sellmo-variant-map-btn">' + escHtml( 'mapped' === status ? ( i18n.change || 'Change' ) : ( i18n.map || 'Map' ) ) + '</button>';
			actions += ' <button type="button" class="button button-small sellmo-variant-import-btn">' + escHtml( i18n.importProduct || 'Import to WooCommerce' ) + '</button>';
			if ( wcEditUrl ) {
				actions += ' <a class="button button-small" target="_blank" href="' + escHtml( wcEditUrl ) + '">' + escHtml( i18n.viewInWC || 'View in WC' ) + '</a>';
			}

			return '<tr class="sellmo-variant-row" data-variant-id="' + variantId + '" data-variant-code="' + code + '" data-mapping-id="' + mappingId + '" style="display:none;">'
				+ '<th class="check-column"></th>'
				+ '<td class="sellmo-mapper-product-cell sellmo-variant-name-cell"><span class="sellmo-variant-branch">↳</span> ' + options + ( shopName ? ' <span class="sellmo-variant-shop">' + shopName + '</span>' : '' ) + '</td>'
				+ '<td class="sellmo-mapper-code-cell"><code>' + code + '</code></td>'
				+ '<td class="sellmo-mapper-category-cell">—</td>'
				+ '<td class="sellmo-mapper-price-cell">' + ( price > 0 ? escHtml( price.toFixed( 2 ) ) : '—' ) + '</td>'
				+ '<td class="sellmo-mapper-wc-cell">' + wcSelect + '</td>'
				+ '<td class="sellmo-mapper-status-cell">' + badge + '</td>'
				+ '<td class="sellmo-mapper-actions-cell"><div class="sellmo-mapper-actions-wrap">' + actions + '</div></td>'
				+ '</tr>';
		}

		function buildMapperPagination( currentPage, totalPages ) {
			if ( totalPages <= 1 ) {
				return '';
			}

			var html = '';
			var start = Math.max( 1, currentPage - 2 );
			var end = Math.min( totalPages, currentPage + 2 );

			if ( currentPage > 1 ) {
				html += '<a class="button sellmo-mapper-page-link" data-page="' + ( currentPage - 1 ) + '">‹</a> ';
			}

			if ( start > 1 ) {
				html += '<a class="button sellmo-mapper-page-link" data-page="1">1</a>';
				html += start > 2 ? ' <span class="sellmo-pag-ellipsis">…</span> ' : ' ';
			}

			for ( var p = start; p <= end; p++ ) {
				if ( p === currentPage ) {
					html += '<span class="button button-primary sellmo-page-current">' + p + '</span> ';
				} else {
					html += '<a class="button sellmo-mapper-page-link" data-page="' + p + '">' + p + '</a> ';
				}
			}

			if ( end < totalPages ) {
				html += end < ( totalPages - 1 ) ? '<span class="sellmo-pag-ellipsis">…</span> ' : '';
				html += '<a class="button sellmo-mapper-page-link" data-page="' + totalPages + '">' + totalPages + '</a>';
			}

			if ( currentPage < totalPages ) {
				html += ' <a class="button sellmo-mapper-page-link" data-page="' + ( currentPage + 1 ) + '">›</a>';
			}

			return html;
		}

		function showMapperError( message ) {
			$( '#sellmo-mapper-error-text' ).text( message );
			$( '#sellmo-mapper-error' ).show();
		}
	}

} )( jQuery );