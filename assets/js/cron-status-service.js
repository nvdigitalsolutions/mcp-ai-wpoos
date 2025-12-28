/**
 * Cron Status Service
 *
 * Handles fetching and updating cron job status for display in chat interfaces.
 * Uses SSE-first approach with 30-second REST polling fallback.
 * Follows separation of concerns by encapsulating API communication logic.
 *
 * @package WP_MCP_AI
 */

(function (window) {
	'use strict';

	// Prevent multiple initialization
	if (window.wpMcpAiCronStatus) {
		return;
	}

	/**
	 * Cron Status Service
	 * 
	 * Provides methods to fetch and manage cron job status display using SSE or REST.
	 */
	const CronStatusService = {
		/**
		 * Fallback polling interval in milliseconds (30 seconds)
		 */
		fallbackPollingInterval: 30000,

		/**
		 * Active SSE connections by container ID
		 */
		sseConnections: {},

		/**
		 * Fallback polling timers by container ID (used when SSE fails)
		 */
		fallbackPollers: {},

		/**
		 * Cached status data by container ID
		 */
		cache: {},

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

			// Add REST authentication header.
			// Guest tokens take priority for public chat surfaces.
			if (guestToken) {
				headers['X-WP-MCP-AI-Guest'] = guestToken;
			} else if (nonce) {
				headers['X-WP-Nonce'] = nonce;
			}

			return fetch(url, {
				method: 'GET',
				headers: headers,
				credentials: 'same-origin',
			})
				.then(function (response) {
					if (!response.ok) {
						throw new Error('Failed to fetch cron status');
					}
					return response.json();
				})
				.catch(function (error) {
					if (window.console && console.error) {
						console.error('[NV oOS] Cron status REST fetch error:', error);
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
			if (hasAuth && window.wpMcpAiSSE && window.wpMcpAiSSE.isSupported()) {
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
					const sseConnection = window.wpMcpAiSSE.connect(sseUrl, {
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
									if (window.wpMcpAiJobBus) {
										window.wpMcpAiJobBus.handleJobUpdate(data.job_id, data);
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
							if (window.console && console.log) {
								console.log('[NV oOS] SSE cron status connection established for', containerId);
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
		 * Start fallback REST polling
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

			// Fetch immediately
			this.fetchStatusREST(endpoint, nonce, 10, assistantId, guestToken).then(function (data) {
				if (data) {
					self.cache[containerId] = data;
					if (callback && typeof callback === 'function') {
						callback(data);
					}
				}
			});

			// Set up polling interval
			this.fallbackPollers[containerId] = setInterval(function () {
				self.fetchStatusREST(endpoint, nonce, 10, assistantId, guestToken).then(function (data) {
					if (data) {
						self.cache[containerId] = data;
						if (callback && typeof callback === 'function') {
							callback(data);
						}
					}
				});
			}, this.fallbackPollingInterval);
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
				clearInterval(this.fallbackPollers[containerId]);
				delete this.fallbackPollers[containerId];
			}
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
		 * @param {string} containerId - Chat container ID
		 * @return {Object|null} Cached status data or null
		 */
		getCached: function (containerId) {
			return this.cache[containerId] || null;
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
				
				if (url && !element.classList.contains('wp-mcp-ai-job-clickable')) {
					// Mark as clickable
					element.classList.add('wp-mcp-ai-job-clickable');
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
			if (!window.wpMcpAiJobBus || !data || !data.jobs) {
				return;
			}

			// Emit update for each job
			data.jobs.forEach(function (job) {
				if (job && job.job_id) {
					window.wpMcpAiJobBus.handleJobUpdate(job.job_id, job);
				}
			});
		},

		// Legacy API compatibility - deprecated, use startMonitoring/stopMonitoring
		startPolling: function (containerId, endpoint, nonce, callback, assistantId) {
			if (window.console && console.warn) {
				console.warn('[NV oOS] CronStatusService.startPolling is deprecated, use startMonitoring instead');
			}
			return this.startMonitoring(containerId, endpoint, nonce, callback, assistantId);
		},

		stopPolling: function (containerId) {
			if (window.console && console.warn) {
				console.warn('[NV oOS] CronStatusService.stopPolling is deprecated, use stopMonitoring instead');
			}
			return this.stopMonitoring(containerId);
		}
	};

	// Export to global scope
	window.wpMcpAiCronStatus = CronStatusService;

})(window);
