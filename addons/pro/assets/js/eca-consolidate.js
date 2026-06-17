/**
 * ECA Consolidate & Add Page JavaScript
 *
 * @package WP_MCP_AI_Pro
 */

/* global jQuery, wpMcpAiEcaConsolidate */
( function ( $ ) {
	'use strict';

	const ECA = {
		init: function () {
			this.bindEvents();
		},

		bindEvents: function () {
			const self = this;

			// Workflow mode switching.
			$( '.wp-mcp-ai-eca-consolidate-page .workflow-option' ).on( 'click', function () {
				const workflow = $( this ).data( 'workflow' );

				// Update active button.
				$( '.wp-mcp-ai-eca-consolidate-page .workflow-option' ).removeClass( 'active' );
				$( this ).addClass( 'active' );

				// Show/hide workflow content.
				$( '.wp-mcp-ai-eca-consolidate-page .workflow-content' ).hide().removeClass( 'active' );
				$( '#workflow-' + workflow ).show().addClass( 'active' );
			} );

			// Load ECA records.
			$( '#wp-mcp-ai-load-eca-btn' ).on( 'click', function () {
				self.loadEcaRecords();
			} );

			// Bulk import.
			$( '#wp-mcp-ai-eca-bulk-import-btn' ).on( 'click', function () {
				self.handleBulkImport();
			} );

			// Clear import.
			$( '#wp-mcp-ai-eca-bulk-clear-btn' ).on( 'click', function () {
				$( '#wp-mcp-ai-eca-bulk-import-text' ).val( '' );
				$( '#wp-mcp-ai-eca-bulk-import-result' ).hide().empty();
			} );

			// File upload.
			$( '#wp-mcp-ai-eca-file-upload-btn' ).on( 'click', function () {
				$( '#wp-mcp-ai-eca-file-upload' ).click();
			} );

			$( '#wp-mcp-ai-eca-file-upload' ).on( 'change', function () {
				self.handleFileSelect( this );
			} );

			// Guided entry buttons.
			$( '.wp-mcp-ai-eca-consolidate-page .record-type-btn' ).on( 'click', function () {
				const type = $( this ).data( 'type' );
				self.handleGuidedEntry( type );
			} );
		},

		/**
		 * Load ECA records preview.
		 */
		loadEcaRecords: function () {
			const ecaId  = $( '#wp-mcp-ai-eca-select' ).val();
			const $preview = $( '#wp-mcp-ai-eca-records-preview' );
			const $noSel   = $( '#wp-mcp-ai-eca-no-selection' );

			if ( ! ecaId ) {
				$preview.hide();
				$noSel.show();
				return;
			}

			$noSel.hide();
			$preview.show().html(
				'<p><span class="spinner is-active"></span> ' +
				wpMcpAiEcaConsolidate.strings.loading +
				'</p>'
			);

			$.post(
				wpMcpAiEcaConsolidate.ajaxUrl,
				{
					action: 'wp_mcp_ai_get_eca_records_preview',
					nonce: wpMcpAiEcaConsolidate.nonce,
					eca_id: ecaId,
				},
				function ( response ) {
					if ( response.success && response.data && response.data.eca ) {
						const eca = response.data.eca;
						let html = '<div class="eca-preview-card">';
						html += '<h3>' + ECA.escapeHtml( eca.title ) + '</h3>';
						html += '<table class="widefat striped">';
						html += '<tr><th>' + ECA.__('Status') + '</th><td>' + ECA.escapeHtml( eca.status ) + '</td></tr>';
						if ( eca.category ) {
							html += '<tr><th>' + ECA.__('Category') + '</th><td>' + ECA.escapeHtml( eca.category ) + '</td></tr>';
						}
						if ( eca.schedule ) {
							html += '<tr><th>' + ECA.__('Schedule') + '</th><td>' + ECA.escapeHtml( eca.schedule ) + '</td></tr>';
						}
						if ( eca.location ) {
							html += '<tr><th>' + ECA.__('Location') + '</th><td>' + ECA.escapeHtml( eca.location ) + '</td></tr>';
						}
						if ( eca.capacity ) {
							html += '<tr><th>' + ECA.__('Capacity') + '</th><td>' + ECA.escapeHtml( eca.capacity ) + '</td></tr>';
						}
						if ( eca.instructor ) {
							html += '<tr><th>' + ECA.__('Instructor') + '</th><td>' + ECA.escapeHtml( eca.instructor ) + '</td></tr>';
						}
						if ( eca.enrolled ) {
							html += '<tr><th>' + ECA.__('Enrolled') + '</th><td>' + ECA.escapeHtml( eca.enrolled ) + '</td></tr>';
						}
						if ( eca.term ) {
							html += '<tr><th>' + ECA.__('Term') + '</th><td>' + ECA.escapeHtml( eca.term ) + '</td></tr>';
						}
						html += '</table>';
						if ( eca.content ) {
							html += '<h4>' + ECA.__('Description') + '</h4>';
							html += '<div class="eca-preview-content">' + eca.content + '</div>';
						}
						html += '<p><a href="' + ECA.escapeHtml( eca.edit_url ) + '" class="button button-primary">' + ECA.__('Edit ECA') + '</a></p>';
						html += '</div>';
						$preview.html( html );
					} else {
						$preview.html(
							'<div class="notice notice-error inline"><p>' +
							( response.data && response.data.message ? ECA.escapeHtml( response.data.message ) : wpMcpAiEcaConsolidate.strings.error ) +
							'</p></div>'
						);
					}
				}
			).fail( function () {
				$preview.html(
					'<div class="notice notice-error inline"><p>' +
					wpMcpAiEcaConsolidate.strings.error +
					'</p></div>'
				);
			} );
		},

		/**
		 * Handle bulk import.
		 */
		handleBulkImport: function () {
			const importText = $( '#wp-mcp-ai-eca-bulk-import-text' ).val().trim();
			const $result   = $( '#wp-mcp-ai-eca-bulk-import-result' );

			if ( ! importText ) {
				$result.show().html(
					'<div class="notice notice-error inline"><p>' +
					wpMcpAiEcaConsolidate.strings.enterEcaInfo +
					'</p></div>'
				);
				return;
			}

			$result.show().html(
				'<p><span class="spinner is-active"></span> ' +
				wpMcpAiEcaConsolidate.strings.aiAssisting +
				'</p>'
			);

			$.post(
				wpMcpAiEcaConsolidate.ajaxUrl,
				{
					action: 'wp_mcp_ai_bulk_import_eca_info',
					nonce: wpMcpAiEcaConsolidate.nonce,
					import_text: importText,
				},
				function ( response ) {
					if ( response.success ) {
						$result.html(
							'<div class="notice notice-success inline"><p>' +
							ECA.escapeHtml( response.data.message ) +
							'</p></div>'
						);
					} else {
						$result.html(
							'<div class="notice notice-error inline"><p>' +
							( response.data && response.data.message ? ECA.escapeHtml( response.data.message ) : wpMcpAiEcaConsolidate.strings.error ) +
							'</p></div>'
						);
					}
				}
			).fail( function () {
				$result.html(
					'<div class="notice notice-error inline"><p>' +
					wpMcpAiEcaConsolidate.strings.error +
					'</p></div>'
				);
			} );
		},

		/**
		 * Handle file selection for upload.
		 */
		handleFileSelect: function ( input ) {
			const files = input.files;
			const $list = $( '#wp-mcp-ai-eca-file-list' );
			const $items = $( '#wp-mcp-ai-eca-file-items' );

			if ( files.length > 0 ) {
				$list.show();
				$items.empty();
				Array.from( files ).forEach( function ( file ) {
					$items.append( '<li>' + ECA.escapeHtml( file.name ) + ' (' + ECA.formatFileSize( file.size ) + ')</li>' );
				} );
			} else {
				$list.hide();
			}
		},

		/**
		 * Handle guided entry button click.
		 */
		handleGuidedEntry: function ( type ) {
			const $container = $( '#guided-form-container' );
			const urls = {
				eca: wpMcpAiEcaConsolidate.addEcaUrl,
				student: wpMcpAiEcaConsolidate.addStudentUrl,
				enrollment: wpMcpAiEcaConsolidate.ecasUrl,
				attendance: wpMcpAiEcaConsolidate.ecasUrl,
				term: wpMcpAiEcaConsolidate.ecasUrl,
			};

			if ( urls[ type ] ) {
				$container.show().html(
					'<p><span class="spinner is-active"></span> ' +
					wpMcpAiEcaConsolidate.strings.aiAssisting +
					'</p><p><a href="' + ECA.escapeHtml( urls[ type ] ) + '" class="button button-primary" target="_blank">' +
					ECA.__('Open in New Tab') +
					'</a></p>'
				);
			}
		},

		/**
		 * Escape HTML entities.
		 */
		escapeHtml: function ( text ) {
			const div = document.createElement( 'div' );
			div.appendChild( document.createTextNode( text ) );
			return div.innerHTML;
		},

		/**
		 * Format file size.
		 */
		formatFileSize: function ( bytes ) {
			if ( bytes < 1024 ) return bytes + ' B';
			if ( bytes < 1048576 ) return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
			return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		},

		/**
		 * Translation helper.
		 */
		__: function ( str ) {
			// Minimal pass-through; strings are already translated in PHP.
			return str;
		},
	};

	// Initialize on DOM ready.
	$( document ).ready( function () {
		ECA.init();
	} );

}( jQuery ) );
