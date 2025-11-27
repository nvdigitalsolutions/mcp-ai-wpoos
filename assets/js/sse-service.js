/**
 * Server-Sent Events (SSE) Service
 *
 * Handles SSE connections for real-time updates from the server.
 * Follows separation of concerns by encapsulating SSE communication logic.
 *
 * @package WP_MCP_AI
 */

(function (window) {
	'use strict';

	// Prevent multiple initialization
	if (window.wpMcpAiSSE) {
		return;
	}

	/**
	 * EventSource ready state constants
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
	 * Provides methods to create and manage Server-Sent Events connections.
	 */
	const SSEService = {
		/**
		 * Active EventSource connections by key
		 */
		connections: {},

		/**
		 * Check if SSE is supported by the browser
		 * 
		 * @return {boolean} True if EventSource is available
		 */
		isSupported: function () {
			return typeof EventSource !== 'undefined';
		},

		/**
		 * Get human-readable description for EventSource ready state
		 * 
		 * @param {number} readyState - EventSource ready state value
		 * @return {string} Human-readable state description
		 */
		getReadyStateName: function (readyState) {
			return READY_STATE_NAMES[readyState] || 'UNKNOWN';
		},

		/**
		 * Extract useful error information from an SSE error event
		 * EventSource error events are notoriously uninformative, so we
		 * gather as much context as possible from the connection state.
		 * 
		 * @param {Event} event - The error event
		 * @param {EventSource} eventSource - The EventSource instance
		 * @param {string} url - The connection URL (for logging)
		 * @return {Object} Error details object
		 */
		extractErrorDetails: function (event, eventSource, url) {
			const details = {
				type: 'sse_error',
				readyState: eventSource ? eventSource.readyState : -1,
				readyStateName: eventSource ? this.getReadyStateName(eventSource.readyState) : 'N/A',
				timestamp: new Date().toISOString()
			};

			// Add URL origin for debugging (without exposing full URL which may contain tokens)
			if (url) {
				try {
					const urlObj = new URL(url, window.location.origin);
					details.endpoint = urlObj.pathname;
					details.origin = urlObj.origin;
				} catch (e) {
					details.endpoint = '(invalid URL)';
				}
			}

			// Determine likely cause based on ready state
			if (eventSource) {
				switch (eventSource.readyState) {
					case READY_STATE.CONNECTING:
						details.likelyCause = 'Connection failed during handshake - check endpoint availability, CORS, or authentication';
						break;
					case READY_STATE.CLOSED:
						details.likelyCause = 'Connection closed unexpectedly - server may have rejected the request, returned non-200 status, or connection timed out';
						break;
					case READY_STATE.OPEN:
						details.likelyCause = 'Error during active connection - server may have closed the stream or sent malformed data';
						break;
					default:
						details.likelyCause = 'Unknown error state';
				}
			}

			return details;
		},

		/**
		 * Create an SSE connection
		 * 
		 * @param {string} url - SSE endpoint URL
		 * @param {Object} options - Configuration options
		 * @param {Function} options.onMessage - Callback for message events
		 * @param {Function} options.onError - Callback for error events
		 * @param {Function} options.onOpen - Optional callback for open event
		 * @param {Object} options.eventHandlers - Map of custom event names to handlers
		 * @return {Object} Connection object with EventSource and close method
		 */
		connect: function (url, options) {
			if (!this.isSupported()) {
				if (options.onError) {
					options.onError(new Error('EventSource not supported'));
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
				const eventSource = new EventSource(url);
				const connectionKey = this.generateConnectionKey(url);

				// Handle open event
				eventSource.addEventListener('open', function () {
					if (options.onOpen && typeof options.onOpen === 'function') {
						options.onOpen();
					}
				});

				// Handle generic message events
				if (options.onMessage && typeof options.onMessage === 'function') {
					eventSource.addEventListener('message', function (event) {
						try {
							// Check for [DONE] marker
							if (event.data === '[DONE]') {
								return;
							}

							// Try to parse JSON
							const data = event.data ? JSON.parse(event.data) : null;
							options.onMessage(data, event);
						} catch (parseError) {
							if (window.console && console.error) {
								console.error('[WP oOS SSE] Failed to parse message:', parseError);
							}
						}
					});
				}

				// Handle custom event types
				if (options.eventHandlers && typeof options.eventHandlers === 'object') {
					Object.keys(options.eventHandlers).forEach(function (eventName) {
						const handler = options.eventHandlers[eventName];
						if (typeof handler === 'function') {
							eventSource.addEventListener(eventName, function (event) {
								try {
									const data = event.data ? JSON.parse(event.data) : null;
									handler(data, event);
								} catch (parseError) {
									if (window.console && console.error) {
										console.error('[WP oOS SSE] Failed to parse event:', parseError);
									}
								}
							});
						}
					});
				}

				// Handle errors
				// Store reference to SSEService for error handling
				const self = this;
				eventSource.addEventListener('error', function (event) {
					// Extract detailed error information
					const errorDetails = self.extractErrorDetails(event, eventSource, url);
					
					if (window.console && console.error) {
						console.error('[WP oOS SSE] Connection error:', errorDetails.likelyCause);
						console.error('[WP oOS SSE] Error details:', {
							readyState: errorDetails.readyStateName + ' (' + errorDetails.readyState + ')',
							endpoint: errorDetails.endpoint,
							timestamp: errorDetails.timestamp
						});
						// Log troubleshooting hints based on the error state
						if (errorDetails.readyState === READY_STATE.CLOSED) {
							console.info('[WP oOS SSE] Troubleshooting: Check browser Network tab for the failed SSE request. Common issues include:');
							console.info('  - 401/403 errors: Authentication required or failed');
							console.info('  - 404 errors: Endpoint not found');
							console.info('  - CORS errors: Missing Access-Control headers');
							console.info('  - Network errors: Server unreachable');
						}
					}

					if (options.onError && typeof options.onError === 'function') {
						options.onError(event);
					}
				});

				// Store connection
				this.connections[connectionKey] = {
					eventSource: eventSource,
					url: url,
					createdAt: Date.now()
				};

				// Return connection object
				return {
					eventSource: eventSource,
					close: function () {
						self.closeConnection(connectionKey);
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
			return 'sse_' + url.replace(/[^a-zA-Z0-9]/g, '_') + '_' + Date.now();
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
