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
				eventSource.addEventListener('error', function (event) {
					if (window.console && console.error) {
						console.error('[WP oOS SSE] Connection error:', event);
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
				const self = this;
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
