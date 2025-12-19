/**
 * Admin Model Selector
 *
 * Handles conditional model dropdown based on provider selection.
 * Works for multiple instances on the same page (Assistant, Profession, Team).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

( function( $ ) {
	'use strict';

	/**
	 * Model Selector Controller
	 */
	const ModelSelector = {
		/**
		 * Initialize model selector functionality.
		 */
		init: function() {
			// Find all provider selects that have a corresponding model field.
			$( '.wp-mcp-ai-provider-select' ).each( function() {
				const $providerSelect = $( this );
				const targetSelector = $providerSelect.data( 'model-target' );
				const $modelField = $( targetSelector );

				if ( $modelField.length ) {
					// Initialize the model field for this provider.
					ModelSelector.initModelField( $providerSelect, $modelField );

					// Bind change event.
					$providerSelect.on( 'change', function() {
						ModelSelector.handleProviderChange( $providerSelect, $modelField );
					} );

					// Load initial models for the selected provider (convert text input to dropdown).
					const initialProvider = $providerSelect.val();
					if ( initialProvider ) {
						ModelSelector.loadModels( initialProvider, $modelField );
					}
				}
			} );
		},

		/**
		 * Initialize model field for a provider select.
		 *
		 * @param {jQuery} $providerSelect Provider select element.
		 * @param {jQuery} $modelField     Model field element.
		 */
		initModelField: function( $providerSelect, $modelField ) {
			// If model field is still a text input, we're good - it will be replaced on first load.
			// If it's already a select, it's been previously converted.
			console.log( 'WP MCP AI: Initialized model selector for provider field:', $providerSelect.attr( 'id' ) );
		},

		/**
		 * Handle provider change event.
		 *
		 * @param {jQuery} $providerSelect Provider select element.
		 * @param {jQuery} $modelField     Model field element.
		 */
		handleProviderChange: function( $providerSelect, $modelField ) {
			const provider = $providerSelect.val();

			if ( ! provider ) {
				// If no provider selected, show text input.
				ModelSelector.convertToTextInput( $modelField );
				return;
			}

			// Load models for the selected provider.
			ModelSelector.loadModels( provider, $modelField );
		},

		/**
		 * Load models for a provider via AJAX.
		 *
		 * @param {string} provider    Provider name.
		 * @param {jQuery} $modelField Model field element.
		 */
		loadModels: function( provider, $modelField ) {
			if ( ! provider ) {
				return;
			}

			// Store current value before replacing field.
			const currentValue = $modelField.val();
			const fieldId = $modelField.attr( 'id' );
			const fieldName = $modelField.attr( 'name' );
			const fieldClasses = $modelField.attr( 'class' );

			// Show loading state.
			ModelSelector.showLoadingState( $modelField );

			// Make AJAX request.
			$.ajax( {
				url: wpMcpAiModelSelector.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_models_for_provider',
					nonce: wpMcpAiModelSelector.nonce,
					provider: provider
				},
				success: function( response ) {
					if ( response.success && response.data.models ) {
						// Convert to select dropdown with models.
						ModelSelector.convertToSelect( $modelField, response.data.models, currentValue, fieldId, fieldName, fieldClasses );
					} else {
						// Show error and keep as text input.
						const errorMsg = response.data && response.data.message ? response.data.message : wpMcpAiModelSelector.errorMessage;
						ModelSelector.showError( $modelField, errorMsg );
						ModelSelector.convertToTextInput( $modelField, currentValue, fieldId, fieldName, fieldClasses );
					}
				},
				error: function() {
					// Show error and keep as text input.
					ModelSelector.showError( $modelField, wpMcpAiModelSelector.errorMessage );
					ModelSelector.convertToTextInput( $modelField, currentValue, fieldId, fieldName, fieldClasses );
				}
			} );
		},

		/**
		 * Convert model field to select dropdown.
		 *
		 * @param {jQuery} $modelField   Model field element.
		 * @param {Object} models        Models object (id => name).
		 * @param {string} currentValue  Current value to preserve.
		 * @param {string} fieldId       Field ID.
		 * @param {string} fieldName     Field name.
		 * @param {string} fieldClasses  Field classes.
		 */
		convertToSelect: function( $modelField, models, currentValue, fieldId, fieldName, fieldClasses ) {
			const $container = $modelField.parent();

			// Create new select element.
			const $select = $( '<select></select>' )
				.attr( 'id', fieldId )
				.attr( 'name', fieldName )
				.attr( 'class', fieldClasses );

			// Add empty option.
			$select.append( $( '<option></option>' ).val( '' ).text( wpMcpAiModelSelector.selectModelText ) );

			// Add model options.
			$.each( models, function( modelId, modelName ) {
				const $option = $( '<option></option>' )
					.val( modelId )
					.text( modelName );

				if ( modelId === currentValue ) {
					$option.prop( 'selected', true );
				}

				$select.append( $option );
			} );

			// If current value is not in the list, add it as a custom option.
			if ( currentValue && ! models.hasOwnProperty( currentValue ) ) {
				const $customOption = $( '<option></option>' )
					.val( currentValue )
					.text( currentValue + ' (custom)' )
					.prop( 'selected', true );
				$select.append( $customOption );
			}

			// Replace field.
			$modelField.replaceWith( $select );

			// Remove any error messages.
			$container.find( '.wp-mcp-ai-model-error' ).remove();
		},

		/**
		 * Convert model field to text input.
		 *
		 * @param {jQuery} $modelField   Model field element.
		 * @param {string} currentValue  Current value to preserve.
		 * @param {string} fieldId       Field ID.
		 * @param {string} fieldName     Field name.
		 * @param {string} fieldClasses  Field classes.
		 */
		convertToTextInput: function( $modelField, currentValue, fieldId, fieldName, fieldClasses ) {
			// If already a text input, just update value.
			if ( $modelField.is( 'input[type="text"]' ) ) {
				if ( currentValue !== undefined ) {
					$modelField.val( currentValue );
				}
				return;
			}

			// Get current attributes if not provided.
			currentValue = currentValue !== undefined ? currentValue : $modelField.val();
			fieldId = fieldId || $modelField.attr( 'id' );
			fieldName = fieldName || $modelField.attr( 'name' );
			fieldClasses = fieldClasses || $modelField.attr( 'class' );

			const $container = $modelField.parent();

			// Create new text input.
			const $input = $( '<input type="text" />' )
				.attr( 'id', fieldId )
				.attr( 'name', fieldName )
				.attr( 'class', fieldClasses )
				.val( currentValue );

			// Replace field.
			$modelField.replaceWith( $input );

			// Remove any error messages.
			$container.find( '.wp-mcp-ai-model-error' ).remove();
		},

		/**
		 * Show loading state for model field.
		 *
		 * @param {jQuery} $modelField Model field element.
		 */
		showLoadingState: function( $modelField ) {
			$modelField.prop( 'disabled', true );
			$modelField.parent().find( '.wp-mcp-ai-model-error' ).remove();

			// Add loading indicator if not exists.
			if ( ! $modelField.parent().find( '.wp-mcp-ai-model-loading' ).length ) {
				$modelField.after( '<span class="wp-mcp-ai-model-loading spinner is-active" style="float: none; margin: 0 5px;"></span>' );
			}
		},

		/**
		 * Show error message.
		 *
		 * @param {jQuery} $modelField Model field element.
		 * @param {string} message     Error message.
		 */
		showError: function( $modelField, message ) {
			// Remove loading indicator.
			$modelField.parent().find( '.wp-mcp-ai-model-loading' ).remove();
			$modelField.prop( 'disabled', false );

			// Remove existing error.
			$modelField.parent().find( '.wp-mcp-ai-model-error' ).remove();

			// Add error message.
			$modelField.after( '<p class="wp-mcp-ai-model-error description" style="color: #dc3232; margin-top: 5px;">' + message + '</p>' );
		}
	};

	// Initialize on document ready.
	$( document ).ready( function() {
		if ( typeof wpMcpAiModelSelector !== 'undefined' ) {
			ModelSelector.init();
		}
	} );

}( jQuery ) );
