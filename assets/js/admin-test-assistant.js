/**
 * Test Assistant Admin Interface
 *
 * Provides modal-based chat interface for testing AI assistants in the WordPress admin.
 *
 * @package WP_MCP_AI
 */

(function () {
	'use strict';

	/**
	 * Escape HTML to prevent XSS attacks.
	 *
	 * @param {string} text Text to escape.
	 * @return {string} Escaped text.
	 */
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	/**
	 * Initialize the test assistant interface.
	 */
	function init() {
		const testButtons = document.querySelectorAll('.wp-mcp-ai-test-assistant-btn');
		const modal = document.getElementById('wp-mcp-ai-test-modal');
		
		if (!testButtons.length || !modal) {
			return;
		}

		const modalClose = modal.querySelector('.wp-mcp-ai-test-modal__close');
		const modalBackdrop = modal.querySelector('.wp-mcp-ai-test-modal__backdrop');

		// Attach click handlers to test buttons.
		testButtons.forEach(function (button) {
			button.addEventListener('click', function () {
				const assistantId = button.getAttribute('data-assistant-id');
				const assistantTitle = button.getAttribute('data-assistant-title');

				if (assistantId) {
					openTestModal(assistantId, assistantTitle);
				}
			});
		});

		// Close modal on close button click.
		if (modalClose) {
			modalClose.addEventListener('click', closeTestModal);
		}

		// Close modal on backdrop click only (not when clicking inside the panel).
		if (modal) {
			modal.addEventListener('click', function(event) {
				// Close only if clicking on the modal container or backdrop, not the panel or its contents.
				if (event.target === modal || event.target === modalBackdrop) {
					closeTestModal();
				}
			});
		}

		// Close modal on Escape key.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal.style.display !== 'none') {
				closeTestModal();
			}
		});
	}

	/**
	 * Open the test modal with chat interface for the specified assistant.
	 *
	 * @param {string} assistantId    The assistant post ID.
	 * @param {string} assistantTitle The assistant title for display.
	 */
	function openTestModal(assistantId, assistantTitle) {
		const modal = document.getElementById('wp-mcp-ai-test-modal');
		const modalTitle = document.getElementById('wp-mcp-ai-test-modal__title');
		const chatContainer = document.getElementById('wp-mcp-ai-test-chat-container');

		if (!modal || !chatContainer) {
			return;
		}

		// Update modal title.
		if (modalTitle) {
			modalTitle.textContent = escapeHtml(assistantTitle || 'Test Assistant');
		}

		// Clear previous chat container.
		chatContainer.innerHTML = '';

		// Create unique instance ID for this chat.
		const instanceId = 'wp-mcp-ai-test-chat-' + assistantId + '-' + Date.now();

		// Build chat HTML structure (based on shortcode template).
		const chatHTML = buildChatHTML(instanceId);
		chatContainer.innerHTML = chatHTML;

		// Initialize chat instance configuration.
		if (!window.wpMcpAiChatInstances) {
			window.wpMcpAiChatInstances = {};
		}

		window.wpMcpAiChatInstances[instanceId] = {
			assistantId: assistantId,
			endpoint: (window.wpMcpAiChat && window.wpMcpAiChat.restUrl) ? window.wpMcpAiChat.restUrl + '/chat' : '/wp-json/mcp-ai/v1/chat',
			sessionKey: generateSessionKey(),
			canUploadAttachments: true,
			toolShortcuts: [],
			fileAccept: '',
			allowedImageMimes: [],
			allowedFileMimes: [],
			allowedExtensions: [],
			restNonce: (window.wpMcpAiChat && window.wpMcpAiChat.nonce) ? window.wpMcpAiChat.nonce : '',
			uploadEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.uploadEndpoint) ? window.wpMcpAiChat.uploadEndpoint : '',
			filesEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.filesEndpoint) ? window.wpMcpAiChat.filesEndpoint : '',
			transcriptsEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.transcriptsEndpoint) ? window.wpMcpAiChat.transcriptsEndpoint : '',
			historyPerPage: 20,
		};

		// Show modal.
		modal.style.display = 'block';
		document.body.classList.add('wp-mcp-ai-test-modal-open');

		// Trigger chat.js initialization.
		initializeChatInstance(instanceId);
	}

	/**
	 * Close the test modal.
	 */
	function closeTestModal() {
		const modal = document.getElementById('wp-mcp-ai-test-modal');
		const chatContainer = document.getElementById('wp-mcp-ai-test-chat-container');

		if (modal) {
			modal.style.display = 'none';
			document.body.classList.remove('wp-mcp-ai-test-modal-open');
		}

		// Clear chat container.
		if (chatContainer) {
			chatContainer.innerHTML = '';
		}
	}

	/**
	 * Build the chat interface HTML structure.
	 *
	 * @param {string} instanceId Unique instance identifier.
	 * @return {string} HTML string for chat interface.
	 */
	function buildChatHTML(instanceId) {
		const placeholderEscaped = escapeHtml(getPlaceholder());
		const attachLabelEscaped = escapeHtml(getAttachLabel());
		const transcribeLabelEscaped = escapeHtml(getTranscribeLabel());
		const sendLabelEscaped = escapeHtml(getSendLabel());
		
		return '<div class="wp-mcp-ai-chat" id="' + instanceId + '" data-wp-mcp-ai-chat>' +
			'<div class="wp-mcp-ai-chat__messages" role="log" aria-live="polite" aria-atomic="false"></div>' +
			'<div class="wp-mcp-ai-chat__status" role="status" aria-live="polite"></div>' +
			'<div class="wp-mcp-ai-chat__attachments" style="display: none;">' +
			'<div class="wp-mcp-ai-chat__attachments-header"></div>' +
			'<div class="wp-mcp-ai-chat__attachments-list"></div>' +
			'</div>' +
			'<form class="wp-mcp-ai-chat__form">' +
			'<div class="wp-mcp-ai-chat__input-wrapper">' +
			'<textarea class="wp-mcp-ai-chat__input" rows="3" placeholder="' + placeholderEscaped + '"></textarea>' +
			'<div class="wp-mcp-ai-chat__actions">' +
			'<button type="button" class="wp-mcp-ai-chat__attach" aria-label="' + attachLabelEscaped + '" style="display: none;">' +
			'<span class="dashicons dashicons-paperclip"></span>' +
			'</button>' +
			'<button type="button" class="wp-mcp-ai-chat__transcribe" aria-label="' + transcribeLabelEscaped + '" style="display: none;">' +
			'<span class="dashicons dashicons-microphone"></span>' +
			'</button>' +
			'<button type="submit" class="wp-mcp-ai-chat__submit">' + sendLabelEscaped + '</button>' +
			'</div>' +
			'</div>' +
			'<input type="file" class="wp-mcp-ai-chat__file-input" style="display: none;" />' +
			'<input type="file" class="wp-mcp-ai-chat__transcribe-input" accept="audio/*" style="display: none;" />' +
			'</form>' +
			'<div class="wp-mcp-ai-chat__tool-shortcuts"></div>' +
			'<div class="wp-mcp-ai-chat__controls" style="display: none;">' +
			'<button type="button" class="wp-mcp-ai-chat__transcript-toggle"></button>' +
			'<button type="button" class="wp-mcp-ai-chat__new-chat"></button>' +
			'<button type="button" class="wp-mcp-ai-chat__history-toggle"></button>' +
			'</div>' +
			'<div class="wp-mcp-ai-chat__history" style="display: none;">' +
			'<div class="wp-mcp-ai-chat__history-status"></div>' +
			'<div class="wp-mcp-ai-chat__history-list"></div>' +
			'</div>' +
			'</div>';
	}

	/**
	 * Initialize a chat instance manually.
	 *
	 * @param {string} instanceId Instance identifier.
	 */
	function initializeChatInstance(instanceId) {
		// Wait a brief moment for DOM to settle.
		setTimeout(function () {
			const container = document.getElementById(instanceId);

			if (!container) {
				return;
			}

			// Trigger a DOMContentLoaded event to re-init chat.js.
			const event = document.createEvent('Event');
			event.initEvent('DOMContentLoaded', true, true);
			document.dispatchEvent(event);

			// Focus the textarea to give user immediate access.
			setTimeout(function() {
				const textarea = container.querySelector('.wp-mcp-ai-chat__input');
				if (textarea) {
					textarea.focus();
				}
			}, 200);
		}, 100);
	}

	/**
	 * Generate a unique session key for the chat instance.
	 *
	 * @return {string} Session key.
	 */
	function generateSessionKey() {
		return 'test-' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
	}

	/**
	 * Get placeholder text for input.
	 *
	 * @return {string} Placeholder text.
	 */
	function getPlaceholder() {
		return (window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.placeholder) ? window.wpMcpAiChat.strings.placeholder : 'Ask something...';
	}

	/**
	 * Get send button label.
	 *
	 * @return {string} Send label.
	 */
	function getSendLabel() {
		return (window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.send) ? window.wpMcpAiChat.strings.send : 'Send';
	}

	/**
	 * Get attach button label.
	 *
	 * @return {string} Attach label.
	 */
	function getAttachLabel() {
		return (window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.attachFile) ? window.wpMcpAiChat.strings.attachFile : 'Attach file';
	}

	/**
	 * Get transcribe button label.
	 *
	 * @return {string} Transcribe label.
	 */
	function getTranscribeLabel() {
		return (window.wpMcpAiChat && window.wpMcpAiChat.strings && window.wpMcpAiChat.strings.transcribeAudio) ? window.wpMcpAiChat.strings.transcribeAudio : 'Transcribe audio';
	}

	// Initialize when DOM is ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
