/**
 * AJAX Error Service for WP oOS
 *
 * Provides centralized error handling for all AJAX requests with:
 * - Proper handling of timeout, abort, and network errors
 * - User-friendly error messages
 * - Consistent error logging
 * - Retry mechanisms
 *
 * @package WP_MCP_AI
 */

(function($, window) {
	'use strict';

	/**
	 * AJAX Error Service
	 *
	 * Handles all AJAX error scenarios consistently across the plugin.
	 */
	const AjaxErrorService = {
		/**
		 * Default timeout for AJAX requests (30 seconds).
		 */
		DEFAULT_TIMEOUT: 30000,

		/**
		 * Error type constants.
		 */
		ERROR_TYPES: {
			TIMEOUT: 'timeout',
			ABORT: 'abort',
			ERROR: 'error',
			PARSE: 'parsererror',
			NETWORK: 'network'
		},

		/**
		 * Make an AJAX request with proper error handling.
		 *
		 * @param {Object} options - jQuery AJAX options.
		 * @param {Object} handlers - Success/error handlers.
		 * @param {Function} handlers.success - Success callback.
		 * @param {Function} handlers.error - Error callback.
		 * @param {Function} handlers.complete - Complete callback (always runs).
		 * @return {jqXHR} jQuery XHR object.
		 */
		request: function(options, handlers) {
			handlers = handlers || {};

			// Set default timeout if not specified
			if (!options.timeout) {
				options.timeout = this.DEFAULT_TIMEOUT;
			}

			// Store original handlers
			const originalSuccess = handlers.success;
			const originalError = handlers.error;
			const originalComplete = handlers.complete;

			// Create the AJAX request
			const xhr = $.ajax(options);

			// Handle success
			if (originalSuccess) {
				xhr.done(function(response, textStatus, jqXHR) {
					try {
						originalSuccess.call(this, response, textStatus, jqXHR);
					} catch (e) {
						console.error('Error in success handler:', e);
					}
				});
			}

			// Handle errors with proper categorization
			xhr.fail(function(jqXHR, textStatus, errorThrown) {
				const error = AjaxErrorService.parseError(jqXHR, textStatus, errorThrown);
				
				// Always log errors to console for debugging
				AjaxErrorService.logError(error, {
					url: options.url || jqXHR.url || 'unknown',
					method: options.type || jqXHR.type || 'unknown',
					data: options.data || {}
				});
				
				if (originalError) {
					try {
						originalError.call(this, error, jqXHR);
					} catch (e) {
						console.error('Error in error handler:', e);
					}
				} else {
					// Default error handling
					AjaxErrorService.showDefaultError(error);
				}
			});

			// Handle completion
			if (originalComplete) {
				xhr.always(function() {
					try {
						originalComplete.call(this);
					} catch (e) {
						console.error('Error in complete handler:', e);
					}
				});
			}

			return xhr;
		},

		/**
		 * Parse AJAX error into a structured error object.
		 *
		 * @param {jqXHR} jqXHR - jQuery XHR object.
		 * @param {string} textStatus - Error status text.
		 * @param {string} errorThrown - Error thrown.
		 * @return {Object} Structured error object.
		 */
		parseError: function(jqXHR, textStatus, errorThrown) {
			const error = {
				type: textStatus || this.ERROR_TYPES.ERROR,
				message: '',
				details: errorThrown || '',
				statusCode: jqXHR.status || 0,
				response: null,
				userMessage: '',
				suggestions: []
			};

			// Handle different error types
			switch (error.type) {
				case this.ERROR_TYPES.TIMEOUT:
					error.message = 'Request timed out';
					error.userMessage = 'The request took too long to complete. Please try again.';
					error.suggestions = [
						'Check your internet connection',
						'Try again in a few moments',
						'Contact support if the problem persists'
					];
					break;

				case this.ERROR_TYPES.ABORT:
					error.message = 'Request was cancelled';
					error.userMessage = 'The request was cancelled.';
					error.suggestions = [];
					break;

				case this.ERROR_TYPES.PARSE:
					error.message = 'Invalid response format';
					error.userMessage = 'The server returned an invalid response.';
					error.suggestions = [
						'Try refreshing the page',
						'Check for plugin conflicts',
						'Contact support if the problem persists'
					];
					break;

				case this.ERROR_TYPES.ERROR:
				default:
					// Try to parse server response
					if (jqXHR.responseJSON && jqXHR.responseJSON.data) {
						error.response = jqXHR.responseJSON;
						error.message = jqXHR.responseJSON.data.message || 'Server error';
						error.userMessage = error.message;
						
						// Include suggestions if provided by server
						if (jqXHR.responseJSON.data.suggestions) {
							error.suggestions = jqXHR.responseJSON.data.suggestions;
						}
					} else if (jqXHR.responseText) {
						error.message = 'Server error: ' + jqXHR.responseText.substring(0, 100);
						error.userMessage = this.getStatusMessage(error.statusCode);
					} else {
						error.message = 'Unknown error occurred';
						error.userMessage = this.getStatusMessage(error.statusCode);
					}

					// Add generic suggestions based on status code
					if (error.suggestions.length === 0) {
						error.suggestions = this.getSuggestionsForStatus(error.statusCode);
					}
					break;
			}

			return error;
		},

		/**
		 * Get user-friendly message for HTTP status code.
		 *
		 * @param {number} statusCode - HTTP status code.
		 * @return {string} User-friendly message.
		 */
		getStatusMessage: function(statusCode) {
			const messages = {
				0: 'Unable to connect to the server. Please check your internet connection.',
				400: 'Invalid request. Please check your input and try again.',
				401: 'Authentication failed. Please refresh the page and try again.',
				403: 'You do not have permission to perform this action.',
				404: 'The requested resource was not found.',
				429: 'Too many requests. Please wait a moment and try again.',
				500: 'Server error occurred. Please try again later.',
				502: 'Server is temporarily unavailable. Please try again later.',
				503: 'Service unavailable. Please try again later.'
			};

			return messages[statusCode] || 'An error occurred. Please try again.';
		},

		/**
		 * Get recovery suggestions for HTTP status code.
		 *
		 * @param {number} statusCode - HTTP status code.
		 * @return {Array} Array of suggestion strings.
		 */
		getSuggestionsForStatus: function(statusCode) {
			if (statusCode === 0) {
				return [
					'Check your internet connection',
					'Ensure the WordPress site is accessible',
					'Try disabling browser extensions that may block requests'
				];
			} else if (statusCode >= 500) {
				return [
					'Try again in a few moments',
					'Check server error logs',
					'Contact your hosting provider if the problem persists'
				];
			} else if (statusCode === 401 || statusCode === 403) {
				return [
					'Refresh the page and try again',
					'Log out and log back in',
					'Clear your browser cache and cookies'
				];
			} else if (statusCode === 429) {
				return [
					'Wait a few minutes before trying again',
					'Reduce the frequency of your requests'
				];
			}

			return [
				'Try again',
				'Refresh the page',
				'Contact support if the problem persists'
			];
		},

		/**
		 * Show default error alert.
		 *
		 * @param {Object} error - Parsed error object.
		 */
		showDefaultError: function(error) {
			let message = error.userMessage;
			
			if (error.suggestions.length > 0) {
				message += '\n\nSuggestions:\n' + error.suggestions.join('\n');
			}

			alert(message);
		},

		/**
		 * Log error to console (if debugging is enabled).
		 *
		 * @param {Object} error - Parsed error object.
		 * @param {Object} context - Additional context.
		 */
		logError: function(error, context) {
			if (window.console && console.error) {
				console.error('[WP oOS AJAX Error]', {
					error: error,
					context: context,
					timestamp: new Date().toISOString()
				});
			}
		},

		/**
		 * Create a wrapped error handler for consistent error handling.
		 *
		 * @param {Function} callback - Error callback function.
		 * @param {Object} options - Options for error handling.
		 * @param {boolean} options.showAlert - Whether to show default alert on error.
		 * @param {boolean} options.logError - Whether to log error to console.
		 * @return {Function} Wrapped error handler.
		 */
		createErrorHandler: function(callback, options) {
			options = $.extend({
				showAlert: false,
				logError: true
			}, options || {});

			return function(error, jqXHR) {
				// Log if requested
				if (options.logError) {
					AjaxErrorService.logError(error, {
						url: jqXHR.url || 'unknown',
						method: jqXHR.type || 'unknown'
					});
				}

				// Show alert if requested and no custom callback
				if (options.showAlert && !callback) {
					AjaxErrorService.showDefaultError(error);
				}

				// Call custom callback if provided
				if (callback && typeof callback === 'function') {
					callback(error, jqXHR);
				}
			};
		}
	};

	// Expose to global scope
	window.wpMcpAiAjaxErrorService = AjaxErrorService;

	// Also expose as jQuery plugin
	$.wpMcpAiAjax = function(options, handlers) {
		return AjaxErrorService.request(options, handlers);
	};

})(jQuery, window);
