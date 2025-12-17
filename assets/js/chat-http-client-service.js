/**
 * HTTP Client Service for WP oOS Chat
 * 
 * Provides a robust HTTP client with automatic retry logic using ky.
 * This replaces raw fetch calls with a more resilient implementation.
 * 
 * Features:
 * - Automatic retry with exponential backoff
 * - Better error handling
 * - Request/response hooks for logging
 * - Timeout support
 * - Progress notifications
 * 
 * NOTE: This file uses ES6 imports which are handled by esbuild during the build process.
 * ESLint is configured to allow this via the overrides section in .eslintrc.json.
 * 
 * @package WP_MCP_AI
 * @since 1.1.0
 */

import ky from 'ky';

(function(window) {
	'use strict';

	/**
	 * Default retry configuration
	 */
	const DEFAULT_RETRY_CONFIG = {
		limit: 3,
		methods: ['get', 'post', 'put', 'patch', 'delete'],
		statusCodes: [408, 413, 429, 500, 502, 503, 504],
		backoffLimit: 10000, // Max 10 seconds between retries
	};

	/**
	 * Default timeout in milliseconds (30 seconds)
	 */
	const DEFAULT_TIMEOUT = 30000;

	/**
	 * Create a configured ky instance with retry logic
	 * 
	 * @param {Object} options - Configuration options
	 * @param {number} options.timeout - Request timeout in milliseconds
	 * @param {number} options.retryLimit - Maximum number of retries
	 * @param {Function} options.onRetry - Callback function for retry events
	 * @return {Object} Configured ky instance
	 */
	function createHttpClient(options) {
		options = options || {};
		
		const retryConfig = Object.assign({}, DEFAULT_RETRY_CONFIG, {
			limit: options.retryLimit !== undefined ? options.retryLimit : DEFAULT_RETRY_CONFIG.limit,
		});

		const kyOptions = {
			retry: retryConfig,
			timeout: options.timeout || DEFAULT_TIMEOUT,
			hooks: {
				beforeRetry: []
			}
		};

		// Add retry hook if callback provided
		if (options.onRetry && typeof options.onRetry === 'function') {
			kyOptions.hooks.beforeRetry.push(function({ request, error, retryCount }) {
				options.onRetry({
					url: request.url,
					error: error,
					retryCount: retryCount,
					maxRetries: retryConfig.limit
				});
			});
		}

		return ky.create(kyOptions);
	}

	/**
	 * Perform a JSON POST request with retry logic
	 * 
	 * @param {string} url - The URL to POST to
	 * @param {Object} data - Data to send as JSON
	 * @param {Object} headers - Request headers
	 * @param {Object} options - Additional options (timeout, retryLimit, onRetry, signal)
	 * @return {Promise} Promise that resolves to the response
	 */
	function postJson(url, data, headers, options) {
		options = options || {};
		headers = headers || {};
		
		const client = createHttpClient({
			timeout: options.timeout,
			retryLimit: options.retryLimit,
			onRetry: options.onRetry
		});

		const requestOptions = {
			json: data,
			headers: headers,
			credentials: 'same-origin',
		};

		// Add AbortSignal if provided (for cancellation)
		if (options.signal) {
			requestOptions.signal = options.signal;
		}

		return client.post(url, requestOptions);
	}

	/**
	 * Perform a file upload with retry logic
	 * 
	 * @param {string} url - The URL to POST to
	 * @param {File|Blob} file - File or Blob to upload
	 * @param {Object} headers - Request headers
	 * @param {Object} options - Additional options (timeout, retryLimit, onRetry, signal)
	 * @return {Promise} Promise that resolves to the response
	 */
	function uploadFile(url, file, headers, options) {
		options = options || {};
		headers = headers || {};
		
		const client = createHttpClient({
			timeout: options.timeout,
			retryLimit: options.retryLimit,
			onRetry: options.onRetry
		});

		const requestOptions = {
			body: file,
			headers: headers,
			credentials: 'same-origin',
		};

		// Add AbortSignal if provided (for cancellation)
		if (options.signal) {
			requestOptions.signal = options.signal;
		}

		return client.post(url, requestOptions);
	}

	/**
	 * Perform a GET request with retry logic
	 * 
	 * @param {string} url - The URL to GET
	 * @param {Object} headers - Request headers
	 * @param {Object} options - Additional options (timeout, retryLimit, onRetry, signal)
	 * @return {Promise} Promise that resolves to the response
	 */
	function get(url, headers, options) {
		options = options || {};
		headers = headers || {};
		
		const client = createHttpClient({
			timeout: options.timeout,
			retryLimit: options.retryLimit,
			onRetry: options.onRetry
		});

		const requestOptions = {
			headers: headers,
			credentials: 'same-origin',
		};

		// Add AbortSignal if provided (for cancellation)
		if (options.signal) {
			requestOptions.signal = options.signal;
		}

		return client.get(url, requestOptions);
	}

	/**
	 * Perform a DELETE request with retry logic
	 * 
	 * @param {string} url - The URL to DELETE
	 * @param {Object} headers - Request headers
	 * @param {Object} options - Additional options (timeout, retryLimit, onRetry, signal)
	 * @return {Promise} Promise that resolves to the response
	 */
	function del(url, headers, options) {
		options = options || {};
		headers = headers || {};
		
		const client = createHttpClient({
			timeout: options.timeout,
			retryLimit: options.retryLimit,
			onRetry: options.onRetry
		});

		const requestOptions = {
			headers: headers,
			credentials: 'same-origin',
		};

		// Add AbortSignal if provided (for cancellation)
		if (options.signal) {
			requestOptions.signal = options.signal;
		}

		return client.delete(url, requestOptions);
	}

	/**
	 * Helper to convert ky response errors to a user-friendly format
	 * 
	 * @param {Error} error - The error from ky
	 * @return {Promise<Object>} Promise that resolves to error details
	 */
	async function parseError(error) {
		const result = {
			message: error.message || 'Unknown error',
			status: null,
			statusText: null,
			data: null
		};

		if (error.response) {
			result.status = error.response.status;
			result.statusText = error.response.statusText;
			
			try {
				result.data = await error.response.json();
			} catch {
				try {
					result.data = await error.response.text();
				} catch {
					// Ignore parsing errors
				}
			}
		}

		return result;
	}

	// Export public API
	window.wpMcpAiHttpClient = {
		createHttpClient: createHttpClient,
		postJson: postJson,
		uploadFile: uploadFile,
		get: get,
		delete: del,
		parseError: parseError,
		DEFAULT_TIMEOUT: DEFAULT_TIMEOUT,
		DEFAULT_RETRY_CONFIG: DEFAULT_RETRY_CONFIG
	};

})(window);
