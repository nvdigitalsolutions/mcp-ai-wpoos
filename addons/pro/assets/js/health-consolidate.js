/**
 * Health Records Consolidate & Add Page JavaScript
 *
 * @package WP_MCP_AI_Pro
 */

( function( $ ) {
	'use strict';

	/**
	 * Initialize the health consolidate page.
	 */
	function initHealthConsolidate() {
		// Workflow switching.
		initWorkflowSwitcher();

		// Member selection and preview.
		initMemberSelection();

		// Bulk import functionality.
		initBulkImport();
	}

	/**
	 * Initialize workflow mode switcher.
	 */
	function initWorkflowSwitcher() {
		$( '.workflow-option' ).on( 'click', function() {
			const workflow = $( this ).data( 'workflow' );
			
			// Update active button.
			$( '.workflow-option' ).removeClass( 'active' );
			$( this ).addClass( 'active' );

			// Show corresponding content.
			$( '.workflow-content' ).removeClass( 'active' ).hide();
			$( '#workflow-' + workflow ).addClass( 'active' ).fadeIn( 200 );
		} );
	}

	/**
	 * Initialize member selection.
	 */
	function initMemberSelection() {
		const memberSelect = $( '#wp-mcp-ai-member-select' );
		const loadButton = $( '#wp-mcp-ai-load-member-btn' );
		const previewContainer = $( '#wp-mcp-ai-records-preview' );
		const noSelectionNotice = $( '#wp-mcp-ai-no-selection' );

		if ( ! memberSelect.length || ! loadButton.length ) {
			return;
		}

		// Load member records when button is clicked.
		loadButton.on( 'click', function( e ) {
			e.preventDefault();
			loadMemberRecords();
		} );

		// Also load on Enter key in select.
		memberSelect.on( 'keypress', function( e ) {
			if ( e.which === 13 ) {
				e.preventDefault();
				loadMemberRecords();
			}
		} );

		/**
		 * Load member records via AJAX.
		 */
		function loadMemberRecords() {
			const memberId = memberSelect.val();

			if ( ! memberId ) {
				alert( wpMcpAiHealthConsolidate.strings.selectMember );
				return;
			}

			// Show loading state.
			loadButton.prop( 'disabled', true ).text( wpMcpAiHealthConsolidate.strings.loading );
			previewContainer.html( '<div class="notice notice-info inline"><p>' + wpMcpAiHealthConsolidate.strings.loading + '</p></div>' ).show();
			noSelectionNotice.hide();

			// Make AJAX request.
			$.ajax( {
				url: wpMcpAiHealthConsolidate.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_member_records_preview',
					nonce: wpMcpAiHealthConsolidate.nonce,
					member_id: memberId,
				},
				success: function( response ) {
					if ( response.success && response.data.html ) {
						previewContainer.html( response.data.html ).show();
						noSelectionNotice.hide();

						// Trigger custom event for extensions.
						$( document ).trigger( 'wpMcpAiMemberRecordsLoaded', [ memberId, response.data.member_data ] );

						// Optionally check completeness and show suggestions.
						checkRecordCompleteness( memberId );
					} else {
						const errorMessage = response.data && response.data.message ? response.data.message : wpMcpAiHealthConsolidate.strings.error;
						previewContainer.html( '<div class="notice notice-error inline"><p>' + errorMessage + '</p></div>' ).show();
					}
				},
				error: function() {
					previewContainer.html( '<div class="notice notice-error inline"><p>' + wpMcpAiHealthConsolidate.strings.error + '</p></div>' ).show();
				},
				complete: function() {
					loadButton.prop( 'disabled', false ).text( loadButton.data( 'original-text' ) || wpMcpAiHealthConsolidate.strings.loadMember || 'Load Member Records' );
				},
			} );
		}

		/**
		 * Check record completeness for a member.
		 *
		 * @param {number} memberId Member ID.
		 */
		function checkRecordCompleteness( memberId ) {
			$.ajax( {
				url: wpMcpAiHealthConsolidate.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_check_record_completeness',
					nonce: wpMcpAiHealthConsolidate.nonce,
					member_id: memberId,
				},
				success: function( response ) {
					if ( response.success && response.data.suggestions && response.data.suggestions.length > 0 ) {
						// Display suggestions in AI chat or as a notice.
						displayCompletnessSuggestions( response.data.suggestions, response.data.completeness_message );
					}
				},
			} );
		}

		/**
		 * Display completeness suggestions.
		 *
		 * @param {Array}  suggestions Suggestions array.
		 * @param {string} message     Completeness message.
		 */
		function displayCompletnessSuggestions( suggestions, message ) {
			// You can customize how suggestions are displayed.
			// For now, we'll log them for potential AI assistant pickup.
			if ( window.console ) {
				console.log( 'Health Profile Completeness:', message );
				console.log( 'Suggestions:', suggestions );
			}

			// Trigger custom event for AI assistant integration.
			$( document ).trigger( 'wpMcpAiCompletenessSuggestions', [ suggestions, message ] );
		}

		// Store original button text.
		loadButton.data( 'original-text', loadButton.text() );
	}

	/**
	 * Initialize bulk import functionality.
	 */
	function initBulkImport() {
		const textarea = $( '#wp-mcp-ai-bulk-import-text' );
		const importButton = $( '#wp-mcp-ai-bulk-import-btn' );
		const clearButton = $( '#wp-mcp-ai-bulk-clear-btn' );
		const resultContainer = $( '#wp-mcp-ai-bulk-import-result' );
		const memberSelect = $( '#wp-mcp-ai-member-select' );
		const autoCreateCheckbox = $( '#wp-mcp-ai-bulk-auto-create' );
		const confirmationCheckbox = $( '#wp-mcp-ai-bulk-require-confirmation' );

		if ( ! importButton.length ) {
			return;
		}

		// Clear button.
		clearButton.on( 'click', function() {
			textarea.val( '' );
			resultContainer.hide().html( '' );
		} );

		// Import button.
		importButton.on( 'click', function() {
			const rawText = textarea.val().trim();
			const memberId = memberSelect.val();
			const autoCreate = autoCreateCheckbox.is( ':checked' );
			const requireConfirmation = confirmationCheckbox.is( ':checked' );

			if ( ! memberId ) {
				alert( wpMcpAiHealthConsolidate.strings.selectMember );
				memberSelect.focus();
				return;
			}

			if ( ! rawText ) {
				alert( 'Please enter health information to import.' );
				textarea.focus();
				return;
			}

			// Show loading state.
			importButton.prop( 'disabled', true );
			importButton.find( '.dashicons' ).removeClass( 'dashicons-update' ).addClass( 'dashicons-update spin' );
			resultContainer.html( '<div class="notice notice-info inline"><p>' + wpMcpAiHealthConsolidate.strings.analyzing + '</p></div>' ).show();

			// Make AJAX request.
			$.ajax( {
				url: wpMcpAiHealthConsolidate.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_bulk_import_health_info',
					nonce: wpMcpAiHealthConsolidate.nonce,
					member_id: memberId,
					raw_information: rawText,
					auto_create: autoCreate,
					require_confirmation: requireConfirmation,
				},
				success: function( response ) {
					if ( response.success && response.data.summary_html ) {
						resultContainer.html( response.data.summary_html ).show();

						// Clear textarea on success if auto-created.
						if ( autoCreate && ! requireConfirmation ) {
							textarea.val( '' );
						}

						// Trigger custom event.
						$( document ).trigger( 'wpMcpAiBulkImportComplete', [ memberId, response.data.result ] );
					} else {
						const errorMessage = response.data && response.data.message ? response.data.message : wpMcpAiHealthConsolidate.strings.error;
						resultContainer.html( '<div class="notice notice-error inline"><p>' + errorMessage + '</p></div>' ).show();
					}
				},
				error: function() {
					resultContainer.html( '<div class="notice notice-error inline"><p>' + wpMcpAiHealthConsolidate.strings.error + '</p></div>' ).show();
				},
				complete: function() {
					importButton.prop( 'disabled', false );
					importButton.find( '.dashicons' ).removeClass( 'spin' ).addClass( 'dashicons-update' );
				},
			} );
		} );
	}

	// Add CSS for spinning icon.
	const style = document.createElement( 'style' );
	style.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } } .spin { animation: spin 1s linear infinite; }';
	document.head.appendChild( style );

	// Initialize on document ready.
	$( document ).ready( function() {
		initHealthConsolidate();
	} );
} )( jQuery );
