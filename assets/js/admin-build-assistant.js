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
					submitButton.prop( 'disabled', false ).text( 'Create Assistant' );

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
					submitButton.prop( 'disabled', false ).text( 'Create Assistant' );
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
			var $chatContainer = $( '.wp-mcp-ai-chat-container .wp-mcp-ai-chat' );

			if ( $chatContainer.length === 0 ) {
				return;
			}

			// Show the Build button in the chat interface.
			var $buildButton = $chatContainer.find( '.wp-mcp-ai-chat__build' );
			if ( $buildButton.length > 0 ) {
				$buildButton.removeAttr( 'hidden' ).show();
			}

			// Handle Build button click in chat (Prompt tab).
			$( document ).on( 'click', '.wp-mcp-ai-chat-container .wp-mcp-ai-chat__build', function( e ) {
				e.preventDefault();
				self.handleBuildFromConversation( $( this ) );
			} );
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
				alert( 'Please describe what kind of assistant you want to create before clicking Build.' );
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
				assistantId: $chat.attr( 'id' ) ? $chat.attr( 'id' ).replace( 'wp-mcp-ai-chat-', '' ) : null
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
