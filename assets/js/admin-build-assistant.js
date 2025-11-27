/**
 * WP MCP AI Build Assistant Page JavaScript
 *
 * Handles the Build Assistant admin page functionality.
 *
 * @package WP_MCP_AI
 */

/* global jQuery, wpMcpAiBuildAssistant */

( function( $ ) {
	'use strict';

	/**
	 * Build Assistant Page Controller.
	 */
	const BuildAssistantPage = {
		/**
		 * Initialize the page.
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Add hover effects to cards.
			$( '.wp-mcp-ai-config-card' ).on( 'mouseenter', function() {
				$( this ).addClass( 'hover' );
			} ).on( 'mouseleave', function() {
				$( this ).removeClass( 'hover' );
			} );
		}
	};

	// Initialize when document is ready.
	$( document ).ready( function() {
		BuildAssistantPage.init();
	} );

} )( jQuery );
