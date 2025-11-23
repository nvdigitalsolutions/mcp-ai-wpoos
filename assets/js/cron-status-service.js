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
		 * @return {Promise} Promise resolving to status data
		 */
		fetchStatusREST: function (endpoint, nonce, limit, assistantId) {
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

			if (nonce) {
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
						console.error('[WP MCP AI] Cron status REST fetch error:', error);
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
		 */
		startMonitoring: function (containerId, endpoint, nonce, callback, assistantId) {
			// Stop existing monitoring if any
			this.stopMonitoring(containerId);

			const self = this;

			// Try SSE first if supported
			if (window.wpMcpAiSSE && window.wpMcpAiSSE.isSupported()) {
				// Build SSE URL with stream parameter
				let sseUrl = endpoint + '?stream=true&limit=10';
				if (assistantId) {
					sseUrl += '&assistant_id=' + encodeURIComponent(assistantId);
				}

				// Note: EventSource doesn't support custom headers
				// WordPress will use session cookie authentication automatically

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
								}
							}
						},
						onError: function (error) {
							if (window.console && console.warn) {
								console.warn('[WP MCP AI] SSE cron status failed, falling back to REST polling:', error);
							}
							// Fall back to REST polling
							self.startFallbackPolling(containerId, endpoint, nonce, callback, assistantId);
						},
						onOpen: function () {
							if (window.console && console.log) {
								console.log('[WP MCP AI] SSE cron status connection established for', containerId);
							}
						}
					});

					if (sseConnection) {
						this.sseConnections[containerId] = sseConnection;
						
						// Set timeout to fall back to polling after 30 seconds if no SSE data received
						setTimeout(function () {
							// If SSE hasn't received any data yet, fall back to polling
							if (!sseReceived) {
								if (window.console && console.warn) {
									console.warn('[WP MCP AI] SSE cron status timeout (no data received), falling back to REST polling');
								}
								self.stopSSE(containerId);
								self.startFallbackPolling(containerId, endpoint, nonce, callback, assistantId);
							}
						}, 30000);
					} else {
						// SSE connection failed, use REST polling
						this.startFallbackPolling(containerId, endpoint, nonce, callback, assistantId);
					}
				} catch (error) {
					if (window.console && console.error) {
						console.error('[WP MCP AI] SSE cron status connection error:', error);
					}
					// Fall back to REST polling
					this.startFallbackPolling(containerId, endpoint, nonce, callback, assistantId);
				}
			} else {
				// SSE not supported, use REST polling
				this.startFallbackPolling(containerId, endpoint, nonce, callback, assistantId);
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
		 */
		startFallbackPolling: function (containerId, endpoint, nonce, callback, assistantId) {
			const self = this;

			// Fetch immediately
			this.fetchStatusREST(endpoint, nonce, 10, assistantId).then(function (data) {
				if (data) {
					self.cache[containerId] = data;
					if (callback && typeof callback === 'function') {
						callback(data);
					}
				}
			});

			// Set up polling interval
			this.fallbackPollers[containerId] = setInterval(function () {
				self.fetchStatusREST(endpoint, nonce, 10, assistantId).then(function (data) {
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

		// Legacy API compatibility - deprecated, use startMonitoring/stopMonitoring
		startPolling: function (containerId, endpoint, nonce, callback, assistantId) {
			if (window.console && console.warn) {
				console.warn('[WP MCP AI] CronStatusService.startPolling is deprecated, use startMonitoring instead');
			}
			return this.startMonitoring(containerId, endpoint, nonce, callback, assistantId);
		},

		stopPolling: function (containerId) {
			if (window.console && console.warn) {
				console.warn('[WP MCP AI] CronStatusService.stopPolling is deprecated, use stopMonitoring instead');
			}
			return this.stopMonitoring(containerId);
		}
	};

	// Export to global scope
	window.wpMcpAiCronStatus = CronStatusService;

})(window);
