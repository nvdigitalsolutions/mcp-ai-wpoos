/**
 * Project Management AI Assistant - Unified Metabox Interface
 *
 * Provides modal-based chat interface and quick action buttons for PM CPTs.
 * Based on the test assistant page pattern for consistency.
 *
 * @package WP_MCP_AI
 * @version 1.0.1
 */

(function ($) {
	'use strict';

	console.log('[PM AI Assistant Unified] Script loaded v1.0.1:', new Date().toISOString());

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
	 * Initialize the PM AI Assistant interface.
	 */
	function init() {
		console.log('[PM AI Assistant Unified] Initializing...');

		const $selector = $('#wp-mcp-ai-pm-assistant-select');
		const $openButton = $('.wp-mcp-ai-pm-open-assistant');
		const $actionButtons = $('.wp-mcp-ai-pm-ai-btn');
		const modal = document.getElementById('wp-mcp-ai-pm-assistant-modal');

		if (!$selector.length) {
			console.error('[PM AI Assistant Unified] Assistant selector not found');
			return;
		}

		console.log('[PM AI Assistant Unified] Found selector, buttons:', $actionButtons.length);

		// Handle assistant selection
		$selector.on('change', function () {
			const assistantId = $(this).val();
			const $selectedOption = $(this).find('option:selected');
			const assistantTitle = $selectedOption.data('title') || $selectedOption.text();

			console.log('[PM AI Assistant Unified] Assistant selected:', assistantId, assistantTitle);

			if (assistantId) {
				// Enable all buttons
				$openButton.prop('disabled', false);
				$actionButtons.prop('disabled', false);

				// Store selected assistant info for later use
				$openButton.data('assistant-id', assistantId);
				$openButton.data('assistant-title', assistantTitle);
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

			// Get title from the editor
			let title = $('#title').val() || $('#post-title-0').val() || $('input[name="post_title"]').val() || '';
			title = $.trim(title);

			if (!title) {
				alert(wpMcpAiPmAi.strings.noTitle);
				$('#title, #post-title-0, input[name="post_title"]').first().focus();
				return;
			}

			executeQuickAction(action, postId, title, assistantId, $button);
		});

		// Handle modal close
		if (modal) {
			const modalClose = modal.querySelector('.wp-mcp-ai-cpt-modal__close');
			const modalBackdrop = modal.querySelector('.wp-mcp-ai-cpt-modal__backdrop');

			if (modalClose) {
				modalClose.addEventListener('click', closeAssistantModal);
			}

			// Close modal on backdrop click
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

		console.log('[PM AI Assistant Unified] ✓ Initialization complete');
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
		console.log('[PM AI Assistant Unified] Opening modal:', assistantId, assistantTitle);

		const modal = document.getElementById('wp-mcp-ai-pm-assistant-modal');
		const modalTitle = document.getElementById('wp-mcp-ai-pm-modal-title');
		const chatContainer = document.getElementById('wp-mcp-ai-pm-assistant-chat-container');

		if (!modal || !chatContainer) {
			console.error('[PM AI Assistant Unified] Modal elements not found');
			return;
		}

		// Update modal title
		if (modalTitle) {
			modalTitle.textContent = assistantTitle || 'AI Assistant';
		}

		// Clear previous chat
		chatContainer.innerHTML = '';

		// Create unique instance ID
		const instanceId = 'wp-mcp-ai-pm-chat-' + assistantId + '-' + Date.now();

		// Build chat HTML (same as test assistant)
		const chatHTML = buildChatHTML(instanceId);
		chatContainer.innerHTML = chatHTML;

		// Initialize chat instance configuration
		if (!window.wpMcpAiChatInstances) {
			window.wpMcpAiChatInstances = {};
		}

		const baseConfig = window.wpMcpAiChat || {};
		const baseRestUrl = baseConfig.restUrl || '/wp-json/mcp-ai/v1';

		// Debug: Log base configuration
		console.log('[PM AI Assistant Unified] Base configuration:', {
			hasWpMcpAiChat: !!window.wpMcpAiChat,
			hasNonce: !!baseConfig.nonce,
			hasRestUrl: !!baseConfig.restUrl,
			restUrl: baseRestUrl,
			nonce: baseConfig.nonce ? baseConfig.nonce.substring(0, 10) + '...' : 'MISSING'
		});

		if (!baseConfig.nonce) {
			console.error('[PM AI Assistant Unified] ❌ CRITICAL: REST nonce is missing!');
			console.error('[PM AI Assistant Unified] wpMcpAiChat contents:', window.wpMcpAiChat);
			console.error('[PM AI Assistant Unified] Chat will fail authentication without a nonce');
		}

		// Get context data for the current post
		const contextData = wpMcpAiPmAssistant && wpMcpAiPmAssistant.contextData ? wpMcpAiPmAssistant.contextData : {};

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
			saveTranscript: false, // Don't save metabox chats
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
			// Add PM context
			contextData: contextData,
			postId: postId,
			postType: postType
		};

		console.log('[PM AI Assistant Unified] ✓ Configuration created for instance:', instanceId);
		console.log('[PM AI Assistant Unified] Configuration details:', {
			instanceId: instanceId,
			assistantId: assistantId,
			hasNonce: !!baseConfig.nonce,
			nonce: baseConfig.nonce ? baseConfig.nonce.substring(0, 10) + '...' : 'MISSING',
			restUrl: baseRestUrl,
			userId: baseConfig.currentUserId || 0,
			postId: postId,
			postType: postType
		});

		// Store reference globally for debugging
		if (!window.wpMcpAiChatInstances[instanceId]) {
			console.error('[PM AI Assistant Unified] ❌ Failed to store instance configuration!');
		}

		// Show modal
		modal.style.display = 'block';
		document.body.classList.add('wp-mcp-ai-modal-open');

		// Initialize chat instance
		initializeChatInstance(instanceId);
	}

	/**
	 * Close the assistant modal.
	 */
	function closeAssistantModal() {
		console.log('[PM AI Assistant Unified] Closing modal');

		const modal = document.getElementById('wp-mcp-ai-pm-assistant-modal');
		const chatContainer = document.getElementById('wp-mcp-ai-pm-assistant-chat-container');

		if (modal) {
			modal.style.display = 'none';
			document.body.classList.remove('wp-mcp-ai-modal-open');
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
		// Build compact chat template for modal
		// IMPORTANT: Use <div> instead of <form> because the modal is rendered inside the WordPress
		// post edit form, and nested forms are invalid HTML (browsers strip nested form tags).
		// The chat.js init() function will handle both form and div containers.
		//
		// CRITICAL: The .wp-mcp-ai-chat__form wrapper div (line 291) is REQUIRED for chat initialization.
		// Without it, chat.js init() will exit early and buttons will not work.
		return '<div class="wp-mcp-ai-chat wp-mcp-ai-chat--template-compact" id="' + escapeHtml(instanceId) + '" data-wp-mcp-ai-chat data-template="compact">' +
			'<div class="wp-mcp-ai-chat__transcript-controls">' +
			'<button type="button" class="wp-mcp-ai-chat__transcript-toggle" aria-expanded="false">' +
			'<svg class="wp-mcp-ai-chat__transcript-toggle-icon" viewBox="0 0 24 24"><path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z"></path></svg>' +
			'<span class="screen-reader-text">Expand conversation</span>' +
			'</button>' +
			'</div>' +
			'<div class="wp-mcp-ai-chat__messages" aria-live="polite"></div>' +
			// CRITICAL: .wp-mcp-ai-chat__form wrapper STARTS here - required for initialization
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
			// Use type="button" (not "submit") to prevent triggering parent WordPress form submission
			'<button type="button" class="wp-mcp-ai-chat__submit">Send</button>' +
			'</div>' +
			// CRITICAL: .wp-mcp-ai-chat__form wrapper ENDS here
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
		console.log('[PM AI Assistant Unified] Initializing chat instance:', instanceId);

		setTimeout(function () {
			const container = document.getElementById(instanceId);

			if (!container) {
				console.error('[PM AI Assistant Unified] Container not found:', instanceId);
				return;
			}

			// Debug: Log element presence for validation
			console.log('[PM AI Assistant Unified] Validating required elements...');
			const form = container.querySelector('.wp-mcp-ai-chat__form');
			const textarea = container.querySelector('.wp-mcp-ai-chat__input');
			const messagesEl = container.querySelector('.wp-mcp-ai-chat__messages');
			const statusEl = container.querySelector('.wp-mcp-ai-chat__status');

			console.log('[PM AI Assistant Unified] Element check:', {
				container: !!container,
				form: !!form,
				textarea: !!textarea,
				messagesEl: !!messagesEl,
				statusEl: !!statusEl,
				hasDataAttr: container.hasAttribute('data-wp-mcp-ai-chat'),
				instanceId: container.getAttribute('id')
			});

			// Critical diagnostic: If form is missing, log the container's HTML structure
			if (!form) {
				console.error('[PM AI Assistant Unified] ❌ CRITICAL: .wp-mcp-ai-chat__form element is MISSING!');
				console.error('[PM AI Assistant Unified] This will cause chat initialization to fail.');
				console.error('[PM AI Assistant Unified] Container HTML structure:', container.innerHTML.substring(0, 500));
				console.error('[PM AI Assistant Unified] This may indicate a caching issue. Please hard-refresh (Ctrl+Shift+R).');
			}

			// Debug: Log instance configuration
			const config = window.wpMcpAiChatInstances ? window.wpMcpAiChatInstances[instanceId] : null;
			console.log('[PM AI Assistant Unified] Instance config:', {
				hasGlobal: !!window.wpMcpAiChatInstances,
				hasConfig: !!config,
				hasNonce: config && !!config.restNonce,
				hasAssistantId: config && !!config.assistantId,
				nonce: config && config.restNonce ? config.restNonce.substring(0, 10) + '...' : 'MISSING'
			});

			// Debug: Log localStorage availability
			console.log('[PM AI Assistant Unified] Storage check:', {
				hasLocalStorage: typeof window.localStorage !== 'undefined',
				hasStorageService: typeof window.wpMcpAiChatStorage !== 'undefined'
			});

			// Trigger chat initialization
			if (window.wpMcpAiChatInit && typeof window.wpMcpAiChatInit.init === 'function') {
				console.log('[PM AI Assistant Unified] Calling wpMcpAiChatInit.init()...');
				window.wpMcpAiChatInit.init();
				console.log('[PM AI Assistant Unified] ✓ Chat initialization called');

				// Verify initialization success by checking for the initialized attribute
				setTimeout(function () {
					const initialized = container.hasAttribute('data-wp-mcp-ai-initialized');
					console.log('[PM AI Assistant Unified] Initialization result:', {
						initialized: initialized,
						hasAttribute: container.hasAttribute('data-wp-mcp-ai-initialized')
					});

					if (!initialized) {
						console.error('[PM AI Assistant Unified] ❌ Chat initialization failed - container not marked as initialized');
						console.error('[PM AI Assistant Unified] This usually means validation failed in chat.js init()');
					}

					// Focus textarea if initialized
					const textarea = container.querySelector('.wp-mcp-ai-chat__input');
					if (textarea && initialized) {
						textarea.focus();
						console.log('[PM AI Assistant Unified] ✓ Textarea focused');
					}
				}, 300);
			} else {
				console.error('[PM AI Assistant Unified] Chat init function not available');
				console.error('[PM AI Assistant Unified] window.wpMcpAiChatInit:', window.wpMcpAiChatInit);
			}
		}, 100);
	}

	/**
	 * Execute a quick action.
	 *
	 * @param {string} action   Action to execute.
	 * @param {string} postId   Post ID.
	 * @param {string} title    Post title.
	 * @param {string} assistantId Assistant ID.
	 * @param {jQuery} $button  Button element.
	 */
	function executeQuickAction(action, postId, title, assistantId, $button) {
		console.log('[PM AI Assistant Unified] Executing action:', action);

		const $container = $button.closest('.wp-mcp-ai-pm-ai-actions');
		const $result = $container.find('.wp-mcp-ai-pm-ai-result');
		const $resultContent = $container.find('.wp-mcp-ai-pm-ai-result-content');
		const $loading = $container.find('.wp-mcp-ai-pm-ai-loading');

		// Hide previous results
		$result.hide();

		// Show loading
		$loading.show();
		$button.prop('disabled', true);

		// Prepare data
		const data = {
			action: 'wp_mcp_ai_pm_' + action,
			nonce: wpMcpAiPmAi.nonce,
			post_id: postId,
			title: title,
			assistant_id: assistantId
		};

		// Add description for relevant actions
		if (action === 'suggest_tasks' || action === 'analyze_project') {
			let description = '';
			if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
				description = tinymce.get('content').getContent();
			} else {
				description = $('#content').val();
			}
			data.description = description;
		}

		// Make AJAX request
		$.post(wpMcpAiPmAi.ajaxUrl, data, function (response) {
			$loading.hide();
			$button.prop('disabled', false);

			if (response.success) {
				handleActionSuccess(action, response.data, $resultContent, $result);
			} else {
				showActionError(response.data.message, $resultContent, $result);
			}
		}).fail(function () {
			$loading.hide();
			$button.prop('disabled', false);
			showActionError(wpMcpAiPmAi.strings.error, $resultContent, $result);
		});
	}

	/**
	 * Handle successful action response.
	 *
	 * @param {string} action         Action type.
	 * @param {Object} data           Response data.
	 * @param {jQuery} $resultContent Result content element.
	 * @param {jQuery} $result        Result container element.
	 */
	function handleActionSuccess(action, data, $resultContent, $result) {
		switch (action) {
			case 'generate_description':
				if (data.description) {
					insertIntoEditor(data.description);
					$resultContent.html('<strong>' + wpMcpAiPmAi.strings.applied + '</strong>');
					$result.find('.notice').removeClass('notice-error').addClass('notice-success');
					$result.show();

					setTimeout(function () {
						$result.fadeOut();
					}, 3000);
				}
				break;

			case 'suggest_tasks':
				if (data.tasks && data.tasks.length > 0) {
					let html = '<strong>' + wpMcpAiPmAi.strings.viewTasks + '</strong><br>';
					html += '<ol style="margin: 10px 0; padding-left: 20px;">';
					data.tasks.forEach(function (task) {
						html += '<li style="margin: 5px 0;">' + escapeHtml(task) + '</li>';
					});
					html += '</ol>';

					$resultContent.html(html);
					$result.find('.notice').removeClass('notice-error').addClass('notice-info');
					$result.show();
				}
				break;

			case 'analyze_project':
				if (data.analysis) {
					const html = '<strong>Project Analysis:</strong><br>' + escapeHtml(data.analysis).replace(/\n/g, '<br>');
					$resultContent.html(html);
					$result.find('.notice').removeClass('notice-error').addClass('notice-info');
					$result.show();
				}
				break;

			case 'suggest_agenda':
				if (data.agenda) {
					insertIntoEditor(data.agenda);
					$resultContent.html('<strong>' + wpMcpAiPmAi.strings.applied + '</strong>');
					$result.find('.notice').removeClass('notice-error').addClass('notice-success');
					$result.show();

					setTimeout(function () {
						$result.fadeOut();
					}, 3000);
				}
				break;
		}
	}

	/**
	 * Show error message.
	 *
	 * @param {string} message        Error message.
	 * @param {jQuery} $resultContent Result content element.
	 * @param {jQuery} $result        Result container element.
	 */
	function showActionError(message, $resultContent, $result) {
		$resultContent.html('<strong>Error:</strong> ' + escapeHtml(message));
		$result.find('.notice').removeClass('notice-success notice-info').addClass('notice-error');
		$result.show();
	}

	/**
	 * Insert content into the WordPress editor.
	 *
	 * @param {string} content Content to insert.
	 */
	function insertIntoEditor(content) {
		if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
			tinymce.get('content').setContent(content);
		} else {
			$('#content').val(content);
		}
	}

	/**
	 * Generate a unique session key.
	 *
	 * @return {string} Session key.
	 */
	function generateSessionKey() {
		return 'pm-' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
	}

	// Initialize when DOM is ready
	if (typeof wp !== 'undefined' && wp.domReady) {
		wp.domReady(init);
	} else {
		$(document).ready(init);
	}

})(jQuery);
