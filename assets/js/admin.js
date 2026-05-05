/* global lsbData, jQuery */
( function ( $ ) {
	'use strict';

	var activeField = 'h1';

	function updateDirtyCounter() {
		var count = 0;
		$( '.lsb-value-input' ).each( function () {
			if ( isInputDirty( $( this ) ) ) count++;
		} );
		$( '#lsb-dirty-count' ).text( count > 0 ? count + ' ' + lsbData.i18n.unsaved : '' );
	}

	function isInputDirty( $input ) {
		return $input.val() !== $input.data( 'initial-value' );
	}

	function reconcileRowDirty( $row ) {
		var dirty = false;
		$row.find( '.lsb-value-input' ).each( function () {
			if ( isInputDirty( $( this ) ) ) { dirty = true; return false; }
		} );
		$row.toggleClass( 'lsb-dirty', dirty );
	}

	function showAdminNotice( message, type ) {
		$( '.lsb-save-all-notice' ).remove();
		var $notice = $( '<div class="notice notice-' + ( type || 'success' ) + ' is-dismissible lsb-save-all-notice"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">' + lsbData.i18n.dismiss + '</span></button></div>' );
		$( '.wp-header-end' ).after( $notice );
		$notice.on( 'click', '.notice-dismiss', function () { $notice.fadeOut( 200, function () { $notice.remove(); } ); } );
		setTimeout( function () { $notice.fadeOut( 400, function () { $notice.remove(); } ); }, 5000 );
	}

	// ---- Shared helpers ----

	function resetInitialValue( $inputs, value ) {
		$inputs.each( function () {
			$( this ).data( 'initial-value', value !== undefined ? value : $( this ).val() );
		} );
	}

	function setRowStatus( $status, state, msg ) {
		$status.removeClass( 'success error' );
		if ( state === 'loading' ) {
			$status.html( '<span class="lsb-spinner"></span>' );
		} else {
			$status.addClass( state ).text( msg );
		}
	}

	function importSummary( resp ) {
		return 'Importé : ' + resp.data.imported + ' — Ignoré : ' + resp.data.skipped;
	}

	function closeDialogAndNotify( dialogSel, msg ) {
		setTimeout( function () {
			if ( dialogSel ) { $( dialogSel ).hide(); }
			showAdminNotice( msg, 'success' );
		}, 500 );
	}

	function patchInputRows( rows, buildSelector ) {
		var missed = 0;
		$.each( rows || [], function ( _, row ) {
			$.each( row.fields, function ( field, val ) {
				if ( val === '' ) return;
				var $input = $( buildSelector( row, field ) );
				if ( $input.length ) {
					$input.val( val ).data( 'initial-value', val );
					$input.closest( 'tr' ).removeClass( 'lsb-dirty' );
				} else { missed++; }
			} );
		} );
		return missed;
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

	// =====================================================================
	// Section 7: Unified row save
	// Handles .lsb-save-row, .lsb-save-network-row, .lsb-save-address-row
	// Action name is read from data-action on the button.
	// =====================================================================
	function collectRowPayload( $row, $btn, action ) {
		var payload = { action: action, nonce: lsbData.nonce };
		if ( 'lsb_save_all' === action ) {
			var entityType = $btn.data( 'entity-type' );
			var entityId   = $btn.data( 'entity-id' );
			var rows       = [];
			$row.find( '.lsb-value-input' ).each( function () {
				rows.push( {
					field:       $( this ).data( 'field' ),
					entity_type: entityType,
					entity_id:   entityId,
					value:       $( this ).val(),
				} );
			} );
			payload.rows = rows;
		} else if ( 'lsb_save_network_all' === action ) {
			var scope = $btn.data( 'scope' );
			var slug  = $btn.data( 'slug' );
			var rows  = [];
			$row.find( '.lsb-network-input' ).each( function () {
				rows.push( {
					scope_id: scope,
					slug:     slug,
					field:    $( this ).data( 'field' ),
					value:    $( this ).val(),
				} );
			} );
			payload.rows = rows;
		} else if ( 'lsb_save_network_address_row' === action ) {
			payload.blog_id = $btn.data( 'blog-id' );
			$row.find( '.lsb-address-input' ).each( function () {
				payload[ $( this ).data( 'field' ) ] = $( this ).val();
			} );
		}
		return payload;
	}

	$( document ).on( 'click', '.lsb-save-row, .lsb-save-network-row, .lsb-save-address-row', function () {
		var $btn    = $( this );
		var $row    = $btn.closest( 'tr' );
		var action  = $btn.data( 'action' );
		var $status = $row.find( '.lsb-row-status' );
		var payload = collectRowPayload( $row, $btn, action );

		$btn.prop( 'disabled', true );
		setRowStatus( $status, 'loading' );

		$.post( lsbData.ajaxUrl, payload ).done( function ( response ) {
			if ( response.success ) {
				setRowStatus( $status, 'success', lsbData.i18n.saved );
				resetInitialValue( $row.find( '.lsb-value-input, .lsb-network-input, .lsb-address-input' ) );
				$row.removeClass( 'lsb-dirty' );
				updateDirtyCounter();
				if ( 'lsb_save_all' === action ) {
					var $activeInput = $row.find( '.lsb-field-panel[data-field="' + activeField + '"] .lsb-value-input' );
					if ( $activeInput.length && $activeInput.val() ) {
						fetchPreview( $activeInput );
					}
				}
			} else {
				setRowStatus( $status, 'error', lsbData.i18n.error );
			}
		} ).fail( function () {
			setRowStatus( $status, 'error', lsbData.i18n.error );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// =====================================================================
	// Section 8: Unified row clear
	// Handles .lsb-clear-row, .lsb-clear-network-row, .lsb-clear-address-row
	// Action name is read from data-action on the button.
	// =====================================================================
	function clearRowPayload( $row, $btn, action ) {
		var payload = { action: action, nonce: lsbData.nonce };
		if ( 'lsb_save_all' === action ) {
			var field = $btn.data( 'field' );
			payload.rows = [ {
				field:       field,
				entity_type: $btn.data( 'entity-type' ),
				entity_id:   $btn.data( 'entity-id' ),
				value:       '',
			} ];
		} else if ( 'lsb_save_network_row' === action ) {
			payload.scope_id = $btn.data( 'scope' );
			payload.slug     = $btn.data( 'slug' );
			payload.field    = $btn.data( 'field' );
			payload.value    = '';
		} else if ( 'lsb_save_network_address_row' === action ) {
			payload.blog_id     = $btn.data( 'blog-id' );
			payload.ville       = '';
			payload.code_postal = '';
			payload.adresse     = '';
			payload.departement = '';
		}
		return payload;
	}

	$( document ).on( 'click', '.lsb-clear-row, .lsb-clear-network-row, .lsb-clear-address-row', function () {
		var $btn    = $( this );
		var $row    = $btn.closest( 'tr' );
		var action  = $btn.data( 'action' );
		var $status = $row.find( '.lsb-row-status' );
		var payload = clearRowPayload( $row, $btn, action );

		$btn.prop( 'disabled', true );
		setRowStatus( $status, 'loading' );

		$.post( lsbData.ajaxUrl, payload ).done( function ( response ) {
			if ( response.success ) {
				if ( 'lsb_save_all' === action ) {
					var field  = $btn.data( 'field' );
					var $input = $row.find( '.lsb-value-input[data-field="' + field + '"]' );
					$input.val( '' );
					resetInitialValue( $input, '' );
					$row.find( '.lsb-field-panel[data-field="' + field + '"] .lsb-preview' ).text( '' );
				} else if ( 'lsb_save_network_row' === action ) {
					var field  = payload.field;
					var $input = $row.find( '.lsb-network-input[data-field="' + field + '"]' );
					$input.val( '' );
					resetInitialValue( $input, '' );
				} else if ( 'lsb_save_network_address_row' === action ) {
					var $inputs = $row.find( '.lsb-address-input' );
					$inputs.val( '' );
					resetInitialValue( $inputs, '' );
					$row.removeClass( 'lsb-dirty' );
				}
				reconcileRowDirty( $row );
				updateDirtyCounter();
				setRowStatus( $status, 'success', lsbData.i18n.saved );
				showAdminNotice( lsbData.i18n.saved, 'success' );
			} else {
				setRowStatus( $status, 'error', lsbData.i18n.error );
			}
		} ).fail( function () {
			setRowStatus( $status, 'error', lsbData.i18n.error );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// =====================================================================
	// Section 9: Unified bulk clear
	// Site bulk clear intercepts form submit; network + address share one click handler.
	// =====================================================================

	// Site-level: intercept WP bulk action form submit
	$( document ).on( 'submit', '#lsb-editor-form', function ( e ) {
		var top    = $( 'select[name="action"]' ).val()  || '-1';
		var bottom = $( 'select[name="action2"]' ).val() || '-1';
		var action = top !== '-1' ? top : bottom;
		if ( action !== 'lsb_bulk_clear' ) return;
		e.preventDefault();
		$( 'input[name="lsb_item[]"]:checked' ).closest( 'tr' ).each( function () {
			var $row = $( this );
			$row.find( '.lsb-value-input[data-field="' + activeField + '"]' ).val( '' );
			$row.find( '.lsb-field-panel[data-field="' + activeField + '"] .lsb-preview' ).text( '' );
			reconcileRowDirty( $row );
		} );
		$( 'input[name="lsb_item[]"]' ).prop( 'checked', false );
		$( '#cb-select-all-1, #cb-select-all-2' ).prop( 'checked', false );
		updateDirtyCounter();
		$( '#lsb-save-all' ).trigger( 'click' );
	} );

	// Network-level + address-level: click on apply button
	$( document ).on( 'click', '#lsb-bulk-apply-net, #lsb-address-bulk-apply', function () {
		var isNet     = $( this ).is( '#lsb-bulk-apply-net' );
		var actionVal = isNet
			? $( '#lsb-bulk-action-net' ).val()
			: $( '#lsb-address-bulk-action' ).val();
		var expected  = isNet ? 'lsb_bulk_clear' : 'lsb_address_bulk_clear';
		if ( actionVal !== expected ) return;

		if ( isNet ) {
			$( 'input[name="lsb_net_item[]"]:checked' ).closest( 'tr' ).each( function () {
				var $row = $( this );
				$row.find( '.lsb-network-input[data-field="' + activeField + '"]' ).val( '' );
				reconcileRowDirty( $row );
			} );
			$( 'input[name="lsb_net_item[]"]' ).prop( 'checked', false );
			$( '#cb-select-all-net' ).prop( 'checked', false );
		} else {
			$( 'input.lsb-address-cb:checked' ).closest( 'tr' ).each( function () {
				var $row = $( this );
				$row.find( '.lsb-address-input' ).val( '' );
				reconcileRowDirty( $row );
			} );
			$( 'input.lsb-address-cb' ).prop( 'checked', false );
			$( '#cb-select-all-address' ).prop( 'checked', false );
		}
		updateDirtyCounter();
		$( '#lsb-save-all' ).trigger( 'click' );
	} );

	// ---- Select all — network-level ----
	$( document ).on( 'change', '#cb-select-all-net', function () {
		$( 'input[name="lsb_net_item[]"]' ).prop( 'checked', $( this ).is( ':checked' ) );
	} );

	// ---- Save all dirty inputs (site rows, network rows, address rows) ----
	$( document ).on( 'click', '#lsb-save-all', function () {
		var $btn         = $( this );
		var siteRows     = [];
		var networkRows  = [];
		var addressBlogIds = {};

		$( '.lsb-value-input' ).each( function () {
			var $input = $( this );
			if ( ! isInputDirty( $input ) ) return;
			if ( $input.hasClass( 'lsb-address-input' ) ) {
				addressBlogIds[ $input.data( 'blog-id' ) ] = true;
			} else if ( $input.hasClass( 'lsb-network-input' ) ) {
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

		var addressRows = [];
		$.each( addressBlogIds, function ( blogId ) {
			var row = { blog_id: blogId };
			$( '.lsb-address-input[data-blog-id="' + blogId + '"]' ).each( function () {
				row[ $( this ).data( 'field' ) ] = $( this ).val();
			} );
			addressRows.push( row );
		} );

		if ( ! siteRows.length && ! networkRows.length && ! addressRows.length ) return;

		$btn.prop( 'disabled', true ).text( lsbData.i18n.saving );

		var done   = 0;
		var errors = 0;
		var total  = ( siteRows.length ? 1 : 0 ) + ( networkRows.length ? 1 : 0 ) + ( addressRows.length ? 1 : 0 );

		function finish( success ) {
			if ( ! success ) errors++;
			done++;
			if ( done >= total ) {
				if ( errors === 0 ) {
					resetInitialValue( $( '.lsb-value-input' ) );
					$( '.lsb-dirty' ).removeClass( 'lsb-dirty' );
					updateDirtyCounter();
				}
				$btn.prop( 'disabled', false ).text( $btn.data( 'original-text' ) || lsbData.i18n.saved );
				showAdminNotice(
					errors === 0 ? lsbData.i18n.saveAllOk : lsbData.i18n.saveAllError,
					errors === 0 ? 'success' : 'error'
				);
			}
		}

		if ( siteRows.length ) {
			$.post( lsbData.ajaxUrl, { action: 'lsb_save_all', nonce: lsbData.nonce, rows: siteRows } )
				.done( function ( r ) { finish( r && r.success ); } )
				.fail( function () { finish( false ); } );
		}
		if ( networkRows.length ) {
			$.post( lsbData.ajaxUrl, { action: 'lsb_save_network_all', nonce: lsbData.nonce, rows: networkRows } )
				.done( function ( r ) { finish( r && r.success ); } )
				.fail( function () { finish( false ); } );
		}
		if ( addressRows.length ) {
			$.post( lsbData.ajaxUrl, { action: 'lsb_save_network_address_all', nonce: lsbData.nonce, rows: addressRows } )
				.done( function ( r ) { finish( r && r.success ); } )
				.fail( function () { finish( false ); } );
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

	// ---- Address page: select all checkbox ----
	$( document ).on( 'change', '#cb-select-all-address', function () {
		$( 'input.lsb-address-cb' ).prop( 'checked', $( this ).is( ':checked' ) );
	} );

	// =====================================================================
	// Section 10: Panel / dialog management
	// =====================================================================
	$( document ).on( 'click', '.lsb-panel-toggle', function ( e ) {
		e.preventDefault();
		var $btn      = $( this );
		var target    = $btn.data( 'target' );
		var exclusive = $btn.data( 'exclusive' );
		if ( exclusive ) {
			$( exclusive ).hide();
		}
		$( target ).toggle();
	} );

	$( document ).on( 'click', '.lsb-panel-close', function ( e ) {
		e.preventDefault();
		var target = $( this ).data( 'target' );
		$( target ).hide();
	} );

	// =====================================================================
	// Section 11: Generic CSV import handler
	// All import buttons use class .lsb-do-import.
	// Behavior driven by data-attributes:
	//   data-action          — AJAX action name
	//   data-file-field      — selector for <input type="file">
	//   data-object-field    — selector for optional object <select> (site-level only)
	//   data-nonce-field     — selector for hidden nonce input
	//   data-result          — selector for result <p>
	//   data-reload-on-success="1" — reload when resp.data.imported > 0
	//   data-reload-on-missed="1"  — reload only when DOM patch misses rows
	//   data-patch-rows="1"        — patch network input cells inline after import
	// =====================================================================
	$( document ).on( 'click', '.lsb-do-import', function () {
		var $btn            = $( this );
		var action          = $btn.data( 'action' );
		var fileField       = $btn.data( 'file-field' );
		var objectField     = $btn.data( 'object-field' );
		var nonceField      = $btn.data( 'nonce-field' );
		var resultEl        = $btn.data( 'result' );
		var reloadOnSuccess = $btn.data( 'reload-on-success' );
		var reloadOnMissed  = $btn.data( 'reload-on-missed' );
		var patchRows       = $btn.data( 'patch-rows' );

		var file = $( fileField )[ 0 ].files[ 0 ];
		if ( ! file ) {
			window.alert( $btn.data( 'empty-msg' ) || 'Veuillez sélectionner un fichier CSV.' );
			return;
		}

		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', $( nonceField ).val() );
		if ( objectField ) {
			fd.append( 'lsb_object', $( objectField ).val() );
		}
		fd.append( 'lsb_csv', file );

		$btn.prop( 'disabled', true );

		$.ajax( {
			url:         lsbData.ajaxUrl,
			type:        'POST',
			data:        fd,
			processData: false,
			contentType: false,
		} ).done( function ( resp ) {
			if ( resp.success ) {
				var msg = 'Importé : ' + resp.data.imported + ' — Ignoré : ' + resp.data.skipped;
				if ( resp.data.errors && resp.data.errors.length ) {
					msg += '\n' + resp.data.errors.slice( 0, 5 ).join( '\n' );
					if ( resp.data.errors.length > 5 ) {
						msg += '\n…et ' + ( resp.data.errors.length - 5 ) + ' autres.';
					}
				}
				$( resultEl ).css( 'white-space', 'pre-line' ).text( msg );

				if ( patchRows ) {
					// Network scope import: patch inline, reload only when some rows missed
					var missed = patchInputRows( resp.data.rows, function ( row, field ) {
						return '.lsb-network-input[data-scope="' + row.scope_id + '"][data-slug="' + row.slug + '"][data-field="' + field + '"]';
					} );
					if ( reloadOnMissed && missed > 0 ) {
						setTimeout( function () { location.reload(); }, 600 );
					} else if ( resp.data.imported > 0 ) {
						closeDialogAndNotify( $btn.data( 'dialog' ), importSummary( resp ) );
					}
				} else if ( $btn.data( 'patch-site-rows' ) ) {
					// Site-level import: patch inline, reload if any row missed
					var missed = patchInputRows( resp.data.rows, function ( row, field ) {
						return '.lsb-value-input[data-entity-type="' + row.entity_type + '"][data-entity-id="' + row.entity_id + '"][data-field="' + field + '"]';
					} );
					if ( missed > 0 ) {
						setTimeout( function () { location.reload(); }, 600 );
					} else {
						closeDialogAndNotify( $btn.data( 'dialog' ), importSummary( resp ) );
					}
				} else if ( reloadOnSuccess && resp.data.imported > 0 ) {
					// Address import: flat DOM patch, reload only if no rows returned
					if ( resp.data.rows && resp.data.rows.length ) {
						$.each( resp.data.rows, function ( _, row ) {
							$.each( [ 'ville', 'code_postal', 'adresse', 'departement' ], function ( _, field ) {
								var $input = $( '.lsb-address-input[data-blog-id="' + row.blog_id + '"][data-field="' + field + '"]' );
								if ( $input.length ) {
									$input.val( row[ field ] ).data( 'initial-value', row[ field ] );
									$input.closest( 'tr' ).removeClass( 'lsb-dirty' );
								}
							} );
						} );
						closeDialogAndNotify( $btn.data( 'dialog' ), importSummary( resp ) );
					} else {
						setTimeout( function () { location.reload(); }, 800 );
					}
				}
			} else {
				$( resultEl ).text( resp.data.message || 'Erreur.' );
			}
		} ).fail( function () {
			$( resultEl ).text( 'Erreur réseau.' );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// =====================================================================
	// Section 12: Address prefill handler (lsb_prefill_network_addresses)
	// =====================================================================
	$( document ).on( 'click', '.lsb-do-address-prefill', function () {
		var $btn       = $( this );
		var action     = $btn.data( 'action' );
		var nonceField = $btn.data( 'nonce-field' );
		var acfField   = $btn.data( 'acf-field' );
		var resultEl   = $btn.data( 'result' );
		var confirmMsg = $btn.data( 'confirm' );

		if ( confirmMsg && ! confirm( confirmMsg ) ) return;

		$btn.prop( 'disabled', true );

		$.post( lsbData.ajaxUrl, {
			action:    action,
			nonce:     $( nonceField ).val(),
			acf_field: $( acfField ).val(),
		} ).done( function ( r ) {
			if ( r.success ) {
				$( resultEl ).text( 'Sites remplis : ' + r.data.filled );
				if ( r.data.filled > 0 ) {
					setTimeout( function () { location.reload(); }, 800 );
				}
			} else {
				$( resultEl ).text( r.data.message || 'Erreur.' );
			}
		} ).fail( function () {
			$( resultEl ).text( 'Erreur réseau.' );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

}( jQuery ) );

// =====================================================================
// Section 13: Network scope select-all sync (vanilla JS, no jQuery)
// =====================================================================
( function () {
	function syncCheckboxes( source, targets ) {
		targets.forEach( function ( cb ) { cb.checked = source.checked; } );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var all1 = document.getElementById( 'cb-select-all' );
		var all2 = document.getElementById( 'cb-select-all-2' );
		var rows = Array.from( document.querySelectorAll( 'input[name="scope_ids[]"]' ) );

		if ( all1 ) {
			all1.addEventListener( 'change', function () {
				syncCheckboxes( this, rows );
				if ( all2 ) all2.checked = this.checked;
			} );
		}
		if ( all2 ) {
			all2.addEventListener( 'change', function () {
				syncCheckboxes( this, rows );
				if ( all1 ) all1.checked = this.checked;
			} );
		}

		// Handle bottom bulk action select (mirror to top before submit)
		var form        = document.getElementById( 'lsb-scopes-form' );
		var topAction    = document.querySelector( 'select[name="bulk_action"]' );
		var bottomAction = document.querySelector( 'select[name="bulk_action_bottom"]' );
		if ( form && bottomAction ) {
			form.addEventListener( 'submit', function () {
				if ( bottomAction.value !== '-1' && topAction ) {
					topAction.value = bottomAction.value;
				}
			} );
		}
	} );

}() );

// ─── Toggle-all in settings cards ─────────────────────────────────────────
( function ( $ ) {

	function syncToggleAll( $card ) {
		var $boxes  = $card.find( 'input[type="checkbox"]:not(.lsb-toggle-all-cb)' );
		var total   = $boxes.length;
		var checked = $boxes.filter( ':checked' ).length;
		$card.find( '.lsb-toggle-all-cb' ).prop( 'checked', total > 0 && total === checked );
	}

	$( document ).on( 'change', '.lsb-toggle-all-cb', function () {
		var checked = $( this ).prop( 'checked' );
		$( this ).closest( '.lsb-settings-card' )
			.find( 'input[type="checkbox"]:not(.lsb-toggle-all-cb)' )
			.prop( 'checked', checked );
	} );

	$( document ).on( 'change', '.lsb-settings-card input[type="checkbox"]:not(.lsb-toggle-all-cb)', function () {
		syncToggleAll( $( this ).closest( '.lsb-settings-card' ) );
	} );

	$( function () {
		$( '.lsb-settings-card' ).each( function () {
			syncToggleAll( $( this ) );
		} );
	} );

}( jQuery ) );

// ─── Confirmation reset réseau ─────────────────────────────────────────────
( function ( $ ) {
	$( document ).on( 'submit', '#lsb-reset-form', function ( e ) {
		var withSites = $( this ).find( 'input[name="lsb_reset_include_sites"]' ).is( ':checked' );
		var msg = withSites
			? 'Supprimer toutes les données réseau ET les réglages locaux de chaque site ? Cette action est irréversible.'
			: 'Supprimer toutes les données réseau ? Cette action est irréversible.';
		if ( ! window.confirm( msg ) ) {
			e.preventDefault();
		}
	} );
}( jQuery ) );
