/**
 * MCP Server Diagnostic Page JavaScript
 *
 * Handles testing of MCP endpoints and methods in the diagnostic page.
 *
 * @package WP_MCP_AI
 */

/* global wpMcpAiMcpDiagnostic */

(function( $ ) {
	'use strict';

	$( document ).ready( function() {
		// Check if localized script data is available.
		if ( typeof wpMcpAiMcpDiagnostic === 'undefined' ) {
			console.error( '[WP oOS] wpMcpAiMcpDiagnostic is not defined. The script may not be properly localized.' );
			return;
		}

		// Use localized script data.
		const ajaxUrl = wpMcpAiMcpDiagnostic.ajaxUrl;
		const nonce = wpMcpAiMcpDiagnostic.nonce;

		// Test MCP endpoint connectivity.
		$( '#test-mcp-endpoint' ).on( 'click', function() {
			const button = $( this );
			const resultDiv = $( '#mcp-endpoint-test-result' );

			button.prop( 'disabled', true ).text( wpMcpAiMcpDiagnostic.i18n.testing );
			resultDiv.html( '<p>' + wpMcpAiMcpDiagnostic.i18n.testingEndpoint + '</p>' );

			$.ajax( {
				url: ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_test_mcp_endpoint',
					nonce: nonce
				},
				success: function( response ) {
					if ( response.success ) {
						resultDiv.html(
							'<div class="notice notice-success inline"><p><strong>' +
							wpMcpAiMcpDiagnostic.i18n.success +
							'</strong> ' + response.data.message + '</p>' +
							'<pre style="background: #f5f5f5; padding: 10px; overflow-x: auto;">' +
							JSON.stringify( response.data.response, null, 2 ) +
							'</pre></div>'
						);
					} else {
						const errorMessage = ( response.data && response.data.message ) ?
							response.data.message :
							wpMcpAiMcpDiagnostic.i18n.unknownError;
						resultDiv.html(
							'<div class="notice notice-error inline"><p><strong>' +
							wpMcpAiMcpDiagnostic.i18n.error +
							'</strong> ' + errorMessage + '</p></div>'
						);
					}
				},
				error: function( xhr, status, error ) {
					resultDiv.html(
						'<div class="notice notice-error inline"><p><strong>' +
						wpMcpAiMcpDiagnostic.i18n.error +
						'</strong> ' + error + '</p></div>'
					);
				},
				complete: function() {
					button.prop( 'disabled', false ).text( wpMcpAiMcpDiagnostic.i18n.testEndpoint );
				}
			} );
		} );

		// Test individual MCP methods.
		$( '.test-mcp-method' ).on( 'click', function() {
			const button = $( this );
			const method = button.data( 'method' );
			const methodId = button.data( 'method-id' );
			const resultDiv = $( '#result-' + methodId );

			button.prop( 'disabled', true ).text( wpMcpAiMcpDiagnostic.i18n.testing );
			resultDiv.html( '<p>' + wpMcpAiMcpDiagnostic.i18n.testingMethod + '</p>' );

			$.ajax( {
				url: ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_test_mcp_method',
					nonce: nonce,
					method: method
				},
				success: function( response ) {
					if ( response.success ) {
						let resultCount = '';
						if ( response.data.response && response.data.response.result ) {
							const result = response.data.response.result;
							if ( result.tools && Array.isArray( result.tools ) ) {
								resultCount = ' (' + result.tools.length + ' tools)';
							} else if ( result.resources && Array.isArray( result.resources ) ) {
								resultCount = ' (' + result.resources.length + ' resources)';
							} else if ( result.prompts && Array.isArray( result.prompts ) ) {
								resultCount = ' (' + result.prompts.length + ' prompts)';
							}
						}

						resultDiv.html(
							'<div class="notice notice-success inline"><p><strong>' +
							wpMcpAiMcpDiagnostic.i18n.success +
							'</strong> ' + response.data.message + resultCount + '</p>' +
							'<details><summary style="cursor: pointer;">' +
							wpMcpAiMcpDiagnostic.i18n.viewResponse +
							'</summary>' +
							'<pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; max-height: 400px;">' +
							JSON.stringify( response.data.response, null, 2 ) +
							'</pre></details></div>'
						);
					} else {
						const errorMessage = ( response.data && response.data.message ) ?
							response.data.message :
							wpMcpAiMcpDiagnostic.i18n.unknownError;
						resultDiv.html(
							'<div class="notice notice-error inline"><p><strong>' +
							wpMcpAiMcpDiagnostic.i18n.error +
							'</strong> ' + errorMessage + '</p></div>'
						);
					}
				},
				error: function( xhr, status, error ) {
					resultDiv.html(
						'<div class="notice notice-error inline"><p><strong>' +
						wpMcpAiMcpDiagnostic.i18n.error +
						'</strong> ' + error + '</p></div>'
					);
				},
				complete: function() {
					// Store original button text in data attribute if not already stored.
					if ( ! button.data( 'original-text' ) ) {
						button.data( 'original-text', button.text() );
					}
					button.prop( 'disabled', false ).text( button.data( 'original-text' ) );
				}
			} );
		} );
	} );
}( jQuery ) );
