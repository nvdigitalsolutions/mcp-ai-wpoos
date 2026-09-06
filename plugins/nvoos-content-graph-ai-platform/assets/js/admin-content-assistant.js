/**
 * Content Assistant Metabox JavaScript
 *
 * Provides modal-based chat interface and quick action buttons for content editing.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

(function ($) {
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
	 * Generate a unique session key.
	 *
	 * @return {string} Session key.
	 */
	function generateSessionKey() {
		const array = new Uint8Array( 16 );
		crypto.getRandomValues( array );
		return 'ca_' + Array.from( array, function( b ) { return b.toString( 16 ).padStart( 2, '0' ); } ).join( '' );
	}

	/**
	 * Initialize the Content Assistant interface.
	 */
	function init() {
		const $selector = $('#wp-mcp-ai-content-assistant-select');
		const $openButton = $('.wp-mcp-ai-content-open-assistant');
		const $actionButtons = $('.wp-mcp-ai-content-action-btn');
		const modal = document.getElementById('wp-mcp-ai-content-assistant-modal');

		if (!$selector.length) {
			return;
		}

		// Handle assistant selection
		$selector.on('change', function () {
			const assistantId = $(this).val();
			const $selectedOption = $(this).find('option:selected');
			const assistantTitle = $selectedOption.data('title') || $selectedOption.text();

			if (assistantId) {
				// Enable all buttons
				$openButton.prop('disabled', false);
				$actionButtons.prop('disabled', false);

				// Store selected assistant info
				$openButton.data('assistant-id', assistantId);
				$openButton.data('assistant-title', assistantTitle);
				$actionButtons.data('assistant-id', assistantId);
			} else {
				// Disable all buttons
				$openButton.prop('disabled', true);
				$actionButtons.prop('disabled', true);
			}
		});

		// Handle "Open AI Assistant" button click
		$openButton.on('click', function () {
			const assistantId = $(this).data('assistant-id');
			const assistantTitle = $(this).data('assistant-title');
			const postId = $(this).data('post-id');
			const postType = $(this).data('post-type');

			if (assistantId) {
				openAssistantModal(assistantId, assistantTitle, postId, postType);
			}
		});

		// Handle quick action buttons
		$actionButtons.on('click', function (e) {
			e.preventDefault();

			const $button = $(this);
			const action = $button.data('action');
			const postId = $button.data('post-id');
			const assistantId = $selector.val();

			if (!assistantId) {
				alert('Please select an assistant first.');
				return;
			}

			executeQuickAction(action, postId, assistantId, $button);
		});

		// Handle modal close
		if (modal) {
			const modalClose = modal.querySelector('.wp-mcp-ai-content-modal__close');
			const modalBackdrop = modal.querySelector('.wp-mcp-ai-content-modal__backdrop');

			if (modalClose) {
				modalClose.addEventListener('click', closeAssistantModal);
			}

			modal.addEventListener('click', function (event) {
				if (event.target === modal || event.target === modalBackdrop) {
					closeAssistantModal();
				}
			});
		}

		// Close modal on Escape key
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal && modal.style.display !== 'none') {
				closeAssistantModal();
			}
		});
	}

	/**
	 * Open the assistant modal with chat interface.
	 *
	 * @param {string} assistantId    Assistant post ID.
	 * @param {string} assistantTitle Assistant title.
	 * @param {string} postId         Current post ID.
	 * @param {string} postType       Current post type.
	 */
	function openAssistantModal(assistantId, assistantTitle, postId, postType) {
		const modal = document.getElementById('wp-mcp-ai-content-assistant-modal');
		const modalTitle = document.getElementById('wp-mcp-ai-content-modal-title');
		const chatContainer = document.getElementById('wp-mcp-ai-content-assistant-chat-container');

		if (!modal || !chatContainer) {
			return;
		}

		// Update modal title
		if (modalTitle) {
			modalTitle.textContent = assistantTitle || 'AI Assistant';
		}

		// Clear previous chat
		chatContainer.innerHTML = '';

		// Create unique instance ID
		const instanceId = 'wp-mcp-ai-content-chat-' + assistantId + '-' + Date.now();

		// Build chat HTML
		const chatHTML = buildChatHTML(instanceId);
		chatContainer.innerHTML = chatHTML;

		// Initialize chat instance configuration
		if (!window.wpMcpAiChatInstances) {
			window.wpMcpAiChatInstances = {};
		}

		const baseConfig = window.wpMcpAiChat || {};
		const baseRestUrl = baseConfig.restUrl || '/wp-json/mcp-ai/v1';

		// Get context data
		const contextData = window.wpMcpAiContentAssistant && window.wpMcpAiContentAssistant.contextData ?
			window.wpMcpAiContentAssistant.contextData : {};

		window.wpMcpAiChatInstances[instanceId] = {
			id: instanceId,
			assistantId: assistantId,
			userId: baseConfig.currentUserId || 0,
			restUrl: baseRestUrl,
			messagesEndpoint: baseRestUrl + 'chat-client',
			toolsEndpoint: baseRestUrl + 'tools',
			filesEndpoint: baseConfig.filesEndpoint || (baseRestUrl + 'files/'),
			transcriptsEndpoint: baseConfig.transcriptsEndpoint || (baseRestUrl + 'chat-transcripts'),
			crawl4aiTaskEndpoint: baseRestUrl + 'crawl4ai/task/',
			crawl4aiDefaultPollMs: 5000,
			sessionKey: generateSessionKey(),
			enableStreaming: true,
			canUploadAttachments: true,
			saveTranscript: false,
			allowSensitiveTools: true,
			requiredCapability: 'edit_posts',
			allowGuests: false,
			toolShortcuts: [],
			fileAccept: baseConfig.fileAccept || '',
			allowedImageMimes: baseConfig.allowedImageMimes || [],
			allowedFileMimes: baseConfig.allowedFileMimes || [],
			allowedExtensions: baseConfig.allowedExtensions || [],
			restNonce: baseConfig.nonce || '',
			historyPerPage: 20,
			asyncToolTimeout: baseConfig.asyncToolTimeout || 300000,
			contextData: contextData,
			postId: postId,
			postType: postType
		};

		// Show modal
		modal.style.display = 'block';
		document.body.classList.add('wp-mcp-ai-content-modal-open');

		// Initialize chat instance
		initializeChatInstance(instanceId);
	}

	/**
	 * Close the assistant modal.
	 */
	function closeAssistantModal() {
		const modal = document.getElementById('wp-mcp-ai-content-assistant-modal');
		const chatContainer = document.getElementById('wp-mcp-ai-content-assistant-chat-container');

		if (modal) {
			modal.style.display = 'none';
			document.body.classList.remove('wp-mcp-ai-content-modal-open');
		}

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
		return '<div class="wp-mcp-ai-chat wp-mcp-ai-chat--template-compact" id="' + escapeHtml(instanceId) + '" data-wp-mcp-ai-chat data-template="compact">' +
			'<div class="wp-mcp-ai-chat__transcript-controls">' +
			'<button type="button" class="wp-mcp-ai-chat__transcript-toggle" aria-expanded="false">' +
			'<svg class="wp-mcp-ai-chat__transcript-toggle-icon" viewBox="0 0 24 24"><path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z"></path></svg>' +
			'<span class="screen-reader-text">Expand conversation</span>' +
			'</button>' +
			'</div>' +
			'<div class="wp-mcp-ai-chat__messages" aria-live="polite"></div>' +
			'<div class="wp-mcp-ai-chat__form">' +
			'<div class="wp-mcp-ai-chat__status" role="status" aria-live="polite" hidden></div>' +
			'<textarea class="wp-mcp-ai-chat__input" rows="4" placeholder="Ask something…"></textarea>' +
			'<div class="wp-mcp-ai-chat__attachments" hidden>' +
			'<div class="wp-mcp-ai-chat__attachments-header">Attachments</div>' +
			'<ul class="wp-mcp-ai-chat__attachments-list"></ul>' +
			'</div>' +
			'<div class="wp-mcp-ai-chat__actions">' +
			'<input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden>' +
			'<input type="file" class="wp-mcp-ai-chat__transcribe-input" accept="audio/*" hidden>' +
			'<button type="button" class="wp-mcp-ai-chat__transcribe" aria-label="Transcribe audio"><svg class="wp-mcp-ai-chat__transcribe-icon" viewBox="0 0 24 24"><path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path></svg><span class="screen-reader-text">Transcribe audio</span></button>' +
			'<button type="button" class="wp-mcp-ai-chat__attach">Attach file</button>' +
			'<button type="button" class="wp-mcp-ai-chat__submit">Send</button>' +
			'</div>' +
			'</div>' +
			'<div class="wp-mcp-ai-chat__controls">' +
			'<div class="wp-mcp-ai-chat__quota-monitor" role="status" aria-live="polite"></div>' +
			'<div class="wp-mcp-ai-chat__control-buttons">' +
			'<button type="button" class="wp-mcp-ai-chat__save" aria-label="Save conversation"><svg class="wp-mcp-ai-chat__save-icon" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2zM5 5v14h14V9h-4V5H5z"></path><path d="M7 5h6v3H7V5zm5 9a2 2 0 11-4 0 2 2 0 014 0z"></path></svg><span class="screen-reader-text">Save</span></button>' +
			'<button type="button" class="wp-mcp-ai-chat__export" aria-label="Export conversation"><svg class="wp-mcp-ai-chat__export-icon" viewBox="0 0 24 24"><path d="M12 16a1 1 0 01-1-1V5a1 1 0 012 0v10a1 1 0 01-1 1z"></path><path d="M12 16a1 1 0 01-.707-.293l-4-4a1 1 0 011.414-1.414L12 13.586l3.293-3.293a1 1 0 011.414 1.414l-4 4A1 1 0 0112 16z"></path><path d="M5 19a1 1 0 010-2h14a1 1 0 010 2H5z"></path></svg><span class="screen-reader-text">Export</span></button>' +
			'<button type="button" class="wp-mcp-ai-chat__new-chat" aria-label="Start new conversation"><svg class="wp-mcp-ai-chat__new-chat-icon" viewBox="0 0 24 24"><path d="M12 4a1 1 0 011 1v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6V5a1 1 0 011-1z"></path></svg><span class="screen-reader-text">New</span></button>' +
			'</div>' +
			'</div>' +
			'</div>';
	}

	/**
	 * Initialize a chat instance.
	 *
	 * @param {string} instanceId Instance identifier.
	 */
	function initializeChatInstance(instanceId) {
		setTimeout(function () {
			const container = document.getElementById(instanceId);

			if (!container) {
				return;
			}

			// Trigger chat initialization
			if (window.wpMcpAiChatInit && typeof window.wpMcpAiChatInit.init === 'function') {
				window.wpMcpAiChatInit.init();

				// Focus textarea after initialization
				setTimeout(function () {
					const textarea = container.querySelector('.wp-mcp-ai-chat__input');
					if (textarea) {
						textarea.focus();
					}
				}, 100);
			}
		}, 100);
	}

	/**
	 * Execute a quick action.
	 *
	 * @param {string} action      Action to execute.
	 * @param {string} postId      Post ID.
	 * @param {string} assistantId Assistant ID.
	 * @param {jQuery} $button     Button element.
	 */
	function executeQuickAction(action, postId, assistantId, $button) {
		const $resultContainer = $('.wp-mcp-ai-content-action-result');
		const $resultContent = $('.wp-mcp-ai-content-action-result-content');
		const $loading = $('.wp-mcp-ai-content-action-loading');

		// Hide previous results
		$resultContainer.hide();

		// Show loading
		$loading.show();
		$button.prop('disabled', true);

		// Get content based on action
		let content = '';
		const title = $('#title').val() || $('#post-title-0').val() || $('input[name="post_title"]').val() || '';

		// Try different editor methods
		if (window.tinymce && window.tinymce.activeEditor) {
			content = window.tinymce.activeEditor.getContent();
		} else if ($('#content').length) {
			content = $('#content').val();
		} else if ($('.editor-post-text-editor').length) {
			content = $('.editor-post-text-editor').val();
		}

		// Build prompt based on action
		let prompt = '';
		switch (action) {
			case 'improve_content':
				prompt = 'Please improve and enhance the following content:\n\nTitle: ' + title + '\n\nContent:\n' + content;
				break;
			case 'generate_outline':
				prompt = 'Please generate a detailed outline for content with this title: ' + title;
				break;
			case 'seo_optimize':
				prompt = 'Please provide SEO optimization suggestions for:\n\nTitle: ' + title + '\n\nContent:\n' + content;
				break;
			case 'rewrite':
				prompt = 'Please rewrite the following content in a different style:\n\nTitle: ' + title + '\n\nContent:\n' + content;
				break;
			case 'expand':
				prompt = 'Please expand and add more detail to:\n\nTitle: ' + title + '\n\nContent:\n' + content;
				break;
			case 'summarize':
				prompt = 'Please create a concise summary of:\n\nTitle: ' + title + '\n\nContent:\n' + content;
				break;
			default:
				prompt = 'Help me with: ' + action;
		}

		// Send request via AJAX
		$.ajax({
			url: window.wpMcpAiContentAssistant.ajaxUrl,
			method: 'POST',
			data: {
				action: 'wp_mcp_ai_content_assistant_action',
				nonce: window.wpMcpAiContentAssistant.nonce,
				assistant_id: assistantId,
				post_id: postId,
				quick_action: action,
				prompt: prompt
			},
			success: function (response) {
				$loading.hide();
				$button.prop('disabled', false);

				if (response.success && response.data) {
					$resultContent.html(escapeHtml(response.data.message || response.data));
					$resultContainer.show();
				} else {
					$resultContent.text(response.data || window.wpMcpAiContentAssistant.strings.error);
					$resultContainer.show();
				}
			},
			error: function () {
				$loading.hide();
				$button.prop('disabled', false);
				$resultContent.text(window.wpMcpAiContentAssistant.strings.error);
				$resultContainer.show();
			}
		});
	}

	// Initialize when DOM is ready
	$(document).ready(init);

	// Also try with wp.domReady if available (Gutenberg support)
	if (window.wp && window.wp.domReady) {
		window.wp.domReady(init);
	}

})(jQuery);
