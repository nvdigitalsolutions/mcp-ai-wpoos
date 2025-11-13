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
		fetchStatus: function (endpoint, nonce, limit) {
			limit = limit || 10;

			const url = endpoint + '?limit=' + limit;
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
		startPolling: function (containerId, endpoint, nonce, callback) {
			// Stop existing poller if any
			this.stopPolling(containerId);

			// Fetch immediately
			const self = this;
			this.fetchStatus(endpoint, nonce, 10).then(function (data) {
				if (data) {
					self.cache[containerId] = data;
					if (callback && typeof callback === 'function') {
						callback(data);
					}
				}
			});

			// Set up polling interval
			this.pollers[containerId] = setInterval(function () {
				self.fetchStatus(endpoint, nonce, 10).then(function (data) {
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
		}
	};

	// Export to global scope
	window.wpMcpAiCronStatus = CronStatusService;

})(window);
