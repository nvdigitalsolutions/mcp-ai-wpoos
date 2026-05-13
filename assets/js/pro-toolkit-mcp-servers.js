/**
 * Pro Toolkit MCP Servers Admin Page — client-side behaviour.
 *
 * Vanilla JS + wp.apiFetch. No build step required.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.6.0
 */

/* global wpMcpAiProMcpServers */
( function ( apiFetch ) {
	'use strict';

	if ( typeof wpMcpAiProMcpServers === 'undefined' ) {
		return;
	}

	const cfg      = wpMcpAiProMcpServers;
	const t        = cfg.i18n || {};
	const apiBase  = cfg.apiBase  || '';
	const restNonce = cfg.nonce  || '';

	// ------------------------------------------------------------------
	// Utility helpers
	// ------------------------------------------------------------------

	/**
	 * Copy text to clipboard and temporarily show feedback on a button.
	 *
	 * @param {string} text   Text to copy.
	 * @param {HTMLElement} btn  Button element to update.
	 */
	function copyToClipboard( text, btn ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				showCopyFeedback( btn );
			} ).catch( function () {
				fallbackCopy( text, btn );
			} );
		} else {
			fallbackCopy( text, btn );
		}
	}

	/** Fallback clipboard copy via textarea. */
	function fallbackCopy( text, btn ) {
		const ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity  = '0';
		document.body.appendChild( ta );
		ta.focus();
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) { /* noop */ }
		document.body.removeChild( ta );
		showCopyFeedback( btn );
	}

	/** Flash "Copied!" text on a button for 1.5 s. */
	function showCopyFeedback( btn ) {
		if ( ! btn ) { return; }
		const orig = btn.textContent;
		btn.textContent = t.tokenCopied || 'Copied!';
		setTimeout( function () {
			btn.textContent = orig;
		}, 1500 );
	}

	// ------------------------------------------------------------------
	// Copy endpoint / URL buttons
	// ------------------------------------------------------------------

	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.wp-mcp-ai-copy-endpoint' );
		if ( ! btn ) { return; }
		e.preventDefault();
		const endpoint = btn.getAttribute( 'data-endpoint' ) || '';
		copyToClipboard( endpoint, btn );
	} );

	// ------------------------------------------------------------------
	// Toggle form — add confirmation for disable
	// ------------------------------------------------------------------

	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.wp-mcp-ai-toggle-btn' );
		if ( ! btn ) { return; }
		const confirmMsg = btn.getAttribute( 'data-confirm' ) || '';
		if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
			e.preventDefault();
		}
	} );

	// ------------------------------------------------------------------
	// Token: generate via REST API
	// ------------------------------------------------------------------

	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.wp-mcp-ai-generate-token-btn' );
		if ( ! btn ) { return; }
		e.preventDefault();

		const slug       = btn.getAttribute( 'data-slug' ) || '';
		const wrapper    = btn.closest( '.wp-mcp-ai-generate-token' );
		const labelInput = wrapper ? wrapper.querySelector( '.wp-mcp-ai-token-label-input' ) : null;
		const label      = labelInput ? labelInput.value.trim() : '';

		btn.disabled    = true;
		btn.textContent = t.generating || 'Generating…';

		apiFetch( {
			url: apiBase + '/mcp/' + encodeURIComponent( slug ) + '/token',
			method: 'POST',
			data: { label: label },
			headers: { 'X-WP-Nonce': restNonce },
		} ).then( function ( response ) {
			btn.disabled    = false;
			btn.textContent = 'Generate Token';
			if ( response && response.token ) {
				showTokenModal( slug, response.token );
			}
		} ).catch( function ( err ) {
			btn.disabled    = false;
			btn.textContent = 'Generate Token';
			const msg = ( err && err.message ) ? err.message : 'Error generating token.';
			window.alert( msg );
		} );
	} );

	// ------------------------------------------------------------------
	// Token: revoke via REST API
	// ------------------------------------------------------------------

	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.wp-mcp-ai-revoke-token' );
		if ( ! btn ) { return; }
		e.preventDefault();

		if ( ! window.confirm( t.confirmRevoke || 'Revoke this token? This cannot be undone.' ) ) {
			return;
		}

		const slug   = btn.getAttribute( 'data-slug' )   || '';
		const prefix = btn.getAttribute( 'data-prefix' ) || '';

		btn.disabled    = true;
		btn.textContent = t.revoking || 'Revoking…';

		apiFetch( {
			url: apiBase + '/mcp/' + encodeURIComponent( slug ) + '/token/' + encodeURIComponent( prefix ),
			method: 'DELETE',
			headers: { 'X-WP-Nonce': restNonce },
		} ).then( function () {
			// Remove the row from the DOM without a full reload.
			const row = btn.closest( 'tr[data-prefix]' );
			if ( row ) {
				row.parentNode.removeChild( row );
			}
		} ).catch( function ( err ) {
			btn.disabled    = false;
			btn.textContent = 'Revoke';
			const msg = ( err && err.message ) ? err.message : 'Error revoking token.';
			window.alert( msg );
		} );
	} );

	// ------------------------------------------------------------------
	// Token: one-time reveal modal helpers
	// ------------------------------------------------------------------

	/**
	 * Show the one-time reveal modal, populate it with the raw token,
	 * and prevent background scroll.
	 *
	 * @param {string} slug  Server slug.
	 * @param {string} token Raw bearer token string.
	 */
	function showTokenModal( slug, token ) {
		const modal = document.getElementById( 'wp-mcp-ai-token-modal-' + slug );
		if ( ! modal ) { return; }
		const input = modal.querySelector( '.wp-mcp-ai-token-value' );
		if ( input ) { input.value = token; }
		modal.style.display = 'flex';
		document.body.style.overflow = 'hidden';
		// Auto-select the value.
		if ( input ) {
			input.focus();
			input.select();
		}
	}

	/** Copy-token button inside the modal. */
	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.wp-mcp-ai-copy-token-btn' );
		if ( ! btn ) { return; }
		e.preventDefault();
		const modal = btn.closest( '.wp-mcp-ai-token-modal' );
		if ( ! modal ) { return; }
		const input = modal.querySelector( '.wp-mcp-ai-token-value' );
		copyToClipboard( input ? input.value : '', btn );
	} );

	/** Dismiss button — require that the user has acknowledged the warning. */
	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.wp-mcp-ai-token-dismiss' );
		if ( ! btn ) { return; }
		e.preventDefault();
		const slug  = btn.getAttribute( 'data-slug' ) || '';
		const modal = document.getElementById( 'wp-mcp-ai-token-modal-' + slug );
		if ( modal ) {
			// Wipe the token value before closing.
			const input = modal.querySelector( '.wp-mcp-ai-token-value' );
			if ( input ) { input.value = ''; }
			modal.style.display = 'none';
			document.body.style.overflow = '';
		}
		// Reload so the new token appears in the list.
		window.location.reload();
	} );

	// ------------------------------------------------------------------
	// Audit log: clear confirmation
	// ------------------------------------------------------------------

	const clearAuditForm = document.getElementById( 'wp-mcp-ai-clear-audit-form' );
	if ( clearAuditForm ) {
		clearAuditForm.addEventListener( 'submit', function ( e ) {
			const confirmed = window.confirm( t.confirmClearLog || 'Clear the entire audit log? This cannot be undone.' );
			if ( ! confirmed ) { e.preventDefault(); }
		} );
	}

	// ------------------------------------------------------------------
	// Audit log: CSV export
	// ------------------------------------------------------------------

	const exportCsvBtn = document.getElementById( 'wp-mcp-ai-export-csv' );
	if ( exportCsvBtn ) {
		exportCsvBtn.addEventListener( 'click', function () {
			const entries = JSON.parse( exportCsvBtn.getAttribute( 'data-entries' ) || '[]' );
			exportAuditCsv( entries );
		} );
	}

	function exportAuditCsv( entries ) {
		const headers = [ 'timestamp', 'server', 'consumer', 'action', 'result' ];
		const rows    = [ headers ];
		entries.forEach( function ( e ) {
			rows.push( [
				e.ts    ? new Date( e.ts * 1000 ).toISOString() : '',
				e.source   || '',
				e.consumer || '',
				e.action   || '',
				e.result   || '',
			] );
		} );
		const csv = rows.map( function ( r ) {
			return r.map( function ( c ) {
				return '"' + String( c ).replace( /"/g, '""' ) + '"';
			} ).join( ',' );
		} ).join( '\r\n' );
		downloadBlob( csv, 'mcp-audit-log.csv', 'text/csv;charset=utf-8;' );
	}

	// ------------------------------------------------------------------
	// Audit log: JSON export
	// ------------------------------------------------------------------

	const exportJsonBtn = document.getElementById( 'wp-mcp-ai-export-json' );
	if ( exportJsonBtn ) {
		exportJsonBtn.addEventListener( 'click', function () {
			const entries = JSON.parse( exportJsonBtn.getAttribute( 'data-entries' ) || '[]' );
			downloadBlob( JSON.stringify( entries, null, 2 ), 'mcp-audit-log.json', 'application/json' );
		} );
	}

	/**
	 * Trigger a file download from a string.
	 *
	 * @param {string} content  File content.
	 * @param {string} filename Suggested filename.
	 * @param {string} type     MIME type.
	 */
	function downloadBlob( content, filename, type ) {
		const blob = new Blob( [ content ], { type: type } );
		const url  = URL.createObjectURL( blob );
		const a    = document.createElement( 'a' );
		a.href     = url;
		a.download = filename;
		a.style.display = 'none';
		document.body.appendChild( a );
		a.click();
		URL.revokeObjectURL( url );
		document.body.removeChild( a );
	}

	// ------------------------------------------------------------------
	// Discovery tab: load + refresh + copy
	// ------------------------------------------------------------------

	const discoveryPreview = document.getElementById( 'wp-mcp-ai-discovery-preview' );

	function loadDiscovery() {
		if ( ! discoveryPreview ) { return; }
		discoveryPreview.textContent = t.loading || 'Loading…';

		fetch( cfg.wellKnownUrl, { headers: { 'Accept': 'application/json' } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				discoveryPreview.textContent = JSON.stringify( data, null, 2 );
			} )
			.catch( function ( err ) {
				discoveryPreview.textContent = 'Error: ' + ( err.message || 'could not fetch' );
			} );
	}

	if ( discoveryPreview ) {
		loadDiscovery();
	}

	const refreshBtn = document.getElementById( 'wp-mcp-ai-refresh-discovery' );
	if ( refreshBtn ) {
		refreshBtn.addEventListener( 'click', loadDiscovery );
	}

	const copyDiscoveryBtn = document.getElementById( 'wp-mcp-ai-copy-discovery' );
	if ( copyDiscoveryBtn ) {
		copyDiscoveryBtn.addEventListener( 'click', function () {
			const text = discoveryPreview ? discoveryPreview.textContent : '';
			copyToClipboard( text, copyDiscoveryBtn );
		} );
	}

	// ------------------------------------------------------------------
	// Servers tab: select-all checkbox
	// ------------------------------------------------------------------

	const cbAll = document.getElementById( 'cb-select-all-1' );
	if ( cbAll ) {
		cbAll.addEventListener( 'change', function () {
			const checkboxes = document.querySelectorAll( 'input[name="server[]"]' );
			checkboxes.forEach( function ( cb ) { cb.checked = cbAll.checked; } );
		} );
	}

}( window.wp && window.wp.apiFetch ? window.wp.apiFetch : function ( opts ) {
	// Minimal apiFetch shim for environments where wp.apiFetch might not be
	// loaded before this script. Handles non-2xx responses and JSON parse errors.
	return window.fetch( opts.url, {
		method: opts.method || 'GET',
		headers: Object.assign( { 'Content-Type': 'application/json' }, opts.headers || {} ),
		body: opts.data ? JSON.stringify( opts.data ) : undefined,
	} ).then( function ( response ) {
		if ( ! response.ok ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( body ) {
				const err = new Error( ( body && body.message ) ? body.message : 'Request failed (' + response.status + ')' );
				err.code   = ( body && body.code ) ? body.code : 'request_failed';
				err.status = response.status;
				return Promise.reject( err );
			} );
		}
		return response.json().catch( function () {
			const err = new Error( 'Invalid JSON response' );
			err.code = 'invalid_json';
			return Promise.reject( err );
		} );
	} );
} ) );
