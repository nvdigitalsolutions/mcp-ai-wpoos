/**
 * NV oOS Markup Subsystem — Chat client integration.
 *
 * Lightweight shim that listens for chat events / SSE deltas containing
 * a `markup_elicitation` payload and lazily renders the canvas widget
 * inside the assistant message bubble.
 *
 * The integration is deliberately decoupled from `chat.js` so the main
 * bundle size is unchanged when no markup tools are in play. A custom
 * DOM event (`wp-mcp-ai-chat:tool-result`) is dispatched by the chat
 * client when it receives a tool result. If no such event is wired up,
 * we additionally observe newly-inserted message nodes for a
 * `data-markup-payload` attribute the chat renderer can inject.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

( function ( window, document ) {
	'use strict';

	if ( window.WPMcpAiMarkupClient ) {
		return;
	}

	/**
	 * Get the configured auth options from the localized chat config.
	 *
	 * @return {Object} {nonce, bearer, guestToken}
	 */
	function getAuthOptions() {
		const cfg = window.wpMcpAiChat || {};
		return {
			nonce:      cfg.nonce || '',
			bearer:     cfg.assistantBearer || '',
			guestToken: cfg.guestToken || '',
		};
	}

	/**
	 * Get strings from the chat config (with safe defaults).
	 *
	 * @return {Object} Localized strings.
	 */
	function getStrings() {
		const cfg = window.wpMcpAiChat || {};
		const s = ( cfg.strings && cfg.strings.markup ) || {};
		return s;
	}

	/**
	 * Render a markup elicitation into a target element.
	 *
	 * @param {HTMLElement} host    Host element.
	 * @param {Object}      payload Elicitation payload.
	 * @return {Promise<HTMLElement>}
	 */
	function renderInto( host, payload ) {
		if ( ! window.WPMcpAiMarkupWidget ) {
			return Promise.reject( new Error( 'Markup widget not loaded' ) );
		}
		return window.WPMcpAiMarkupWidget.render( host, payload, {
			strings:    getStrings(),
			nonce:      getAuthOptions().nonce,
			bearer:     getAuthOptions().bearer,
			guestToken: getAuthOptions().guestToken,
			onSubmitted: function ( response ) {
				document.dispatchEvent( new CustomEvent( 'wp-mcp-ai-markup:resolved', {
					detail: { requestId: payload.request_id, response: response },
				} ) );
			},
			onCancelled: function () {
				document.dispatchEvent( new CustomEvent( 'wp-mcp-ai-markup:resolved', {
					detail: { requestId: payload.request_id, cancelled: true },
				} ) );
			},
		} );
	}

	/**
	 * Process a tool result — if it's a markup elicitation, render the widget.
	 *
	 * @param {Object}      result Tool result payload.
	 * @param {HTMLElement} host   Optional host element. When omitted, looks for
	 *                             `[data-markup-host="<request_id>"]` in the document.
	 * @return {boolean} True when the payload was handled.
	 */
	function handleToolResult( result, host ) {
		if ( ! result || typeof result !== 'object' ) {
			return false;
		}
		if ( result.type !== 'markup_elicitation' || ! result.request_id ) {
			return false;
		}
		let target = host;
		if ( ! target ) {
			target = document.querySelector( '[data-markup-host="' + result.request_id + '"]' );
		}
		if ( ! target ) {
			// Fallback: append to the last assistant bubble.
			const bubbles = document.querySelectorAll( '.wp-mcp-ai-chat__messages .wp-mcp-ai-chat__message--assistant' );
			target = bubbles.length ? bubbles[ bubbles.length - 1 ] : document.body;
		}
		renderInto( target, result );
		return true;
	}

	// Listen for chat tool result events.
	document.addEventListener( 'wp-mcp-ai-chat:tool-result', function ( ev ) {
		if ( ev && ev.detail && ev.detail.result ) {
			handleToolResult( ev.detail.result, ev.detail.host || null );
		}
	} );

	// Also accept direct elicitation events (e.g. dispatched by tests / external integrations).
	document.addEventListener( 'wp-mcp-ai-markup:elicit', function ( ev ) {
		if ( ev && ev.detail ) {
			handleToolResult( ev.detail, ev.detail.host || null );
		}
	} );

	window.WPMcpAiMarkupClient = {
		handleToolResult: handleToolResult,
		renderInto:       renderInto,
	};
}( typeof window !== 'undefined' ? window : this,
   typeof document !== 'undefined' ? document : null ) );
