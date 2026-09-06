/**
 * NV oOS Content Graph — Remote Sources admin JS.
 *
 * Handles the Add Source modal, sync/test/delete buttons, field-map
 * validation, and embeddings reindex on the Content Graph Remote Sources tab.
 *
 * Config is injected via nvoosContentGraphRemoteAdmin (wp_localize_script).
 *
 * @since 1.0.0
 * @package NvoosContentGraph
 */

( function ( $ ) {
	const cfg = window.nvoosContentGraphRemoteAdmin || {};

	// ── Open modal for adding a new source ────────────────────────
	$( '.nvoos-add-source-btn' ).on( 'click', function () {
		const driver = $( this ).data( 'driver' );
		const label = $( this ).data( 'label' );
		let schema = $( this ).data( 'schema' ) || {};
		if ( typeof schema === 'string' ) {
			try { schema = JSON.parse( schema ); } catch ( e ) { schema = {}; }
		}
		$( '#nvoos-source-driver' ).val( driver );
		$( '#nvoos-modal-title' ).text( cfg.i18n.addSource + ': ' + label );
		$( '#nvoos-source-config-fields' ).html( buildFields( schema ) );
		$( '#nvoos-remote-source-modal' ).show();
	} );

	$( '#nvoos-modal-cancel' ).on( 'click', function () {
		$( '#nvoos-remote-source-modal' ).hide();
	} );

	// ── Save source form submission ──────────────────────────────
	$( '#nvoos-remote-source-form' ).on( 'submit', function ( e ) {
		e.preventDefault();
		const data = { action: 'nvoos_content_graph_save_remote_source', nonce: cfg.nonce };
		$( this ).serializeArray().forEach( function ( f ) { data[ f.name ] = f.value; } );
		$.post( cfg.ajaxurl, data, function ( res ) {
			if ( res.success ) { location.reload(); } else { $( '#nvoos-modal-message' ).text( res.data || 'Error' ); }
		} );
	} );

	// ── Live field-map validation ─────────────────────────────────
	let fmTimer = null;
	$( document ).on( 'input', 'textarea[name="field_map"]', function () {
		const $ta = $( this );
		let $fb = $ta.siblings( '.nvoos-fieldmap-feedback' );
		if ( ! $fb.length ) {
			$fb = $( '<div class="nvoos-fieldmap-feedback" style="margin-top:6px;font-size:12px;"></div>' );
			$ta.after( $fb );
		}
		clearTimeout( fmTimer );
		fmTimer = setTimeout( function () {
			const val = $ta.val();
			if ( ! val || ! val.trim() ) { $fb.html( '' ); return; }
			$.post( cfg.ajaxurl, {
				action: 'nvoos_content_graph_validate_field_map',
				nonce: cfg.nonce,
				field_map: val,
			}, function ( res ) {
				if ( ! res || ! res.success || ! res.data ) { $fb.html( '' ); return; }
				const d = res.data;
				let html = '';
				if ( d.valid ) {
					html += '<span style="color:#1a7f37;">\u2713 ' + cfg.i18n.validMap + '</span>';
					if ( d.fields && d.fields.length ) {
						html += ' <span style="color:#666;">(' + d.fields.length + ' ' + cfg.i18n.paths + ')</span>';
					}
				} else {
					html += '<span style="color:#b32d2e;">\u2717 ' + cfg.i18n.invalidMap + '</span>';
				}
				if ( d.errors && d.errors.length ) {
					html += '<ul style="color:#b32d2e;margin:4px 0 0 18px;">';
					d.errors.forEach( function ( err ) { html += '<li>' + $( '<div>' ).text( err ).html() + '</li>'; } );
					html += '</ul>';
				}
				if ( d.warnings && d.warnings.length ) {
					html += '<ul style="color:#bf8700;margin:4px 0 0 18px;">';
					d.warnings.forEach( function ( w ) { html += '<li>' + $( '<div>' ).text( w ).html() + '</li>'; } );
					html += '</ul>';
				}
				$fb.html( html );
			} );
		}, 350 );
	} );

	// ── Sync button ──────────────────────────────────────────────
	$( '.nvoos-sync-source-btn' ).on( 'click', function () {
		const slug = $( this ).data( 'slug' );
		const btn = $( this ).prop( 'disabled', true ).text( '...' );
		$.post( cfg.ajaxurl, { action: 'nvoos_content_graph_sync_remote_source', nonce: cfg.nonce, slug: slug }, function ( res ) {
			btn.prop( 'disabled', false ).text( cfg.i18n.sync );
			window.alert( res.success ? JSON.stringify( res.data ) : ( 'Error: ' + ( res.data || 'unknown' ) ) );
		} );
	} );

	// ── Test button ──────────────────────────────────────────────
	$( '.nvoos-test-source-btn' ).on( 'click', function () {
		const slug = $( this ).data( 'slug' );
		$.post( cfg.ajaxurl, { action: 'nvoos_content_graph_test_remote_source', nonce: cfg.nonce, slug: slug }, function ( res ) {
			window.alert( res.success ? cfg.i18n.connectionOk : ( 'Error: ' + ( res.data || 'unknown' ) ) );
		} );
	} );

	// ── Delete button ────────────────────────────────────────────
	$( '.nvoos-delete-source-btn' ).on( 'click', function () {
		if ( ! window.confirm( cfg.i18n.deleteConfirm ) ) { return; }
		const slug = $( this ).data( 'slug' );
		$.post( cfg.ajaxurl, { action: 'nvoos_content_graph_delete_remote_source', nonce: cfg.nonce, slug: slug }, function ( res ) {
			if ( res.success ) { $( '#nvoos-source-row-' + slug ).closest( 'tr' ).remove(); } else { window.alert( 'Error: ' + ( res.data || 'unknown' ) ); }
		} );
	} );

	// ── Reindex embeddings ───────────────────────────────────────
	$( '#nvoos-reindex-btn' ).on( 'click', function () {
		const btn = $( this ).prop( 'disabled', true );
		const status = $( '#nvoos-reindex-status' ).text( cfg.i18n.reindexing );
		$.post( cfg.ajaxurl, { action: 'nvoos_content_graph_reindex_embeddings', nonce: $( this ).data( 'nonce' ) } )
			.done( function ( res ) {
				btn.prop( 'disabled', false );
				if ( res && res.success ) {
					const processed = ( res.data && res.data.processed ) || 0;
					const failed = ( res.data && res.data.failed ) || 0;
					let msg = cfg.i18n.doneStored + ' ' + processed;
					if ( failed > 0 ) {
						msg += ' · ' + cfg.i18n.failed + ' ' + failed;
						if ( res.data && res.data.error ) {
							msg += ' — ' + res.data.error;
						} else {
							msg += ' (' + cfg.i18n.checkApiKey + ')';
						}
					}
					status.text( msg );
				} else {
					status.text( 'Error: ' + ( ( res && res.data ) || 'unknown' ) );
				}
			} )
			.fail( function ( xhr ) {
				// Server-side 500/timeout — always restore the button so the
				// panel never hangs in the "Reindexing…" state.
				btn.prop( 'disabled', false );
				let msg = cfg.i18n.reindexFailed;
				try {
					const parsed = JSON.parse( xhr.responseText );
					if ( parsed && parsed.data && parsed.data.message ) {
						msg += ': ' + parsed.data.message;
					} else if ( parsed && parsed.message ) {
						msg += ': ' + parsed.message;
					}
				} catch ( e ) {
					// Non-JSON error page — keep the default message.
				}
				status.text( 'Error: ' + msg );
			} );
	} );

	// ── Dynamic form builder for driver config fields ────────────
	function buildFields( schema ) {
		if ( ! schema || ! schema.properties ) { return ''; }
		let html = '<table class="form-table"><tbody>';
		Object.keys( schema.properties ).forEach( function ( key ) {
			const field = schema.properties[ key ] || {};
			const label = $( '<div/>' ).text( field.label || key ).html();
			const desc  = $( '<div/>' ).text( field.description || '' ).html();
			const escKey = $( '<div/>' ).text( key ).html();
			const type   = field.type || 'text';
			let control = '';

			if ( 'password' === type ) {
				control = '<input type="password" name="config[' + escKey + ']" class="regular-text" autocomplete="new-password"' + ( field.required ? ' required' : '' ) + '>';
			} else if ( 'textarea' === type ) {
				control = '<textarea name="config[' + escKey + ']" class="large-text" rows="4"' + ( field.required ? ' required' : '' ) + '></textarea>';
			} else if ( 'checkbox' === type ) {
				// Hidden input guarantees the key is submitted even when unchecked.
				control = '<input type="hidden" name="config[' + escKey + ']" value="0">' +
					'<label><input type="checkbox" name="config[' + escKey + ']" value="1"' + ( field.default ? ' checked' : '' ) + '>' +
					' ' + desc + '</label>';
			} else if ( 'number' === type ) {
				control = '<input type="number" name="config[' + escKey + ']" class="small-text"' + ( field.required ? ' required' : '' ) + '>';
			} else if ( 'url' === type ) {
				control = '<input type="url" name="config[' + escKey + ']" class="regular-text"' + ( field.required ? ' required' : '' ) + '>';
			} else {
				control = '<input type="text" name="config[' + escKey + ']" class="regular-text"' + ( field.required ? ' required' : '' ) + '>';
			}

			html += '<tr><th scope="row"><label>' + label + '</label></th><td>' + control;
			if ( 'checkbox' !== type && field.description ) {
				html += ' <p class="description">' + desc + '</p>';
			}
			html += '</td></tr>';
		} );
		html += '</tbody></table>';
		return html;
	}
}( jQuery ) );
