/**
 * AI CPT Assistant JavaScript
 *
 * Handles chat interface interactions for the AI assistant metabox.
 */

(function ($) {
	'use strict';

	/**
	 * AI CPT Assistant Handler
	 */
	const WpMcpAiCptAssistant = {
		/**
		 * Initialize the assistant
		 */
		init: function () {
			this.bindEvents();
		},

		/**
		 * Bind event handlers
		 */
		bindEvents: function () {
			// Send button click
			$(document).on('click', '.wp-mcp-ai-cpt-send-button', function (e) {
				e.preventDefault();
				const $button = $(this);
				const isTermScreen = $button.attr('id') === 'wp-mcp-ai-cpt-send-button-term';
				WpMcpAiCptAssistant.sendMessage(isTermScreen);
			});

			// Enter key to send (with Shift+Enter for new line)
			$(document).on('keydown', '.wp-mcp-ai-cpt-chat-input', function (e) {
				if (e.key === 'Enter' && !e.shiftKey) {
					e.preventDefault();
					const isTermScreen = $(this).attr('id') === 'wp-mcp-ai-cpt-chat-input-term';
					WpMcpAiCptAssistant.sendMessage(isTermScreen);
				}
			});
		},

		/**
		 * Send message to AI assistant
		 *
		 * @param {boolean} isTermScreen Whether this is a term edit screen
		 */
		sendMessage: function (isTermScreen) {
			const inputId = isTermScreen ? '#wp-mcp-ai-cpt-chat-input-term' : '#wp-mcp-ai-cpt-chat-input';
			const messagesId = isTermScreen ? '#wp-mcp-ai-cpt-chat-messages-term' : '#wp-mcp-ai-cpt-chat-messages';
			const statusId = isTermScreen ? '#wp-mcp-ai-cpt-chat-status-term' : '#wp-mcp-ai-cpt-chat-status';
			const buttonId = isTermScreen ? '#wp-mcp-ai-cpt-send-button-term' : '#wp-mcp-ai-cpt-send-button';

			const $input = $(inputId);
			const $messages = $(messagesId);
			const $status = $(statusId);
			const $button = $(buttonId);

			const message = $input.val().trim();

			if (!message) {
				this.showStatus(statusId, wpMcpAiCpt.i18n.emptyMessage, 'error');
				return;
			}

			// Get context data
			const $assistant = $input.closest('.wp-mcp-ai-cpt-assistant');
			const postId = $assistant.data('post-id') || 0;
			const postType = $assistant.data('post-type') || '';
			const termId = $assistant.data('term-id') || 0;
			const taxonomy = $assistant.data('taxonomy') || '';

			// Clear welcome message if it exists
			$messages.find('.wp-mcp-ai-cpt-welcome-message').remove();

			// Add user message to chat
			this.addMessage(messagesId, 'user', message);

			// Clear input and disable button
			$input.val('');
			$button.prop('disabled', true);
			this.showStatus(statusId, wpMcpAiCpt.i18n.sending, 'sending');

			// Prepare AJAX data
			const data = {
				action: 'wp_mcp_ai_cpt_chat',
				nonce: wpMcpAiCpt.nonce,
				message: message,
				post_id: postId,
				post_type: postType,
				term_id: termId,
				taxonomy: taxonomy
			};

			// Send AJAX request
			$.ajax({
				url: wpMcpAiCpt.ajaxUrl,
				type: 'POST',
				data: data,
				success: function (response) {
					$button.prop('disabled', false);
					$status.text('').removeClass('is-error is-sending');

					if (response.success && response.data && response.data.response) {
						// Add AI response to chat
						WpMcpAiCptAssistant.addMessage(messagesId, 'assistant', response.data.response);
					} else {
						const errorMsg = response.data && response.data.message ? response.data.message : wpMcpAiCpt.i18n.error;
						WpMcpAiCptAssistant.showStatus(statusId, errorMsg, 'error');
						WpMcpAiCptAssistant.addMessage(messagesId, 'error', errorMsg);
					}
				},
				error: function (xhr, status, error) {
					$button.prop('disabled', false);
					const errorMsg = wpMcpAiCpt.i18n.error;
					WpMcpAiCptAssistant.showStatus(statusId, errorMsg, 'error');
					WpMcpAiCptAssistant.addMessage(messagesId, 'error', errorMsg);
				}
			});
		},

		/**
		 * Add a message to the chat
		 *
		 * @param {string} messagesId ID of messages container
		 * @param {string} role       Message role (user, assistant, error)
		 * @param {string} content    Message content
		 */
		addMessage: function (messagesId, role, content) {
			const $messages = $(messagesId);
			
			let roleLabel = '';
			let messageClass = '';
			
			if (role === 'user') {
				roleLabel = 'You';
				messageClass = 'wp-mcp-ai-cpt-message-user';
			} else if (role === 'assistant') {
				roleLabel = 'AI Assistant';
				messageClass = 'wp-mcp-ai-cpt-message-assistant';
			} else if (role === 'error') {
				roleLabel = 'Error';
				messageClass = 'wp-mcp-ai-cpt-message-error';
			}

			// Convert markdown-style formatting to HTML
			const formattedContent = this.formatContent(content);

			const $message = $('<div>')
				.addClass('wp-mcp-ai-cpt-message')
				.addClass(messageClass)
				.html(
					'<div class="wp-mcp-ai-cpt-message-role">' + this.escapeHtml(roleLabel) + '</div>' +
					'<div class="wp-mcp-ai-cpt-message-content">' + formattedContent + '</div>'
				);

			$messages.append($message);
			
			// Scroll to bottom
			$messages.scrollTop($messages[0].scrollHeight);
		},

		/**
		 * Format message content (convert markdown-like syntax to HTML)
		 *
		 * @param {string} content Raw content
		 * @return {string} Formatted HTML content
		 */
		formatContent: function (content) {
			// Escape HTML first
			let formatted = this.escapeHtml(content);

			// Convert **bold** to <strong>
			formatted = formatted.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

			// Convert *italic* to <em>
			formatted = formatted.replace(/\*(.+?)\*/g, '<em>$1</em>');

			// Convert `code` to <code>
			formatted = formatted.replace(/`(.+?)`/g, '<code>$1</code>');

			// Convert line breaks to <br>
			formatted = formatted.replace(/\n/g, '<br>');

			// Convert URLs to links
			formatted = formatted.replace(
				/(https?:\/\/[^\s<]+)/g,
				'<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
			);

			return formatted;
		},

		/**
		 * Escape HTML special characters
		 *
		 * @param {string} text Text to escape
		 * @return {string} Escaped text
		 */
		escapeHtml: function (text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		/**
		 * Show status message
		 *
		 * @param {string} statusId ID of status container
		 * @param {string} message  Status message
		 * @param {string} type     Status type (error, sending, etc)
		 */
		showStatus: function (statusId, message, type) {
			const $status = $(statusId);
			$status
				.text(message)
				.removeClass('is-error is-sending')
				.addClass('is-' + type);

			// Auto-clear non-error statuses after 3 seconds
			if (type !== 'error') {
				setTimeout(function () {
					$status.text('').removeClass('is-' + type);
				}, 3000);
			}
		}
	};

	// Initialize when document is ready
	$(document).ready(function () {
		WpMcpAiCptAssistant.init();
	});

})(jQuery);
