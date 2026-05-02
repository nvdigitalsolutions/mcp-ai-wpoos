/**
 * NV oOS Markup Subsystem — Fallback module.
 *
 * Renders a textual fallback when the inline canvas cannot be hosted
 * (older Elementor widget context, very small viewports, screen reader
 * users, browsers without canvas / Konva). The fallback opens the same
 * canvas page in a new tab — the URL-mode elicitation route documented
 * in MCP spec 2025-11-25.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

( function ( window, document ) {
	'use strict';

	/**
	 * Detect whether the host environment can render the inline canvas.
	 *
	 * The detection is intentionally conservative: any failure → fallback.
	 *
	 * @return {boolean} True when the inline canvas should be used.
	 */
	function canRenderInline() {
		if ( ! document || typeof document.createElement !== 'function' ) {
			return false;
		}
		// Canvas support.
		try {
			const canvas = document.createElement( 'canvas' );
			if ( ! canvas || ! canvas.getContext || ! canvas.getContext( '2d' ) ) {
				return false;
			}
		} catch ( e ) {
			return false;
		}
		// Konva must have loaded successfully.
		if ( ! window.Konva || ! window.Konva.Stage ) {
			return false;
		}
		// Honour explicit opt-out via querystring or body class.
		if ( document.body && document.body.classList &&
			 document.body.classList.contains( 'wp-mcp-ai-markup-disabled' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Build a textual fallback element.
	 *
	 * @param {Object} payload Markup elicitation payload.
	 * @param {Object} strings Localized strings.
	 * @return {HTMLElement} Fallback root element.
	 */
	function build( payload, strings ) {
		strings = strings || {};
		const root = document.createElement( 'div' );
		root.className = 'wp-mcp-ai-markup-fallback';
		root.setAttribute( 'role', 'group' );
		root.setAttribute( 'aria-label', strings.fallbackTitle || 'Markup request' );

		const heading = document.createElement( 'p' );
		heading.className = 'wp-mcp-ai-markup-fallback__title';
		heading.textContent = strings.fallbackTitle || 'Visual markup requested';
		root.appendChild( heading );

		if ( payload.instructions ) {
			const p = document.createElement( 'p' );
			p.className = 'wp-mcp-ai-markup-fallback__instructions';
			p.textContent = String( payload.instructions ).slice( 0, 1024 );
			root.appendChild( p );
		}

		const openLink = document.createElement( 'a' );
		openLink.className = 'wp-mcp-ai-markup-fallback__open button';
		openLink.href = payload.fallback_url || '#';
		openLink.target = '_blank';
		openLink.rel = 'noopener noreferrer';
		openLink.textContent = strings.openInTab || 'Open editor in a new tab';
		root.appendChild( openLink );

		const cancelButton = document.createElement( 'button' );
		cancelButton.type = 'button';
		cancelButton.className = 'wp-mcp-ai-markup-fallback__cancel button-link';
		cancelButton.textContent = strings.cancel || 'Cancel';
		cancelButton.setAttribute( 'data-markup-cancel', '1' );
		root.appendChild( cancelButton );

		return root;
	}

	window.WPMcpAiMarkupFallback = {
		canRenderInline: canRenderInline,
		build:           build,
	};
}( typeof window !== 'undefined' ? window : this,
   typeof document !== 'undefined' ? document : null ) );
