/**
 * Project Management AI Assistant Metabox
 *
 * Handles the AI assistant chat interface within project/task/event edit screens.
 * Uses client-side HTML and configuration generation (like Build Assistant page)
 * to avoid issues with PHP shortcode rendering and globals in AJAX contexts.
 *
 * @package WP_MCP_AI
 */

// Unconditional debug output to verify script loads
console.log('[PM AI Assistant] Script file loaded at:', new Date().toISOString());

(function ($) {
	'use strict';
	
	// Configuration constants for metabox polling behavior.
	const DEFAULT_POLLING_ATTEMPTS = 50;  // Max attempts for block editor (50 × ~200ms avg = ~10s).
	const HYBRID_POLLING_ATTEMPTS = 30;   // Max attempts for hybrid mode (30 × ~200ms avg = ~6s).
	const INITIAL_POLLING_DELAY = 100;    // Initial delay in milliseconds.
	const MAX_POLLING_DELAY = 500;        // Maximum delay after exponential backoff.
	const BACKOFF_MULTIPLIER = 1.5;       // Exponential backoff multiplier.
	
	// Verify jQuery is available
	if (!$) {
		console.error('[PM AI Assistant] CRITICAL: jQuery is not available!');
		return;
	}
	console.log('[PM AI Assistant] jQuery is available, version:', $.fn.jquery);

	/**
	 * Escape HTML to prevent XSS.
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
	 * Initialize the AI assistant metabox.
	 */
	function initPmAiAssistant() {
		console.log('[PM AI Assistant] initPmAiAssistant() function called');
		
		const $selector = $('#wp-mcp-ai-pm-assistant-select');
		const $modal = $('#wp-mcp-ai-pm-assistant-modal');
		const $chatContainer = $('#wp-mcp-ai-pm-assistant-chat-container');
		const $modalClose = $modal.find('.wp-mcp-ai-pm-assistant-modal__close');
		const $modalBackdrop = $modal.find('.wp-mcp-ai-pm-assistant-modal__backdrop');

		console.log('[PM AI Assistant] Element search results:', {
			selector: $selector.length,
			modal: $modal.length,
			chatContainer: $chatContainer.length,
			modalClose: $modalClose.length,
			modalBackdrop: $modalBackdrop.length
		});

		if (!$selector.length || !$chatContainer.length || !$modal.length) {
			console.error('[PM AI Assistant] CRITICAL: Required elements not found - initialization aborted');
			return;
		}

		// Log successful initialization
		console.log('[PM AI Assistant] ✓ Initialization successful, all elements found');

		// Move modal to body to ensure position: fixed works correctly.
		// Modals rendered inside metaboxes may not display as overlays due to CSS positioning contexts.
		// Ensure modal stays hidden - don't remove the inline style set by PHP.
		$modal.removeClass('wp-mcp-ai-pm-assistant-modal--visible');
		$modal.appendTo('body');
		
		console.log('[PM AI Assistant] ✓ Modal moved to body, parent is now:', $modal.parent()[0].tagName);

		// Get localized data.
		const config = window.wpMcpAiPmAssistant || {};
		const contextType = config.contextType || 'project';
		const contextData = config.contextData || {};
		const postId = config.postId || 0;
		
		console.log('[PM AI Assistant] Configuration loaded:', {
			hasConfig: !!window.wpMcpAiPmAssistant,
			contextType: contextType,
			postId: postId
		});

		// Handle assistant selection - open modal directly.
		$selector.on('change', function () {
			const assistantId = $(this).val();
			const $selectedOption = $(this).find('option:selected');
			const assistantTitle = $selectedOption.data('title') || $selectedOption.text();

			console.log('[PM AI Assistant] ⚡ Selector change event fired!', {
				assistantId: assistantId,
				assistantTitle: assistantTitle,
				hasValue: !!assistantId
			});

			if (!assistantId) {
				// Close modal if open when no assistant selected
				closeModal();
				console.log('[PM AI Assistant] No assistant selected, modal closed');
				return;
			}

			console.log('[PM AI Assistant] ➜ Opening modal for assistant:', assistantId, assistantTitle);

			// Open modal and initialize chat interface directly.
			openModal(assistantId, assistantTitle, contextType, contextData, postId);
		});

		console.log('[PM AI Assistant] ✓ Change event handler attached to selector');

		// Close modal on close button click.
		$modalClose.on('click', closeModal);

		// Close modal on backdrop click.
		$modal.on('click', function (event) {
			if (event.target === $modal[0] || event.target === $modalBackdrop[0]) {
				closeModal();
			}
		});

		// Close modal on Escape key.
		$(document).on('keydown', function (e) {
			if (e.key === 'Escape' && $modal.hasClass('wp-mcp-ai-pm-assistant-modal--visible')) {
				closeModal();
			}
		});
		
		console.log('[PM AI Assistant] ✓ Close handlers attached (button, backdrop, escape key)');

		/**
		 * Open the modal with chat interface.
		 *
		 * @param {string} assistantId     Assistant ID.
		 * @param {string} assistantTitle  Assistant title.
		 * @param {string} contextType     Context type (project, task, or event).
		 * @param {Object} contextData     Context data about the current item.
		 * @param {number} postId          Current post ID.
		 */
		function openModal(assistantId, assistantTitle, contextType, contextData, postId) {
			console.log('[PM AI Assistant] openModal() called with:', {
				assistantId: assistantId,
				assistantTitle: assistantTitle,
				contextType: contextType,
				postId: postId
			});
			
			// Verify modal element exists
			if (!$modal.length) {
				console.error('[PM AI Assistant] CRITICAL: Modal element not found in DOM');
				return;
			}
			
			// Update modal title.
			$modal.find('#wp-mcp-ai-pm-assistant-modal__title').text(assistantTitle || 'AI Assistant');

			// Show modal by adding visible class. The CSS !important rule will override the inline display: none.
			$modal.addClass('wp-mcp-ai-pm-assistant-modal--visible');
			$('body').addClass('wp-mcp-ai-pm-assistant-modal-open');
			
			console.log('[PM AI Assistant] Modal display updated:', {
				displayStyle: $modal.css('display'),
				hasVisibleClass: $modal.hasClass('wp-mcp-ai-pm-assistant-modal--visible'),
				bodyHasOpenClass: $('body').hasClass('wp-mcp-ai-pm-assistant-modal-open')
			});

			// Initialize chat interface if not already initialized.
			if ($chatContainer.is(':empty')) {
				console.log('[PM AI Assistant] Chat container is empty, initializing chat interface...');
				initChatInterface(assistantId, contextType, contextData, postId);
			} else {
				console.log('[PM AI Assistant] Chat container already has content, skipping re-initialization');
			}
		}

		/**
		 * Close the modal.
		 */
		function closeModal() {
			console.log('[PM AI Assistant] Closing modal');
			$modal.removeClass('wp-mcp-ai-pm-assistant-modal--visible');
			$('body').removeClass('wp-mcp-ai-pm-assistant-modal-open');
		}
	}

	/**
	 * Isolate chat form from page form validation.
	 * Prevents the chat form from interfering with WordPress edit page form validation.
	 *
	 * @param {jQuery} $container Chat container element.
	 */
	function isolateChatForm($container) {
		const $chatForm = $container.find('.wp-mcp-ai-chat__form');
		
		if (!$chatForm.length) {
			console.log('[PM AI Assistant] No chat form found yet (will be created by chat.js)');
			return;
		}

		// Prevent form submission from bubbling to page form.
		$chatForm.on('submit', function(event) {
			event.stopPropagation();
		});

		// Prevent Enter key in chat inputs from triggering page form submission.
		$chatForm.on('keydown', 'input, textarea', function(event) {
			if (event.key === 'Enter' && !event.shiftKey) {
				// Let the chat form handle Enter key, don't let it bubble to page form.
				event.stopPropagation();
			}
		});

		// Mark form as isolated to prevent WordPress validation from checking it.
		$chatForm.attr('data-isolated-form', 'true');
		$chatForm.addClass('wp-mcp-ai-isolated-form');

		console.log('[PM AI Assistant] ✓ Chat form isolated from page form validation');
	}

	/**
	 * Initialize the chat interface.
	 * Builds HTML and configuration directly in JavaScript (like Build Assistant page).
	 * This avoids issues with PHP shortcode rendering and globals in AJAX contexts.
	 *
	 * @param {string} assistantId   Assistant ID.
	 * @param {string} _contextType  Context type (project, task, or event). Reserved for future use.
	 * @param {Object} _contextData  Context data about the current item. Reserved for future use.
	 * @param {number} _postId       Current post ID. Reserved for future use.
	 */
	function initChatInterface(assistantId, _contextType, _contextData, _postId) {
		const $container = $('#wp-mcp-ai-pm-assistant-chat-container');

		console.log('[PM AI Assistant] initChatInterface() called for assistant:', assistantId);

		// Clear previous chat container.
		$container.empty();

		// Create unique instance ID for this chat.
		const instanceId = 'wp-mcp-ai-pm-chat-' + assistantId + '-' + Date.now();
		console.log('[PM AI Assistant] Generated instance ID:', instanceId);

		// Build chat HTML structure directly in JavaScript.
		const chatHTML = buildChatHTML(instanceId);
		$container.html(chatHTML);
		console.log('[PM AI Assistant] ✓ Chat HTML injected into container');

		// Initialize chat instance configuration directly in JavaScript.
		if (!window.wpMcpAiChatInstances) {
			window.wpMcpAiChatInstances = {};
		}

		// Build endpoints from global config or defaults.
		const baseRestUrl = (window.wpMcpAiChat && window.wpMcpAiChat.restUrl) ? window.wpMcpAiChat.restUrl : '/wp-json/mcp-ai/v1';
		
		console.log('[PM AI Assistant] Building chat configuration...', {
			hasWpMcpAiChat: !!window.wpMcpAiChat,
			baseRestUrl: baseRestUrl
		});
		
		// Get file upload configuration from global config.
		const fileAccept = (window.wpMcpAiChat && window.wpMcpAiChat.fileAccept) ? window.wpMcpAiChat.fileAccept : '';
		const allowedImageMimes = (window.wpMcpAiChat && window.wpMcpAiChat.allowedImageMimes) ? window.wpMcpAiChat.allowedImageMimes : [];
		const allowedFileMimes = (window.wpMcpAiChat && window.wpMcpAiChat.allowedFileMimes) ? window.wpMcpAiChat.allowedFileMimes : [];
		const allowedExtensions = (window.wpMcpAiChat && window.wpMcpAiChat.allowedExtensions) ? window.wpMcpAiChat.allowedExtensions : [];

		// Create configuration object.
		window.wpMcpAiChatInstances[instanceId] = {
			id: instanceId,
			assistantId: assistantId,
			userId: (window.wpMcpAiChat && typeof window.wpMcpAiChat.currentUserId !== 'undefined') ? window.wpMcpAiChat.currentUserId : 0,
			restUrl: baseRestUrl,
			messagesEndpoint: baseRestUrl + '/chat-client',
			toolsEndpoint: baseRestUrl + '/tools',
			filesEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.filesEndpoint) ? window.wpMcpAiChat.filesEndpoint : baseRestUrl + '/files/',
			transcriptsEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.transcriptsEndpoint) ? window.wpMcpAiChat.transcriptsEndpoint : baseRestUrl + '/chat-transcripts',
			crawl4aiTaskEndpoint: baseRestUrl + '/crawl4ai/task/',
			crawl4aiDefaultPollMs: 5000,
			sessionKey: generateSessionKey(),
			enableStreaming: true,
			canUploadAttachments: true,
			saveTranscript: false, // Don't save metabox chats.
			allowSensitiveTools: true, // Admin users can access all tools.
			requiredCapability: 'edit_posts',
			allowGuests: false,
			toolShortcuts: [],
			fileAccept: fileAccept,
			allowedImageMimes: allowedImageMimes,
			allowedFileMimes: allowedFileMimes,
			allowedExtensions: allowedExtensions,
			restNonce: (window.wpMcpAiChat && window.wpMcpAiChat.nonce) ? window.wpMcpAiChat.nonce : '',
			historyPerPage: 20,
			asyncToolTimeout: 300000 // 5 minutes.
		};

		console.log('[PM AI Assistant] ✓ Chat configuration created and stored in window.wpMcpAiChatInstances[' + instanceId + ']');

		// Isolate chat form from page form validation.
		isolateChatForm($container);

		// Initialize chat instance.
		initializeChatInstance(instanceId);
	}

	/**
	 * Build context message for the AI assistant.
	 * Reserved for future use when context passing is implemented.
	 *
	 * @param {string} contextType Context type.
	 * @param {Object} contextData Context data.
	 * @return {string} Context message.
	 */
	// eslint-disable-next-line no-unused-vars
	function buildContextMessage(contextType, contextData) {
		let message = 'You are assisting with a ' + contextType + ' in the WordPress admin area.\n\n';
		message += '**Current ' + contextType.charAt(0).toUpperCase() + contextType.slice(1) + ' Details:**\n';
		message += '- ID: ' + contextData.id + '\n';
		message += '- Title: ' + contextData.title + '\n';

		if (contextData.description) {
			message += '- Description: ' + contextData.description + '\n';
		}

		// Add type-specific details.
		if (contextType === 'task') {
			if (contextData.task_status) {
				message += '- Status: ' + contextData.task_status + '\n';
			}
			if (contextData.task_priority) {
				message += '- Priority: ' + contextData.task_priority + '\n';
			}
			if (contextData.project_id) {
				message += '- Project ID: ' + contextData.project_id + '\n';
			}
			if (contextData.due_date) {
				message += '- Due Date: ' + contextData.due_date + '\n';
			}
			if (contextData.assigned_to) {
				message += '- Assigned To (User ID): ' + contextData.assigned_to + '\n';
			}
		} else if (contextType === 'event') {
			if (contextData.start_date) {
				message += '- Start Date: ' + contextData.start_date + '\n';
			}
			if (contextData.end_date) {
				message += '- End Date: ' + contextData.end_date + '\n';
			}
			if (contextData.location) {
				message += '- Location: ' + contextData.location + '\n';
			}
			if (contextData.event_type) {
				message += '- Type: ' + contextData.event_type + '\n';
			}
			if (contextData.all_day) {
				message += '- All Day Event: Yes\n';
			}
		} else if (contextType === 'project') {
			if (contextData.project_status) {
				message += '- Status: ' + contextData.project_status + '\n';
			}
			if (contextData.start_date) {
				message += '- Start Date: ' + contextData.start_date + '\n';
			}
			if (contextData.end_date) {
				message += '- End Date: ' + contextData.end_date + '\n';
			}
			if (contextData.budget) {
				message += '- Budget: ' + contextData.budget + '\n';
			}
			if (contextData.completion_percentage) {
				message += '- Completion: ' + contextData.completion_percentage + '%\n';
			}
		}

		message += '\n';
		message +=
			'Use the available tools to help the user manage this ' +
			contextType +
			'. You can list, create, update, or delete related items as needed.';

		return message;
	}

	/**
	 * Build the chat interface HTML structure.
	 * Based on Build Assistant page approach.
	 * IMPORTANT: Must match the structure expected by chat.js, which requires:
	 * - A <form class="wp-mcp-ai-chat__form"> wrapper around input controls
	 * - Messages div BEFORE the form
	 * - Controls div AFTER the form
	 *
	 * @param {string} instanceId - Unique instance identifier.
	 * @return {string} HTML string for chat interface.
	 */
	function buildChatHTML(instanceId) {
		return '<div class="wp-mcp-ai-chat wp-mcp-ai-chat--template-compact" id="' + escapeHtml(instanceId) + '" data-wp-mcp-ai-chat data-template="compact">' +
			'<div class="wp-mcp-ai-chat__transcript-controls">' +
			'<button type="button" class="wp-mcp-ai-chat__transcript-toggle" aria-expanded="false" aria-label="Expand conversation">' +
			'<svg class="wp-mcp-ai-chat__transcript-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z"></path>' +
			'</svg>' +
			'<span class="screen-reader-text">Expand conversation</span>' +
			'</button>' +
			'</div>' +
			'<div class="wp-mcp-ai-chat__messages" aria-live="polite"></div>' +
			'<form class="wp-mcp-ai-chat__form" data-instance-id="' + escapeHtml(instanceId) + '">' +
			'<div class="wp-mcp-ai-chat__status" role="status" aria-live="polite" hidden><span class="wp-mcp-ai-chat__status-text"></span></div>' +
			'<div class="wp-mcp-ai-chat__tool-shortcuts-wrapper" hidden>' +
			'<button type="button" class="wp-mcp-ai-chat__tool-shortcuts-toggle wp-mcp-ai-chat__tool-shortcuts-toggle--collapsed" aria-expanded="false" aria-controls="' + escapeHtml(instanceId) + '-tool-shortcuts">' +
			'<span class="wp-mcp-ai-chat__tool-shortcuts-toggle-text">Quick Tasks</span>' +
			'<svg class="wp-mcp-ai-chat__tool-shortcuts-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z"></path>' +
			'</svg>' +
			'</button>' +
			'<div id="' + escapeHtml(instanceId) + '-tool-shortcuts" class="wp-mcp-ai-chat__tool-shortcuts wp-mcp-ai-chat__tool-shortcuts--collapsed" role="group" aria-label="Assistant tool tasks" hidden></div>' +
			'</div>' +
			'<textarea id="' + escapeHtml(instanceId) + '-input" class="wp-mcp-ai-chat__input" rows="4" placeholder="Ask something…" required></textarea>' +
			'<div class="wp-mcp-ai-chat__attachments" hidden>' +
			'<div class="wp-mcp-ai-chat__attachments-header">Attachments</div>' +
			'<ul class="wp-mcp-ai-chat__attachments-list"></ul>' +
			'</div>' +
			'<div class="wp-mcp-ai-chat__actions">' +
			'<input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden>' +
			'<input type="file" class="wp-mcp-ai-chat__transcribe-input" accept="audio/*" hidden>' +
			'<button type="button" class="wp-mcp-ai-chat__voice-chat" aria-label="Voice chat">' +
			'<svg class="wp-mcp-ai-chat__voice-chat-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path>' +
			'<circle cx="12" cy="12" r="1.5" fill="currentColor"></circle>' +
			'</svg>' +
			'<span class="screen-reader-text">Voice chat</span>' +
			'</button>' +
			'<button type="button" class="wp-mcp-ai-chat__transcribe" aria-label="Transcribe audio">' +
			'<svg class="wp-mcp-ai-chat__transcribe-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path>' +
			'<path d="M12 16a7 7 0 0 0 6.93-6H17a5 5 0 0 1-10 0H5.07A7 7 0 0 0 12 16zm-1 2.05V21h2v-2.95A9 9 0 0 0 20.95 11H19a7 7 0 0 1-14 0H3.05A9 9 0 0 0 11 18.05z"></path>' +
			'</svg>' +
			'<span class="screen-reader-text">Transcribe audio</span>' +
			'</button>' +
			'<button type="button" class="wp-mcp-ai-chat__attach">Attach file</button>' +
			'<button type="button" class="wp-mcp-ai-chat__build" hidden>Build</button>' +
			'<button type="submit" class="wp-mcp-ai-chat__submit">Send</button>' +
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
			'<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2zM5 5v14h14V9h-4V5H5z"></path>' +
			'<path d="M7 5h6v3H7V5zm5 9a2 2 0 11-4 0 2 2 0 014 0z"></path>' +
			'</svg>' +
			'<span class="screen-reader-text">Save conversation</span>' +
			'</button>' +
			'<button type="button" class="wp-mcp-ai-chat__export" aria-label="Export conversation" title="Export conversation">' +
			'<svg class="wp-mcp-ai-chat__export-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M12 16a1 1 0 01-1-1V5a1 1 0 012 0v10a1 1 0 01-1 1z"></path>' +
			'<path d="M12 16a1 1 0 01-.707-.293l-4-4a1 1 0 011.414-1.414L12 13.586l3.293-3.293a1 1 0 011.414 1.414l-4 4A1 1 0 0112 16z"></path>' +
			'<path d="M5 19a1 1 0 010-2h14a1 1 0 010 2H5z"></path>' +
			'</svg>' +
			'<span class="screen-reader-text">Export conversation</span>' +
			'</button>' +
			'<button type="button" class="wp-mcp-ai-chat__history-toggle" aria-expanded="false" aria-controls="' + escapeHtml(instanceId) + '-history" aria-label="Show previous conversations">' +
			'<svg class="wp-mcp-ai-chat__history-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M6 5.5a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h7a1 1 0 010 2H7a1 1 0 01-1-1z"></path>' +
			'<path d="M5 9a1 1 0 012 0 1 1 0 11-2 0zm0 6a1 1 0 012 0 1 1 0 11-2 0zm0-12a1 1 0 012 0 1 1 0 11-2 0z"></path>' +
			'</svg>' +
			'<span class="screen-reader-text">Show previous conversations</span>' +
			'</button>' +
			'<button type="button" class="wp-mcp-ai-chat__new-chat" aria-label="Start new conversation">' +
			'<svg class="wp-mcp-ai-chat__new-chat-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M12 4a1 1 0 011 1v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6V5a1 1 0 011-1z"></path>' +
			'</svg>' +
			'<span class="screen-reader-text">Start new conversation</span>' +
			'</button>' +
			'</div>' +
			'</div>' +
			'<section class="wp-mcp-ai-chat__history" id="' + escapeHtml(instanceId) + '-history" hidden aria-label="Previous conversations">' +
			'<div class="wp-mcp-ai-chat__history-header">' +
			'<button type="button" class="wp-mcp-ai-chat__history-refresh" aria-label="Refresh conversation history" title="Refresh conversation history">' +
			'<svg class="wp-mcp-ai-chat__history-refresh-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M4 12a8 8 0 018-8V3c-1.105 0-2.165.21-3.13.594l1.42 1.42A6.004 6.004 0 0112 5a7 7 0 110 14 7 7 0 01-6.93-6H3a8 8 0 008 8 8 8 0 000-16V3l-3 3 3 3v-1.078z"></path>' +
			'</svg>' +
			'<span class="screen-reader-text">Refresh conversation history</span>' +
			'</button>' +
			'</div>' +
			'<div class="wp-mcp-ai-chat__history-status" role="status" aria-live="polite" hidden></div>' +
			'<ul class="wp-mcp-ai-chat__history-list" role="list"></ul>' +
			'<button type="button" class="wp-mcp-ai-chat__history-load-more" hidden>Load More</button>' +
			'</section>' +
			'</div>';
	}

	/**
	 * Generate a unique session key for the chat instance.
	 *
	 * @return {string} Session key.
	 */
	function generateSessionKey() {
		return 'pm-' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
	}

	/**
	 * Initialize a chat instance manually.
	 *
	 * @param {string} instanceId - Instance identifier.
	 */
	function initializeChatInstance(instanceId) {
		console.log('[PM AI Assistant] initializeChatInstance() called for:', instanceId);
		
		// Wait a brief moment for DOM to settle.
		setTimeout(function() {
			const container = document.getElementById(instanceId);

			if (!container) {
				console.error('[PM AI Assistant] CRITICAL: Container element not found in DOM:', instanceId);
				return;
			}
			
			console.log('[PM AI Assistant] ✓ Container element found, checking for chat init function...');
			console.log('[PM AI Assistant] window.wpMcpAiChatInit available?', !!window.wpMcpAiChatInit);
			console.log('[PM AI Assistant] window.wpMcpAiChatInit.init available?', 
				!!(window.wpMcpAiChatInit && typeof window.wpMcpAiChatInit.init === 'function'));

			// Re-initialize chat.js to pick up the new instance.
			if (window.wpMcpAiChatInit && typeof window.wpMcpAiChatInit.init === 'function') {
				try {
					console.log('[PM AI Assistant] Calling window.wpMcpAiChatInit.init()...');
					window.wpMcpAiChatInit.init();
					console.log('[PM AI Assistant] ✓ Chat initialization successful');
					
					// Focus the textarea to give user immediate access.
					setTimeout(function() {
						const textarea = container.querySelector('.wp-mcp-ai-chat__input');
						if (textarea) {
							textarea.focus();
							console.log('[PM AI Assistant] ✓ Chat textarea focused');
						}
					}, 200);
				} catch (error) {
					console.error('[PM AI Assistant] CRITICAL: Chat initialization failed with error:', error);
				}
			} else {
				console.error('[PM AI Assistant] CRITICAL: window.wpMcpAiChatInit.init not available');
				console.error('[PM AI Assistant] This means the chat bundle script is not loaded or not initialized');
				console.error('[PM AI Assistant] Check that wp-mcp-ai-chat script is enqueued properly');
			}
		}, 100);
	}

	/**
	 * Check if the block editor (Gutenberg) is active.
	 *
	 * @return {boolean} True if block editor is active.
	 */
	function isBlockEditorActive() {
		// Check if wp.data and editor store exist (block editor indicators).
		// Wrap in try-catch to handle potential exceptions from wp.data.select().
		try {
			return typeof wp !== 'undefined' && 
				   wp.data && 
				   typeof wp.data.select === 'function' &&
				   wp.data.select('core/editor') !== undefined;
		} catch (error) {
			console.log('[PM AI Assistant] Block editor detection failed:', error);
			return false;
		}
	}

	/**
	 * Wait for the metabox to be rendered in the DOM.
	 * Uses polling with exponential backoff for the block editor.
	 *
	 * @param {Function} callback - Function to call when metabox is ready.
	 * @param {number} maxAttempts - Maximum number of polling attempts.
	 */
	function waitForMetabox(callback, maxAttempts) {
		maxAttempts = maxAttempts || DEFAULT_POLLING_ATTEMPTS;
		let attempts = 0;
		let delay = INITIAL_POLLING_DELAY;

		function checkMetabox() {
			attempts++;
			
			const $selector = $('#wp-mcp-ai-pm-assistant-select');
			const $modal = $('#wp-mcp-ai-pm-assistant-modal');
			const $chatContainer = $('#wp-mcp-ai-pm-assistant-chat-container');

			console.log('[PM AI Assistant] Polling attempt ' + attempts + '/' + maxAttempts + ', delay: ' + delay + 'ms');
			console.log('[PM AI Assistant] Found elements:', {
				selector: $selector.length,
				modal: $modal.length,
				chatContainer: $chatContainer.length
			});

			if ($selector.length && $modal.length && $chatContainer.length) {
				console.log('[PM AI Assistant] ✓ All required elements found after ' + attempts + ' attempts');
				callback();
				return;
			}

			if (attempts >= maxAttempts) {
				console.error('[PM AI Assistant] TIMEOUT: Metabox elements not found after ' + maxAttempts + ' attempts');
				return;
			}

			// Use exponential backoff: increase delay up to MAX_POLLING_DELAY.
			delay = Math.min(delay * BACKOFF_MULTIPLIER, MAX_POLLING_DELAY);
			setTimeout(checkMetabox, delay);
		}

		checkMetabox();
	}

	/**
	 * Initialize for block editor context.
	 * Uses wp.domReady if available, or waits for metabox to appear.
	 */
	function initForBlockEditor() {
		console.log('[PM AI Assistant] Block editor detected, using specialized initialization');

		// Use wp.domReady if available (WordPress 5.0+).
		if (typeof wp !== 'undefined' && wp.domReady) {
			console.log('[PM AI Assistant] Using wp.domReady hook');
			wp.domReady(function() {
				console.log('[PM AI Assistant] ⚡ wp.domReady fired');
				waitForMetabox(function() {
					try {
						initPmAiAssistant();
						console.log('[PM AI Assistant] ✓ Block editor initialization complete');
					} catch (error) {
						console.error('[PM AI Assistant] CRITICAL: Block editor initialization failed:', error);
					}
				});
			});
		} else {
			// Fallback: Wait for document ready then poll for metabox.
			console.log('[PM AI Assistant] wp.domReady not available, using fallback polling');
			$(document).ready(function() {
				console.log('[PM AI Assistant] ⚡ document.ready fired, starting metabox polling');
				waitForMetabox(function() {
					try {
						initPmAiAssistant();
						console.log('[PM AI Assistant] ✓ Block editor initialization complete (fallback)');
					} catch (error) {
						console.error('[PM AI Assistant] CRITICAL: Block editor initialization failed:', error);
					}
				});
			});
		}
	}

	// Determine which editor is active and initialize accordingly.
	console.log('[PM AI Assistant] Determining editor type...');
	console.log('[PM AI Assistant] wp object available?', typeof wp !== 'undefined');
	console.log('[PM AI Assistant] wp.domReady available?', typeof wp !== 'undefined' && typeof wp.domReady !== 'undefined');
	console.log('[PM AI Assistant] wp.data available?', typeof wp !== 'undefined' && typeof wp.data !== 'undefined');

	if (isBlockEditorActive()) {
		initForBlockEditor();
	} else {
		// For classic editor or when block editor detection fails, use a hybrid approach.
		// Try classic first, but also set up polling as a fallback.
		console.log('[PM AI Assistant] Block editor not detected, using hybrid approach');
		$(document).ready(function () {
			console.log('[PM AI Assistant] ⚡ Document ready event fired');
			
			const $selector = $('#wp-mcp-ai-pm-assistant-select');
			
			if ($selector.length) {
				// Elements exist, initialize immediately.
				console.log('[PM AI Assistant] Elements found immediately, initializing');
				try {
					initPmAiAssistant();
					console.log('[PM AI Assistant] ✓ Initialization complete');
				} catch (error) {
					console.error('[PM AI Assistant] CRITICAL: Initialization failed:', error);
				}
			} else {
				// Elements don't exist yet, poll for them (might be block editor).
				console.log('[PM AI Assistant] Elements not found, starting polling (might be block editor)');
				waitForMetabox(function() {
					try {
						initPmAiAssistant();
						console.log('[PM AI Assistant] ✓ Initialization complete (after polling)');
					} catch (error) {
						console.error('[PM AI Assistant] CRITICAL: Initialization failed:', error);
					}
				}, HYBRID_POLLING_ATTEMPTS);
			}
		});
	}
	
	console.log('[PM AI Assistant] Script initialization complete, waiting for editor to load');
})(jQuery);
