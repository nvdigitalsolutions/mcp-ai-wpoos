/**
 * Professional Selector Frontend JavaScript
 *
 * Handles the professional selection UI with modal chat interface and dynamic model loading.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Professional Selector Controller
	 */
	const ProfessionalSelector = {
		/**
		 * Initialize all professional selector instances.
		 */
		init: function() {
			$('[data-wp-mcp-ai-professional-selector]').each(function() {
				const $container = $(this);
				const instanceId = $container.attr('id');
				const configScript = $('script[data-selector-config="' + instanceId + '"]');
				
				if (configScript.length) {
					const config = JSON.parse(configScript.text());
					ProfessionalSelector.initInstance($container, config);
				}
			});
		},

		/**
		 * Initialize a single professional selector instance.
		 *
		 * @param {jQuery} $container Container element.
		 * @param {Object} config     Configuration object.
		 */
		initInstance: function($container, config) {
			const $form = $container.find('[data-selector-form]');
			const $modal = $container.find('[data-modal]');
			const $modalClose = $container.find('[data-modal-close]');
			const $modalBackdrop = $container.find('[data-modal-backdrop]');
			const $assistantSelect = $container.find('[data-assistant-select]');
			const $providerSelect = $container.find('[data-provider-select]');
			const $modelSelect = $container.find('[data-model-select]');
			const $modelLoading = $container.find('[data-model-loading]');
			const $professionalSelect = $container.find('[data-professional-select]');
			const $errorMessage = $container.find('[data-error-message]');
			const $temperatureInput = $container.find('[data-temperature-input]');

			// Store instance data.
			$container.data('selector-config', config);
			$container.data('selector-state', {
				assistant: null,
				professional: null,
				provider: null,
				model: null,
				temperature: null
			});

			// Load models when professional is selected (to populate defaults).
			$professionalSelect.on('change', function() {
				const professionalId = $(this).val();
				if (professionalId) {
					ProfessionalSelector.loadProfessionalDefaults($container, professionalId);
				}
			});

			// Load models when provider changes.
			$providerSelect.on('change', function() {
				const provider = $(this).val();
				if (provider) {
					ProfessionalSelector.loadModels($container, provider);
				} else {
					$modelSelect.prop('disabled', true).html('<option value="">' + wpMcpAiProfessionalSelector.strings.selectModel + '</option>');
				}
			});

			// Handle form submission.
			$container.find('.wp-mcp-ai-professional-selector__form').on('submit', function(e) {
				e.preventDefault();
				ProfessionalSelector.handleStartChat($container);
			});

			// Handle modal close button.
			$modalClose.on('click', function() {
				ProfessionalSelector.closeModal($container);
			});

			// Handle modal backdrop click.
			$modal.on('click', function(e) {
				if (e.target === $modal[0] || e.target === $modalBackdrop[0]) {
					ProfessionalSelector.closeModal($container);
				}
			});

			// Handle escape key to close modal.
			$(document).on('keydown', function(e) {
				if (e.key === 'Escape' && $modal.is(':visible')) {
					ProfessionalSelector.closeModal($container);
				}
			});
		},

		/**
		 * Load professional defaults.
		 *
		 * @param {jQuery} $container    Container element.
		 * @param {string} professionalId Professional ID.
		 */
		loadProfessionalDefaults: function($container, professionalId) {
			const $providerSelect = $container.find('[data-provider-select]');
			const $temperatureInput = $container.find('[data-temperature-input]');

			$.ajax({
				url: wpMcpAiProfessionalSelector.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_professional_config',
					nonce: wpMcpAiProfessionalSelector.nonce,
					professional_id: professionalId
				},
				success: function(response) {
					if (response.success && response.data.defaults) {
						const defaults = response.data.defaults;
						
						// Set provider if available.
						if (defaults.provider) {
							$providerSelect.val(defaults.provider).trigger('change');
						}
						
						// Set temperature if available and field is shown.
						if (defaults.temperature && $temperatureInput.length) {
							$temperatureInput.val(defaults.temperature);
						}
					}
				}
			});
		},

		/**
		 * Load models for a provider via AJAX.
		 *
		 * @param {jQuery} $container Container element.
		 * @param {string} provider   Provider name.
		 */
		loadModels: function($container, provider) {
			const $modelSelect = $container.find('[data-model-select]');
			const $modelLoading = $container.find('[data-model-loading]');

			$modelSelect.prop('disabled', true);
			$modelLoading.removeAttr('hidden');

			// Use the same AJAX endpoint as admin model selector.
			$.ajax({
				url: wpMcpAiProfessionalSelector.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_models_for_provider',
					nonce: wpMcpAiProfessionalSelector.nonce,
					provider: provider
				},
				success: function(response) {
					$modelLoading.attr('hidden', '');
					
					if (response.success && response.data.models) {
						ProfessionalSelector.populateModels($modelSelect, response.data.models);
						$modelSelect.prop('disabled', false);
					} else {
						// Show specific error message if available, otherwise use generic message.
						const errorMessage = response.data && response.data.message 
							? response.data.message 
							: wpMcpAiProfessionalSelector.strings.errorLoading;
						ProfessionalSelector.showError($container, errorMessage);
						$modelSelect.prop('disabled', false);
					}
				},
				error: function() {
					$modelLoading.attr('hidden', '');
					ProfessionalSelector.showError($container, wpMcpAiProfessionalSelector.strings.errorLoading);
					$modelSelect.prop('disabled', false);
				}
			});
		},

		/**
		 * Populate model dropdown with options.
		 *
		 * @param {jQuery} $select Model select element.
		 * @param {Object} models  Models object (id => name).
		 */
		populateModels: function($select, models) {
			$select.html('<option value="">' + wpMcpAiProfessionalSelector.strings.selectModel + '</option>');
			
			$.each(models, function(modelId, modelName) {
				$select.append($('<option></option>')
					.val(modelId)
					.text(modelName));
			});
		},

		/**
		 * Handle start chat button click.
		 *
		 * @param {jQuery} $container Container element.
		 */
		handleStartChat: function($container) {
			const $assistantSelect = $container.find('[data-assistant-select]');
			const $professionalSelect = $container.find('[data-professional-select]');
			const $providerSelect = $container.find('[data-provider-select]');
			const $modelSelect = $container.find('[data-model-select]');
			const $temperatureInput = $container.find('[data-temperature-input]');

			const assistant = $assistantSelect.val();
			const professional = $professionalSelect.val();
			const provider = $providerSelect.val();
			const model = $modelSelect.val();
			const temperature = $temperatureInput.length ? $temperatureInput.val() : null;

			// Validate required fields.
			if (!assistant || !professional || !provider || !model) {
				ProfessionalSelector.showError($container, wpMcpAiProfessionalSelector.strings.selectRequired);
				return;
			}

			// Store selection state.
			const state = {
				assistant: assistant,
				assistantName: $assistantSelect.find('option:selected').text(),
				professional: professional,
				professionalName: $professionalSelect.find('option:selected').text(),
				provider: provider,
				providerName: $providerSelect.find('option:selected').text(),
				model: model,
				temperature: temperature
			};
			$container.data('selector-state', state);

			// Hide error message.
			ProfessionalSelector.hideError($container);

			// Open modal and create chat interface.
			ProfessionalSelector.openModal($container, state);
		},

		/**
		 * Open the modal with the chat interface.
		 *
		 * @param {jQuery} $container Container element.
		 * @param {Object} state      Selection state.
		 */
		openModal: function($container, state) {
			const config = $container.data('selector-config');
			const $modal = $container.find('[data-modal]');
			const $modalTitle = $container.find('[data-modal-title]');
			const $modalConfig = $container.find('[data-modal-config]');
			const $modalChat = $container.find('[data-modal-chat]');

			// Update modal title.
			$modalTitle.text('Chat: ' + state.professionalName);

			// Build configuration display text.
			let configText = '<p><strong>Configuration:</strong></p>';
			configText += '<ul>';
			configText += '<li><strong>Assistant:</strong> ' + ProfessionalSelector.escapeHtml(state.assistantName) + '</li>';
			configText += '<li><strong>Professional:</strong> ' + ProfessionalSelector.escapeHtml(state.professionalName) + '</li>';
			configText += '<li><strong>Provider:</strong> ' + ProfessionalSelector.escapeHtml(state.providerName) + '</li>';
			configText += '<li><strong>Model:</strong> ' + ProfessionalSelector.escapeHtml(state.model) + '</li>';
			if (state.temperature) {
				configText += '<li><strong>Temperature:</strong> ' + ProfessionalSelector.escapeHtml(state.temperature) + '</li>';
			}
			configText += '</ul>';
			$modalConfig.html(configText);

			// Create and render chat interface.
			ProfessionalSelector.createChatInterface($container, state, $modalChat);

			// Show modal with both jQuery method and CSS class for maximum compatibility.
			$modal.show().addClass('wp-mcp-ai-modal--visible');
			$('body').addClass('wp-mcp-ai-professional-selector-modal-open');
		},

		/**
		 * Close the modal.
		 *
		 * @param {jQuery} $container Container element.
		 */
		closeModal: function($container) {
			const $modal = $container.find('[data-modal]');
			const $modalChat = $container.find('[data-modal-chat]');

			$modal.hide().removeClass('wp-mcp-ai-modal--visible');
			$('body').removeClass('wp-mcp-ai-professional-selector-modal-open');
			
			// Clear chat container.
			$modalChat.empty();
		},

		/**
		 * Escape HTML to prevent XSS.
		 *
		 * @param {string} text Text to escape.
		 * @return {string} Escaped text.
		 */
		escapeHtml: function(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		/**
		 * Create the chat interface.
		 *
		 * @param {jQuery} $container   Container element.
		 * @param {Object} state        Selection state.
		 * @param {jQuery} $chatWrapper Chat wrapper element.
		 */
		createChatInterface: function($container, state, $chatWrapper) {
			const config = $container.data('selector-config');

			// Create unique instance ID for this chat.
			const instanceId = 'wp-mcp-ai-prof-selector-chat-' + state.professional + '-' + Date.now();

			// Build chat HTML structure directly (no AJAX - more reliable).
			const chatHTML = ProfessionalSelector.buildChatHTML(instanceId);
			$chatWrapper.html(chatHTML);

			// Initialize chat instance configuration.
			if (!window.wpMcpAiChatInstances) {
				window.wpMcpAiChatInstances = {};
			}

			// Build endpoints from global config or defaults
			const baseRestUrl = (window.wpMcpAiChat && window.wpMcpAiChat.restUrl) 
				? window.wpMcpAiChat.restUrl 
				: '/wp-json/mcp-ai/v1';

			// Store configuration for chat initialization.
			window.wpMcpAiChatInstances[instanceId] = {
				id: instanceId,
				assistantId: state.assistant,
				professionId: state.professional,
				provider: state.provider,
				model: state.model,
				temperature: state.temperature,
				userId: (window.wpMcpAiChat && typeof window.wpMcpAiChat.currentUserId !== 'undefined') 
					? window.wpMcpAiChat.currentUserId 
					: 0,
				restUrl: baseRestUrl,
				messagesEndpoint: baseRestUrl + '/chat-client',
				toolsEndpoint: baseRestUrl + '/tools',
				filesEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.filesEndpoint) 
					? window.wpMcpAiChat.filesEndpoint 
					: baseRestUrl + '/files/',
				transcriptsEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.transcriptsEndpoint) 
					? window.wpMcpAiChat.transcriptsEndpoint 
					: baseRestUrl + '/chat-transcripts',
				crawl4aiTaskEndpoint: baseRestUrl + '/crawl4ai/task/',
				uploadEndpoint: (window.wpMcpAiChat && window.wpMcpAiChat.uploadEndpoint) 
					? window.wpMcpAiChat.uploadEndpoint 
					: '/wp-json/wp/v2/media',
				sessionKey: ProfessionalSelector.generateSessionKey(),
				enableStreaming: config.enableStreaming,
				canUploadAttachments: true,
				saveTranscript: config.saveTranscript,
				allowSensitiveTools: config.allowSensitiveTools,
				toolShortcuts: [],
				fileAccept: (window.wpMcpAiChat && window.wpMcpAiChat.fileAccept) ? window.wpMcpAiChat.fileAccept : '',
				allowedImageMimes: (window.wpMcpAiChat && window.wpMcpAiChat.allowedImageMimes) ? window.wpMcpAiChat.allowedImageMimes : [],
				allowedFileMimes: (window.wpMcpAiChat && window.wpMcpAiChat.allowedFileMimes) ? window.wpMcpAiChat.allowedFileMimes : [],
				allowedExtensions: (window.wpMcpAiChat && window.wpMcpAiChat.allowedExtensions) ? window.wpMcpAiChat.allowedExtensions : [],
				restNonce: (window.wpMcpAiChat && window.wpMcpAiChat.nonce) ? window.wpMcpAiChat.nonce : '',
				historyPerPage: 20,
			};

			// Initialize the chat interface immediately.
			ProfessionalSelector.initializeChatInterface();
		},

		/**
		 * Show error message.
		 *
		 * @param {jQuery} $container Container element.
		 * @param {string} message    Error message.
		 */
		showError: function($container, message) {
			const $errorMessage = $container.find('[data-error-message]');
			$errorMessage.text(message).removeAttr('hidden');
		},

		/**
		 * Hide error message.
		 *
		 * @param {jQuery} $container Container element.
		 */
		hideError: function($container) {
			const $errorMessage = $container.find('[data-error-message]');
			$errorMessage.attr('hidden', '').text('');
		},

		/**
		 * Initialize the dynamically inserted chat interface.
		 *
		 * This calls the chat.js initialization function to attach event handlers
		 * to the dynamically inserted chat HTML. A delay is used to ensure the
		 * DOM is fully rendered and the chat instance configuration is available
		 * after the AJAX response completes.
		 */
		initializeChatInterface: function() {
			/**
			 * Delay before initializing chat interface (milliseconds).
			 * This ensures the DOM is fully rendered and JavaScript execution context
			 * is complete after AJAX response. This delay matches the pattern used
			 * in admin-test-profession.js for similar dynamic chat initialization.
			 */
			const CHAT_INIT_DELAY_MS = 100;
			
			setTimeout(function() {
				// Check if the chat init API is available
				if (typeof window.wpMcpAiChatInit !== 'undefined' && window.wpMcpAiChatInit.init) {
					// Call the chat initialization function
					window.wpMcpAiChatInit.init();
					
					if (window.console && console.log) {
						console.log('[Professional Selector] Chat interface initialized successfully.');
					}
				} else if (window.console && console.warn) {
					console.warn('[Professional Selector] Chat initialization API not available. Chat may not function correctly.');
					console.warn('[Professional Selector] Available: window.wpMcpAiChatInit =', typeof window.wpMcpAiChatInit);
				}
			}, CHAT_INIT_DELAY_MS);
		},

		/**
		 * Build the chat interface HTML structure.
		 * Based on the pattern from admin-test-profession.js for reliability.
		 *
		 * @param {string} instanceId Unique instance identifier.
		 * @return {string} HTML string for chat interface.
		 */
		buildChatHTML: function(instanceId) {
			// These are hardcoded safe strings, no need to escape
			const placeholderText = 'Ask me anything...';
			const attachLabel = 'Attach file';
			const transcribeLabel = 'Transcribe audio';
			const sendLabel = 'Send';
			
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
				'<textarea class="wp-mcp-ai-chat__input" rows="4" placeholder="' + placeholderText + '" required></textarea>' +
				'<div class="wp-mcp-ai-chat__attachments" hidden>' +
				'<div class="wp-mcp-ai-chat__attachments-header">Attachments</div>' +
				'<ul class="wp-mcp-ai-chat__attachments-list"></ul>' +
				'</div>' +
				'<div class="wp-mcp-ai-chat__actions">' +
				'<input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden />' +
				'<input type="file" class="wp-mcp-ai-chat__transcribe-input" accept="audio/*" hidden />' +
				'<button type="button" class="wp-mcp-ai-chat__transcribe" aria-label="' + transcribeLabel + '">' +
				'<svg class="wp-mcp-ai-chat__transcribe-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
				'<path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path>' +
				'<path d="M12 16a7 7 0 0 0 6.93-6H17a5 5 0 0 1-10 0H5.07A7 7 0 0 0 12 16zm-1 2.05V21h2v-2.95A9 9 0 0 0 20.95 11H19a7 7 0 0 1-14 0H3.05A9 9 0 0 0 11 18.05z"></path>' +
				'</svg>' +
				'<span class="screen-reader-text">' + transcribeLabel + '</span>' +
				'</button>' +
				'<button type="button" class="wp-mcp-ai-chat__attach">' + attachLabel + '</button>' +
				'<button type="submit" class="wp-mcp-ai-chat__submit">' + sendLabel + '</button>' +
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
		},

		/**
		 * Generate a unique session key for the chat instance.
		 * Uses timestamp and crypto API for better entropy.
		 *
		 * @return {string} Session key.
		 */
		generateSessionKey: function() {
			const timestamp = Date.now().toString(36);
			const random1 = Math.random().toString(36).substring(2, 15);
			const random2 = Math.random().toString(36).substring(2, 15);
			return 'prof-selector-' + timestamp + '-' + random1 + random2;
		}
	};

	// Initialize on document ready.
	$(document).ready(function() {
		if (typeof wpMcpAiProfessionalSelector !== 'undefined') {
			ProfessionalSelector.init();
		}
	});

}(jQuery));
