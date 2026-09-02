/**
 * HTTP Client Service for NV oOS Chat
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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * In-flight nonce refresh promise (deduplicates concurrent refreshes).
	 */
	let nonceRefreshInFlight = null;

	/**
	 * Derive the session-nonce endpoint from the localised REST base URL.
	 *
	 * @return {string} Endpoint URL, or '' when the REST URL is unavailable.
	 */
	function getSessionNonceEndpoint() {
		const restUrl = (window.wpMcpAiChat && window.wpMcpAiChat.restUrl) || '';
		if (!restUrl) {
			return '';
		}
		return restUrl.replace(/\/$/, '') + '/session/nonce';
	}

	/**
	 * Determine whether an error is WordPress's rest_cookie_invalid_nonce
	 * rejection (HTTP 403, "Cookie check failed").
	 *
	 * This is the signature of a request that carried a valid auth cookie but
	 * a nonce that no longer verifies for the current user/session — typically
	 * because the page HTML (and its embedded nonce) was served from a
	 * full-page cache or the session token rotated while a long-lived SPA tab
	 * stayed open.
	 *
	 * @param {Error} error Thrown ky HTTP error.
	 * @return {Promise<boolean>} True when the failure is a stale nonce.
	 */
	async function isStaleNonceError(error) {
		if (!error || !error.response || error.response.status !== 403) {
			return false;
		}

		try {
			const data = await error.response.clone().json();
			return !!(data && data.code === 'rest_cookie_invalid_nonce');
		} catch (parseError) {
			return false;
		}
	}

	/**
	 * Fetch and store a fresh wp_rest nonce for the current session.
	 *
	 * The request is intentionally sent WITHOUT the X-WP-Nonce header so
	 * WordPress treats it as an unauthenticated probe instead of rejecting it
	 * with the very rest_cookie_invalid_nonce error we are recovering from.
	 * The nonce is still minted from the auth cookie carried by the request
	 * (wp_get_session_token), so it verifies for the logged-in user on
	 * subsequent requests.
	 *
	 * @return {Promise<string>} Fresh nonce ('' when the refresh fails).
	 */
	async function fetchFreshNonce() {
		const endpoint = getSessionNonceEndpoint();
		if (!endpoint) {
			throw new Error('Session nonce endpoint unavailable.');
		}

		const response = await fetch(endpoint, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { Accept: 'application/json' }
		});

		if (!response.ok) {
			throw new Error('Session nonce endpoint returned HTTP ' + response.status);
		}

		const data = await response.json();
		const nonce = (data && typeof data.nonce === 'string') ? data.nonce : '';
		if (!nonce) {
			throw new Error('Session nonce endpoint returned no nonce.');
		}

		// Update the global config so header builders that fall back to
		// globalConfig.nonce (and the nonceRefreshed flag consumed by
		// chat.js resolveNonce) pick up the fresh value.
		if (window.wpMcpAiChat) {
			window.wpMcpAiChat.nonce = nonce;
			window.wpMcpAiChat.nonceRefreshed = true;
		}

		// Keep registered instance configs current for any chat re-initialised
		// after the refresh (live instances consult the nonceRefreshed flag).
		const instances = window.wpMcpAiChatInstances || {};
		Object.keys(instances).forEach(function (id) {
			if (instances[id] && typeof instances[id] === 'object') {
				instances[id].restNonce = nonce;
			}
		});

		return nonce;
	}

	/**
	 * Refresh the REST nonce, deduplicating concurrent refresh attempts.
	 *
	 * @return {Promise<string>} Fresh nonce ('' when the refresh fails).
	 */
	function refreshRestNonce() {
		if (nonceRefreshInFlight) {
			return nonceRefreshInFlight;
		}

		nonceRefreshInFlight = fetchFreshNonce().then(
			function (nonce) {
				nonceRefreshInFlight = null;
				return nonce;
			},
			function (error) {
				nonceRefreshInFlight = null;
				if (window.console && console.warn) {
					console.warn('NV oOS: Failed to refresh REST nonce:', error);
				}
				return '';
			}
		);

		return nonceRefreshInFlight;
	}

	/**
	 * Replace a stale _wpnonce query parameter with a fresh nonce.
	 *
	 * Only touches URLs that already carry a _wpnonce parameter so endpoints
	 * that rely on header-based authentication are left unchanged.
	 *
	 * @param {string} url   Request URL.
	 * @param {string} nonce Fresh nonce.
	 * @return {string} URL with the updated _wpnonce parameter.
	 */
	function applyNonceToUrl(url, nonce) {
		if (!url || !nonce || url.indexOf('_wpnonce=') === -1) {
			return url;
		}

		try {
			const parsed = new URL(url, window.location.origin);
			parsed.searchParams.set('_wpnonce', nonce);
			return parsed.toString();
		} catch (parseError) {
			return url;
		}
	}

	/**
	 * Run a request, transparently retrying once with a freshly minted nonce
	 * when WordPress rejects it with rest_cookie_invalid_nonce (403).
	 *
	 * @param {Function} requestFactory Factory producing the request promise.
	 * @param {Function} onRefreshed     Optional callback invoked with the fresh nonce before the retry (used to patch headers/URL).
	 * @return {Promise} Response of the first or retried attempt.
	 */
	async function withNonceHeal(requestFactory, onRefreshed) {
		try {
			return await requestFactory();
		} catch (error) {
			if (!(await isStaleNonceError(error))) {
				throw error;
			}

			const nonce = await refreshRestNonce();
			if (!nonce) {
				throw error;
			}

			if (onRefreshed) {
				onRefreshed(nonce);
			}

			return requestFactory();
		}
	}

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
				beforeRetry: [],
				beforeRequest: [],
				afterResponse: []
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

		// Add beforeRequest hooks for request instrumentation
		if (options.beforeRequest && typeof options.beforeRequest === 'function') {
			kyOptions.hooks.beforeRequest.push(function(request) {
				options.beforeRequest(request);
			});
		}

		// Add afterResponse hook for global response handling (e.g., auth failure detection)
		kyOptions.hooks.afterResponse.push(function(request, responseOptions, response) {
			// Handle 401 Unauthorized globally - notify consumers to refresh auth
			if (response.status === 401 && options.onAuthFailure && typeof options.onAuthFailure === 'function') {
				options.onAuthFailure({
					url: request.url,
					status: response.status,
					statusText: response.statusText
				});
			}
			return response;
		});

		// Add custom afterResponse hook if provided
		if (options.afterResponse && typeof options.afterResponse === 'function') {
			kyOptions.hooks.afterResponse.push(function(request, responseOptions, response) {
				options.afterResponse(request, response);
				return response;
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

		let targetUrl = url;
		const requester = function () {
			return client.post(targetUrl, requestOptions);
		};

		return withNonceHeal(requester, function (nonce) {
			headers['X-WP-Nonce'] = nonce;
			targetUrl = applyNonceToUrl(url, nonce);
		});
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

		let targetUrl = url;
		const requester = function () {
			return client.post(targetUrl, requestOptions);
		};

		return withNonceHeal(requester, function (nonce) {
			headers['X-WP-Nonce'] = nonce;
			targetUrl = applyNonceToUrl(url, nonce);
		});
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

		let targetUrl = url;
		const requester = function () {
			return client.get(targetUrl, requestOptions);
		};

		return withNonceHeal(requester, function (nonce) {
			headers['X-WP-Nonce'] = nonce;
			targetUrl = applyNonceToUrl(url, nonce);
		});
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

		let targetUrl = url;
		const requester = function () {
			return client.delete(targetUrl, requestOptions);
		};

		return withNonceHeal(requester, function (nonce) {
			headers['X-WP-Nonce'] = nonce;
			targetUrl = applyNonceToUrl(url, nonce);
		});
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
		refreshRestNonce: refreshRestNonce,
		getSessionNonceEndpoint: getSessionNonceEndpoint,
		DEFAULT_TIMEOUT: DEFAULT_TIMEOUT,
		DEFAULT_RETRY_CONFIG: DEFAULT_RETRY_CONFIG
	};

})(window);
