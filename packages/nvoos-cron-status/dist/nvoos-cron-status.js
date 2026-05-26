const _config = {
	/**
	 * SSE adapter compatible with @nvdigitalsolutions/nvoos-events SSEService.
	 * Must expose:
	 *   isSupported(): boolean
	 *   connect(url, options): { close(): void } | null
	 * When omitted, the service skips SSE and uses REST polling only.
	 */
	sseAdapter: null,

	/**
	 * Job event bus with a handleJobUpdate(jobId, payload) method.
	 * Compatible with @nvdigitalsolutions/nvoos-events JobEventBus.
	 * When omitted, individual cron_job_status emissions are dropped silently.
	 */
	jobBus: null,

	/**
	 * CSS class added to elements made clickable by attachClickHandlers().
	 * Default mirrors the upstream WordPress plugin for drop-in compatibility.
	 */
	jobClickableClass: 'nvoos-job-clickable'
};

/**
 * Configure injectable dependencies for the cron status service.
 *
 * @param {Object} options
 * @param {Object} [options.sseAdapter]      SSE service with isSupported() + connect().
 * @param {Object} [options.jobBus]          Job event bus with handleJobUpdate(jobId, payload).
 * @param {string} [options.jobClickableClass] CSS class for click-enabled job elements.
 */
export function configure(options) {
	options = options || {};
	if (options.sseAdapter !== undefined) _config.sseAdapter = options.sseAdapter;
	if (options.jobBus !== undefined)     _config.jobBus = options.jobBus;
	if (options.jobClickableClass)        _config.jobClickableClass = options.jobClickableClass;
}

