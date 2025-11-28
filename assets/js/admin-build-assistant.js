/**
 * WP MCP AI Build Assistant Page JavaScript
 *
 * Handles the Build Assistant admin page functionality.
 *
 * @package WP_MCP_AI
 */

/* global jQuery, wpMcpAiCreateAssistant, wpMcpAiChat */

( function( $ ) {
	'use strict';

	/**
	 * Build Assistant Page Controller.
	 */
	const BuildAssistantPage = {
		/**
		 * Current chat instance ID.
		 */
		currentInstanceId: null,

		/**
		 * Initialize the page.
		 */
		init: function() {
			this.initManualTab();
			this.initPromptTab();
		},

		/**
		 * Initialize the Manual tab functionality.
		 */
		initManualTab: function() {
			var self = this;
			var form = $( '#wp-mcp-ai-create-assistant-form' );

			if ( form.length === 0 ) {
				return;
			}

			// Handle form submission.
			form.on( 'submit', function( e ) {
				e.preventDefault();
				self.handleManualFormSubmit( form );
			} );

			// Handle file attachments.
			$( '#assistant-attachments' ).on( 'change', function( e ) {
				self.handleFileAttachments( e.target.files );
			} );

			// Remove attachment.
			$( document ).on( 'click', '.remove-attachment', function() {
				$( this ).closest( '.wp-mcp-ai-attachment-item' ).remove();
			} );
		},

		/**
		 * Handle manual form submission.
		 *
		 * @param {jQuery} form - The form element.
		 */
		handleManualFormSubmit: function( form ) {
			var self = this;

			// Clear previous messages.
			$( '.wp-mcp-ai-error-message, .wp-mcp-ai-success-message' ).remove();

			// Validate professions (max 3).
			var professions = $( '#assistant-professions' ).val();
			if ( ! professions || professions.length === 0 ) {
				self.showMessage( 'error', wpMcpAiCreateAssistant.strings.required );
				return;
			}
			if ( professions.length > 3 ) {
				self.showMessage( 'error', wpMcpAiCreateAssistant.strings.maxProfessions );
				return;
			}

			// Validate regions (max 2).
			var regions = $( '#assistant-regions' ).val();
			if ( ! regions || regions.length === 0 ) {
				self.showMessage( 'error', wpMcpAiCreateAssistant.strings.required );
				return;
			}
			if ( regions.length > 2 ) {
				self.showMessage( 'error', wpMcpAiCreateAssistant.strings.maxRegions );
				return;
			}

			// Show loading state.
			var submitButton = $( '#wp-mcp-ai-submit-create' );
			submitButton.prop( 'disabled', true ).text( wpMcpAiCreateAssistant.strings.creating );

			// Collect attachment IDs from uploaded files.
			var attachmentIds = [];
			$( '#assistant-attachments-list .wp-mcp-ai-attachment-item' ).each( function() {
				var id = $( this ).data( 'attachment-id' );
				if ( id ) {
					attachmentIds.push( id );
				}
			} );

			// Prepare form data.
			var formData = {
				action: 'wp_mcp_ai_create_assistant_from_modal',
				nonce: wpMcpAiCreateAssistant.nonce,
				title: $( '#assistant-title' ).val(),
				professions: professions,
				regions: regions,
				industry_focus: $( '#assistant-industry' ).val(),
				provider: $( '#assistant-provider' ).val(),
				model: $( '#assistant-model' ).val(),
				temperature: $( '#assistant-temperature' ).val(),
				async: $( '#assistant-async' ).is( ':checked' ) ? '1' : '0',
				attachment_ids: attachmentIds
			};

			// Send AJAX request.
			$.ajax( {
				url: wpMcpAiCreateAssistant.ajaxUrl,
				type: 'POST',
				data: formData,
				success: function( response ) {
					submitButton.prop( 'disabled', false ).text( wpMcpAiCreateAssistant.strings.createAssistant );

					if ( response.success ) {
						self.showMessage( 'success', response.data.message || wpMcpAiCreateAssistant.strings.success );

						// Redirect to edit page if assistant was created synchronously.
						if ( response.data.assistant_id ) {
							setTimeout( function() {
								window.location.href = response.data.edit_link || ( 'post.php?post=' + response.data.assistant_id + '&action=edit' );
							}, 1000 );
						} else if ( response.data.status === 'scheduled' ) {
							// For async creation, just show success and reload.
							setTimeout( function() {
								form[ 0 ].reset();
								location.reload();
							}, 2000 );
						}
					} else {
						self.showMessage( 'error', response.data.message || wpMcpAiCreateAssistant.strings.error );
					}
				},
				error: function( xhr, status, error ) {
					submitButton.prop( 'disabled', false ).text( wpMcpAiCreateAssistant.strings.createAssistant );
					self.showMessage( 'error', wpMcpAiCreateAssistant.strings.error + ' (' + error + ')' );
				}
			} );
		},

		/**
		 * Handle file attachments.
		 *
		 * @param {FileList} files - The files to upload.
		 */
		handleFileAttachments: function( files ) {
			var self = this;
			var $list = $( '#assistant-attachments-list' );
			var allowedTypes = [
				'text/plain',
				'text/markdown',
				'application/pdf',
				'application/msword',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
			];

			for ( var i = 0; i < files.length; i++ ) {
				var file = files[ i ];

				// Basic type validation.
				var isValid = allowedTypes.some( function( type ) {
					return file.type === type || file.name.match( /\.(txt|md|pdf|doc|docx)$/i );
				} );

				if ( ! isValid ) {
					self.showMessage( 'error', 'File "' + file.name + '" is not a supported type.' );
					continue;
				}

				// Upload file.
				self.uploadAttachment( file, $list );
			}

			// Reset file input.
			$( '#assistant-attachments' ).val( '' );
		},

		/**
		 * Upload an attachment file.
		 *
		 * @param {File}   file  - The file to upload.
		 * @param {jQuery} $list - The list element to append to.
		 */
		uploadAttachment: function( file, $list ) {
			var formData = new FormData();
			formData.append( 'file', file );
			formData.append( 'action', 'wp_mcp_ai_upload_assistant_attachment' );
			formData.append( 'nonce', wpMcpAiCreateAssistant.nonce );

			// Create placeholder item.
			var $item = $( '<li class="wp-mcp-ai-attachment-item uploading">' +
				'<span class="name">' + file.name + '</span>' +
				'<span class="status">Uploading...</span>' +
				'</li>' );
			$list.append( $item );

			$.ajax( {
				url: wpMcpAiCreateAssistant.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function( response ) {
					if ( response.success ) {
						$item.removeClass( 'uploading' )
							.data( 'attachment-id', response.data.attachment_id )
							.find( '.status' ).html( '<button type="button" class="remove-attachment">&times;</button>' );
					} else {
						$item.addClass( 'error' ).find( '.status' ).text( 'Failed' );
						setTimeout( function() {
							$item.remove();
						}, 2000 );
					}
				},
				error: function() {
					$item.addClass( 'error' ).find( '.status' ).text( 'Failed' );
					setTimeout( function() {
						$item.remove();
					}, 2000 );
				}
			} );
		},

		/**
		 * Initialize the Prompt tab functionality.
		 */
		initPromptTab: function() {
			var self = this;
			var $select = $( '#wp-mcp-ai-prompt-assistant-select' );
			var $startBtn = $( '#wp-mcp-ai-start-chat-btn' );
			var $chatContainer = $( '#wp-mcp-ai-prompt-chat-container' );
			var $toolsSection = $( '#wp-mcp-ai-prompt-tools-section' );
			var $toolsGrid = $( '#wp-mcp-ai-prompt-tools-grid' );

			if ( $select.length === 0 ) {
				return;
			}

			// Initialize tool selection tracking.
			self.selectedTools = [];

			// Enable/disable start button based on selection.
			$select.on( 'change', function() {
				var selectedValue = $( this ).val();
				$startBtn.prop( 'disabled', ! selectedValue );

				// Show/hide and update tools section when assistant is selected.
				if ( selectedValue ) {
					var $selectedOption = $( this ).find( 'option:selected' );
					var selectedToolsJson = $selectedOption.data( 'selected-tools' );
					var selectedTools = [];

					if ( selectedToolsJson ) {
						try {
							selectedTools = typeof selectedToolsJson === 'string' ? JSON.parse( selectedToolsJson ) : selectedToolsJson;
							if ( ! Array.isArray( selectedTools ) ) {
								selectedTools = [];
							}
						} catch ( e ) {
							selectedTools = [];
						}
					}

					// Update tool checkboxes.
					self.updateToolCheckboxes( selectedTools );
					self.selectedTools = selectedTools.slice();

					// Show tools section.
					$toolsSection.show();
				} else {
					// Hide tools section.
					$toolsSection.hide();
					self.selectedTools = [];
				}
			} );

			// Handle tool checkbox changes.
			$toolsGrid.on( 'change', '.wp-mcp-ai-prompt-tools__checkbox', function() {
				var $checkbox = $( this );
				var toolSlug = $checkbox.val();
				var isChecked = $checkbox.is( ':checked' );
				var $item = $checkbox.closest( '.wp-mcp-ai-prompt-tools__item' );

				// Update item state.
				$item.attr( 'data-selected', isChecked ? 'true' : 'false' );

				// Update selected tools array.
				if ( isChecked ) {
					if ( self.selectedTools.indexOf( toolSlug ) === -1 ) {
						self.selectedTools.push( toolSlug );
					}
				} else {
					var index = self.selectedTools.indexOf( toolSlug );
					if ( index > -1 ) {
						self.selectedTools.splice( index, 1 );
					}
				}

				// Update group counts.
				self.updateToolGroupCounts();
			} );

			// Handle Start Chat button click.
			$startBtn.on( 'click', function() {
				var $selectedOption = $select.find( 'option:selected' );
				var assistantId = $selectedOption.val();
				var assistantTitle = $selectedOption.data( 'assistant-title' );
				var toolShortcutsJson = $selectedOption.data( 'tool-shortcuts' );
				var toolShortcuts = [];

				if ( toolShortcutsJson ) {
					try {
						toolShortcuts = typeof toolShortcutsJson === 'string' ? JSON.parse( toolShortcutsJson ) : toolShortcutsJson;
						if ( ! Array.isArray( toolShortcuts ) ) {
							toolShortcuts = [];
						}
					} catch ( e ) {
						toolShortcuts = [];
					}
				}

				if ( assistantId ) {
					// Pass the currently selected tools to the chat widget.
					self.initializeChatWidget( assistantId, assistantTitle, toolShortcuts, $chatContainer, self.selectedTools.slice() );
				}
			} );

			// Handle Build button click in chat (Prompt tab).
			$( document ).on( 'click', '.wp-mcp-ai-chat-container .wp-mcp-ai-chat__build', function( e ) {
				e.preventDefault();
				self.handleBuildFromConversation( $( this ) );
			} );
		},

		/**
		 * Update tool checkboxes based on selected tools array.
		 *
		 * @param {Array} selectedTools Array of tool slugs that should be checked.
		 */
		updateToolCheckboxes: function( selectedTools ) {
			var $toolsGrid = $( '#wp-mcp-ai-prompt-tools-grid' );

			// Uncheck all checkboxes first.
			$toolsGrid.find( '.wp-mcp-ai-prompt-tools__checkbox' ).each( function() {
				var $checkbox = $( this );
				var toolSlug = $checkbox.val();
				var isSelected = selectedTools.indexOf( toolSlug ) > -1;

				$checkbox.prop( 'checked', isSelected );
				$checkbox.closest( '.wp-mcp-ai-prompt-tools__item' ).attr( 'data-selected', isSelected ? 'true' : 'false' );
			} );

			// Update group counts.
			this.updateToolGroupCounts();
		},

		/**
		 * Update the count display for each tool group.
		 */
		updateToolGroupCounts: function() {
			$( '#wp-mcp-ai-prompt-tools-grid .wp-mcp-ai-prompt-tools__group' ).each( function() {
				var $group = $( this );
				var $checkboxes = $group.find( '.wp-mcp-ai-prompt-tools__checkbox' );
				var checkedCount = $checkboxes.filter( ':checked' ).length;

				$group.find( '.wp-mcp-ai-prompt-tools__selected-count' ).text( checkedCount );
			} );
		},

		/**
		 * Initialize the chat widget for a selected assistant.
		 *
		 * @param {string} assistantId     The assistant post ID.
		 * @param {string} assistantTitle  The assistant title for display.
		 * @param {Array}  toolShortcuts   Tool shortcuts for the assistant.
		 * @param {jQuery} $container      The container element for the chat.
		 * @param {Array}  selectedTools   Array of selected tool slugs.
		 */
		initializeChatWidget: function( assistantId, assistantTitle, toolShortcuts, $container, selectedTools ) {
			var self = this;

			// Default to empty array if not provided.
			if ( ! Array.isArray( selectedTools ) ) {
				selectedTools = [];
			}

			// Clear previous chat.
			$container.empty();

			// Create unique instance ID.
			var instanceId = 'wp-mcp-ai-prompt-chat-' + assistantId + '-' + Date.now();
			self.currentInstanceId = instanceId;

			// Build chat HTML structure.
			var chatHTML = self.buildChatHTML( instanceId, assistantTitle );
			$container.html( chatHTML );

			// Initialize chat instance configuration.
			if ( ! window.wpMcpAiChatInstances ) {
				window.wpMcpAiChatInstances = {};
			}

			// Build endpoints from base REST URL.
			var baseRestUrl = ( window.wpMcpAiChat && window.wpMcpAiChat.restUrl ) ? window.wpMcpAiChat.restUrl : '/wp-json/mcp-ai/v1';

			// Get file upload configuration from global config.
			var fileAccept = ( window.wpMcpAiChat && window.wpMcpAiChat.fileAccept ) ? window.wpMcpAiChat.fileAccept : '';
			var allowedImageMimes = ( window.wpMcpAiChat && window.wpMcpAiChat.allowedImageMimes ) ? window.wpMcpAiChat.allowedImageMimes : [];
			var allowedFileMimes = ( window.wpMcpAiChat && window.wpMcpAiChat.allowedFileMimes ) ? window.wpMcpAiChat.allowedFileMimes : [];
			var allowedExtensions = ( window.wpMcpAiChat && window.wpMcpAiChat.allowedExtensions ) ? window.wpMcpAiChat.allowedExtensions : [];

			window.wpMcpAiChatInstances[ instanceId ] = {
				id: instanceId,
				assistantId: assistantId,
				userId: ( window.wpMcpAiChat && typeof window.wpMcpAiChat.currentUserId !== 'undefined' ) ? window.wpMcpAiChat.currentUserId : 0,
				restUrl: baseRestUrl,
				messagesEndpoint: baseRestUrl + '/chat-client',
				toolsEndpoint: baseRestUrl + '/tools',
				filesEndpoint: ( window.wpMcpAiChat && window.wpMcpAiChat.filesEndpoint ) ? window.wpMcpAiChat.filesEndpoint : baseRestUrl + '/files/',
				transcriptsEndpoint: ( window.wpMcpAiChat && window.wpMcpAiChat.transcriptsEndpoint ) ? window.wpMcpAiChat.transcriptsEndpoint : baseRestUrl + '/chat-transcripts',
				crawl4aiTaskEndpoint: baseRestUrl + '/crawl4ai/task/',
				uploadEndpoint: ( window.wpMcpAiChat && window.wpMcpAiChat.uploadEndpoint ) ? window.wpMcpAiChat.uploadEndpoint : '/wp-json/wp/v2/media',
				sessionKey: self.generateSessionKey(),
				enableStreaming: true,
				canUploadAttachments: true,
				saveTranscript: false, // Don't save transcripts for builder conversations
				allowSensitiveTools: true, // Admin users can access all tools
				toolShortcuts: toolShortcuts,
				selectedTools: selectedTools, // Tools selected by the user for this session
				fileAccept: fileAccept,
				allowedImageMimes: allowedImageMimes,
				allowedFileMimes: allowedFileMimes,
				allowedExtensions: allowedExtensions,
				restNonce: ( window.wpMcpAiChat && window.wpMcpAiChat.nonce ) ? window.wpMcpAiChat.nonce : '',
				historyPerPage: 20,
				asyncToolTimeout: ( window.wpMcpAiChat && window.wpMcpAiChat.asyncToolTimeout ) ? window.wpMcpAiChat.asyncToolTimeout : 300000
			};

			// Show the Build button.
			var $buildButton = $container.find( '.wp-mcp-ai-chat__build' );
			if ( $buildButton.length > 0 ) {
				$buildButton.removeAttr( 'hidden' ).show();
			}

			// Trigger chat.js initialization.
			self.triggerChatInit( instanceId );
		},

		/**
		 * Build the chat interface HTML structure.
		 *
		 * @param {string} instanceId      Unique instance identifier.
		 * @param {string} assistantTitle  The assistant title for display.
		 * @return {string} HTML string for chat interface.
		 */
		buildChatHTML: function( instanceId, assistantTitle ) {
			var self = this;
			var placeholderText = self.getString( 'placeholder', 'Ask something...' );
			var attachLabel = self.getString( 'attachFile', 'Attach file' );
			var transcribeLabel = self.getString( 'transcribeAudio', 'Transcribe audio' );
			var sendLabel = self.getString( 'send', 'Send' );
			var buildLabel = self.getString( 'build', 'Build' );

			return '<div class="wp-mcp-ai-chat" id="' + self.escapeHtml( instanceId ) + '" data-wp-mcp-ai-chat>' +
				'<div class="wp-mcp-ai-chat__assistant">' +
				'<label class="wp-mcp-ai-chat__label">' + self.escapeHtml( assistantTitle || 'Assistant' ) + '</label>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__transcript-controls">' +
				'<button type="button" class="wp-mcp-ai-chat__transcript-toggle" aria-expanded="false" aria-label="Expand conversation">' +
				'<svg class="wp-mcp-ai-chat__transcript-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z" />' +
				'</svg>' +
				'<span class="screen-reader-text">Expand conversation</span>' +
				'</button>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__messages" role="log" aria-live="polite" aria-atomic="false"></div>' +
				'<form class="wp-mcp-ai-chat__form">' +
				'<div class="wp-mcp-ai-chat__status" role="status" aria-live="polite" hidden></div>' +
				'<div class="wp-mcp-ai-chat__tool-shortcuts-wrapper" hidden>' +
				'<button type="button" class="wp-mcp-ai-chat__tool-shortcuts-toggle wp-mcp-ai-chat__tool-shortcuts-toggle--collapsed" aria-expanded="false" aria-controls="' + instanceId + '-tool-shortcuts">' +
				'<span class="wp-mcp-ai-chat__tool-shortcuts-toggle-text">Quick Tasks</span>' +
				'<svg class="wp-mcp-ai-chat__tool-shortcuts-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z" />' +
				'</svg>' +
				'</button>' +
				'<div id="' + instanceId + '-tool-shortcuts" class="wp-mcp-ai-chat__tool-shortcuts wp-mcp-ai-chat__tool-shortcuts--collapsed" role="group" aria-label="Assistant tool tasks" hidden></div>' +
				'</div>' +
				'<textarea class="wp-mcp-ai-chat__input" rows="4" placeholder="' + self.escapeHtml( placeholderText ) + '" required></textarea>' +
				'<div class="wp-mcp-ai-chat__attachments" hidden>' +
				'<div class="wp-mcp-ai-chat__attachments-header">Attachments</div>' +
				'<ul class="wp-mcp-ai-chat__attachments-list"></ul>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__actions">' +
				'<input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden />' +
				'<input type="file" class="wp-mcp-ai-chat__transcribe-input" accept="audio/*" hidden />' +
				'<button type="button" class="wp-mcp-ai-chat__transcribe" aria-label="' + self.escapeHtml( transcribeLabel ) + '">' +
				'<svg class="wp-mcp-ai-chat__transcribe-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path>' +
				'<path d="M12 16a7 7 0 0 0 6.93-6H17a5 5 0 0 1-10 0H5.07A7 7 0 0 0 12 16zm-1 2.05V21h2v-2.95A9 9 0 0 0 20.95 11H19a7 7 0 0 1-14 0H3.05A9 9 0 0 0 11 18.05z"></path>' +
				'</svg>' +
				'<span class="screen-reader-text">' + self.escapeHtml( transcribeLabel ) + '</span>' +
				'</button>' +
				'<button type="button" class="wp-mcp-ai-chat__attach">' + self.escapeHtml( attachLabel ) + '</button>' +
				'<button type="button" class="wp-mcp-ai-chat__build">' + self.escapeHtml( buildLabel ) + '</button>' +
				'<button type="submit" class="wp-mcp-ai-chat__submit">' + self.escapeHtml( sendLabel ) + '</button>' +
				'</div>' +
				'</form>' +
				'<div class="wp-mcp-ai-chat__controls">' +
				'<div class="wp-mcp-ai-chat__quota-monitor" role="status" aria-live="polite" aria-atomic="true"></div>' +
				'<div class="wp-mcp-ai-chat__cron-status" role="status" aria-live="polite" aria-atomic="true" hidden>' +
				'<span class="wp-mcp-ai-chat__cron-status-label">Jobs:</span>' +
				'<span class="wp-mcp-ai-chat__cron-status-pending" title="Pending jobs">' +
				'<span class="wp-mcp-ai-chat__cron-status-count">0</span>' +
				'</span>' +
				'<span class="wp-mcp-ai-chat__cron-status-completed" title="Completed jobs">' +
				'<span class="wp-mcp-ai-chat__cron-status-count">0</span>' +
				'</span>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__control-buttons">' +
				'<button type="button" class="wp-mcp-ai-chat__new-chat" aria-label="Start new conversation">' +
				'<svg class="wp-mcp-ai-chat__new-chat-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 4a1 1 0 011 1v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6V5a1 1 0 011-1z" />' +
				'</svg>' +
				'<span class="screen-reader-text">Start new conversation</span>' +
				'</button>' +
				'</div>' +
				'</div>' +
				'</div>';
		},

		/**
		 * Trigger chat.js initialization for the instance.
		 *
		 * @param {string} instanceId Instance identifier.
		 */
		triggerChatInit: function( instanceId ) {
			// Wait for DOM to settle.
			setTimeout( function() {
				var container = document.getElementById( instanceId );

				if ( ! container ) {
					return;
				}

				// Trigger a DOMContentLoaded event to re-init chat.js.
				var event = document.createEvent( 'Event' );
				event.initEvent( 'DOMContentLoaded', true, true );
				document.dispatchEvent( event );

				// Focus the textarea.
				setTimeout( function() {
					var textarea = container.querySelector( '.wp-mcp-ai-chat__input' );
					if ( textarea ) {
						textarea.focus();
					}
				}, 200 );
			}, 100 );
		},

		/**
		 * Generate a unique session key.
		 *
		 * @return {string} Session key.
		 */
		generateSessionKey: function() {
			return 'build-' + Math.random().toString( 36 ).substring( 2, 15 ) + Math.random().toString( 36 ).substring( 2, 15 );
		},

		/**
		 * Get a localized string.
		 *
		 * @param {string} key          The string key.
		 * @param {string} defaultValue Default value if not found.
		 * @return {string} The localized string.
		 */
		getString: function( key, defaultValue ) {
			if ( window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings[ key ] ) {
				return window.wpMcpAiChat.strings[ key ];
			}
			return defaultValue;
		},

		/**
		 * Escape HTML to prevent XSS.
		 *
		 * @param {string} text Text to escape.
		 * @return {string} Escaped text.
		 */
		escapeHtml: function( text ) {
			var div = document.createElement( 'div' );
			div.textContent = text;
			return div.innerHTML;
		},

		/**
		 * Handle building assistant from conversation.
		 *
		 * @param {jQuery} $button - The build button element.
		 */
		handleBuildFromConversation: function( $button ) {
			var self = this;
			var $chat = $button.closest( '.wp-mcp-ai-chat' );

			if ( $button.prop( 'disabled' ) ) {
				return;
			}

			// Disable button and show loading state.
			$button.prop( 'disabled', true );
			var originalText = $button.text();
			$button.text( ( typeof wpMcpAiChat !== 'undefined' && wpMcpAiChat.strings && wpMcpAiChat.strings.building ) ? wpMcpAiChat.strings.building : 'Building...' );

			// Get the conversation history from the chat.
			var conversationData = self.collectConversationData( $chat );

			if ( ! conversationData || ! conversationData.messages || conversationData.messages.length === 0 ) {
				self.showMessage( 'error', wpMcpAiCreateAssistant.strings.emptyConversation );
				$button.prop( 'disabled', false ).text( originalText );
				return;
			}

			// Get attachment IDs from the chat.
			var attachmentIds = self.collectAttachmentIds( $chat );

			// Send request to build the assistant.
			$.ajax( {
				url: wpMcpAiCreateAssistant.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_build_assistant_from_conversation',
					nonce: wpMcpAiCreateAssistant.nonce,
					conversation: JSON.stringify( conversationData ),
					attachment_ids: attachmentIds
				},
				success: function( response ) {
					$button.prop( 'disabled', false ).text( originalText );

					if ( response.success ) {
						// Show success message.
						self.showMessage( 'success', response.data.message || 'Assistant created successfully!' );

						// Redirect to edit page if assistant was created.
						if ( response.data.assistant_id && response.data.edit_link ) {
							setTimeout( function() {
								window.location.href = response.data.edit_link;
							}, 1500 );
						} else if ( response.data.status === 'scheduled' ) {
							setTimeout( function() {
								location.reload();
							}, 2000 );
						}
					} else {
						self.showMessage( 'error', response.data.message || 'Failed to create assistant.' );
					}
				},
				error: function( xhr, status, error ) {
					$button.prop( 'disabled', false ).text( originalText );
					self.showMessage( 'error', 'An error occurred: ' + error );
				}
			} );
		},

		/**
		 * Collect conversation data from the chat interface.
		 *
		 * @param {jQuery} $chat - The chat container element.
		 * @return {Object} Conversation data.
		 */
		collectConversationData: function( $chat ) {
			var messages = [];
			var $messagesContainer = $chat.find( '.wp-mcp-ai-chat__messages' );

			$messagesContainer.find( '.wp-mcp-ai-chat__message' ).each( function() {
				var $msg = $( this );
				var role = 'user';

				if ( $msg.hasClass( 'wp-mcp-ai-chat__message--assistant' ) ) {
					role = 'assistant';
				} else if ( $msg.hasClass( 'wp-mcp-ai-chat__message--system' ) ) {
					role = 'system';
				}

				var content = $msg.find( '.wp-mcp-ai-chat__message-content' ).text().trim();

				if ( content ) {
					messages.push( {
						role: role,
						content: content
					} );
				}
			} );

			// Also get the current input if it has content.
			var $input = $chat.find( '.wp-mcp-ai-chat__input' );
			var currentInput = $input.val().trim();
			if ( currentInput ) {
				messages.push( {
					role: 'user',
					content: currentInput
				} );
			}

			return {
				messages: messages,
				assistantId: $chat.data( 'assistant-id' ) || ( $chat.attr( 'id' ) ? $chat.attr( 'id' ).replace( 'wp-mcp-ai-prompt-chat-', '' ).split( '-' )[ 0 ] : null )
			};
		},

		/**
		 * Collect attachment IDs from the chat interface.
		 *
		 * @param {jQuery} $chat - The chat container element.
		 * @return {Array} Attachment IDs.
		 */
		collectAttachmentIds: function( $chat ) {
			var ids = [];
			$chat.find( '.wp-mcp-ai-chat__attachments-list [data-attachment-id]' ).each( function() {
				var id = $( this ).data( 'attachment-id' );
				if ( id ) {
					ids.push( id );
				}
			} );
			return ids;
		},

		/**
		 * Show a message on the page.
		 *
		 * @param {string} type    - Message type ('success' or 'error').
		 * @param {string} message - The message text.
		 */
		showMessage: function( type, message ) {
			// Remove existing messages.
			$( '.wp-mcp-ai-error-message, .wp-mcp-ai-success-message' ).remove();

			var className = type === 'success' ? 'wp-mcp-ai-success-message' : 'wp-mcp-ai-error-message';
			var $message = $( '<div class="' + className + '">' + message + '</div>' );
			$( '.wp-mcp-ai-section' ).first().prepend( $message );

			// Scroll to top of the content.
			$( 'html, body' ).animate( { scrollTop: $( '.wp-mcp-ai-section' ).first().offset().top - 50 }, 300 );
		}
	};

	// Initialize when document is ready.
	$( document ).ready( function() {
		BuildAssistantPage.init();
	} );

} )( jQuery );
