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

		// Guided entry functionality.
		initGuidedEntry();
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
					loadButton.prop( 'disabled', false ).text( loadButton.data( 'original-text' ) || wpMcpAiHealthConsolidate.strings.loadMember );
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
		
		// File upload elements.
		const fileInput = $( '#wp-mcp-ai-file-upload' );
		const fileUploadBtn = $( '#wp-mcp-ai-file-upload-btn' );
		const fileList = $( '#wp-mcp-ai-file-list' );
		const fileItems = $( '#wp-mcp-ai-file-items' );
		
		let uploadedFiles = [];
		let uploadedAttachmentIds = [];

		if ( ! importButton.length ) {
			return;
		}

		// File upload button click.
		fileUploadBtn.on( 'click', function() {
			fileInput.click();
		} );

		// Handle file selection.
		fileInput.on( 'change', function( e ) {
			const files = e.target.files;
			if ( files.length > 0 ) {
				uploadFiles( files );
			}
		} );

		/**
		 * Upload files to WordPress media library.
		 *
		 * @param {FileList} files Files to upload.
		 */
		function uploadFiles( files ) {
			const memberId = memberSelect.val();
			
			if ( ! memberId ) {
				alert( wpMcpAiHealthConsolidate.strings.selectMember );
				memberSelect.focus();
				return;
			}

			Array.from( files ).forEach( function( file ) {
				const formData = new FormData();
				formData.append( 'action', 'wp_mcp_ai_upload_health_document' );
				formData.append( 'nonce', wpMcpAiHealthConsolidate.nonce );
				formData.append( 'member_id', memberId );
				formData.append( 'file', file );

				$.ajax( {
					url: wpMcpAiHealthConsolidate.ajaxUrl,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function( response ) {
						if ( response.success && response.data.attachment_id ) {
							uploadedFiles.push( {
								name: response.data.file_name,
								id: response.data.attachment_id,
								url: response.data.file_url
							} );
							uploadedAttachmentIds.push( response.data.attachment_id );
							updateFileList();
						} else {
							const errorMessage = response.data && response.data.message ? response.data.message : wpMcpAiHealthConsolidate.strings.error;
							alert( errorMessage );
						}
					},
					error: function() {
						alert( wpMcpAiHealthConsolidate.strings.error );
					}
				} );
			} );
		}

		/**
		 * Update the file list UI.
		 */
		function updateFileList() {
			if ( uploadedFiles.length === 0 ) {
				fileList.hide();
				return;
			}

			fileItems.empty();
			uploadedFiles.forEach( function( file, index ) {
				const li = $( '<li>' );
				const fileName = $( '<span class="file-name"></span>' ).text( file.name );
				li.append( '<span class="dashicons dashicons-media-document"></span>' )
					.append( fileName )
					.append( '<span class="dashicons dashicons-no file-remove" data-index="' + index + '"></span>' );
				fileItems.append( li );
			} );

			fileList.show();
		}

		// Remove file from list.
		fileItems.on( 'click', '.file-remove', function() {
			const index = $( this ).data( 'index' );
			uploadedFiles.splice( index, 1 );
			uploadedAttachmentIds.splice( index, 1 );
			updateFileList();
		} );

		// Clear button.
		clearButton.on( 'click', function() {
			textarea.val( '' );
			resultContainer.hide().html( '' );
			uploadedFiles = [];
			uploadedAttachmentIds = [];
			fileInput.val( '' );
			updateFileList();
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

			if ( ! rawText && uploadedAttachmentIds.length === 0 ) {
				alert( wpMcpAiHealthConsolidate.strings.enterHealthInfo );
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
					attachment_ids: uploadedAttachmentIds
				},
				success: function( response ) {
					if ( response.success && response.data.summary_html ) {
						resultContainer.html( response.data.summary_html ).show();

						// Clear inputs on success if auto-created.
						if ( autoCreate && ! requireConfirmation ) {
							textarea.val( '' );
							uploadedFiles = [];
							uploadedAttachmentIds = [];
							fileInput.val( '' );
							updateFileList();
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

	/**
	 * Initialize guided entry functionality.
	 */
	function initGuidedEntry() {
		const recordTypeBtns = $( '.record-type-btn' );
		const guidedFormContainer = $( '#guided-form-container' );
		const memberSelect = $( '#wp-mcp-ai-member-select' );

		if ( ! recordTypeBtns.length ) {
			return;
		}

		// Handle record type button clicks.
		recordTypeBtns.on( 'click', function() {
			const recordType = $( this ).data( 'type' );
			const memberId = memberSelect.val();

			// Check if a member is selected.
			if ( ! memberId ) {
				alert( wpMcpAiHealthConsolidate.strings.selectMember );
				memberSelect.focus();
				// Switch to the sidebar workflow for member selection.
				$( '.workflow-option[data-workflow="review"]' ).trigger( 'click' );
				return;
			}

			// Highlight the clicked button.
			recordTypeBtns.removeClass( 'active' );
			$( this ).addClass( 'active' );

			// Show the form container with a loading message.
			guidedFormContainer.html(
				'<div class="notice notice-info inline"><p>' + wpMcpAiHealthConsolidate.strings.aiAssisting + '</p></div>'
			).show();

			// Scroll to the AI assistant section and trigger guidance.
			scrollToAIAssistant();
			triggerAIGuidance( recordType, memberId );
		} );

		/**
		 * Scroll to the AI assistant section.
		 */
		function scrollToAIAssistant() {
			const aiSection = $( '.wp-mcp-ai-consolidate-ai-section' );
			if ( aiSection.length ) {
				$( 'html, body' ).animate( {
					scrollTop: aiSection.offset().top - 50
				}, 500 );
			}
		}

		/**
		 * Trigger AI guidance for record creation.
		 *
		 * @param {string} recordType Type of record to create.
		 * @param {number} memberId   Member ID.
		 */
		function triggerAIGuidance( recordType, memberId ) {
			// Get member name for context.
			const memberName = memberSelect.find( 'option:selected' ).text();

			// Map record types to friendly names.
			const recordTypeNames = {
				'medical_record': 'medical record',
				'checkup': 'checkup/appointment',
				'prescription': 'prescription',
				'policy': 'insurance policy',
				'allergy': 'allergy'
			};

			const recordTypeName = recordTypeNames[ recordType ] || recordType;

			// Build the AI prompt.
			const prompt = 'Please help me add a new ' + recordTypeName + ' for ' + memberName + ' (member ID: ' + memberId + '). Guide me through the required fields step by step.';

			// Try to send the message to the chat interface.
			const chatTextarea = $( '.wp-mcp-ai-chat textarea[name="message"]' );
			const chatSubmitBtn = $( '.wp-mcp-ai-chat button[type="submit"]' );

			if ( chatTextarea.length && chatSubmitBtn.length ) {
				// Pre-fill the chat with the guidance request.
				chatTextarea.val( prompt ).focus();
				
				// Optionally auto-submit the message.
				setTimeout( function() {
					chatSubmitBtn.trigger( 'click' );
				}, 500 );

				// Update the guided form container with instructions.
				guidedFormContainer.html(
					'<div class="notice notice-success inline">' +
					'<p><strong>' + wpMcpAiHealthConsolidate.strings.aiAssisting + '</strong></p>' +
					'<p>The AI assistant below will guide you through creating a ' + recordTypeName + ' for ' + memberName + '.</p>' +
					'</div>'
				).show();
			} else {
				// Fallback: Show a message with a link to the manual entry page.
				const addUrl = getAddUrl( recordType );
				guidedFormContainer.html(
					'<div class="notice notice-warning inline">' +
					'<p>AI assistant not available. <a href="' + addUrl + '" class="button">Create ' + recordTypeName + ' manually</a></p>' +
					'</div>'
				).show();
			}
		}

		/**
		 * Get the URL for manually adding a record.
		 *
		 * @param {string} recordType Record type.
		 * @return {string} Add URL.
		 */
		function getAddUrl( recordType ) {
			const urlMap = {
				'medical_record': wpMcpAiHealthConsolidate.addRecordUrl,
				'checkup': wpMcpAiHealthConsolidate.addCheckupUrl,
				'prescription': wpMcpAiHealthConsolidate.addPrescUrl,
				'policy': wpMcpAiHealthConsolidate.addPolicyUrl,
				'allergy': wpMcpAiHealthConsolidate.addAllergyUrl
			};

			return urlMap[ recordType ] || wpMcpAiHealthConsolidate.addRecordUrl;
		}
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
