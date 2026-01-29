/**
 * Crawl4AI Monitor Admin JavaScript
 *
 * Provides auto-refresh and real-time updates for the Crawl4AI monitor page.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 */

(function($) {
	'use strict';

	const Crawl4AIMonitor = {
		refreshInterval: null,
		autoRefreshEnabled: true,
		refreshRate: 10000, // 10 seconds
		isLoading: false,

		/**
		 * Initialize the monitor.
		 */
		init: function() {
			this.bindEvents();
			this.startAutoRefresh();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Manual refresh button
			$('#refresh-crawl4ai-stats').on('click', this.refreshStats.bind(this));
			
			// Auto-refresh toggle
			$('#toggle-auto-refresh').on('change', this.toggleAutoRefresh.bind(this));
		},

		/**
		 * Start automatic refresh.
		 */
		startAutoRefresh: function() {
			if (this.refreshInterval) {
				clearInterval(this.refreshInterval);
			}

			this.refreshInterval = setInterval(() => {
				if (this.autoRefreshEnabled && !this.isLoading) {
					this.refreshStats();
				}
			}, this.refreshRate);
		},

		/**
		 * Toggle auto-refresh on/off.
		 *
		 * @param {Event} e Change event.
		 */
		toggleAutoRefresh: function(e) {
			this.autoRefreshEnabled = $(e.currentTarget).is(':checked');
			
			if (this.autoRefreshEnabled) {
				this.startAutoRefresh();
				this.showNotice('Auto-refresh enabled', 'success');
			} else {
				if (this.refreshInterval) {
					clearInterval(this.refreshInterval);
				}
				this.showNotice('Auto-refresh disabled', 'info');
			}
		},

		/**
		 * Refresh statistics via AJAX.
		 *
		 * @param {Event} e Click event (optional).
		 */
		refreshStats: function(e) {
			if (e) {
				e.preventDefault();
			}

			if (this.isLoading) {
				return;
			}

			this.isLoading = true;
			const $button = $('#refresh-crawl4ai-stats');
			const originalText = $button.html();

			// Show loading state
			$button.prop('disabled', true);
			$button.html('<span class="dashicons dashicons-update-alt rotating"></span> Refreshing...');

			// Make AJAX request
			$.ajax({
				url: wpMcpAiCrawl4AI.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_crawl4ai_stats',
					nonce: wpMcpAiCrawl4AI.nonce
				},
				success: (response) => {
					if (response.success && response.data) {
						this.updateStats(response.data.stats);
						this.updateJobs(response.data.jobs);
						this.updateLastRefresh();
					} else {
						// Escape error message to prevent XSS
						const errorMsg = this.escapeHtml(response.data?.message || 'Unknown error');
						this.showNotice('Error: ' + errorMsg, 'error');
					}
				},
				error: (xhr, status, error) => {
					console.error('AJAX Error:', status, error);
					this.showNotice('Error refreshing stats. Check console for details.', 'error');
				},
				complete: () => {
					this.isLoading = false;
					$button.prop('disabled', false);
					$button.html(originalText);
				}
			});
		},

		/**
		 * Update statistics display.
		 *
		 * @param {Object} stats Statistics object.
		 */
		updateStats: function(stats) {
			$('[data-stat="total_jobs"]').text(stats.total_jobs || 0);
			$('[data-stat="running_jobs"]').text(stats.running_jobs || 0);
			$('[data-stat="completed_jobs"]').text(stats.completed_jobs || 0);
			$('[data-stat="failed_jobs"]').text(stats.failed_jobs || 0);
			$('[data-stat="browser_pools"]').text(stats.browser_pools || 0);
		},

		/**
		 * Update jobs table.
		 *
		 * @param {Array} jobs Array of job objects.
		 */
		updateJobs: function(jobs) {
			const $container = $('#crawl4ai-jobs-table');
			
			if (!jobs || jobs.length === 0) {
				$container.html('<tr class="no-items"><td colspan="6">' + wpMcpAiCrawl4AI.strings.noJobs + '</td></tr>');
				return;
			}

			let html = '';
			jobs.forEach((job) => {
				const status = job.status || 'unknown';
				const statusClass = this.getStatusClass(status);

				html += '<tr>';
				html += '<td><code>' + this.escapeHtml(job.id || 'N/A') + '</code></td>';
				html += '<td>' + this.escapeHtml(job.url || 'N/A') + '</td>';
				html += '<td><span class="wp-mcp-ai-crawl4ai-monitor__status ' + statusClass + '">' + this.escapeHtml(this.ucfirst(status)) + '</span></td>';
				html += '<td>' + this.escapeHtml(job.started || 'N/A') + '</td>';
				html += '<td>' + this.escapeHtml(job.duration || 'N/A') + '</td>';
				html += '<td>' + this.escapeHtml(job.browser_pool || 'N/A') + '</td>';
				html += '</tr>';
			});

			$container.html(html);
		},

		/**
		 * Get CSS class for job status.
		 *
		 * @param {string} status Job status.
		 * @return {string} CSS class.
		 */
		getStatusClass: function(status) {
			const statusMap = {
				'completed': 'wp-mcp-ai-crawl4ai-monitor__status--completed',
				'running': 'wp-mcp-ai-crawl4ai-monitor__status--running',
				'failed': 'wp-mcp-ai-crawl4ai-monitor__status--failed',
				'pending': 'wp-mcp-ai-crawl4ai-monitor__status--pending'
			};
			return statusMap[status] || 'wp-mcp-ai-crawl4ai-monitor__status--pending';
		},

		/**
		 * Update last refresh time.
		 */
		updateLastRefresh: function() {
			const now = new Date();
			const timeString = now.toLocaleTimeString();
			$('#last-refresh-time').text(timeString);
		},

		/**
		 * Show admin notice.
		 *
		 * @param {string} message Notice message.
		 * @param {string} type Notice type (success, error, info, warning).
		 */
		showNotice: function(message, type) {
			const $notices = $('.wp-mcp-ai-crawl4ai-monitor__notices');
			const noticeClass = 'notice-' + type;
			
			// Escape message to prevent XSS
			const escapedMessage = this.escapeHtml(message);
			
			const $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + escapedMessage + '</p></div>');
			$notices.html($notice);
			
			// Auto-dismiss after 3 seconds
			setTimeout(() => {
				$notice.fadeOut(() => $notice.remove());
			}, 3000);
		},

		/**
		 * Escape HTML to prevent XSS.
		 *
		 * @param {string} text Text to escape.
		 * @return {string} Escaped text.
		 */
		escapeHtml: function(text) {
			const map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};
			return String(text).replace(/[&<>"']/g, (m) => map[m]);
		},

		/**
		 * Uppercase first character.
		 *
		 * @param {string} str String to capitalize.
		 * @return {string} Capitalized string.
		 */
		ucfirst: function(str) {
			return str.charAt(0).toUpperCase() + str.slice(1);
		}
	};

	// Initialize when DOM is ready
	$(document).ready(() => {
		if (typeof wpMcpAiCrawl4AI !== 'undefined') {
			Crawl4AIMonitor.init();
		}
	});

})(jQuery);
