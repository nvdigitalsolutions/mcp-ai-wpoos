/**
 * Orchestration Models View JavaScript
 * 
 * Handles inline editing of model constraints (TPM limits, context window, max output tokens)
 * Follows SoC principles: UI interactions separated from AJAX calls and data management
 * 
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Models View Manager
	 */
	const ModelsView = {
		/**
		 * Initialize the models view
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind event handlers
		 */
		bindEvents: function() {
			// Sync models button
			$(document).on('click', '#sync-models-btn', this.syncModels.bind(this));

			// Make constraint fields editable on click
			$(document).on('click', '.editable-field:not(.editing)', this.startEditing.bind(this));
			
			// Save changes
			$(document).on('click', '.edit-save', this.saveChanges.bind(this));
			
			// Cancel editing
			$(document).on('click', '.edit-cancel', this.cancelEditing.bind(this));
			
			// Save on Enter key
			$(document).on('keypress', '.editable-field input', function(e) {
				if (e.which === 13) {
					e.preventDefault();
					$(this).closest('.editable-field').find('.edit-save').click();
				}
			});
			
			// Cancel on Escape key
			$(document).on('keyup', '.editable-field input', function(e) {
				if (e.which === 27) {
					e.preventDefault();
					$(this).closest('.editable-field').find('.edit-cancel').click();
				}
			});
		},

		/**
		 * Start editing a field
		 */
		startEditing: function(e) {
			const $field = $(e.currentTarget);
			const currentValue = $field.data('value');
			const fieldType = $field.data('field');
			const fieldLabel = $field.data('label') || fieldType;
			
			// Store original value
			$field.data('original-value', currentValue);
			
			// Create input based on field type
			const $input = this.createInput(fieldType, currentValue);
			
			// Replace content with input
			$field.addClass('editing');
			$field.find('.field-display').hide();
			
			// Add input if not already present
			if ($field.find('input').length === 0) {
				$field.append($input);
				$field.append(this.createActionButtons());
			}
			
			// Focus the input
			$input.focus().select();
		},

		/**
		 * Create input element based on field type
		 */
		createInput: function(fieldType, currentValue) {
			let inputType = 'number';
			let min = 0;
			let step = 1;
			
			switch (fieldType) {
				case 'tpm_limit':
				case 'context_window':
					step = 1000;
					break;
				case 'rpm_limit':
					step = 1;
					break;
				case 'max_output_tokens':
					step = 100;
					break;
				case 'cost_per_1k_input_tokens':
				case 'cost_per_1k_output_tokens':
					step = 0.0001;
					min = 0;
					break;
			}
			
			return $('<input>')
				.attr('type', inputType)
				.attr('min', min)
				.attr('step', step)
				.val(currentValue || '');
		},

		/**
		 * Create action buttons for editing
		 */
		createActionButtons: function() {
			return $('<div class="edit-actions">')
				.append(
					$('<button>')
						.addClass('button button-primary button-small edit-save')
						.html('<span class="dashicons dashicons-yes"></span> Save')
				)
				.append(
					$('<button>')
						.addClass('button button-secondary button-small edit-cancel')
						.html('<span class="dashicons dashicons-no-alt"></span> Cancel')
				);
		},

		/**
		 * Save changes to a field
		 */
		saveChanges: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			const $field = $button.closest('.editable-field');
			const $row = $field.closest('tr');
			const $input = $field.find('input');
			
			const newValue = $input.val();
			const fieldType = $field.data('field');
			const modelId = $row.data('model-id');
			const modelName = $row.data('model-name');
			
			// Validate input
			if (newValue === '' || (fieldType.includes('cost') && isNaN(parseFloat(newValue)))) {
				alert('Please enter a valid value');
				return;
			}
			
			// Show loading state
			$row.addClass('updating');
			$button.prop('disabled', true);
			
			// Send AJAX request
			this.updateModelField(modelId, modelName, fieldType, newValue)
				.done((response) => {
					if (response.success) {
						// Update the field display
						$field.data('value', newValue);
						this.updateFieldDisplay($field, fieldType, newValue);
						this.cancelEditing({ currentTarget: $button });
						
						// Show success message
						this.showNotice('success', 'Model constraint updated successfully');
					} else {
						this.showNotice('error', response.data || 'Failed to update model constraint');
					}
				})
				.fail((xhr) => {
					this.showNotice('error', 'Failed to update model constraint. Please try again.');
				})
				.always(() => {
					$row.removeClass('updating');
					$button.prop('disabled', false);
				});
		},

		/**
		 * Cancel editing a field
		 */
		cancelEditing: function(e) {
			const $button = $(e.currentTarget);
			const $field = $button.closest('.editable-field');
			
			// Remove input and buttons
			$field.find('input').remove();
			$field.find('.edit-actions').remove();
			
			// Show original display
			$field.removeClass('editing');
			$field.find('.field-display').show();
		},

		/**
		 * Update field display after save
		 */
		updateFieldDisplay: function($field, fieldType, newValue) {
			const $display = $field.find('.field-display');
			
			switch (fieldType) {
				case 'tpm_limit':
				case 'rpm_limit':
				case 'context_window':
				case 'max_output_tokens':
					$display.find('.limit-value, .context-info strong, .max-output').text(
						this.formatNumber(newValue)
					);
					break;
				case 'cost_per_1k_input_tokens':
				case 'cost_per_1k_output_tokens':
					$display.find('.cost-value').text('$' + parseFloat(newValue).toFixed(4));
					break;
			}
		},

		/**
		 * Format number with thousand separators
		 */
		formatNumber: function(num) {
			return parseInt(num).toLocaleString();
		},

		/**
		 * Update model field via AJAX
		 */
		updateModelField: function(modelId, modelName, fieldType, newValue) {
			return $.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_update_model_constraint',
					nonce: wpMcpAiModels.nonce,
					model_id: modelId,
					model_name: modelName,
					field: fieldType,
					value: newValue
				}
			});
		},

		/**
		 * Show admin notice
		 */
		showNotice: function(type, message) {
			const $notice = $('<div>')
				.addClass('notice notice-' + type + ' is-dismissible')
				.append($('<p>').text(message));
			
			$('.wp-mcp-ai-models-view').prepend($notice);
			
			// Auto-dismiss after 3 seconds
			setTimeout(function() {
				$notice.fadeOut(function() {
					$(this).remove();
				});
			}, 3000);
		},

		/**
		 * Sync models from defaults
		 */
		syncModels: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			
			if (!confirm('This will update existing models with latest pricing/limits and add new models. Custom modifications will be preserved. Continue?')) {
				return;
			}
			
			// Disable button and show loading
			$button.prop('disabled', true);
			$button.html('<span class="dashicons dashicons-update spin"></span> Syncing...');
			
			// Send AJAX request
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_sync_models',
					nonce: wpMcpAiModels.nonce
				}
			})
			.done((response) => {
				if (response.success) {
					this.showNotice('success', response.data.message || 'Models synced successfully');
					// Reload page after 2 seconds to show updated models
					setTimeout(function() {
						window.location.reload();
					}, 2000);
				} else {
					this.showNotice('error', response.data || 'Failed to sync models');
					$button.prop('disabled', false);
					$button.html('<span class="dashicons dashicons-update"></span> Sync Models from Defaults');
				}
			})
			.fail(() => {
				this.showNotice('error', 'Failed to sync models. Please try again.');
				$button.prop('disabled', false);
				$button.html('<span class="dashicons dashicons-update"></span> Sync Models from Defaults');
			});
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
		if ($('.wp-mcp-ai-models-view').length) {
			ModelsView.init();
		}
	});

})(jQuery);
