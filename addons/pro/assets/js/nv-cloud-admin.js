/* global wp, jQuery */
/**
 * NV oOS Cloud admin settings page.
 *
 * Drives the Connect / Disconnect / Refresh / Top-up / Save-Prefs flows
 * against the `/mcp-ai-pro/v1/cloud/*` REST endpoints. Vanilla wp.apiFetch
 * with nonce — no external dependencies.
 *
 * @package WP_MCP_AI_Pro
 */
( function ( apiFetch ) {
	'use strict';

	if ( ! apiFetch ) {
		return;
	}

	function notify( message, isError ) {
		if ( window.console ) {
			window.console[ isError ? 'error' : 'log' ]( '[NV oOS Cloud] ' + message );
		}
		if ( window.alert ) {
			window.alert( message );
		}
	}

	function reload() {
		window.location.reload();
	}

	function bind() {
		var saveBtn = document.getElementById( 'wp-mcp-ai-nv-cloud-save-token' );
		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', function () {
				var input = document.getElementById( 'wp-mcp-ai-nv-cloud-token-input' );
				var token = input ? input.value.trim() : '';
				if ( ! token ) {
					notify( 'Paste a Connect Token first.', true );
					return;
				}
				apiFetch( {
					path: '/mcp-ai-pro/v1/cloud/connect',
					method: 'POST',
					data: { token: token }
				} ).then( reload ).catch( function ( err ) {
					notify( err && err.message ? err.message : 'Failed to save token.', true );
				} );
			} );
		}

		var disconnectBtn = document.getElementById( 'wp-mcp-ai-nv-cloud-disconnect' );
		if ( disconnectBtn ) {
			disconnectBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( 'Disconnect NV oOS Cloud from this site?' ) ) {
					return;
				}
				apiFetch( {
					path: '/mcp-ai-pro/v1/cloud/disconnect',
					method: 'POST'
				} ).then( reload ).catch( function ( err ) {
					notify( err && err.message ? err.message : 'Failed to disconnect.', true );
				} );
			} );
		}

		var refreshBtn = document.getElementById( 'wp-mcp-ai-nv-cloud-refresh' );
		if ( refreshBtn ) {
			refreshBtn.addEventListener( 'click', function () {
				apiFetch( {
					path: '/mcp-ai-pro/v1/cloud/refresh-balance',
					method: 'POST'
				} ).then( reload ).catch( function ( err ) {
					notify( err && err.message ? err.message : 'Failed to refresh balance.', true );
				} );
			} );
		}

		var topupBtn = document.getElementById( 'wp-mcp-ai-nv-cloud-topup' );
		if ( topupBtn ) {
			topupBtn.addEventListener( 'click', function () {
				var amountInput = document.getElementById( 'wp-mcp-ai-nv-cloud-topup-amount' );
				var amount = amountInput ? parseFloat( amountInput.value ) : 0;
				if ( ! amount || amount < 25 ) {
					notify( 'Minimum top-up is $25 USD.', true );
					return;
				}
				apiFetch( {
					path: '/mcp-ai-pro/v1/cloud/topup-url',
					method: 'POST',
					data: { amount_usd: amount }
				} ).then( function ( res ) {
					if ( res && res.checkout_url ) {
						window.open( res.checkout_url, '_blank', 'noopener,noreferrer' );
					} else {
						notify( 'Stripe Checkout URL was empty.', true );
					}
				} ).catch( function ( err ) {
					notify( err && err.message ? err.message : 'Failed to create top-up.', true );
				} );
			} );
		}

		var saveprefsBtn = document.getElementById( 'wp-mcp-ai-nv-cloud-save-prefs' );
		if ( saveprefsBtn ) {
			saveprefsBtn.addEventListener( 'click', function () {
				var defaultEl = document.getElementById( 'wp-mcp-ai-nv-cloud-default' );
				var autoEl    = document.getElementById( 'wp-mcp-ai-nv-cloud-auto-topup' );
				var amountEl  = document.getElementById( 'wp-mcp-ai-nv-cloud-auto-topup-amount' );
				apiFetch( {
					path: '/mcp-ai-pro/v1/cloud/prefs',
					method: 'POST',
					data: {
						use_as_default: defaultEl ? !! defaultEl.checked : false,
						auto_topup_enabled: autoEl ? !! autoEl.checked : false,
						auto_topup_amount_usd: amountEl ? parseFloat( amountEl.value ) : 25
					}
				} ).then( function () {
					notify( 'Preferences saved.' );
				} ).catch( function ( err ) {
					notify( err && err.message ? err.message : 'Failed to save preferences.', true );
				} );
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bind );
	} else {
		bind();
	}
}( window.wp && window.wp.apiFetch ? window.wp.apiFetch : null ) );
