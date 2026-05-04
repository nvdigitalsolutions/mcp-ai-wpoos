/**
 * Admin: Workflow Triggers — vanilla JS controller.
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 */
// (window globals accessed via `window.wpMcpAiTriggers`)
( function () {
	'use strict';

	const cfg     = window.wpMcpAiTriggers || {};
	const apiBase = cfg.apiBase || '';
	const nonce   = cfg.nonce   || '';
	const i18n    = cfg.i18n   || {};

	function restRequest( method, url, body, callback ) {
		const xhr = new XMLHttpRequest();
		xhr.open( method, url, true );
		xhr.setRequestHeader( 'Content-Type', 'application/json' );
		xhr.setRequestHeader( 'X-WP-Nonce', nonce );
		xhr.onload = function () {
			if ( xhr.status >= 200 && xhr.status < 300 ) {
				try { callback( null, JSON.parse( xhr.responseText ) ); }
				catch ( e ) { callback( e, null ); }
			} else {
				callback( new Error( 'HTTP ' + xhr.status ), null );
			}
		};
		xhr.onerror = function () { callback( new Error( 'Network error' ), null ); };
		xhr.send( body ? JSON.stringify( body ) : null );
	}

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	function buildRow( trigger ) {
		const enabled     = trigger.enabled;
		const enabledText = enabled ? ( i18n.labelEnabled || 'Enabled' ) : ( i18n.labelDisabled || 'Disabled' );
		const lastFired   = trigger.last_fired_at
			? new Date( trigger.last_fired_at * 1000 ).toISOString().replace( 'T', ' ' ).slice( 0, 16 )
			: '\u2014';
		return '<tr data-trigger-id="' + trigger.id + '">' +
			'<td>' + escapeHtml( trigger.type ) + '</td>' +
			'<td class="trigger-label">' + escapeHtml( trigger.name ) + '</td>' +
			'<td>' + escapeHtml( String( trigger.workflow_id ) ) + '</td>' +
			'<td class="trigger-status">' + escapeHtml( enabledText ) + '</td>' +
			'<td>' + escapeHtml( lastFired ) + '</td>' +
			'<td>' +
				'<button class="button button-small wp-mcp-ai-toggle-trigger" data-id="' + trigger.id + '" data-enabled="' + ( enabled ? '1' : '0' ) + '">' + escapeHtml( enabled ? 'Disable' : 'Enable' ) + '</button> ' +
				'<button class="button button-small button-link-delete wp-mcp-ai-delete-trigger" data-id="' + trigger.id + '">Delete</button>' +
			'</td></tr>';
	}

	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.wp-mcp-ai-toggle-trigger' );
		if ( ! btn ) { return; }
		const id     = btn.getAttribute( 'data-id' );
		const newVal = '0' === btn.getAttribute( 'data-enabled' );
		restRequest( 'PUT', apiBase + '/' + id, { enabled: newVal }, function ( err ) {
			if ( err ) { alert( i18n.errorGeneric || 'Error' ); return; }
			btn.setAttribute( 'data-enabled', newVal ? '1' : '0' );
			btn.textContent = newVal ? 'Disable' : 'Enable';
			const row = btn.closest( 'tr' );
			const statusTd = row ? row.querySelector( '.trigger-status' ) : null;
			if ( statusTd ) { statusTd.textContent = newVal ? ( i18n.labelEnabled || 'Enabled' ) : ( i18n.labelDisabled || 'Disabled' ); }
		} );
	} );

	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.wp-mcp-ai-delete-trigger' );
		if ( ! btn ) { return; }
		if ( ! window.confirm( i18n.confirmDelete || 'Delete this trigger?' ) ) { return; }
		const id = btn.getAttribute( 'data-id' );
		restRequest( 'DELETE', apiBase + '/' + id, null, function ( err ) {
			if ( err ) { alert( i18n.errorGeneric || 'Error' ); return; }
			const row = btn.closest( 'tr' );
			if ( row ) { row.parentNode.removeChild( row ); }
		} );
	} );

	const form = document.getElementById( 'wp-mcp-ai-add-trigger-form' );
	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const name       = ( form.elements.name.value || '' ).trim();
			const type       = form.elements.type.value;
			const workflowId = parseInt( form.elements.workflow_id.value, 10 );
			const msg        = document.getElementById( 'wp-mcp-ai-trigger-form-msg' );
			if ( ! name || ! type || ! workflowId ) {
				if ( msg ) { msg.textContent = 'Please fill in all fields.'; }
				return;
			}
			restRequest( 'POST', apiBase, { name: name, type: type, workflow_id: workflowId, config: {} }, function ( err, data ) {
				if ( err || ! data ) { if ( msg ) { msg.textContent = i18n.errorGeneric || 'Error'; } return; }
				const tbody = document.getElementById( 'wp-mcp-ai-triggers-body' );
				const noRow = document.getElementById( 'wp-mcp-ai-no-triggers-row' );
				if ( noRow ) { noRow.parentNode.removeChild( noRow ); }
				if ( tbody ) { tbody.insertAdjacentHTML( 'beforeend', buildRow( data ) ); }
				form.reset();
				if ( msg ) { msg.textContent = 'Trigger added.'; }
			} );
		} );
	}
}() );
