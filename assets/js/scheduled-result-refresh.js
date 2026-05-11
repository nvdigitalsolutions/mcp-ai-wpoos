/**
 * NV oOS — Scheduled Result auto-refresh enhancer.
 *
 * Looks for `[data-mcp-ai-refresh-interval]` containers and re-fetches the
 * latest envelope from `/mcp-ai-pro/v1/schedules/{id}/latest-result` on the
 * configured cadence. No-op when fetch or the REST endpoint is unavailable.
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || typeof document === 'undefined' || typeof window.fetch !== 'function' ) {
		return;
	}

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function refresh( container ) {
		var id = container.getAttribute( 'data-mcp-ai-refresh-schedule' );
		if ( ! id ) {
			return;
		}
		var base = ( window.wpApiSettings && window.wpApiSettings.root ) || '/wp-json/';
		var url  = base.replace( /\/$/, '' ) + '/mcp-ai-pro/v1/schedules/' + encodeURIComponent( id ) + '/latest-result';
		var headers = {};
		if ( window.wpApiSettings && window.wpApiSettings.nonce ) {
			headers[ 'X-WP-Nonce' ] = window.wpApiSettings.nonce;
		}
		window
			.fetch( url, { credentials: 'same-origin', headers: headers } )
			.then( function ( r ) {
				return r.ok ? r.json() : null;
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.envelope ) {
					return;
				}
				var body = container.querySelector( '.mcp-ai-scheduled-result__body' );
				if ( ! body ) {
					return;
				}
				// Minimal in-place update: re-render the summary line.
				var envelope = payload.envelope;
				body.innerHTML =
					'<p class="mcp-ai-scheduled-result__summary">' +
					escapeHtml( envelope.summary || '' ) +
					' <span class="mcp-ai-scheduled-result__badge mcp-ai-scheduled-result__badge--' +
					escapeHtml( envelope.status || '' ) +
					'">' +
					escapeHtml( envelope.status || '' ) +
					'</span></p>';
			} )
			.catch( function () {
				/* silent */
			} );
	}

	function init() {
		var containers = document.querySelectorAll( '[data-mcp-ai-refresh-interval]' );
		Array.prototype.forEach.call( containers, function ( container ) {
			var interval = parseInt( container.getAttribute( 'data-mcp-ai-refresh-interval' ), 10 );
			if ( ! interval || interval < 5 ) {
				return;
			}
			window.setInterval( function () {
				refresh( container );
			}, interval * 1000 );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
