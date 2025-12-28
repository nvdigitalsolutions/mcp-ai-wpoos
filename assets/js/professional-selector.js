/**
 * Professional Selector Frontend JavaScript
 *
 * Handles the professional selection UI and dynamic model loading.
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
			const $chatContainer = $container.find('[data-chat-container]');
			const $providerSelect = $container.find('[data-provider-select]');
			const $modelSelect = $container.find('[data-model-select]');
			const $modelLoading = $container.find('[data-model-loading]');
			const $professionalSelect = $container.find('[data-professional-select]');
			const $errorMessage = $container.find('[data-error-message]');
			const $startButton = $container.find('[data-start-button]');
			const $changeButton = $container.find('[data-change-button]');
			const $configDisplay = $container.find('[data-config-display]');
			const $temperatureInput = $container.find('[data-temperature-input]');

			// Store instance data.
			$container.data('selector-config', config);
			$container.data('selector-state', {
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

			// Handle change button.
			$changeButton.on('click', function() {
				ProfessionalSelector.showSelectionForm($container);
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
			const $professionalSelect = $container.find('[data-professional-select]');
			const $providerSelect = $container.find('[data-provider-select]');
			const $modelSelect = $container.find('[data-model-select]');
			const $temperatureInput = $container.find('[data-temperature-input]');

			const professional = $professionalSelect.val();
			const provider = $providerSelect.val();
			const model = $modelSelect.val();
			const temperature = $temperatureInput.length ? $temperatureInput.val() : null;

			// Validate required fields.
			if (!professional || !provider || !model) {
				ProfessionalSelector.showError($container, wpMcpAiProfessionalSelector.strings.selectRequired);
				return;
			}

			// Store selection state.
			const state = {
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

			// Create and render chat interface.
			ProfessionalSelector.createChatInterface($container, state);
		},

		/**
		 * Create the chat interface.
		 *
		 * @param {jQuery} $container Container element.
		 * @param {Object} state      Selection state.
		 */
		createChatInterface: function($container, state) {
			const config = $container.data('selector-config');
			const $chatWrapper = $container.find('[data-chat-wrapper]');
			const $configDisplay = $container.find('[data-config-display]');

			// Build configuration display text.
			let configText = state.professionalName + ' • ' + state.providerName + ' • ' + state.model;
			if (state.temperature) {
				configText += ' • Temperature: ' + state.temperature;
			}
			$configDisplay.text(configText);

			// Build shortcode attributes.
			let shortcodeAtts = 'assistant="profession_' + state.professional + '"';
			
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

			// Note: We use profession_XXX format which is already supported by the existing shortcode.
			// The provider, model, and temperature will be passed via the profession's metadata.
			
			// We need to temporarily update the profession's metadata for this session.
			// This is done via a filter hook in the REST API handler.
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
						ProfessionalSelector.showChatContainer($container);
						// Initialize the dynamically inserted chat interface
						ProfessionalSelector.initializeChatInterface();
					} else {
						// Fallback: Use the shortcode directly (may not work perfectly).
						const shortcode = '[mcp_ai_chat ' + shortcodeAtts + ']';
						$chatWrapper.html('<div class="wp-mcp-ai-professional-selector__chat-placeholder">' + shortcode + '</div>');
						ProfessionalSelector.showChatContainer($container);
					}
				},
				error: function() {
					// Fallback: Use the shortcode directly.
					const shortcode = '[mcp_ai_chat ' + shortcodeAtts + ']';
					$chatWrapper.html('<div class="wp-mcp-ai-professional-selector__chat-placeholder">' + shortcode + '</div>');
					ProfessionalSelector.showChatContainer($container);
				}
			});
		},

		/**
		 * Show the chat container and hide the selection form.
		 *
		 * @param {jQuery} $container Container element.
		 */
		showChatContainer: function($container) {
			$container.find('[data-selector-form]').attr('hidden', '');
			$container.find('[data-chat-container]').removeAttr('hidden');
		},

		/**
		 * Show the selection form and hide the chat container.
		 *
		 * @param {jQuery} $container Container element.
		 */
		showSelectionForm: function($container) {
			$container.find('[data-chat-container]').attr('hidden', '');
			$container.find('[data-selector-form]').removeAttr('hidden');
			$container.find('[data-chat-wrapper]').empty();
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
