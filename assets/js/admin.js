/* global lsbData, jQuery */
( function ( $, wp ) {
	'use strict';

	var dirtyCount  = 0;
	var activeField = 'h1';

	function updateDirtyCounter() {
		var $counter = $( '#lsb-dirty-count' );
		$counter.text( dirtyCount > 0 ? dirtyCount + ' ' + lsbData.i18n.unsaved : '' );
	}

	function isInputDirty( $input ) {
		return $input.val() !== $input.data( 'initial-value' );
	}

	function reconcileRowDirty( $row ) {
		var dirty = false;
		$row.find( '.lsb-value-input' ).each( function () {
			if ( isInputDirty( $( this ) ) ) { dirty = true; return false; }
		} );
		var wasDirty = $row.hasClass( 'lsb-dirty' );
		if ( dirty && ! wasDirty ) {
			$row.addClass( 'lsb-dirty' );
			dirtyCount++;
		} else if ( ! dirty && wasDirty ) {
			$row.removeClass( 'lsb-dirty' );
			dirtyCount = Math.max( 0, dirtyCount - 1 );
		}
	}

	$( function () {
		$( '.lsb-value-input' ).each( function () {
			$( this ).data( 'initial-value', $( this ).val() );
		} );
		$( '#lsb-save-all' ).data( 'original-text', $( '#lsb-save-all' ).text() );

		var $activeTab = $( '.lsb-field-tab.nav-tab-active' );
		if ( $activeTab.length ) {
			activeField = $activeTab.data( 'field' ) || 'h1';
		}
	} );

	// ---- Field tab switching (JS-only, no page reload) ----
	$( document ).on( 'click', '.lsb-field-tab', function ( e ) {
		e.preventDefault();
		var field = $( this ).data( 'field' );
		if ( ! field || field === activeField ) return;
		activeField = field;

		$( '.lsb-field-tab' ).removeClass( 'nav-tab-active' );
		$( this ).addClass( 'nav-tab-active' );

		$( '.lsb-field-panel' ).hide();
		$( '.lsb-field-panel[data-field="' + field + '"]' ).show();

		// Keep clear buttons in sync with the active field
		$( '.lsb-clear-row, .lsb-clear-network-row' ).data( 'field', field ).attr( 'data-field', field );

		if ( window.history && window.history.replaceState ) {
			var url = new URL( window.location.href );
			url.searchParams.set( 'lsb_tab', field );
			window.history.replaceState( {}, '', url.toString() );
		}
	} );

	// ---- Input change tracking ----
	$( document ).on( 'input', '.lsb-value-input', function () {
		reconcileRowDirty( $( this ).closest( 'tr' ) );
		updateDirtyCounter();
		if ( ! $( this ).hasClass( 'lsb-network-input' ) ) {
			schedulePreview( $( this ) );
		}
	} );

	// ---- Site-level save row (all fields for this row) ----
	$( document ).on( 'click', '.lsb-save-row', function () {
		var $btn       = $( this );
		var entityType = $btn.data( 'entity-type' );
		var entityId   = $btn.data( 'entity-id' );
		var $row       = $btn.closest( 'tr' );
		var $status    = $row.find( '.lsb-row-status' );
		var rows       = [];

		$row.find( '.lsb-value-input' ).each( function () {
			rows.push( {
				field:       $( this ).data( 'field' ),
				entity_type: entityType,
				entity_id:   entityId,
				value:       $( this ).val(),
			} );
		} );

		$btn.prop( 'disabled', true );
		$status.removeClass( 'success error' ).html( '<span class="lsb-spinner"></span>' );

		$.post( lsbData.ajaxUrl, {
			action: 'lsb_save_all',
			nonce:  lsbData.nonce,
			rows:   rows,
		} ).done( function ( response ) {
			if ( response.success ) {
				$status.addClass( 'success' ).text( lsbData.i18n.saved );
				$row.find( '.lsb-value-input' ).each( function () {
					$( this ).data( 'initial-value', $( this ).val() );
				} );
				$row.removeClass( 'lsb-dirty' );
				dirtyCount = Math.max( 0, dirtyCount - 1 );
				updateDirtyCounter();
				var $activeInput = $row.find( '.lsb-field-panel[data-field="' + activeField + '"] .lsb-value-input' );
				if ( $activeInput.length && $activeInput.val() ) {
					fetchPreview( $activeInput );
				}
			} else {
				$status.addClass( 'error' ).text( lsbData.i18n.error );
			}
		} ).fail( function () {
			$status.addClass( 'error' ).text( lsbData.i18n.error );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// ---- Network-level save row (all fields for this row) ----
	$( document ).on( 'click', '.lsb-save-network-row', function () {
		var $btn    = $( this );
		var scope   = $btn.data( 'scope' );
		var slug    = $btn.data( 'slug' );
		var $row    = $btn.closest( 'tr' );
		var $status = $row.find( '.lsb-row-status' );
		var rows    = [];

		$row.find( '.lsb-network-input' ).each( function () {
			rows.push( {
				scope_id: scope,
				slug:     slug,
				field:    $( this ).data( 'field' ),
				value:    $( this ).val(),
			} );
		} );

		$btn.prop( 'disabled', true );
		$status.removeClass( 'success error' ).html( '<span class="lsb-spinner"></span>' );

		$.post( lsbData.ajaxUrl, {
			action: 'lsb_save_network_all',
			nonce:  lsbData.nonce,
			rows:   rows,
		} ).done( function ( response ) {
			if ( response.success ) {
				$status.addClass( 'success' ).text( lsbData.i18n.saved );
				$row.find( '.lsb-network-input' ).each( function () {
					$( this ).data( 'initial-value', $( this ).val() );
				} );
				$row.removeClass( 'lsb-dirty' );
				dirtyCount = Math.max( 0, dirtyCount - 1 );
				updateDirtyCounter();
			} else {
				$status.addClass( 'error' ).text( lsbData.i18n.error );
			}
		} ).fail( function () {
			$status.addClass( 'error' ).text( lsbData.i18n.error );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// ---- Clear site row (active field only) ----
	$( document ).on( 'click', '.lsb-clear-row', function () {
		var $btn   = $( this );
		var field  = $btn.data( 'field' );
		var $row   = $btn.closest( 'tr' );
		var $input = $row.find( '.lsb-value-input[data-field="' + field + '"]' );
		$input.val( '' );
		$row.find( '.lsb-field-panel[data-field="' + field + '"] .lsb-preview' ).text( '' );
		reconcileRowDirty( $row );
		updateDirtyCounter();
	} );

	// ---- Clear network row (active field only via AJAX) ----
	$( document ).on( 'click', '.lsb-clear-network-row', function () {
		var $btn    = $( this );
		var scope   = $btn.data( 'scope' );
		var slug    = $btn.data( 'slug' );
		var field   = $btn.data( 'field' );
		var $row    = $btn.closest( 'tr' );
		var $input  = $row.find( '.lsb-network-input[data-field="' + field + '"]' );
		var $status = $row.find( '.lsb-row-status' );

		$btn.prop( 'disabled', true );
		$status.removeClass( 'success error' ).html( '<span class="lsb-spinner"></span>' );

		$.post( lsbData.ajaxUrl, {
			action:   'lsb_save_network_row',
			nonce:    lsbData.nonce,
			scope_id: scope,
			slug:     slug,
			field:    field,
			value:    '',
		} ).done( function ( response ) {
			if ( response.success ) {
				$input.val( '' ).data( 'initial-value', '' );
				reconcileRowDirty( $row );
				updateDirtyCounter();
				$status.addClass( 'success' ).text( lsbData.i18n.saved );
			} else {
				$status.addClass( 'error' ).text( lsbData.i18n.error );
			}
		} ).fail( function () {
			$status.addClass( 'error' ).text( lsbData.i18n.error );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// ---- Save all dirty inputs (all fields, all rows) ----
	$( document ).on( 'click', '#lsb-save-all', function () {
		var $btn        = $( this );
		var siteRows    = [];
		var networkRows = [];

		$( '.lsb-value-input' ).each( function () {
			var $input = $( this );
			if ( ! isInputDirty( $input ) ) return;
			if ( $input.hasClass( 'lsb-network-input' ) ) {
				networkRows.push( {
					scope_id: $input.data( 'scope' ),
					slug:     $input.data( 'slug' ),
					field:    $input.data( 'field' ),
					value:    $input.val(),
				} );
			} else {
				siteRows.push( {
					field:       $input.data( 'field' ),
					entity_type: $input.data( 'entity-type' ),
					entity_id:   $input.data( 'entity-id' ),
					value:       $input.val(),
				} );
			}
		} );

		if ( ! siteRows.length && ! networkRows.length ) return;

		$btn.prop( 'disabled', true ).text( lsbData.i18n.saving );

		var done  = 0;
		var total = ( siteRows.length ? 1 : 0 ) + ( networkRows.length ? 1 : 0 );

		function finish() {
			done++;
			if ( done >= total ) {
				$( '.lsb-dirty' ).removeClass( 'lsb-dirty' );
				dirtyCount = 0;
				updateDirtyCounter();
				$( '.lsb-value-input' ).each( function () {
					$( this ).data( 'initial-value', $( this ).val() );
				} );
				$btn.prop( 'disabled', false ).text( $btn.data( 'original-text' ) || lsbData.i18n.saved );
			}
		}

		if ( siteRows.length ) {
			$.post( lsbData.ajaxUrl, { action: 'lsb_save_all', nonce: lsbData.nonce, rows: siteRows } ).always( finish );
		}
		if ( networkRows.length ) {
			$.post( lsbData.ajaxUrl, { action: 'lsb_save_network_all', nonce: lsbData.nonce, rows: networkRows } ).always( finish );
		}
	} );

	// ---- Token preview ----
	var previewTimers = {};

	function schedulePreview( $input ) {
		var key = $input.data( 'entity-type' ) + '-' + $input.data( 'entity-id' ) + '-' + $input.data( 'field' );
		clearTimeout( previewTimers[ key ] );
		previewTimers[ key ] = setTimeout( function () {
			fetchPreview( $input );
		}, 500 );
	}

	function fetchPreview( $input ) {
		var value = $input.val();
		var $row  = $input.closest( 'tr' );
		var field = $input.data( 'field' );
		if ( ! value.trim() ) {
			$row.find( '.lsb-field-panel[data-field="' + field + '"] .lsb-preview' ).text( '' );
			return;
		}
		$.post( lsbData.ajaxUrl, {
			action: 'lsb_preview_token',
			nonce:  lsbData.nonce,
			value:  value,
		} ).done( function ( response ) {
			if ( response.success ) {
				$row.find( '.lsb-field-panel[data-field="' + field + '"] .lsb-preview' ).text( response.data.resolved );
			}
		} );
	}

	// ---- Force H1 dynamic sync with "Types actifs" ----
	$( document ).on( 'change', 'input[name="lsb_editor_types[post_types][]"], input[name="lsb_editor_types[taxonomies][]"]', function () {
		var typeSlug   = $( this ).val();
		var $forceItem = $( '#lsb-force-h1-card .lsb-force-h1-item[data-type="' + typeSlug + '"]' );
		if ( $( this ).is( ':checked' ) ) {
			$forceItem.show();
		} else {
			$forceItem.hide();
			$forceItem.find( 'input[type="checkbox"]' ).prop( 'checked', false );
		}
	} );

}( jQuery, wp ) );
