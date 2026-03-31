/**
 * Registration Product Consolidate & Import Page JavaScript
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

( function( $ ) {
	'use strict';

	// Config shorthand.
	var cfg = window.wpMcpAiRegConsolidate || {};
	var s = cfg.strings || {};

	// Escape helper.
	function escapeHtml( text ) {
		var d = document.createElement( 'div' );
		d.textContent = String( text );
		return d.innerHTML.replace( /"/g, '&quot;' );
	}

	// Convert snake_case to Title Case.
	function toTitleCase( str ) {
		return str.replace( /_/g, ' ' ).replace( /\b\w/g, function( l ) {
			return l.toUpperCase();
		} );
	}

	function initRegConsolidate() {
		initProductSelection();
		initBulkImport();
		initGuidedEntry();
	}

	// --- Product Selection ---
	function initProductSelection() {
		var productSelect = $( '#wp-mcp-ai-product-select' );
		var loadBtn = $( '#wp-mcp-ai-load-product-btn' );

		loadBtn.on( 'click', function() {
			var productId = productSelect.val();
			if ( ! productId ) {
				window.alert( s.selectProduct || 'Please select a product first.' );
				return;
			}

			loadBtn.prop( 'disabled', true ).text( s.loading || 'Loading...' );

			$.ajax( {
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_product_records_preview',
					nonce: cfg.nonce,
					product_id: productId,
				},
				success: function( response ) {
					if ( response.success ) {
						$( '#wp-mcp-ai-product-records-preview' ).html( response.data.html ).show();
						$( '#wp-mcp-ai-no-product-selection' ).hide();
					} else {
						window.alert( response.data.message || s.error );
					}
				},
				error: function() {
					window.alert( s.error || 'An error occurred.' );
				},
				complete: function() {
					loadBtn.prop( 'disabled', false ).text( 'Load Product Records' );
				},
			} );
		} );
	}

	// --- Bulk Import ---
	function initBulkImport() {
		var uploadBtn = $( '#wp-mcp-ai-reg-file-upload-btn' );
		var fileInput = $( '#wp-mcp-ai-reg-file-upload' );
		var fileList = $( '#wp-mcp-ai-reg-file-list' );
		var fileItems = $( '#wp-mcp-ai-reg-file-items' );
		var importBtn = $( '#wp-mcp-ai-reg-bulk-import-btn' );
		var clearBtn = $( '#wp-mcp-ai-reg-bulk-clear-btn' );
		var textarea = $( '#wp-mcp-ai-reg-bulk-import-text' );
		var resultContainer = $( '#wp-mcp-ai-reg-bulk-import-result' );
		var uploadedAttachmentIds = [];

		// File upload button.
		uploadBtn.on( 'click', function() {
			fileInput.trigger( 'click' );
		} );

		// File selection handler.
		fileInput.on( 'change', function() {
			var files = this.files;
			if ( ! files.length ) {
				return;
			}

			for ( var i = 0; i < files.length; i++ ) {
				uploadSingleFile( files[ i ] );
			}

			this.value = '';
		} );

		function uploadSingleFile( file ) {
			var formData = new FormData();
			formData.append( 'action', 'wp_mcp_ai_upload_reg_document' );
			formData.append( 'nonce', cfg.nonce );
			formData.append( 'file', file );

			var productId = $( '#wp-mcp-ai-product-select' ).val();
			if ( productId ) {
				formData.append( 'product_id', productId );
			}

			var listItem = $( '<li>' )
				.html( '<span class="dashicons dashicons-media-default"></span> ' + escapeHtml( file.name ) + ' <span class="uploading">(uploading...)</span>' );
			fileItems.append( listItem );
			fileList.show();

			$.ajax( {
				url: cfg.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function( response ) {
					if ( response.success ) {
						uploadedAttachmentIds.push( response.data.attachment_id );
						listItem.html(
							'<span class="dashicons dashicons-yes-alt"></span> ' +
							escapeHtml( response.data.name ) +
							' <button type="button" class="button-link remove-file" data-id="' +
							response.data.attachment_id + '">×</button>'
						);
					} else {
						listItem.html(
							'<span class="dashicons dashicons-warning"></span> ' +
							escapeHtml( file.name ) + ' — ' +
							escapeHtml( response.data.message )
						);
					}
				},
				error: function() {
					listItem.html(
						'<span class="dashicons dashicons-warning"></span> ' +
						escapeHtml( file.name ) + ' — Upload failed'
					);
				},
			} );
		}

		// Remove file from list.
		fileItems.on( 'click', '.remove-file', function() {
			var id = parseInt( $( this ).data( 'id' ), 10 );
			if ( isNaN( id ) ) {
				return;
			}
			uploadedAttachmentIds = uploadedAttachmentIds.filter( function( v ) {
				return v !== id;
			} );
			$( this ).closest( 'li' ).remove();
			if ( ! fileItems.children().length ) {
				fileList.hide();
			}
		} );

		// Import button handler.
		importBtn.on( 'click', function() {
			var rawText = textarea.val().trim();
			if ( ! rawText && ! uploadedAttachmentIds.length ) {
				// eslint-disable-next-line no-alert
				window.alert( s.enterProductInfo || 'Please enter product information or upload files to import.' );
				textarea.focus();
				return;
			}

			importBtn.prop( 'disabled', true );
			importBtn.find( '.dashicons' ).removeClass( 'dashicons-update' ).addClass( 'dashicons-update spin' );
			resultContainer.html( '<div class="notice notice-info inline"><p>' + ( s.analyzing || 'Analyzing...' ) + '</p></div>' ).show();

			var autoCreate = $( '#wp-mcp-ai-reg-bulk-auto-create' ).is( ':checked' );
			var requireConfirmation = $( '#wp-mcp-ai-reg-bulk-require-confirmation' ).is( ':checked' );

			$.ajax( {
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_bulk_import_reg_products',
					nonce: cfg.nonce,
					product_id: $( '#wp-mcp-ai-product-select' ).val() || 0,
					bulk_text: rawText,
					auto_create: autoCreate,
					require_confirmation: requireConfirmation,
					attachment_ids: uploadedAttachmentIds,
				},
				success: function( response ) {
					if ( response.success ) {
						resultContainer.html( response.data.summary ).show();
						$( document ).trigger( 'wpMcpAiRegBulkImportComplete', [ response.data ] );
					} else {
						resultContainer.html( '<div class="notice notice-error inline"><p>' + escapeHtml( response.data.message ) + '</p></div>' ).show();
					}
				},
				error: function() {
					resultContainer.html( '<div class="notice notice-error inline"><p>' + ( s.error || 'An error occurred.' ) + '</p></div>' ).show();
				},
				complete: function() {
					importBtn.prop( 'disabled', false );
					importBtn.find( '.dashicons' ).removeClass( 'spin' ).addClass( 'dashicons-update' );
				},
			} );
		} );

		// Clear button.
		clearBtn.on( 'click', function() {
			textarea.val( '' );
			uploadedAttachmentIds = [];
			fileItems.empty();
			fileList.hide();
			resultContainer.hide().empty();
		} );
	}

	// --- Guided Entry ---
	function initGuidedEntry() {
		var container = $( '#reg-guided-form-container' );
		var buttons = $( '.record-type-btn' );

		buttons.on( 'click', function() {
			buttons.removeClass( 'active' );
			$( this ).addClass( 'active' );

			var type = $( this ).data( 'type' );
			var url = '';

			switch ( type ) {
				case 'reg_product':
					url = cfg.addProductUrl;
					break;
				case 'registration':
					url = cfg.addRegUrl;
					break;
				case 'reg_document':
					url = cfg.addDocUrl;
					break;
				case 'country':
					url = cfg.productsUrl + '&post_type=mcp_ai_reg_country';
					break;
				case 'requirement':
					url = cfg.productsUrl + '&post_type=mcp_ai_reg_requirement';
					break;
			}

			var typeName = toTitleCase( type );

			container.html(
				'<div class="guided-entry-redirect">' +
				'<p>Opening the ' + escapeHtml( typeName.toLowerCase() ) + ' editor...</p>' +
				'<p><a href="' + url + '" class="button button-primary" target="_blank">' +
				'Open ' + escapeHtml( typeName ) + ' Editor' +
				'</a></p>' +
				'<p class="description">The AI assistant in the "AI Research" tab can also guide you through creating records step by step.</p>' +
				'</div>'
			).show();
		} );
	}

	// Initialize on document ready.
	$( document ).ready( initRegConsolidate );

}( jQuery ) );
