/**
 * Project Management AI Assistant Metabox
 *
 * Handles the AI assistant chat interface within project/task/event edit screens.
 *
 * @package WP_MCP_AI
 */

(function ($) {
	'use strict';

	/**
	 * Initialize the AI assistant metabox.
	 */
	function initPmAiAssistant() {
		const $selector = $('#wp-mcp-ai-pm-assistant-select');
		const $modal = $('#wp-mcp-ai-pm-assistant-modal');
		const $chatContainer = $('#wp-mcp-ai-pm-assistant-chat-container');
		const $buildAction = $('#wp-mcp-ai-pm-build-action');
		const $buildBtn = $('#wp-mcp-ai-pm-build-btn');
		const $modalClose = $modal.find('.wp-mcp-ai-pm-assistant-modal__close');
		const $modalBackdrop = $modal.find('.wp-mcp-ai-pm-assistant-modal__backdrop');

		if (!$selector.length || !$chatContainer.length || !$modal.length) {
			if (window.console && console.log) {
				console.log('[PM AI Assistant] Required elements not found:', {
					selector: $selector.length,
					chatContainer: $chatContainer.length,
					modal: $modal.length
				});
			}
			return;
		}

		// Move modal to body to ensure position: fixed works correctly.
		// Modals rendered inside metaboxes may not display as overlays due to CSS positioning contexts.
		// Remove any inline styles that might interfere, then hide using CSS class.
		$modal.removeAttr('style');
		$modal.removeClass('wp-mcp-ai-pm-assistant-modal--visible');
		$modal.appendTo('body');
		
		if (window.console && console.log) {
			console.log('[PM AI Assistant] Modal moved to body and hidden');
		}

		// Get localized data.
		const config = window.wpMcpAiPmAssistant || {};
		const contextType = config.contextType || 'project';
		const contextData = config.contextData || {};
		const postId = config.postId || 0;

		// Handle assistant selection.
		$selector.on('change', function () {
			const assistantId = $(this).val();
			const $selectedOption = $(this).find('option:selected');
			const assistantTitle = $selectedOption.data('title') || $selectedOption.text();

			if (!assistantId) {
				$buildAction.hide();
				if (window.console && console.log) {
					console.log('[PM AI Assistant] No assistant selected, hiding button');
				}
				return;
			}

			// Update Build with AI button data attributes.
			$buildBtn.attr('data-assistant-id', assistantId);
			$buildBtn.attr('data-assistant-title', assistantTitle);

			// Show Build with AI button.
			$buildAction.show();
			if (window.console && console.log) {
				console.log('[PM AI Assistant] Assistant selected:', assistantId, assistantTitle);
			}
		});

		// Handle Build with AI button click.
		$buildBtn.on('click', function () {
			const assistantId = $(this).attr('data-assistant-id');
			const assistantTitle = $(this).attr('data-assistant-title');

			if (!assistantId) {
				return;
			}

			// Open modal and initialize chat interface.
			openModal(assistantId, assistantTitle, contextType, contextData, postId);
		});

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
			if (window.console && console.log) {
				console.log('[PM AI Assistant] Opening modal for assistant:', assistantId, assistantTitle);
			}
			
			// Update modal title.
			$modal.find('#wp-mcp-ai-pm-assistant-modal__title').text(assistantTitle || 'AI Assistant');

			// Show modal using CSS class to override any inline styles.
			$modal.addClass('wp-mcp-ai-pm-assistant-modal--visible');
			$('body').addClass('wp-mcp-ai-pm-assistant-modal-open');

			// Initialize chat interface if not already initialized.
			if ($chatContainer.is(':empty')) {
				if (window.console && console.log) {
					console.log('[PM AI Assistant] Chat container is empty, initializing...');
				}
				initChatInterface(assistantId, contextType, contextData, postId);
			} else {
				if (window.console && console.log) {
					console.log('[PM AI Assistant] Chat container already has content, skipping initialization');
				}
			}
		}

		/**
		 * Close the modal.
		 */
		function closeModal() {
			if (window.console && console.log) {
				console.log('[PM AI Assistant] Closing modal');
			}
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

		if (window.console && console.log) {
			console.log('[PM AI Assistant] Chat form isolated from page form validation');
		}
	}

	/**
	 * Initialize the chat interface.
	 *
	 * @param {string} assistantId   Assistant ID.
	 * @param {string} contextType   Context type (project, task, or event).
	 * @param {Object} contextData   Context data about the current item.
	 * @param {number} postId        Current post ID.
	 */
	function initChatInterface(assistantId, contextType, contextData, postId) {
		const $container = $('#wp-mcp-ai-pm-assistant-chat-container');
		const config = window.wpMcpAiPmAssistant || {};

		// Ensure AJAX URL is available.
		const ajaxUrl = config.ajaxUrl || window.ajaxurl;
		if (!ajaxUrl) {
			console.error('[PM AI Assistant] AJAX URL not available');
			$container.html(
				'<div class="notice notice-error"><p>Configuration error: AJAX URL not found. Please refresh the page.</p></div>'
			);
			return;
		}

		// Show loading state.
		$container.html('<div class="wp-mcp-ai-pm-assistant-loading">Loading AI assistant...</div>');

		// Build context message to prepend to chat.
		const contextMessage = buildContextMessage(contextType, contextData);

		// Make AJAX request to get the rendered chat shortcode.
		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'wp_mcp_ai_pm_render_chat',
				assistant_id: assistantId,
				context_message: contextMessage,
				post_id: postId,
				nonce: config.nonce,
			},
			success: function (response) {
				if (response.success && response.data.html) {
					// Insert the rendered chat HTML.
					$container.html(response.data.html);

					// Attempt to recover configuration/instance from the response map if direct values are missing.
					var responseConfig = response.data.config;
					var responseInstanceId = response.data.instance_id;
					var configMap = response.data.config_map || response.data.configs || null;

					if ((!responseConfig || !responseInstanceId) && configMap) {
						var configKeys = Object.keys(configMap);

						if (!responseInstanceId && configKeys.length > 1 && window.console && console.warn) {
							console.warn('[PM AI Assistant] Multiple chat configurations available but no instance ID was returned; cannot auto-select config.', {
								configKeys: configKeys
							});
						}

						if (!responseInstanceId && configKeys.length === 1) {
							responseInstanceId = configKeys[0];
						}

						if (responseInstanceId && configMap[responseInstanceId]) {
							responseConfig = configMap[responseInstanceId];
						}
					}

					// Inject the chat configuration into the global window object.
					// This is necessary because wp_add_inline_script() doesn't work in AJAX contexts.
					if (responseConfig && responseInstanceId) {
						if (!window.wpMcpAiChatInstances) {
							window.wpMcpAiChatInstances = {};
						}
						
						// Check if configuration already exists and log warning if overwriting
						if (window.wpMcpAiChatInstances[responseInstanceId]) {
							if (window.console && console.warn) {
								console.warn('[PM AI Assistant] Overwriting existing configuration for instance:', responseInstanceId);
							}
						}
						
						window.wpMcpAiChatInstances[responseInstanceId] = responseConfig;
						
						if (window.console && console.log) {
							console.log('[PM AI Assistant] Chat configuration injected for instance:', responseInstanceId);
							console.log('[PM AI Assistant] Assistant ID:', responseConfig.assistantId);
						}
					} else {
						if (window.console && console.warn) {
							console.warn('[PM AI Assistant] Chat configuration or instance ID missing in response', {
								instanceId: responseInstanceId,
								configKeys: configMap ? Object.keys(configMap) : []
							});
						}
					}

					// Isolate chat form from page form validation.
					isolateChatForm($container);

					// Trigger chat initialization if available.
					if (window.wpMcpAiChatInit && typeof window.wpMcpAiChatInit.init === 'function') {
						try {
							window.wpMcpAiChatInit.init();
						} catch (error) {
							console.error('[PM AI Assistant] Failed to reinitialize chat:', error);
							// Show user-friendly error message.
							$container.prepend(
								'<div class="notice notice-warning is-dismissible"><p>' +
									'Chat loaded but initialization encountered an issue. Some features may not work properly. ' +
									'<a href="#" onclick="window.location.reload(); return false;">Refresh page</a>' +
									'</p></div>'
							);
						}
					}
				} else {
					$container.html(
						'<div class="notice notice-error"><p>' +
							(response.data?.message || 'Failed to load AI assistant.') +
							'</p></div>'
					);
				}
			},
			error: function (xhr, status, error) {
				console.error('[PM AI Assistant] AJAX error:', error);
				$container.html(
					'<div class="notice notice-error"><p>Failed to load AI assistant. Please refresh the page and try again.</p></div>'
				);
			},
		});
	}

	/**
	 * Build context message for the AI assistant.
	 *
	 * @param {string} contextType Context type.
	 * @param {Object} contextData Context data.
	 * @return {string} Context message.
	 */
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

	// Initialize on document ready.
	$(document).ready(function () {
		initPmAiAssistant();
	});
})(jQuery);
