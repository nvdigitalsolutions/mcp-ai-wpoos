/**
 * Admin Test Profession Page JavaScript
 *
 * Handles the test profession modal and chat interface initialization.
 * Follows SoC by separating UI interaction from chat logic.
 * Follows the same pattern as admin-test-assistant.js for consistency.
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
	 * Initialize the test profession interface.
	 */
	function init() {
		const testButtons = document.querySelectorAll('.wp-mcp-ai-test-profession-btn');
		const modal = document.getElementById('wp-mcp-ai-test-profession-modal');
		
		if (!testButtons.length || !modal) {
			return;
		}

		const modalClose = modal.querySelector('.wp-mcp-ai-test-modal__close');
		const modalBackdrop = modal.querySelector('.wp-mcp-ai-test-modal__backdrop');

		// Attach click handlers to test buttons.
		testButtons.forEach(function (button) {
			button.addEventListener('click', function () {
				const professionId = button.getAttribute('data-profession-id');
				const professionTitle = button.getAttribute('data-profession-title');
				const professionDataJson = button.getAttribute('data-profession-data');
				let professionData = null;

				// Parse profession data if available
				if (professionDataJson) {
					try {
						professionData = JSON.parse(professionDataJson);
					} catch (e) {
						console.error('Failed to parse profession data:', e);
					}
				}

				if (professionId) {
					openTestModal(professionId, professionTitle, professionData);
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
	 * Open the test modal with chat interface for the specified profession.
	 *
	 * @param {string} professionId     The profession post ID.
	 * @param {string} professionTitle  The profession title for display.
	 * @param {Object} professionData   Profession details data.
	 */
	function openTestModal(professionId, professionTitle, professionData) {
		const modal = document.getElementById('wp-mcp-ai-test-profession-modal');
		const modalTitle = document.getElementById('wp-mcp-ai-test-profession-modal__title');
		const chatContainer = document.getElementById('wp-mcp-ai-test-profession-chat-container');
		const detailsContainer = document.getElementById('wp-mcp-ai-profession-details-container');

		if (!modal || !chatContainer) {
			return;
		}

		// Update modal title.
		if (modalTitle) {
			modalTitle.textContent = 'Test Profession: ' + escapeHtml(professionTitle || 'Unknown Profession');
		}

		// Display profession details.
		if (detailsContainer && professionData) {
			detailsContainer.innerHTML = buildProfessionDetailsHTML(professionData);
		} else if (detailsContainer) {
			detailsContainer.innerHTML = '';
		}

		// Clear previous chat container.
		chatContainer.innerHTML = '';

		// Create unique instance ID for this chat.
		const instanceId = 'wp-mcp-ai-test-profession-chat-' + professionId + '-' + Date.now();

		// Build chat HTML structure (based on shortcode template).
		const chatHTML = buildChatHTML(instanceId);
		chatContainer.innerHTML = chatHTML;

		// Initialize chat instance configuration.
		if (!window.wpMcpAiChatInstances) {
			window.wpMcpAiChatInstances = {};
		}

		// Build endpoints from base REST URL
		const baseRestUrl = (window.wpMcpAiChat && window.wpMcpAiChat.restUrl) ? window.wpMcpAiChat.restUrl : '/wp-json/mcp-ai/v1';

		// Get file upload configuration from global config
		const fileAccept = (window.wpMcpAiChat && window.wpMcpAiChat.fileAccept) ? window.wpMcpAiChat.fileAccept : '';
		const allowedImageMimes = (window.wpMcpAiChat && window.wpMcpAiChat.allowedImageMimes) ? window.wpMcpAiChat.allowedImageMimes : [];
		const allowedFileMimes = (window.wpMcpAiChat && window.wpMcpAiChat.allowedFileMimes) ? window.wpMcpAiChat.allowedFileMimes : [];
		const allowedExtensions = (window.wpMcpAiChat && window.wpMcpAiChat.allowedExtensions) ? window.wpMcpAiChat.allowedExtensions : [];

		// Determine which assistant ID to use:
		// 1. If profession has an associated assistant, use that assistant's ID directly
		// 2. Otherwise, use 'profession_' prefix to signal backend to create temporary assistant
		let assistantId;
		if (professionData && professionData.associated_assistant > 0) {
			// Use the associated assistant directly for testing
			assistantId = professionData.associated_assistant;
		} else {
			// Fall back to profession-based temporary assistant
			assistantId = 'profession_' + professionId;
		}

		window.wpMcpAiChatInstances[instanceId] = {
			assistantId: assistantId,
			professionId: professionId,
			userId: (window.wpMcpAiChat && typeof window.wpMcpAiChat.currentUserId !== 'undefined') ? window.wpMcpAiChat.currentUserId : 0,
			messagesEndpoint: baseRestUrl + '/chat-client',
			toolsEndpoint: baseRestUrl + '/tools',
			filesEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.filesEndpoint) ? window.wpMcpAiChat.filesEndpoint : baseRestUrl + '/files/',
			transcriptsEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.transcriptsEndpoint) ? window.wpMcpAiChat.transcriptsEndpoint : baseRestUrl + '/chat-transcripts',
			crawl4aiTaskEndpoint: baseRestUrl + '/crawl4ai/task/',
			uploadEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.uploadEndpoint) ? window.wpMcpAiChat.uploadEndpoint : '/wp-json/wp/v2/media',
			sessionKey: generateSessionKey(),
			enableStreaming: true,
			canUploadAttachments: true,
			saveTranscript: true, // Enable transcript saving for admin testing
			allowSensitiveTools: true, // Admin users can access all tools
			toolShortcuts: [], // Professions don't have predefined tool shortcuts
			fileAccept: fileAccept,
			allowedImageMimes: allowedImageMimes,
			allowedFileMimes: allowedFileMimes,
			allowedExtensions: allowedExtensions,
			restNonce: (window.wpMcpAiChat && window.wpMcpAiChat.nonce) ? window.wpMcpAiChat.nonce : '',
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
		const modal = document.getElementById('wp-mcp-ai-test-profession-modal');
		const chatContainer = document.getElementById('wp-mcp-ai-test-profession-chat-container');
		const detailsContainer = document.getElementById('wp-mcp-ai-profession-details-container');

		if (modal) {
			modal.style.display = 'none';
			document.body.classList.remove('wp-mcp-ai-test-modal-open');
		}

		// Clear chat container.
		if (chatContainer) {
			chatContainer.innerHTML = '';
		}
		
		// Clear details container.
		if (detailsContainer) {
			detailsContainer.innerHTML = '';
		}
	}

	/**
	 * Build profession details HTML display.
	 *
	 * @param {Object} professionData Profession details data.
	 * @return {string} HTML string for profession details.
	 */
	function buildProfessionDetailsHTML(professionData) {
		if (!professionData) {
			return '';
		}

		let html = '<div class="wp-mcp-ai-profession-details">';

		// Associated Assistant (show if configured)
		if (professionData.assistant_title) {
			html += '<div class="wp-mcp-ai-profession-details__section">';
			html += '<span class="wp-mcp-ai-profession-details__label">Test Assistant</span>';
			html += '<div class="wp-mcp-ai-profession-details__value"><strong>' + escapeHtml(professionData.assistant_title) + '</strong></div>';
			html += '</div>';
		}

		// Category
		if (professionData.category) {
			html += '<div class="wp-mcp-ai-profession-details__section">';
			html += '<span class="wp-mcp-ai-profession-details__label">Category</span>';
			html += '<div class="wp-mcp-ai-profession-details__value">' + escapeHtml(professionData.category) + '</div>';
			html += '</div>';
		}

		// Role Description
		if (professionData.role_description) {
			html += '<div class="wp-mcp-ai-profession-details__section">';
			html += '<span class="wp-mcp-ai-profession-details__label">Role Description</span>';
			html += '<div class="wp-mcp-ai-profession-details__value">' + escapeHtml(professionData.role_description) + '</div>';
			html += '</div>';
		}

		// Expertise Areas
		if (professionData.expertise && professionData.expertise.length > 0) {
			html += '<div class="wp-mcp-ai-profession-details__section">';
			html += '<span class="wp-mcp-ai-profession-details__label">Expertise Areas</span>';
			html += '<div class="wp-mcp-ai-profession-details__value"><ul>';
			professionData.expertise.forEach(function(area) {
				html += '<li>' + escapeHtml(area) + '</li>';
			});
			html += '</ul></div>';
			html += '</div>';
		}

		// Default Tools
		if (professionData.tools && professionData.tools.length > 0) {
			html += '<div class="wp-mcp-ai-profession-details__section">';
			html += '<span class="wp-mcp-ai-profession-details__label">Default Tools</span>';
			html += '<div class="wp-mcp-ai-profession-details__value"><ul>';
			professionData.tools.forEach(function(tool) {
				html += '<li><code>' + escapeHtml(tool) + '</code></li>';
			});
			html += '</ul></div>';
			html += '</div>';
		}

		// Knowledge Base Preview
		if (professionData.knowledge_base) {
			html += '<div class="wp-mcp-ai-profession-details__section">';
			html += '<span class="wp-mcp-ai-profession-details__label">Knowledge Base Preview</span>';
			html += '<div class="wp-mcp-ai-profession-details__value">' + escapeHtml(professionData.knowledge_base) + '...</div>';
			html += '</div>';
		}

		html += '</div>';
		return html;
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
			'<button type="button" class="wp-mcp-ai-chat__save" aria-label="Save conversation" title="Save conversation">' +
			'<svg class="wp-mcp-ai-chat__save-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2zM5 5v14h14V9h-4V5H5z" />' +
			'<path d="M7 5h6v3H7V5zm5 9a2 2 0 11-4 0 2 2 0 014 0z" />' +
			'</svg>' +
			'<span class="screen-reader-text">Save conversation</span>' +
			'</button>' +
			'<button type="button" class="wp-mcp-ai-chat__export" aria-label="Export conversation" title="Export conversation">' +
			'<svg class="wp-mcp-ai-chat__export-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M12 16a1 1 0 01-1-1V5a1 1 0 012 0v10a1 1 0 01-1 1z" />' +
			'<path d="M12 16a1 1 0 01-.707-.293l-4-4a1 1 0 011.414-1.414L12 13.586l3.293-3.293a1 1 0 011.414 1.414l-4 4A1 1 0 0112 16z" />' +
			'<path d="M5 19a1 1 0 010-2h14a1 1 0 010 2H5z" />' +
			'</svg>' +
			'<span class="screen-reader-text">Export conversation</span>' +
			'</button>' +
			'<button type="button" class="wp-mcp-ai-chat__history-toggle" aria-expanded="false" aria-controls="' + instanceId + '-history" aria-label="Show previous conversations">' +
			'<svg class="wp-mcp-ai-chat__history-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M6 5.5a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h7a1 1 0 010 2H7a1 1 0 01-1-1z" />' +
			'<path d="M5 9a1 1 0 012 0 1 1 0 11-2 0zm0 6a1 1 0 012 0 1 1 0 11-2 0zm0-12a1 1 0 012 0 1 1 0 11-2 0z" />' +
			'</svg>' +
			'<span class="screen-reader-text">Show previous conversations</span>' +
			'</button>' +
			'<button type="button" class="wp-mcp-ai-chat__new-chat" aria-label="Start new conversation">' +
			'<svg class="wp-mcp-ai-chat__new-chat-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M12 4a1 1 0 011 1v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6V5a1 1 0 011-1z" />' +
			'</svg>' +
			'<span class="screen-reader-text">Start new conversation</span>' +
			'</button>' +
			'</div>' +
			'</div>' +
			'<section class="wp-mcp-ai-chat__history" id="' + instanceId + '-history" hidden aria-label="Previous conversations">' +
			'<div class="wp-mcp-ai-chat__history-header">' +
			'<button type="button" class="wp-mcp-ai-chat__history-refresh" aria-label="Refresh conversation history" title="Refresh conversation history">' +
			'<svg class="wp-mcp-ai-chat__history-refresh-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M4 12a8 8 0 018-8V3c-1.105 0-2.165.21-3.13.594l1.42 1.42A6.004 6.004 0 0112 5a7 7 0 110 14 7 7 0 01-6.93-6H3a8 8 0 008 8 8 8 0 000-16V3l-3 3 3 3v-1.078z"/>' +
			'</svg>' +
			'<span class="screen-reader-text">Refresh conversation history</span>' +
			'</button>' +
			'</div>' +
			'<div class="wp-mcp-ai-chat__history-status" role="status" aria-live="polite" hidden></div>' +
			'<ul class="wp-mcp-ai-chat__history-list" role="list"></ul>' +
			'</section>' +
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
		return 'test-profession-' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
	}

	/**
	 * Get placeholder text for input.
	 *
	 * @return {string} Placeholder text.
	 */
	function getPlaceholder() {
		return 'Test the profession by asking questions...';
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
