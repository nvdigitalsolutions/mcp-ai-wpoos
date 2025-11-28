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
	 * Escape HTML to prevent XSS attacks.
	 *
	 * @param {string} text Text to escape.
	 * @return {string} Escaped text.
	 */
	function escapeHtml( text ) {
		const div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	/**
	 * Build Assistant Page Controller.
	 */
	const BuildAssistantPage = {
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
			const self = this;
			const form = $( '#wp-mcp-ai-create-assistant-form' );

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
			const self = this;

			// Clear previous messages.
			$( '.wp-mcp-ai-error-message, .wp-mcp-ai-success-message' ).remove();

			// Validate professions (max 3).
			const professions = $( '#assistant-professions' ).val();
			if ( ! professions || professions.length === 0 ) {
				self.showMessage( 'error', wpMcpAiCreateAssistant.strings.required );
				return;
			}
			if ( professions.length > 3 ) {
				self.showMessage( 'error', wpMcpAiCreateAssistant.strings.maxProfessions );
				return;
			}

			// Validate regions (max 2).
			const regions = $( '#assistant-regions' ).val();
			if ( ! regions || regions.length === 0 ) {
				self.showMessage( 'error', wpMcpAiCreateAssistant.strings.required );
				return;
			}
			if ( regions.length > 2 ) {
				self.showMessage( 'error', wpMcpAiCreateAssistant.strings.maxRegions );
				return;
			}

			// Show loading state.
			const submitButton = $( '#wp-mcp-ai-submit-create' );
			submitButton.prop( 'disabled', true ).text( wpMcpAiCreateAssistant.strings.creating );

			// Collect attachment IDs from uploaded files.
			const attachmentIds = [];
			$( '#assistant-attachments-list .wp-mcp-ai-attachment-item' ).each( function() {
				const id = $( this ).data( 'attachment-id' );
				if ( id ) {
					attachmentIds.push( id );
				}
			} );

			// Prepare form data.
			const formData = {
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
			const self = this;
			const $list = $( '#assistant-attachments-list' );
			const allowedTypes = [
				'text/plain',
				'text/markdown',
				'application/pdf',
				'application/msword',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
			];

			for ( let i = 0; i < files.length; i++ ) {
				const file = files[ i ];

				// Basic type validation.
				const isValid = allowedTypes.some( function( type ) {
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
			const formData = new FormData();
			formData.append( 'file', file );
			formData.append( 'action', 'wp_mcp_ai_upload_assistant_attachment' );
			formData.append( 'nonce', wpMcpAiCreateAssistant.nonce );

			// Create placeholder item.
			const $item = $( '<li class="wp-mcp-ai-attachment-item uploading">' +
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
			const self = this;
			const $buildButton = $( '.wp-mcp-ai-build-with-ai-btn' );
			const $modal = $( '#wp-mcp-ai-build-assistant-modal' );

			if ( $buildButton.length === 0 || $modal.length === 0 ) {
				return;
			}

			const $modalClose = $modal.find( '.wp-mcp-ai-test-modal__close' );
			const $modalBackdrop = $modal.find( '.wp-mcp-ai-test-modal__backdrop' );

			// Handle Build with AI button click.
			$buildButton.on( 'click', function() {
				const assistantId = $( this ).data( 'assistant-id' );
				const assistantTitle = $( this ).data( 'assistant-title' );

				if ( assistantId ) {
					self.openBuildModal( assistantId, assistantTitle );
				}
			} );

			// Close modal on close button click.
			$modalClose.on( 'click', function() {
				self.closeBuildModal();
			} );

			// Close modal on backdrop click.
			$modal.on( 'click', function( event ) {
				if ( event.target === $modal[ 0 ] || event.target === $modalBackdrop[ 0 ] ) {
					self.closeBuildModal();
				}
			} );

			// Close modal on Escape key.
			$( document ).on( 'keydown', function( e ) {
				if ( e.key === 'Escape' && $modal.is( ':visible' ) ) {
					self.closeBuildModal();
				}
			} );
		},

		/**
		 * Open the Build with AI modal.
		 *
		 * @param {string} assistantId    - The builder assistant ID.
		 * @param {string} assistantTitle - The assistant title for display.
		 */
		openBuildModal: function( assistantId, assistantTitle ) {
			const $modal = $( '#wp-mcp-ai-build-assistant-modal' );
			const $modalTitle = $( '#wp-mcp-ai-build-assistant-modal__title' );
			const $chatContainer = $( '#wp-mcp-ai-build-assistant-chat-container' );

			if ( ! $modal.length || ! $chatContainer.length ) {
				return;
			}

			// Update modal title.
			if ( $modalTitle.length ) {
				$modalTitle.text( escapeHtml( assistantTitle || 'Build with AI' ) );
			}

			// Clear previous chat container.
			$chatContainer.empty();

			// Create unique instance ID for this chat.
			const instanceId = 'wp-mcp-ai-build-chat-' + assistantId + '-' + Date.now();

			// Build chat HTML structure.
			const chatHTML = this.buildChatHTML( instanceId );
			$chatContainer.html( chatHTML );

			// Initialize chat instance configuration.
			if ( ! window.wpMcpAiChatInstances ) {
				window.wpMcpAiChatInstances = {};
			}

			// Build endpoints from base REST URL.
			const baseRestUrl = ( window.wpMcpAiChat && window.wpMcpAiChat.restUrl ) ? window.wpMcpAiChat.restUrl : '/wp-json/mcp-ai/v1';

			// Get file upload configuration from global config.
			const fileAccept = ( window.wpMcpAiChat && window.wpMcpAiChat.fileAccept ) ? window.wpMcpAiChat.fileAccept : '';
			const allowedImageMimes = ( window.wpMcpAiChat && window.wpMcpAiChat.allowedImageMimes ) ? window.wpMcpAiChat.allowedImageMimes : [];
			const allowedFileMimes = ( window.wpMcpAiChat && window.wpMcpAiChat.allowedFileMimes ) ? window.wpMcpAiChat.allowedFileMimes : [];
			const allowedExtensions = ( window.wpMcpAiChat && window.wpMcpAiChat.allowedExtensions ) ? window.wpMcpAiChat.allowedExtensions : [];

			window.wpMcpAiChatInstances[ instanceId ] = {
				assistantId: assistantId,
				userId: ( window.wpMcpAiChat && typeof window.wpMcpAiChat.currentUserId !== 'undefined' ) ? window.wpMcpAiChat.currentUserId : 0,
				messagesEndpoint: baseRestUrl + '/chat-client',
				toolsEndpoint: baseRestUrl + '/tools',
				filesEndpoint: ( window.wpMcpAiChat && window.wpMcpAiChat.filesEndpoint ) ? window.wpMcpAiChat.filesEndpoint : baseRestUrl + '/files/',
				transcriptsEndpoint: ( window.wpMcpAiChat && window.wpMcpAiChat.transcriptsEndpoint ) ? window.wpMcpAiChat.transcriptsEndpoint : baseRestUrl + '/chat-transcripts',
				crawl4aiTaskEndpoint: baseRestUrl + '/crawl4ai/task/',
				uploadEndpoint: ( window.wpMcpAiChat && window.wpMcpAiChat.uploadEndpoint ) ? window.wpMcpAiChat.uploadEndpoint : '/wp-json/wp/v2/media',
				sessionKey: this.generateSessionKey(),
				enableStreaming: true,
				canUploadAttachments: true,
				saveTranscript: false, // Don't save builder conversations.
				allowSensitiveTools: true, // Admin users can access all tools.
				toolShortcuts: [],
				fileAccept: fileAccept,
				allowedImageMimes: allowedImageMimes,
				allowedFileMimes: allowedFileMimes,
				allowedExtensions: allowedExtensions,
				restNonce: ( window.wpMcpAiChat && window.wpMcpAiChat.nonce ) ? window.wpMcpAiChat.nonce : '',
				historyPerPage: 20
			};

			// Show modal.
			$modal.show();
			$( 'body' ).addClass( 'wp-mcp-ai-test-modal-open' );

			// Trigger chat.js initialization.
			this.initializeChatInstance( instanceId );
		},

		/**
		 * Close the Build with AI modal.
		 */
		closeBuildModal: function() {
			const $modal = $( '#wp-mcp-ai-build-assistant-modal' );
			const $chatContainer = $( '#wp-mcp-ai-build-assistant-chat-container' );

			if ( $modal.length ) {
				$modal.hide();
				$( 'body' ).removeClass( 'wp-mcp-ai-test-modal-open' );
			}

			// Clear chat container.
			if ( $chatContainer.length ) {
				$chatContainer.empty();
			}
		},

		/**
		 * Build the chat interface HTML structure.
		 *
		 * @param {string} instanceId - Unique instance identifier.
		 * @return {string} HTML string for chat interface.
		 */
		buildChatHTML: function( instanceId ) {
			const placeholderEscaped = escapeHtml( this.getPlaceholder() );
			const attachLabelEscaped = escapeHtml( this.getAttachLabel() );
			const transcribeLabelEscaped = escapeHtml( this.getTranscribeLabel() );
			const sendLabelEscaped = escapeHtml( this.getSendLabel() );

			return '<div class="wp-mcp-ai-chat" id="' + instanceId + '" data-wp-mcp-ai-chat>' +
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
				'<textarea class="wp-mcp-ai-chat__input" rows="4" placeholder="' + placeholderEscaped + '" required></textarea>' +
				'<div class="wp-mcp-ai-chat__attachments" hidden>' +
				'<div class="wp-mcp-ai-chat__attachments-header">Attachments</div>' +
				'<ul class="wp-mcp-ai-chat__attachments-list"></ul>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__actions">' +
				'<input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden />' +
				'<input type="file" class="wp-mcp-ai-chat__transcribe-input" accept="audio/*" hidden />' +
				'<button type="button" class="wp-mcp-ai-chat__transcribe" aria-label="' + transcribeLabelEscaped + '">' +
				'<svg class="wp-mcp-ai-chat__transcribe-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path>' +
				'<path d="M12 16a7 7 0 0 0 6.93-6H17a5 5 0 0 1-10 0H5.07A7 7 0 0 0 12 16zm-1 2.05V21h2v-2.95A9 9 0 0 0 20.95 11H19a7 7 0 0 1-14 0H3.05A9 9 0 0 0 11 18.05z"></path>' +
				'</svg>' +
				'<span class="screen-reader-text">' + transcribeLabelEscaped + '</span>' +
				'</button>' +
				'<button type="button" class="wp-mcp-ai-chat__attach">' + attachLabelEscaped + '</button>' +
				'<button type="submit" class="wp-mcp-ai-chat__submit">' + sendLabelEscaped + '</button>' +
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
		 * Initialize a chat instance manually.
		 *
		 * @param {string} instanceId - Instance identifier.
		 */
		initializeChatInstance: function( instanceId ) {
			// Wait a brief moment for DOM to settle.
			setTimeout( function() {
				const container = document.getElementById( instanceId );

				if ( ! container ) {
					return;
				}

				// Trigger a DOMContentLoaded event to re-init chat.js.
				const event = document.createEvent( 'Event' );
				event.initEvent( 'DOMContentLoaded', true, true );
				document.dispatchEvent( event );

				// Focus the textarea to give user immediate access.
				setTimeout( function() {
					const textarea = container.querySelector( '.wp-mcp-ai-chat__input' );
					if ( textarea ) {
						textarea.focus();
					}
				}, 200 );
			}, 100 );
		},

		/**
		 * Generate a unique session key for the chat instance.
		 *
		 * @return {string} Session key.
		 */
		generateSessionKey: function() {
			return 'build-' + Math.random().toString( 36 ).substring( 2, 15 ) + Math.random().toString( 36 ).substring( 2, 15 );
		},

		/**
		 * Get placeholder text for input.
		 *
		 * @return {string} Placeholder text.
		 */
		getPlaceholder: function() {
			return ( window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.placeholder ) ? window.wpMcpAiChat.strings.placeholder : 'Describe the assistant you want to create...';
		},

		/**
		 * Get send button label.
		 *
		 * @return {string} Send label.
		 */
		getSendLabel: function() {
			return ( window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.send ) ? window.wpMcpAiChat.strings.send : 'Send';
		},

		/**
		 * Get attach button label.
		 *
		 * @return {string} Attach label.
		 */
		getAttachLabel: function() {
			return ( window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.attachFile ) ? window.wpMcpAiChat.strings.attachFile : 'Attach file';
		},

		/**
		 * Get transcribe button label.
		 *
		 * @return {string} Transcribe label.
		 */
		getTranscribeLabel: function() {
			return ( window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.transcribeAudio ) ? window.wpMcpAiChat.strings.transcribeAudio : 'Transcribe audio';
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

			const className = type === 'success' ? 'wp-mcp-ai-success-message' : 'wp-mcp-ai-error-message';
			const $message = $( '<div class="' + className + '">' + message + '</div>' );
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
