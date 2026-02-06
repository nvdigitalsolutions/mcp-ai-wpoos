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
	SSEService.debug = false;
	
	/**
	 * Enable debug logging
	 */
	SSEService.enableDebug = function() {
		this.debug = true;
	};
	
	/**
	 * Disable debug logging
	 */
	SSEService.disableDebug = function() {
		this.debug = false;
	};

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
							if (this.debug && console && console.error) {
								console.error('[NV oOS SSE] Client error (' + response.status + ')');
							}
							// Throw to stop reconnection
							const errorText = await response.text();
							throw new Error('Client error (' + response.status + '): ' + errorText);
						} else {
							// Server errors (5xx) or network issues are retriable
							if (this.debug && console && console.error) {
								console.error('[NV oOS SSE] Server error (' + response.status + ')');
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
							if (console.error) {
								console.error('[NV oOS SSE] Failed to parse message:', parseError);
							}
						}
					},

					/**
					 * Called when the connection is closed by the server
					 * Can throw an error to trigger reconnection
					 */
					onclose: () => {
						// Connection closed normally
						if (this.debug && console && console.log) {
							console.log('[NV oOS SSE] Connection closed by server');
						}
					},

					/**
					 * Called when an error occurs
					 * Can throw to stop reconnection, or return retry interval
					 */
					onerror: (err) => {
						if (this.debug && console && console.error) {
							console.error('[NV oOS SSE] Connection error:', err);
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
				if (options.body) {
					const method = options.method ? options.method.toUpperCase() : 'GET';
					if (method === 'POST' || method === 'PUT') {
						fetchOptions.body = typeof options.body === 'string' 
							? options.body 
							: JSON.stringify(options.body);
					}
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
				if (console.error) {
					console.error('[NV oOS SSE] Failed to create connection:', error);
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

	


/**
 * Job Event Bus
 *
 * Lightweight event emitter following mitt's API pattern for job status updates.
 * Integrates cron status monitoring (job bar) with async tool completion (chat).
 *
 * API follows mitt (https://github.com/developit/mitt) for compatibility:
 * - on(type, handler) - Register event handler
 * - off(type, handler) - Remove event handler  
 * - emit(type, event) - Invoke handlers for type
 * - all - Map of event names to handlers
 *
 * Job-specific events:
 * - 'job:started' - Job has started
 * - 'job:progress' - Job progress update
 * - 'job:completed' - Job completed successfully
 * - 'job:failed' - Job failed with error
 * - '*' - Wildcard, receives all events
 *
 * @package WP_MCP_AI
 */



	/**
	 * Create a new event emitter instance (mitt-compatible API)
	 *
	 * @param {Map} [all] Optional Map of event handlers
	 * @return {Object} Event emitter with on, off, emit methods
	 */
	function createEventBus(all) {
		all = all || new Map();

		return {
			/**
			 * A Map of event names to registered handler functions
			 */
			all: all,

			/**
			 * Register an event handler for the given type
			 *
			 * @param {string|symbol} type Event type, or '*' for all events
			 * @param {Function} handler Function to call in response to event
			 */
			on: function (type, handler) {
				const handlers = all.get(type);
				if (handlers) {
					handlers.push(handler);
				} else {
					all.set(type, [handler]);
				}
			},

			/**
			 * Remove an event handler for the given type
			 * If handler is omitted, all handlers of the given type are removed
			 *
			 * @param {string|symbol} type Event type
			 * @param {Function} [handler] Handler to remove
			 */
			off: function (type, handler) {
				const handlers = all.get(type);
				if (handlers) {
					if (handler) {
						const idx = handlers.indexOf(handler);
						if (idx > -1) {
							handlers.splice(idx, 1);
						}
					} else {
						all.set(type, []);
					}
				}
			},

			/**
			 * Invoke all handlers for the given type
			 * If present, '*' handlers are invoked after type-matched handlers
			 *
			 * @param {string|symbol} type Event type
			 * @param {*} [evt] Event data passed to handlers
			 */
			emit: function (type, evt) {
				const handlers = all.get(type);
				if (handlers) {
					handlers.slice().forEach(function (handler) {
						handler(evt);
					});
				}
				// Invoke wildcard handlers
				const wildcardHandlers = all.get('*');
				if (wildcardHandlers) {
					wildcardHandlers.slice().forEach(function (handler) {
						handler(type, evt);
					});
				}
			}
		};
	}

	/**
	 * Job Event Bus - Extended event emitter for job status coordination
	 *
	 * Extends the base mitt-compatible API with job-specific features:
	 * - Job status caching
	 * - Promise-based job watching
	 * - Automatic status normalization
	 */
	const JobEventBus = createEventBus();

	/**
	 * Cache of job statuses for quick access
	 */
	JobEventBus.cache = {};

	/**
	 * Handle job status update from any source
	 * Normalizes status and emits appropriate events
	 *
	 * @param {string} jobId Job identifier
	 * @param {Object} data Status data from backend
	 */
	JobEventBus.handleJobUpdate = function (jobId, data) {
		if (!jobId || !data) {
			return;
		}

		// Update cache
		this.cache[jobId] = {
			data: data,
			updatedAt: Date.now()
		};

		// Normalize status string
		const status = typeof data.status === 'string' ? data.status.toLowerCase() : '';

		// Emit typed event
		let eventType;
		switch (status) {
			case 'started':
			case 'pending':
			case 'running':
				eventType = 'job:started';
				break;
			case 'polling':
			case 'delegated':
			case 'progress':
				eventType = 'job:progress';
				break;
			case 'completed':
				eventType = 'job:completed';
				break;
			case 'failed':
			case 'error':
				eventType = 'job:failed';
				break;
			default:
				eventType = 'job:progress';
		}

		// Emit with jobId included in payload
		this.emit(eventType, { jobId: jobId, data: data });
	};

	/**
	 * Watch a job until it completes or fails
	 *
	 * @param {string} jobId Job identifier
	 * @param {Object} [options] Watch options
	 * @param {Function} [options.onProgress] Progress callback
	 * @param {number} [options.timeout] Timeout in ms (default 5 min)
	 * @return {Promise} Resolves with result or rejects with error
	 */
	JobEventBus.watchJob = function (jobId, options) {
		const self = this;
		options = options || {};
		const timeout = options.timeout || 300000;

		return new Promise(function (resolve, reject) {
			let timeoutId = null;
			let completedHandler = null;
			let failedHandler = null;
			let progressHandler = null;

			function cleanup() {
				if (timeoutId) {
					clearTimeout(timeoutId);
				}
				if (completedHandler) {
					self.off('job:completed', completedHandler);
				}
				if (failedHandler) {
					self.off('job:failed', failedHandler);
				}
				if (progressHandler) {
					self.off('job:progress', progressHandler);
				}
			}

			// Check cache for already completed/failed
			const cached = self.cache[jobId];
			if (cached && cached.data) {
				const status = cached.data.status ? cached.data.status.toLowerCase() : '';
				if (status === 'completed') {
					resolve(cached.data);
					return;
				}
				if (status === 'failed' || status === 'error') {
					reject(new Error(cached.data.error || 'Job failed'));
					return;
				}
			}

			// Set timeout
			timeoutId = setTimeout(function () {
				cleanup();
				reject(new Error('Job watch timeout'));
			}, timeout);

			// Listen for completion
			completedHandler = function (evt) {
				if (evt.jobId === jobId) {
					cleanup();
					resolve(evt.data);
				}
			};
			self.on('job:completed', completedHandler);

			// Listen for failure
			failedHandler = function (evt) {
				if (evt.jobId === jobId) {
					cleanup();
					reject(new Error(evt.data && evt.data.error ? evt.data.error : 'Job failed'));
				}
			};
			self.on('job:failed', failedHandler);

			// Listen for progress if callback provided
			if (options.onProgress && typeof options.onProgress === 'function') {
				progressHandler = function (evt) {
					if (evt.jobId === jobId) {
						options.onProgress(evt.data);
					}
				};
				self.on('job:progress', progressHandler);
			}
		});
	};

	/**
	 * Get cached job status
	 *
	 * @param {string} jobId Job identifier
	 * @return {Object|null} Cached data or null
	 */
	JobEventBus.getCached = function (jobId) {
		return this.cache[jobId] || null;
	};

	/**
	 * Clear job from cache
	 *
	 * @param {string} jobId Job identifier
	 */
	JobEventBus.clearCache = function (jobId) {
		if (this.cache[jobId]) {
			delete this.cache[jobId];
		}
	};

	/**
	 * Clear all cached job data
	 */
	JobEventBus.clearAllCache = function () {
		this.cache = {};
	};

	


// ES Module exports
export { SSEService, JobEventBus, createEventBus };
export default { SSEService, JobEventBus, createEventBus };