/**
 * Cron Status Service
 *
 * Handles fetching and updating cron job status for display in chat interfaces.
 * Uses SSE-first approach with 30-second REST polling fallback.
 * Follows separation of concerns by encapsulating API communication logic.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */



	/**
	 * Cron Status Service
	 * 
	 * Provides methods to fetch and manage cron job status display using SSE or REST.
	 */
	const CronStatusService = {
		/**
		 * Initial fallback polling interval in milliseconds (30 seconds)
		 */
		fallbackPollingInterval: 30000,

		/**
		 * Maximum polling interval in milliseconds (5 minutes)
		 */
		maxPollingInterval: 300000,

		/**
		 * Backoff multiplier applied to polling interval on empty responses
		 */
		backoffMultiplier: 1.5,

		/**
		 * Maximum number of consecutive REST polling attempts before stopping
		 */
		maxPollingAttempts: 60,

		/**
		 * Active SSE connections by container ID
		 */
		sseConnections: {},

		/**
		 * Fallback polling timers by container ID (used when SSE fails)
		 */
		fallbackPollers: {},

		/**
		 * Current polling interval per container (for exponential backoff)
		 */
		pollingIntervals: {},

		/**
		 * Polling attempt counters per container
		 */
		pollingAttempts: {},

		/**
		 * Cached status data by container ID
		 */
		cache: {},

		/**
		 * Cache timestamps by container ID
		 */
		cacheTimestamps: {},

		/**
		 * Maximum cache age in milliseconds (2 minutes)
		 */
		cacheMaxAge: 120000,

		/**
		 * Fetch cron job status from REST API (fallback method)
		 * 
		 * @param {string} endpoint - REST API endpoint URL
		 * @param {string} nonce - WordPress REST nonce
		 * @param {number} limit - Maximum number of jobs to fetch
		 * @param {number} assistantId - Optional assistant ID for filtering
		 * @param {string} guestToken - Optional guest token for public chat surfaces
		 * @return {Promise} Promise resolving to status data
		 */
		fetchStatusREST: function (endpoint, nonce, limit, assistantId, guestToken) {
			limit = limit || 10;

			// Build URL with parameters
			let url = endpoint + '?limit=' + limit;
			
			// Add assistant_id for multi-widget isolation
			if (assistantId) {
				url += '&assistant_id=' + encodeURIComponent(assistantId);
			}

			const headers = {
				'Content-Type': 'application/json',
			};

			// Add REST authentication.
			// Guest tokens take priority for public chat surfaces.
			//
			// We send credentials both as a header AND as a query parameter so the
			// request still authenticates when a CDN, reverse proxy, or service worker
			// in front of the site strips custom headers (e.g. X-WP-Nonce or
			// X-WP-MCP-AI-Guest) from cacheable GETs. The server-side permission
			// check (permissions_check_cron_status) accepts either form. This mirrors
			// the SSE URL pattern in chat.js which has always used query params
			// because EventSource cannot send custom headers.
			if (guestToken) {
				headers['X-WP-MCP-AI-Guest'] = guestToken;
				url += '&guest_token=' + encodeURIComponent(guestToken);
			} else if (nonce) {
				headers['X-WP-Nonce'] = nonce;
				url += '&_wpnonce=' + encodeURIComponent(nonce);
			}

			return fetch(url, {
				method: 'GET',
				headers: headers,
				credentials: 'same-origin',
			})
				.then(function (response) {
					if (!response.ok) {
						// Read the response body for diagnostic info.
						return response.text().then(function (body) {
							let detail = 'HTTP ' + response.status;
							if (body && body.length < 500) {
								detail += ': ' + body;
							}
							throw new Error('Failed to fetch cron status (' + detail + ')');
						});
					}
					return response.json();
				})
				.catch(function (error) {
					if (typeof console !== "undefined") { console.error('[nvoos-cron-status] Cron status REST fetch error:', error);
					}
					return null;
				});
		},

		/**
		 * Start monitoring cron status using SSE-first approach
		 * Falls back to REST polling after 30 seconds if SSE fails or is not supported.
		 * 
		 * @param {string} containerId - Chat container ID
		 * @param {string} endpoint - REST API endpoint URL
		 * @param {string} nonce - WordPress REST nonce
		 * @param {Function} callback - Callback function to handle status updates
		 * @param {number} assistantId - Optional assistant ID for filtering
		 * @param {string} guestToken - Optional guest token for public chat surfaces
		 */
		startMonitoring: function (containerId, endpoint, nonce, callback, assistantId, guestToken) {
			// Stop existing monitoring if any
			this.stopMonitoring(containerId);

			const self = this;

			// Check if authentication is available for SSE.
			// SSE requires either a valid nonce or guest token passed via query params.
			// Without authentication, the endpoint will reject the connection with 401,
			// causing console errors. Skip SSE and use REST polling which can handle
			// auth failures more gracefully.
			const hasAuth = !!(nonce || guestToken);

			// Try SSE first if supported AND we have authentication
			if (hasAuth && _config.sseAdapter && _config.sseAdapter.isSupported()) {
				// Build SSE URL with stream parameter
				let sseUrl = endpoint + '?stream=true&limit=10';
				if (assistantId) {
					sseUrl += '&assistant_id=' + encodeURIComponent(assistantId);
				}

				// EventSource doesn't support custom headers, so we must pass
				// authentication via query parameters.
				// Guest tokens take priority over nonces for public chat surfaces.
				if (guestToken) {
					sseUrl += '&guest_token=' + encodeURIComponent(guestToken);
				} else if (nonce) {
					sseUrl += '&_wpnonce=' + encodeURIComponent(nonce);
				}

				let sseReceived = false; // Track if we received any SSE data

				try {
					const sseConnection = _config.sseAdapter.connect(sseUrl, {
						eventHandlers: {
							'cron_status': function (data) {
								sseReceived = true; // Mark that SSE is working
								if (data) {
									self.cache[containerId] = data;
									if (callback && typeof callback === 'function') {
										callback(data);
									}
									// Emit job updates through the event bus for integration
									// with chat.js async tool polling
									self.emitJobUpdates(data);
								}
							},
							// Handle individual job status events from SSE stream
							'cron_job_status': function (data) {
								sseReceived = true;
								if (data && data.job_id) {
									// Emit through job event bus for chat integration
									if (_config.jobBus) {
										_config.jobBus.handleJobUpdate(data.job_id, data);
									}
								}
							}
						},
						onError: function () {
							// SSE connection failed - silently fall back to REST polling.
							// Don't log warnings for expected auth failures (e.g., expired nonce).
							// Stop SSE connection before falling back
							self.stopSSE(containerId);
							// Fall back to REST polling
							self.startFallbackPolling(containerId, endpoint, nonce, callback, assistantId, guestToken);
						},
						onOpen: function () {
							if (typeof console !== "undefined") { console.log('[nvoos-cron-status] SSE cron status connection established for', containerId);
							}
						}
					});

					if (sseConnection) {
						this.sseConnections[containerId] = sseConnection;
						
						// Set timeout to fall back to polling after 30 seconds if no SSE data received.
						// This handles cases where connection succeeds but no data is sent.
						setTimeout(function () {
							// Only fall back if SSE connection still exists and no data received
							if (self.sseConnections[containerId] && !sseReceived) {
								// Silently fall back - no warning needed for expected timeout behavior
								self.stopSSE(containerId);
								self.startFallbackPolling(containerId, endpoint, nonce, callback, assistantId, guestToken);
							}
						}, 30000);
					} else {
						// SSE connection failed, use REST polling
						this.startFallbackPolling(containerId, endpoint, nonce, callback, assistantId, guestToken);
					}
				} catch (error) {
					// SSE connection threw an error - silently fall back to REST polling
					this.startFallbackPolling(containerId, endpoint, nonce, callback, assistantId, guestToken);
				}
			} else {
				// SSE not supported or no authentication available, use REST polling
				this.startFallbackPolling(containerId, endpoint, nonce, callback, assistantId, guestToken);
			}
		},

		/**
		 * Start fallback REST polling with exponential backoff
		 * 
		 * @param {string} containerId - Chat container ID
		 * @param {string} endpoint - REST API endpoint URL
		 * @param {string} nonce - WordPress REST nonce
		 * @param {Function} callback - Callback function to handle status updates
		 * @param {number} assistantId - Optional assistant ID for filtering
		 * @param {string} guestToken - Optional guest token for public chat surfaces
		 */
		startFallbackPolling: function (containerId, endpoint, nonce, callback, assistantId, guestToken) {
			const self = this;

			// Initialize polling state for this container
			this.pollingIntervals[containerId] = this.fallbackPollingInterval;
			this.pollingAttempts[containerId] = 0;

			// Fetch immediately
			this.fetchStatusREST(endpoint, nonce, 10, assistantId, guestToken).then(function (data) {
				if (data) {
					self.cache[containerId] = data;
					self.cacheTimestamps[containerId] = Date.now();
					if (callback && typeof callback === 'function') {
						callback(data);
					}
					// Emit job updates through event bus for REST polling too
					self.emitJobUpdates(data);
				}
			});

			// Set up polling with exponential backoff
			this.scheduleNextPoll(containerId, endpoint, nonce, callback, assistantId, guestToken);
		},

		/**
		 * Schedule next REST polling attempt with exponential backoff
		 * 
		 * @param {string} containerId - Chat container ID
		 * @param {string} endpoint - REST API endpoint URL
		 * @param {string} nonce - WordPress REST nonce
		 * @param {Function} callback - Callback function to handle status updates
		 * @param {number} assistantId - Optional assistant ID for filtering
		 * @param {string} guestToken - Optional guest token for public chat surfaces
		 */
		scheduleNextPoll: function (containerId, endpoint, nonce, callback, assistantId, guestToken) {
			const self = this;
			const currentInterval = this.pollingIntervals[containerId] || this.fallbackPollingInterval;

			this.fallbackPollers[containerId] = setTimeout(function () {
				// Check max attempts
				self.pollingAttempts[containerId] = (self.pollingAttempts[containerId] || 0) + 1;

				if (self.pollingAttempts[containerId] >= self.maxPollingAttempts) {
					if (typeof console !== "undefined") { console.warn('[nvoos-cron-status] Max polling attempts (' + self.maxPollingAttempts + ') reached for', containerId);
					}
					self.stopFallbackPolling(containerId);
					return;
				}

				self.fetchStatusREST(endpoint, nonce, 10, assistantId, guestToken).then(function (data) {
					if (data) {
						self.cache[containerId] = data;
						self.cacheTimestamps[containerId] = Date.now();
						if (callback && typeof callback === 'function') {
							callback(data);
						}
						// Emit job updates through event bus for REST polling too
						self.emitJobUpdates(data);

						// Reset backoff on successful response with data
						self.pollingIntervals[containerId] = self.fallbackPollingInterval;
					} else {
						// Increase interval on empty response (exponential backoff with cap)
						self.pollingIntervals[containerId] = Math.min(
							currentInterval * self.backoffMultiplier,
							self.maxPollingInterval
						);
					}

					// Schedule next poll
					self.scheduleNextPoll(containerId, endpoint, nonce, callback, assistantId, guestToken);
				});
			}, currentInterval);
		},

		/**
		 * Stop SSE connection for a container
		 * 
		 * @param {string} containerId - Chat container ID
		 */
		stopSSE: function (containerId) {
			if (this.sseConnections[containerId]) {
				const connection = this.sseConnections[containerId];
				if (connection && connection.close && typeof connection.close === 'function') {
					connection.close();
				}
				delete this.sseConnections[containerId];
			}
		},

		/**
		 * Stop fallback polling for a container
		 * 
		 * @param {string} containerId - Chat container ID
		 */
		stopFallbackPolling: function (containerId) {
			if (this.fallbackPollers[containerId]) {
				clearTimeout(this.fallbackPollers[containerId]);
				delete this.fallbackPollers[containerId];
			}
			delete this.pollingIntervals[containerId];
			delete this.pollingAttempts[containerId];
		},

		/**
		 * Stop all monitoring for a container
		 * 
		 * @param {string} containerId - Chat container ID
		 */
		stopMonitoring: function (containerId) {
			this.stopSSE(containerId);
			this.stopFallbackPolling(containerId);
		},

		/**
		 * Get cached status for a container
		 * 
		 * Returns null if cache entry has expired (older than cacheMaxAge).
		 * 
		 * @param {string} containerId - Chat container ID
		 * @return {Object|null} Cached status data or null
		 */
		getCached: function (containerId) {
			if (!this.cache[containerId]) {
				return null;
			}

			// Check cache freshness
			const timestamp = this.cacheTimestamps[containerId] || 0;
			if (Date.now() - timestamp > this.cacheMaxAge) {
				// Cache expired, clean up
				delete this.cache[containerId];
				delete this.cacheTimestamps[containerId];
				return null;
			}

			return this.cache[containerId];
		},

		/**
		 * Clear cache for a container
		 * 
		 * @param {string} containerId - Chat container ID
		 */
		clearCache: function (containerId) {
			if (this.cache[containerId]) {
				delete this.cache[containerId];
			}
			if (this.cacheTimestamps[containerId]) {
				delete this.cacheTimestamps[containerId];
			}
		},

		/**
		 * Make job IDs clickable with admin URLs
		 * 
		 * Adds click handlers to job elements to open admin cron manager.
		 * Follows SOC by only handling UI interaction, not data manipulation.
		 * 
		 * @param {HTMLElement} container - Container element with job display
		 */
		makeJobsClickable: function (container) {
			if (!container) {
				return;
			}

			// Find all elements with data-job-url attribute
			const jobElements = container.querySelectorAll('[data-job-url]');

			jobElements.forEach(function (element) {
				const url = element.getAttribute('data-job-url');
				
				if (url && !element.classList.contains(_config.jobClickableClass)) {
					// Mark as clickable
					element.classList.add(_config.jobClickableClass);
					element.style.cursor = 'pointer';
					element.style.textDecoration = 'underline';
					element.title = 'Click to view in Cron Manager';

					// Add click handler
					element.addEventListener('click', function (e) {
						e.preventDefault();
						e.stopPropagation();
						
						// Open in new window/tab
						window.open(url, '_blank');
					});
				}
			});
		},

		/**
		 * Emit job updates through the job event bus
		 * 
		 * Extracts individual job statuses from cron_status data and emits
		 * them through wpMcpAiJobBus for integration with chat.js
		 * 
		 * @param {Object} data Cron status data with jobs array
		 */
		emitJobUpdates: function (data) {
			if (!_config.jobBus || !data || !data.jobs) {
				return;
			}

			// Emit update for each job
			data.jobs.forEach(function (job) {
				if (job && job.job_id) {
					_config.jobBus.handleJobUpdate(job.job_id, job);
				}
			});
		},

		// Legacy API compatibility - deprecated, use startMonitoring/stopMonitoring
		startPolling: function (containerId, endpoint, nonce, callback, assistantId) {
			if (typeof console !== "undefined") { console.warn('[nvoos-cron-status] CronStatusService.startPolling is deprecated, use startMonitoring instead');
			}
			return this.startMonitoring(containerId, endpoint, nonce, callback, assistantId);
		},

		stopPolling: function (containerId) {
			if (typeof console !== "undefined") { console.warn('[nvoos-cron-status] CronStatusService.stopPolling is deprecated, use stopMonitoring instead');
			}
			return this.stopMonitoring(containerId);
		}
	};

// ES Module exports
export { CronStatusService };
export default CronStatusService;
