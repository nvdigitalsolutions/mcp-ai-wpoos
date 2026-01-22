/**
 * Orchestration Dashboard JavaScript
 *
 * Real-time dashboard for monitoring autonomous sessions.
 */
(function($) {
	'use strict';

	const OrchestrationDashboard = {
		refreshInterval: null,
		config: wpMcpAiOrchestration || {},

		init: function() {
			this.bindEvents();
			this.startAutoRefresh();
			this.loadDashboardData();
		},

		bindEvents: function() {
			// Session control buttons.
			$(document).on('click', '.session-action', this.handleSessionAction.bind(this));
			
			// Manual refresh button (if added).
			$(document).on('click', '.refresh-dashboard', this.loadDashboardData.bind(this));
		},

		startAutoRefresh: function() {
			const interval = this.config.refreshInterval || 5000;
			this.refreshInterval = setInterval(() => {
				this.loadDashboardData();
			}, interval);
		},

		loadDashboardData: function() {
			$.ajax({
				url: this.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_dashboard_data',
					nonce: this.config.nonce
				},
				success: (response) => {
					if (response.success && response.data) {
						this.updateDashboard(response.data);
					}
				},
				error: () => {
					console.error('Failed to load dashboard data');
				}
			});
		},

		updateDashboard: function(data) {
			// Update overview cards.
			if (data.overview) {
				$('[data-metric="active_sessions"]').text(data.overview.active_sessions);
				$('[data-metric="total_plans"]').text(data.overview.total_plans);
				$('[data-metric="total_executions"]').text(data.overview.total_executions);
				$('[data-metric="system_health"]').text(data.overview.system_health);
			}

			// Update capacity metrics.
			if (data.capacity) {
				$('[data-metric="utilization"]').text(data.capacity.utilization);
				$('[data-metric="queue_length"]').text(data.capacity.queue_length);
				
				const $statusBadge = $('[data-metric="load_status"]');
				$statusBadge.text(data.capacity.load_status);
				$statusBadge.removeClass('status-critical status-warning status-moderate status-light status-idle');
				$statusBadge.addClass('status-' + data.capacity.load_status.toLowerCase());
			}

			// Update sessions table.
			if (data.sessions) {
				this.updateSessionsTable(data.sessions);
			}

			// Update activity feed.
			if (data.activity) {
				this.updateActivityFeed(data.activity);
			}
		},

		updateSessionsTable: function(sessions) {
			const $tbody = $('#sessions-table-body');
			
			if (sessions.length === 0) {
				$tbody.html('<tr class="no-items"><td colspan="9">' + this.config.strings.noSessions + '</td></tr>');
				return;
			}

			let html = '';
			sessions.forEach(session => {
				const elapsed = this.formatElapsedTime(session.start_time);
				const progress = Math.round((session.iterations / session.max_iterations) * 100);
				const tokenProgress = Math.round((session.tokens_used / session.token_budget) * 100);

				html += '<tr>';
				html += '<td><code>' + session.session_id + '</code></td>';
				html += '<td>' + this.escapeHtml(session.plan_title) + '</td>';
				html += '<td><span class="status-badge status-' + session.status + '">' + session.status + '</span></td>';
				html += '<td><span class="health-badge health-' + session.health + '">' + this.getHealthIcon(session.health) + ' ' + session.health + '</span></td>';
				html += '<td><div class="progress-bar"><div class="progress-fill" style="width:' + progress + '%"></div></div></td>';
				html += '<td>' + session.iterations + ' / ' + session.max_iterations + '</td>';
				html += '<td>' + session.tokens_used + ' / ' + session.token_budget + ' (' + tokenProgress + '%)</td>';
				html += '<td>' + elapsed + '</td>';
				html += '<td>' + this.getSessionActions(session) + '</td>';
				html += '</tr>';
			});

			$tbody.html(html);
		},

		updateActivityFeed: function(activity) {
			const $feed = $('#activity-feed');
			
			if (activity.length === 0) {
				$feed.html('<div class="no-activity">No recent activity</div>');
				return;
			}

			let html = '';
			activity.forEach(item => {
				const time = new Date(item.timestamp * 1000).toLocaleString();
				html += '<div class="activity-item activity-' + item.type + '">';
				html += '<span class="activity-time">' + time + '</span>';
				html += '<span class="activity-message">' + this.escapeHtml(item.message) + '</span>';
				html += '</div>';
			});

			$feed.html(html);
		},

		getHealthIcon: function(health) {
			switch (health) {
				case 'healthy': return '✅';
				case 'warning': return '⚠️';
				case 'critical': return '🚨';
				default: return '❓';
			}
		},

		getSessionActions: function(session) {
			let actions = '';
			
			if (session.status === 'active') {
				actions += '<button class="button button-small session-action" data-session="' + session.session_id + '" data-action="pause">⏸ Pause</button> ';
			} else if (session.status === 'paused') {
				actions += '<button class="button button-small session-action" data-session="' + session.session_id + '" data-action="resume">▶ Resume</button> ';
			}
			
			actions += '<button class="button button-small button-link-delete session-action" data-session="' + session.session_id + '" data-action="stop">⏹ Stop</button>';
			
			return actions;
		},

		handleSessionAction: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			const sessionId = $button.data('session');
			const actionType = $button.data('action');

			$button.prop('disabled', true);

			$.ajax({
				url: this.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_control_session',
					nonce: this.config.nonce,
					session_id: sessionId,
					action_type: actionType
				},
				success: (response) => {
					if (response.success) {
						this.loadDashboardData();
					}
				},
				complete: () => {
					$button.prop('disabled', false);
				}
			});
		},

		formatElapsedTime: function(startTime) {
			const elapsed = Math.floor(Date.now() / 1000) - startTime;
			const hours = Math.floor(elapsed / 3600);
			const minutes = Math.floor((elapsed % 3600) / 60);
			const seconds = elapsed % 60;

			if (hours > 0) {
				return hours + 'h ' + minutes + 'm';
			} else if (minutes > 0) {
				return minutes + 'm ' + seconds + 's';
			} else {
				return seconds + 's';
			}
		},

		escapeHtml: function(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	};

	// Initialize on document ready.
	$(document).ready(function() {
		if ($('.wp-mcp-ai-orchestration-dashboard').length) {
			OrchestrationDashboard.init();
		}
	});

})(jQuery);
