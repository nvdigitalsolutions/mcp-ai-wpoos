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
						ProfessionalSelector.showError($container, wpMcpAiProfessionalSelector.strings.errorLoading);
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

			// Show modal.
			$modal.show();
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

			$modal.hide();
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

			// Build shortcode attributes.
			let shortcodeAtts = 'assistant="' + state.assistant + '" profession="' + state.professional + '"';
			
			if (config.allowGuests) {
				shortcodeAtts += ' allow_guests="true"';
			}
			
			if (!config.saveTranscript) {
				shortcodeAtts += ' save_transcript="false"';
			}
			
			if (config.enableStreaming) {
				shortcodeAtts += ' enable_streaming="true"';
			}
			
			if (config.allowSensitiveTools) {
				shortcodeAtts += ' allow_sensitive_tools="true"';
			}
			
			if (config.template && config.template !== 'classic') {
				shortcodeAtts += ' template="' + config.template + '"';
			}

			// Store provider, model, and temperature overrides in window for the session
			window.wpMcpAiProfessionalOverrides = window.wpMcpAiProfessionalOverrides || {};
			window.wpMcpAiProfessionalOverrides[state.professional] = {
				provider: state.provider,
				model: state.model,
				temperature: state.temperature
			};

			// Load the chat interface HTML via AJAX to properly render the shortcode.
			$.ajax({
				url: wpMcpAiProfessionalSelector.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_render_professional_chat',
					nonce: wpMcpAiProfessionalSelector.nonce,
					professional_id: state.professional,
					provider: state.provider,
					model: state.model,
					temperature: state.temperature,
					shortcode_atts: shortcodeAtts
				},
				success: function(response) {
					if (response.success && response.data.html) {
						$chatWrapper.html(response.data.html);
						
						// Initialize the chat configuration object if the config was returned.
						if (response.data.config) {
							// Extract the instance ID from the HTML
							const $chatContainer = $chatWrapper.find('[data-wp-mcp-ai-chat]');
							if ($chatContainer.length) {
								const instanceId = $chatContainer.attr('id');
								if (instanceId) {
									// Initialize the global config object if needed
									window.wpMcpAiChatInstances = window.wpMcpAiChatInstances || {};
									// Store the configuration
									window.wpMcpAiChatInstances[instanceId] = response.data.config;
								}
							}
						}
						
						// Initialize event handlers for the dynamically inserted chat interface
						ProfessionalSelector.initializeChatInterface();
					} else {
						// Fallback: Use the shortcode directly
						const shortcode = '[mcp_ai_chat ' + shortcodeAtts + ']';
						$chatWrapper.html('<div class="wp-mcp-ai-professional-selector__chat-placeholder">' + shortcode + '</div>');
					}
				},
				error: function() {
					// Fallback: Use the shortcode directly
					const shortcode = '[mcp_ai_chat ' + shortcodeAtts + ']';
					$chatWrapper.html('<div class="wp-mcp-ai-professional-selector__chat-placeholder">' + shortcode + '</div>');
				}
			});
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
		 * to the dynamically inserted chat HTML.
		 */
		initializeChatInterface: function() {
			// Check if the chat init API is available
			if (typeof window.wpMcpAiChatInit !== 'undefined' && window.wpMcpAiChatInit.init) {
				// Call the chat initialization function
				window.wpMcpAiChatInit.init();
			} else if (window.console && console.warn) {
				console.warn('[Professional Selector] Chat initialization API not available. Chat may not function correctly.');
			}
		}
	};

	// Initialize on document ready.
	$(document).ready(function() {
		if (typeof wpMcpAiProfessionalSelector !== 'undefined') {
			ProfessionalSelector.init();
		}
	});

}(jQuery));
