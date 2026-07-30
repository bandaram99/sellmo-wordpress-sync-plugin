/**
 * Select2 (selectWoo) dropdowns for the Sellmo Product Mapper.
 *
 * .sellmo-wc-select2     -> searches WooCommerce products via AJAX (title / SKU).
 * .sellmo-sellmo-select2 -> uses the Sellmo product list already loaded with the
 *                           mapper table (no extra AJAX — the staging table is not
 *                           the data source).
 *
 * Re-runs after every mapper table re-render via the custom 'sellmo:mapper-rendered'
 * event. The event carries the current unmapped Sellmo products as its second argument.
 *
 * Requires: jQuery, selectWoo (bundled with WooCommerce Admin), and the
 * sellmoMapperSelect2 object localised by Sellmo_Mapper_Ajax::enqueue_assets().
 *
 * @package Sellmo_WooCommerce
 * @since   1.1.0
 */
( function ( $ ) {
	'use strict';

	if ( 'undefined' === typeof window.sellmoMapperSelect2 ) {
		return;
	}

	var cfg   = window.sellmoMapperSelect2;
	var i18n  = cfg.i18n || {};
	var $fn   = $.fn.selectWoo ? 'selectWoo' : ( $.fn.select2 ? 'select2' : null );

	if ( ! $fn ) {
		return;
	}

	// Sellmo products passed via the 'sellmo:mapper-rendered' event.
	var mapperSellmoData = [];

	/**
	 * Build the Select2 AJAX config for WooCommerce product search.
	 *
	 * @param {string} action      AJAX action name.
	 * @param {string} placeholder Placeholder text.
	 * @return {Object} selectWoo options object.
	 */
	function ajaxConfig( action, placeholder ) {
		return {
			width              : '100%',
			allowClear         : true,
			placeholder        : placeholder,
			minimumInputLength : 0,
			language           : {
				noResults : function () {
					return i18n.noResults || 'No matches found';
				}
			},
			dropdownParent     : $( document.body ),
			ajax : {
				url            : cfg.ajaxUrl,
				type           : 'POST',
				dataType       : 'json',
				delay          : 250,
				cache          : true,
				data           : function ( params ) {
					return {
						action : action,
						nonce  : cfg.nonce,
						q      : params.term || '',
						page   : params.page || 1
					};
				},
				processResults : function ( data, params ) {
					params.page = params.page || 1;
					return {
						results    : ( data && data.results ) || [],
						pagination : {
							more : !! ( data && data.pagination && data.pagination.more )
						}
					};
				}
			}
		};
	}

	/**
	 * Initialise every uninitialised Select2 dropdown in the mapper table.
	 */
	function initDropdowns() {
		// WooCommerce product column — AJAX search.
		$( '.sellmo-wc-select2' ).each( function () {
			var $el = $( this );
			if ( $el.data( 'sellmo-s2' ) ) {
				return;
			}
			$el[ $fn ]( ajaxConfig(
				'sellmo_wc_search_wc_products',
				i18n.searchWc || 'Search WooCommerce product by name or SKU…'
			) );
			$el.data( 'sellmo-s2', true );
		} );

		// Sellmo product column (WC-only rows) — local data already loaded with the table.
		$( '.sellmo-sellmo-select2' ).each( function () {
			var $el = $( this );
			if ( $el.data( 'sellmo-s2' ) ) {
				return;
			}

			// The empty sentinel is required by selectWoo/Select2 for allowClear + placeholder
			// to work correctly with local data (prevents auto-selection of first item).
			var localItems = [ { id: '', text: '' } ].concat(
				mapperSellmoData.map( function ( item ) {
					var code = String( item.code || '' );
					var name = String( item.name || '' );
					return {
						id   : String( item.id   || '' ),
						text : name + ( code ? ' (' + code + ')' : '' ),
						code : code,
						name : name
					};
				} )
			);

			$el[ $fn ]( {
				width          : '100%',
				allowClear     : true,
				placeholder    : i18n.searchSellmo || 'Search Sellmo product by name or code…',
				data           : localItems,
				dropdownParent : $( document.body ),
				language       : {
					noResults : function () {
						return i18n.noResults || 'No matches found';
					}
				}
			} );
			$el.data( 'sellmo-s2', true );

			// Write the selected item's code + name onto the row so the Map handler
			// can read them from data attributes rather than from option attributes.
			$el.on( 'select2:select', function ( e ) {
				var d    = e.params.data || {};
				var $row = $el.closest( 'tr' );
				$row.attr( 'data-sellmo-id',   d.id   || '' );
				$row.attr( 'data-sellmo-code', d.code || '' );
				$row.attr( 'data-sellmo-name', d.name || '' );
			} );
		} );
	}

	/**
	 * Frontend validation: block a Map click when the required side is empty.
	 */
	function bindValidation() {
		$( document ).on( 'click.sellmoMapValidate', '.sellmo-mapper-map-btn', function ( e ) {
			var $row    = $( this ).closest( 'tr' );
			var rowType = String( $row.data( 'row-type' ) || 'sellmo' );

			if ( 'wc_only' === rowType ) {
				var sellmoVal = $row.find( '.sellmo-sellmo-select2' ).val();
				if ( ! sellmoVal ) {
					e.preventDefault();
					e.stopImmediatePropagation();
					// eslint-disable-next-line no-alert
					window.alert( i18n.searchSellmo || 'Select a Sellmo product first.' );
				}
				return;
			}

			var wcVal = parseInt( $row.find( '.sellmo-wc-select2' ).val() || 0, 10 );
			if ( ! wcVal ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				// eslint-disable-next-line no-alert
				window.alert( i18n.searchWc || 'Select a WooCommerce product first.' );
			}
		} );
	}

	$( function () {
		initDropdowns();
		bindValidation();

		// Re-init after every mapper table re-render. The event carries the current
		// unmapped Sellmo products list so the local dropdown stays up to date.
		$( document ).on( 'sellmo:mapper-rendered', function ( _event, sellmoProducts ) {
			mapperSellmoData = Array.isArray( sellmoProducts ) ? sellmoProducts : [];
			initDropdowns();
		} );
	} );

} )( jQuery );
