/**
 * Server-Sent Events (SSE) Service
 *
 * Enhanced SSE service using @microsoft/fetch-event-source for improved reliability.
 * Provides support for POST requests, custom headers, and automatic reconnection.
 * Maintains backward compatibility with the original EventSource-based API.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

import { fetchEventSource } from '@microsoft/fetch-event-source';

(function (window) {
	'use strict';

	// Prevent multiple initialization
	if (window.wpMcpAiSSE) {
		return;
	}

	/**
	 * EventSource ready state constants (for backward compatibility)
	 * These mirror the EventSource.readyState values for clarity
	 */
	const READY_STATE = {
		CONNECTING: 0,
		OPEN: 1,
		CLOSED: 2
	};

	/**
	 * Human-readable ready state names
	 */
	const READY_STATE_NAMES = {
		0: 'CONNECTING',
		1: 'OPEN',
		2: 'CLOSED'
	};

	/**
	 * SSE Service
	 * 
	 * Provides methods to create and manage Server-Sent Events connections
	 * using @microsoft/fetch-event-source for improved capabilities.
	 */
	const SSEService = {
		/**
		 * Active connections by key
		 */
		connections: {},

		/**
		 * Check if SSE is supported by the browser
		 * Now checks for fetch and AbortController instead of EventSource
		 * 
		 * @return {boolean} True if required APIs are available
		 */
		isSupported: function () {
			return typeof fetch !== 'undefined' && typeof AbortController !== 'undefined';
		},

		/**
		 * Get human-readable description for EventSource ready state (for backward compatibility)
		 * 
		 * @param {number} readyState - EventSource ready state value
		 * @return {string} Human-readable state description
		 */
		getReadyStateName: function (readyState) {
			return READY_STATE_NAMES[readyState] || 'UNKNOWN';
		},

		/**
		 * Create an enhanced SSE connection using @microsoft/fetch-event-source
		 * 
		 * @param {string} url - SSE endpoint URL
		 * @param {Object} options - Configuration options
		 * @param {string} [options.method='GET'] - HTTP method (GET or POST)
		 * @param {Object} [options.headers] - Custom request headers
		 * @param {string|Object} [options.body] - Request body (for POST requests)
		 * @param {Function} [options.onMessage] - Callback for message events
		 * @param {Function} [options.onError] - Callback for error events
		 * @param {Function} [options.onOpen] - Optional callback for open event
		 * @param {Object} [options.eventHandlers] - Map of custom event names to handlers
		 * @param {boolean} [options.openWhenHidden=false] - Keep connection open when page is hidden
		 * @return {Object} Connection object with abort controller and close method
		 */
		connect: function (url, options) {
			if (!this.isSupported()) {
				if (options.onError) {
					options.onError(new Error('fetch or AbortController not supported'));
				}
				return null;
			}

			if (!url || typeof url !== 'string') {
				if (options.onError) {
					options.onError(new Error('Invalid URL'));
				}
				return null;
			}

			options = options || {};

			try {
				const ctrl = new AbortController();
				const connectionKey = this.generateConnectionKey(url);
				const self = this;

				// Build fetch options
				const fetchOptions = {
					method: options.method || 'GET',
					headers: options.headers || {},
					signal: ctrl.signal,
					openWhenHidden: options.openWhenHidden !== undefined ? options.openWhenHidden : false,

					/**
					 * Called when the connection is established
					 * Validates response before processing events
					 */
					async onopen(response) {
						// Check for successful response
						if (response.ok && response.headers.get('content-type')?.includes('text/event-stream')) {
							// Connection successful
							if (options.onOpen && typeof options.onOpen === 'function') {
								options.onOpen(response);
							}
							return; // Everything is good
						} else if (response.status >= 400 && response.status < 500 && response.status !== 429) {
							// Client-side errors (4xx) are usually non-retriable
							if (window.wpMcpAiDebug && window.console && console.error) {
								console.error('[WP oOS SSE] Client error (' + response.status + ')');
							}
							// Throw to stop reconnection
							const errorText = await response.text();
							throw new Error('Client error (' + response.status + '): ' + errorText);
						} else {
							// Server errors (5xx) or network issues are retriable
							if (window.wpMcpAiDebug && window.console && console.error) {
								console.error('[WP oOS SSE] Server error (' + response.status + ')');
							}
							throw new Error('Server error (' + response.status + ')');
						}
					},

					/**
					 * Called when a message is received
					 * Handles both generic messages and custom event types
					 */
					onmessage: (event) => {
						try {
							// Check for [DONE] marker
							if (event.data === '[DONE]') {
								return;
							}

							// Parse JSON data if possible
							let data = event.data;
							try {
								data = JSON.parse(event.data);
							} catch (e) {
								// Not JSON, use raw string
							}

							// Handle custom event types
							if (event.event && options.eventHandlers && options.eventHandlers[event.event]) {
								const handler = options.eventHandlers[event.event];
								if (typeof handler === 'function') {
									handler(data, event);
								}
							}

							// Handle generic message events
							if (options.onMessage && typeof options.onMessage === 'function') {
								options.onMessage(data, event);
							}
						} catch (parseError) {
							if (window.console && console.error) {
								console.error('[WP oOS SSE] Failed to parse message:', parseError);
							}
						}
					},

					/**
					 * Called when the connection is closed by the server
					 * Can throw an error to trigger reconnection
					 */
					onclose: () => {
						// Connection closed normally
						if (window.wpMcpAiDebug && window.console && console.log) {
							console.log('[WP oOS SSE] Connection closed by server');
						}
					},

					/**
					 * Called when an error occurs
					 * Can throw to stop reconnection, or return retry interval
					 */
					onerror: (err) => {
						if (window.wpMcpAiDebug && window.console && console.error) {
							console.error('[WP oOS SSE] Connection error:', err);
						}

						// Call user's error handler if provided
						if (options.onError && typeof options.onError === 'function') {
							options.onError(err);
						}

						// Throw error to stop reconnection on fatal errors
						// For retriable errors, don't throw (library will auto-retry)
						if (err.message && err.message.includes('Client error')) {
							throw err; // Stop reconnecting on client errors
						}
						// For other errors, allow automatic retry
					}
				};

				// Add request body if provided (only for POST/PUT methods)
				if (options.body && (options.method === 'POST' || options.method === 'PUT')) {
					fetchOptions.body = typeof options.body === 'string' 
						? options.body 
						: JSON.stringify(options.body);
				}

				// Start the connection
				// fetchEventSource returns a promise that resolves when the connection ends
				const connectionPromise = fetchEventSource(url, fetchOptions);

				// Store connection reference
				this.connections[connectionKey] = {
					ctrl: ctrl,
					url: url,
					createdAt: Date.now(),
					promise: connectionPromise
				};

				// Return connection object with close method (backward compatible API)
				return {
					ctrl: ctrl,
					close: function () {
						self.closeConnection(connectionKey);
					},
					// Expose abort method for advanced use cases
					abort: function () {
						ctrl.abort();
					}
				};

			} catch (error) {
				if (window.console && console.error) {
					console.error('[WP oOS SSE] Failed to create connection:', error);
				}

				if (options.onError && typeof options.onError === 'function') {
					options.onError(error);
				}

				return null;
			}
		},

		/**
		 * Close a specific connection
		 * 
		 * @param {string} key - Connection key
		 */
		closeConnection: function (key) {
			if (this.connections[key]) {
				const connection = this.connections[key];
				if (connection.ctrl) {
					connection.ctrl.abort();
				}
				// Backward compatibility: also handle old eventSource connections
				if (connection.eventSource) {
					connection.eventSource.close();
				}
				delete this.connections[key];
			}
		},

		/**
		 * Close all active connections
		 */
		closeAll: function () {
			const keys = Object.keys(this.connections);
			const self = this;
			keys.forEach(function (key) {
				self.closeConnection(key);
			});
		},

		/**
		 * Generate a unique key for a connection
		 * 
		 * @param {string} url - Connection URL
		 * @return {string} Connection key
		 */
		generateConnectionKey: function (url) {
			return 'sse_fetch_' + url.replace(/[^a-zA-Z0-9]/g, '_') + '_' + Date.now();
		},

		/**
		 * Get connection count
		 * 
		 * @return {number} Number of active connections
		 */
		getConnectionCount: function () {
			return Object.keys(this.connections).length;
		}
	};

	// Export to global scope
	window.wpMcpAiSSE = SSEService;

	// Clean up connections when page unloads
	window.addEventListener('beforeunload', function () {
		SSEService.closeAll();
	});

})(window);
