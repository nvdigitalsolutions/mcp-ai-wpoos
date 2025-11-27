/**
 * Create Assistant Modal JavaScript
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		var modal = $('#wp-mcp-ai-create-assistant-modal');
		var form = $('#wp-mcp-ai-create-assistant-form');
		var activeTab = 'manual';
		var chatInstance = null;

		// Open modal
		$(document).on('click', '#wp-mcp-ai-open-create-modal', function(e) {
			e.preventDefault();
			modal.fadeIn(200);
			initPromptTabChat();
		});

		// Close modal
		$(document).on('click', '.wp-mcp-ai-modal-close, .wp-mcp-ai-modal-overlay', function(e) {
			e.preventDefault();
			modal.fadeOut(200);
		});

		// Prevent modal content clicks from closing
		$(document).on('click', '.wp-mcp-ai-modal-content', function(e) {
			e.stopPropagation();
		});

		// Close on ESC key
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && modal.is(':visible')) {
				modal.fadeOut(200);
			}
		});

		// Tab switching
		$(document).on('click', '.wp-mcp-ai-modal-tabs .nav-tab', function(e) {
			e.preventDefault();
			var tabId = $(this).data('tab');
			
			if (tabId === activeTab) {
				return;
			}

			// Update active tab button
			$('.wp-mcp-ai-modal-tabs .nav-tab').removeClass('nav-tab-active');
			$(this).addClass('nav-tab-active');

			// Update active tab content
			$('.wp-mcp-ai-modal-tab-content').removeClass('active');
			$('#wp-mcp-ai-tab-' + tabId).addClass('active');

			activeTab = tabId;

			// Update footer buttons based on active tab
			updateFooterButtons();

			// Initialize chat if switching to prompt tab
			if (tabId === 'prompt') {
				initPromptTabChat();
			}
		});

		/**
		 * Initialize the chat in the Prompt tab and show the Build button.
		 */
		function initPromptTabChat() {
			var $chatContainer = $('#wp-mcp-ai-tab-prompt .wp-mcp-ai-chat');
			if ($chatContainer.length === 0) {
				return;
			}

			// Show the Build button in the chat interface
			var $buildButton = $chatContainer.find('.wp-mcp-ai-chat__build');
			if ($buildButton.length > 0) {
				$buildButton.removeAttr('hidden').show();
			}

			// Store reference to chat container
			chatInstance = $chatContainer;
		}

		/**
		 * Update footer buttons based on active tab.
		 */
		function updateFooterButtons() {
			var submitButton = $('#wp-mcp-ai-submit-create');
			
			if (activeTab === 'prompt') {
				// In Prompt tab, hide the manual Create button (chat has its own Build button)
				submitButton.hide();
			} else {
				// In Manual tab, show the Create button
				submitButton.show();
			}
		}

		// Handle Build button click in chat (Prompt tab)
		$(document).on('click', '.wp-mcp-ai-modal-chat-container .wp-mcp-ai-chat__build', function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var $chat = $button.closest('.wp-mcp-ai-chat');
			
			if ($button.prop('disabled')) {
				return;
			}

			// Disable button and show loading state
			$button.prop('disabled', true);
			var originalText = $button.text();
			$button.text(wpMcpAiChat && wpMcpAiChat.strings && wpMcpAiChat.strings.building ? wpMcpAiChat.strings.building : 'Building...');

			// Get the conversation history from the chat
			var conversationData = collectConversationData($chat);
			
			if (!conversationData || !conversationData.messages || conversationData.messages.length === 0) {
				alert('Please describe what kind of assistant you want to create before clicking Build.');
				$button.prop('disabled', false).text(originalText);
				return;
			}

			// Get attachment IDs from the chat
			var attachmentIds = collectAttachmentIds($chat);

			// Send request to build the assistant
			$.ajax({
				url: wpMcpAiCreateAssistant.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_build_assistant_from_conversation',
					nonce: wpMcpAiCreateAssistant.nonce,
					conversation: JSON.stringify(conversationData),
					attachment_ids: attachmentIds
				},
				success: function(response) {
					$button.prop('disabled', false).text(originalText);

					if (response.success) {
						// Show success message
						showModalMessage('success', response.data.message || 'Assistant created successfully!');
						
						// Redirect to edit page if assistant was created
						if (response.data.assistant_id && response.data.edit_link) {
							setTimeout(function() {
								window.location.href = response.data.edit_link;
							}, 1500);
						} else if (response.data.status === 'scheduled') {
							setTimeout(function() {
								modal.fadeOut(200);
								location.reload();
							}, 2000);
						}
					} else {
						showModalMessage('error', response.data.message || 'Failed to create assistant.');
					}
				},
				error: function(xhr, status, error) {
					$button.prop('disabled', false).text(originalText);
					showModalMessage('error', 'An error occurred: ' + error);
				}
			});
		});

		/**
		 * Collect conversation data from the chat interface.
		 */
		function collectConversationData($chat) {
			var messages = [];
			var $messagesContainer = $chat.find('.wp-mcp-ai-chat__messages');
			
			$messagesContainer.find('.wp-mcp-ai-chat__message').each(function() {
				var $msg = $(this);
				var role = 'user';
				
				if ($msg.hasClass('wp-mcp-ai-chat__message--assistant')) {
					role = 'assistant';
				} else if ($msg.hasClass('wp-mcp-ai-chat__message--system')) {
					role = 'system';
				}

				var content = $msg.find('.wp-mcp-ai-chat__message-content').text().trim();
				
				if (content) {
					messages.push({
						role: role,
						content: content
					});
				}
			});

			// Also get the current input if it has content
			var $input = $chat.find('.wp-mcp-ai-chat__input');
			var currentInput = $input.val().trim();
			if (currentInput) {
				messages.push({
					role: 'user',
					content: currentInput
				});
			}

			return {
				messages: messages,
				assistantId: $chat.attr('id') ? $chat.attr('id').replace('wp-mcp-ai-chat-', '') : null
			};
		}

		/**
		 * Collect attachment IDs from the chat interface.
		 */
		function collectAttachmentIds($chat) {
			var ids = [];
			$chat.find('.wp-mcp-ai-chat__attachments-list [data-attachment-id]').each(function() {
				var id = $(this).data('attachment-id');
				if (id) {
					ids.push(id);
				}
			});
			return ids;
		}

		/**
		 * Show a message in the modal.
		 */
		function showModalMessage(type, message) {
			// Remove existing messages
			$('.wp-mcp-ai-error-message, .wp-mcp-ai-success-message').remove();
			
			var className = type === 'success' ? 'wp-mcp-ai-success-message' : 'wp-mcp-ai-error-message';
			var $message = $('<div class="' + className + '">' + message + '</div>');
			$('.wp-mcp-ai-modal-body').prepend($message);
			$('.wp-mcp-ai-modal-body').scrollTop(0);
		}

		// Handle form submission (Manual tab)
		form.on('submit', function(e) {
			e.preventDefault();

			// Only process if on manual tab
			if (activeTab !== 'manual') {
				return;
			}

			// Clear previous messages
			$('.wp-mcp-ai-error-message, .wp-mcp-ai-success-message').remove();

			// Validate professions (max 3)
			var professions = $('#assistant-professions').val();
			if (!professions || professions.length === 0) {
				showError(wpMcpAiCreateAssistant.strings.required);
				return;
			}
			if (professions.length > 3) {
				showError(wpMcpAiCreateAssistant.strings.maxProfessions);
				return;
			}

			// Validate regions (max 2)
			var regions = $('#assistant-regions').val();
			if (!regions || regions.length === 0) {
				showError(wpMcpAiCreateAssistant.strings.required);
				return;
			}
			if (regions.length > 2) {
				showError(wpMcpAiCreateAssistant.strings.maxRegions);
				return;
			}

			// Show loading state
			modal.addClass('loading');
			$('#wp-mcp-ai-submit-create').prop('disabled', true).text(wpMcpAiCreateAssistant.strings.creating);

			// Collect attachment IDs from uploaded files
			var attachmentIds = [];
			$('#assistant-attachments-list .wp-mcp-ai-attachment-item').each(function() {
				var id = $(this).data('attachment-id');
				if (id) {
					attachmentIds.push(id);
				}
			});

			// Prepare form data
			var formData = {
				action: 'wp_mcp_ai_create_assistant_from_modal',
				nonce: wpMcpAiCreateAssistant.nonce,
				title: $('#assistant-title').val(),
				professions: professions,
				regions: regions,
				industry_focus: $('#assistant-industry').val(),
				provider: $('#assistant-provider').val(),
				model: $('#assistant-model').val(),
				temperature: $('#assistant-temperature').val(),
				async: $('#assistant-async').is(':checked') ? '1' : '0',
				attachment_ids: attachmentIds
			};

			// Send AJAX request
			$.ajax({
				url: wpMcpAiCreateAssistant.ajaxUrl,
				type: 'POST',
				data: formData,
				success: function(response) {
					modal.removeClass('loading');
					$('#wp-mcp-ai-submit-create').prop('disabled', false).text('Create Assistant');

					if (response.success) {
						showSuccess(response.data.message || wpMcpAiCreateAssistant.strings.success);
						
						// Redirect to edit page if assistant was created synchronously
						if (response.data.assistant_id) {
							setTimeout(function() {
								window.location.href = response.data.edit_link || ('post.php?post=' + response.data.assistant_id + '&action=edit');
							}, 1000);
						} else if (response.data.status === 'scheduled') {
							// For async creation, just show success and close modal
							setTimeout(function() {
								modal.fadeOut(200);
								form[0].reset();
								location.reload();
							}, 2000);
						}
					} else {
						showError(response.data.message || wpMcpAiCreateAssistant.strings.error);
					}
				},
				error: function(xhr, status, error) {
					modal.removeClass('loading');
					$('#wp-mcp-ai-submit-create').prop('disabled', false).text('Create Assistant');
					showError(wpMcpAiCreateAssistant.strings.error + ' (' + error + ')');
				}
			});
		});

		// Handle file attachments in Manual tab
		$('#assistant-attachments').on('change', function(e) {
			var files = e.target.files;
			if (!files || files.length === 0) {
				return;
			}

			var $list = $('#assistant-attachments-list');
			var allowedTypes = ['text/plain', 'text/markdown', 'application/pdf', 
				'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

			for (var i = 0; i < files.length; i++) {
				var file = files[i];
				
				// Basic type validation
				var isValid = allowedTypes.some(function(type) {
					return file.type === type || file.name.match(/\.(txt|md|pdf|doc|docx)$/i);
				});

				if (!isValid) {
					showError('File "' + file.name + '" is not a supported type.');
					continue;
				}

				// Upload file
				uploadAttachment(file, $list);
			}

			// Reset file input
			$(this).val('');
		});

		/**
		 * Upload an attachment file.
		 */
		function uploadAttachment(file, $list) {
			var formData = new FormData();
			formData.append('file', file);
			formData.append('action', 'wp_mcp_ai_upload_assistant_attachment');
			formData.append('nonce', wpMcpAiCreateAssistant.nonce);

			// Create placeholder item
			var $item = $('<li class="wp-mcp-ai-attachment-item uploading">' +
				'<span class="name">' + file.name + '</span>' +
				'<span class="status">Uploading...</span>' +
				'</li>');
			$list.append($item);

			$.ajax({
				url: wpMcpAiCreateAssistant.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function(response) {
					if (response.success) {
						$item.removeClass('uploading')
							.data('attachment-id', response.data.attachment_id)
							.find('.status').html('<button type="button" class="remove-attachment">&times;</button>');
					} else {
						$item.addClass('error').find('.status').text('Failed');
						setTimeout(function() { $item.remove(); }, 2000);
					}
				},
				error: function() {
					$item.addClass('error').find('.status').text('Failed');
					setTimeout(function() { $item.remove(); }, 2000);
				}
			});
		}

		// Remove attachment
		$(document).on('click', '.remove-attachment', function() {
			$(this).closest('.wp-mcp-ai-attachment-item').remove();
		});

		function showError(message) {
			var errorHtml = '<div class="wp-mcp-ai-error-message">' + message + '</div>';
			$('.wp-mcp-ai-modal-body').prepend(errorHtml);
			// Scroll to top of modal body
			$('.wp-mcp-ai-modal-body').scrollTop(0);
		}

		function showSuccess(message) {
			var successHtml = '<div class="wp-mcp-ai-success-message">' + message + '</div>';
			$('.wp-mcp-ai-modal-body').prepend(successHtml);
			// Scroll to top of modal body
			$('.wp-mcp-ai-modal-body').scrollTop(0);
		}
	});

})(jQuery);
