/**
 * Cron Status Service
 *
 * Handles fetching and updating cron job status for display in chat interfaces.
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
	 * Provides methods to fetch and manage cron job status display.
	 */
	const CronStatusService = {
		/**
		 * Polling interval in milliseconds
		 */
		pollingInterval: 30000, // 30 seconds

		/**
		 * Active polling timers by container ID
		 */
		pollers: {},

		/**
		 * Cached status data by container ID
		 */
		cache: {},

		/**
		 * Fetch cron job status from API
		 * 
		 * @param {string} endpoint - REST API endpoint URL
		 * @param {string} nonce - WordPress REST nonce
		 * @param {number} limit - Maximum number of jobs to fetch
		 * @return {Promise} Promise resolving to status data
		 */
		fetchStatus: function (endpoint, nonce, limit, assistantId) {
			limit = limit || 10;

			// Build URL with parameters
		let url = endpoint + '?limit=' + limit + '&context=chat';
		
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
						console.error('Cron status fetch error:', error);
					}
					return null;
				});
		},

		/**
		 * Start polling for cron status updates
		 * 
		 * @param {string} containerId - Chat container ID
		 * @param {string} endpoint - REST API endpoint URL
		 * @param {string} nonce - WordPress REST nonce
		 * @param {Function} callback - Callback function to handle status updates
		 */
		startPolling: function (containerId, endpoint, nonce, callback, assistantId) {
			// Stop existing poller if any
			this.stopPolling(containerId);

			// Fetch immediately
			const self = this;
			this.fetchStatus(endpoint, nonce, 10, assistantId).then(function (data) {
				if (data) {
					self.cache[containerId] = data;
					if (callback && typeof callback === 'function') {
						callback(data);
					}
				}
			});

			// Set up polling interval
			this.pollers[containerId] = setInterval(function () {
				self.fetchStatus(endpoint, nonce, 10, assistantId).then(function (data) {
					if (data) {
						self.cache[containerId] = data;
						if (callback && typeof callback === 'function') {
							callback(data);
						}
					}
				});
			}, this.pollingInterval);
		},

		/**
		 * Stop polling for a container
		 * 
		 * @param {string} containerId - Chat container ID
		 */
		stopPolling: function (containerId) {
			if (this.pollers[containerId]) {
				clearInterval(this.pollers[containerId]);
				delete this.pollers[containerId];
			}
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
		}
	};

	// Export to global scope
	window.wpMcpAiCronStatus = CronStatusService;

})(window);
