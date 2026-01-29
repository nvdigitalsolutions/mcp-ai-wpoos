/**
 * Cron Manager Admin JavaScript
 *
 * Provides auto-refresh and real-time updates for the Cron Manager page.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 */

(function($) {
	'use strict';

	const CronManager = {
		refreshInterval: null,
		autoRefreshEnabled: true,
		refreshRate: 15000, // 15 seconds
		isLoading: false,

		/**
		 * Initialize the manager.
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
			$('#refresh-cron-manager').on('click', this.refreshStats.bind(this));
			
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
			const $button = $('#refresh-cron-manager');
			const originalText = $button.html();

			// Show loading state
			$button.prop('disabled', true);
			$button.html('<span class="dashicons dashicons-update-alt rotating"></span> Refreshing...');

			// Make AJAX request
			$.ajax({
				url: wpMcpAiCronManager.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_cron_manager_stats',
					nonce: wpMcpAiCronManager.nonce
				},
				success: (response) => {
					if (response.success && response.data) {
						this.updateStats(response.data.stats);
						this.updateJobs(response.data.jobs);
						this.updateDLQStats(response.data.dlq_stats);
						this.updateLastRefresh();
					} else {
						this.showNotice('Error: ' + (response.data?.message || 'Unknown error'), 'error');
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
			$('[data-stat="total"]').text(stats.total || 0);
			$('[data-stat="active"]').text(stats.active || 0);
			$('[data-stat="recurring"]').text(stats.recurring || 0);
			$('[data-stat="one_off"]').text(stats.one_off || 0);
		},

		/**
		 * Update jobs table.
		 *
		 * @param {Array} jobs Array of job objects.
		 */
		updateJobs: function(jobs) {
			const $tbody = $('#cron-jobs-table tbody');
			
			if (!jobs || jobs.length === 0) {
				$tbody.html('<tr class="no-items"><td colspan="8">' + wpMcpAiCronManager.strings.noJobs + '</td></tr>');
				return;
			}

			let html = '';
			jobs.forEach((job) => {
				html += '<tr>';
				html += '<td><code>' + this.escapeHtml(job.hook || '') + '</code></td>';
				html += '<td>' + this.renderStatus(job) + '</td>';
				html += '<td>' + this.renderNextRun(job) + '</td>';
				html += '<td>' + this.renderScheduleType(job) + '</td>';
				html += '<td class="wp-mcp-ai-cron-manager__args">' + this.renderArgs(job.args) + '</td>';
				html += '<td>' + this.escapeHtml(job.creator || 'System') + '</td>';
				html += '<td>' + this.escapeHtml(job.created_at_formatted || 'Unknown') + '</td>';
				html += '<td>' + this.renderActions(job) + '</td>';
				html += '</tr>';
			});

			$tbody.html(html);
		},

		/**
		 * Render job status badge.
		 *
		 * @param {Object} job Job object.
		 * @return {string} HTML for status badge.
		 */
		renderStatus: function(job) {
			const isActive = job.is_active;
			const wasExecuted = job.was_executed;
			
			if (isActive) {
				return '<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--active">Active</span>';
			} else if (wasExecuted) {
				return '<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--executed">Executed</span>';
			} else {
				return '<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--inactive">Inactive</span>';
			}
		},

		/**
		 * Render next run time.
		 *
		 * @param {Object} job Job object.
		 * @return {string} HTML for next run time.
		 */
		renderNextRun: function(job) {
			if (job.next_run_formatted) {
				return this.escapeHtml(job.next_run_formatted);
			}
			return '<em>Not scheduled</em>';
		},

		/**
		 * Render schedule type badge.
		 *
		 * @param {Object} job Job object.
		 * @return {string} HTML for schedule type.
		 */
		renderScheduleType: function(job) {
			const isRecurring = job.is_recurring;
			const schedule = job.schedule || 'single';
			
			if (isRecurring) {
				return '<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--recurring">Recurring</span><br><small>' + this.escapeHtml(schedule) + '</small>';
			} else {
				return '<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--oneoff">One-off</span>';
			}
		},

		/**
		 * Render job arguments.
		 *
		 * @param {Array} args Arguments array.
		 * @return {string} HTML for arguments.
		 */
		renderArgs: function(args) {
			if (!args || args.length === 0) {
				return '<em>None</em>';
			}
			return '<pre>' + this.escapeHtml(JSON.stringify(args, null, 2)) + '</pre>';
		},

		/**
		 * Render action buttons.
		 *
		 * @param {Object} job Job object.
		 * @return {string} HTML for actions.
		 */
		renderActions: function(job) {
			// Return a link to trigger delete via traditional form submit
			return '<a href="#" class="button delete-cron-job" data-job-id="' + this.escapeHtml(job.job_id) + '" data-nonce="' + this.escapeHtml(job.delete_nonce || '') + '">Delete</a>';
		},

		/**
		 * Update DLQ statistics.
		 *
		 * @param {Object} dlqStats DLQ statistics object.
		 */
		updateDLQStats: function(dlqStats) {
			if (!dlqStats) {
				return;
			}

			const $container = $('#dlq-stats-container');
			if (!$container.length) {
				return;
			}

			// Update DLQ counts if elements exist
			$('[data-dlq-stat="total"]').text(dlqStats.total || 0);
			$('[data-dlq-stat="active"]').text(dlqStats.active || 0);
			$('[data-dlq-stat="dismissed"]').text(dlqStats.dismissed || 0);
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
			const $notices = $('.wp-mcp-ai-cron-manager__notices');
			const noticeClass = 'notice-' + type;
			
			const $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
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
		}
	};

	// Initialize when DOM is ready
	$(document).ready(() => {
		if (typeof wpMcpAiCronManager !== 'undefined') {
			CronManager.init();
		}
	});

})(jQuery);
