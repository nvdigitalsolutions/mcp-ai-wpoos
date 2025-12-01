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

(function (window) {
	'use strict';

	// Prevent multiple initialization
	if (window.wpMcpAiJobBus) {
		return;
	}

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

	// Export to global scope
	window.wpMcpAiJobBus = JobEventBus;

	// Also export the factory for creating isolated instances
	window.wpMcpAiCreateEventBus = createEventBus;

})(window);
