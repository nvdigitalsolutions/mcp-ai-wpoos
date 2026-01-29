/**
 * Orchestration Dashboard JavaScript
 *
 * Real-time dashboard for monitoring autonomous sessions.
 */
(function($) {
	'use strict';

	const OrchestrationDashboard = {
		refreshInterval: null,
		config: typeof wpMcpAiOrchestration !== 'undefined' ? wpMcpAiOrchestration : {},

		init: function() {
			console.log('OrchestrationDashboard: Initializing...', this.config);
			
			// Check if config is properly loaded.
			if (!this.config.ajaxUrl || !this.config.nonce) {
				console.error('OrchestrationDashboard: Configuration not loaded properly', this.config);
				// Show user-friendly error message on the page.
				$('.wp-mcp-ai-orchestration-dashboard').prepend(
					'<div class="notice notice-error"><p>' +
					'<strong>Configuration Error:</strong> The orchestration dashboard could not load properly. ' +
					'Please check that the plugin is activated correctly and try refreshing the page.' +
					'</p></div>'
				);
				return;
			}
			
			console.log('OrchestrationDashboard: Configuration loaded successfully');
			this.bindEvents();
			this.startAutoRefresh();
			this.loadDashboardData();
		},

		bindEvents: function() {
			// Session control buttons.
			$(document).on('click', '.session-action', this.handleSessionAction.bind(this));
			
			// Workflow trigger buttons.
			$(document).on('click', '.workflow-trigger', this.handleWorkflowTrigger.bind(this));
			
			// Workflow restart buttons.
			$(document).on('click', '.workflow-restart', this.handleWorkflowRestart.bind(this));
			
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
			console.log('OrchestrationDashboard: Loading dashboard data...');
			$.ajax({
				url: this.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_dashboard_data',
					nonce: this.config.nonce
				},
				success: (response) => {
					console.log('OrchestrationDashboard: AJAX response received', response);
					if (response.success && response.data) {
						console.log('OrchestrationDashboard: Updating dashboard with data', response.data);
						this.updateDashboard(response.data);
					} else {
						console.error('Dashboard data load failed:', response);
					}
				},
				error: (xhr, status, error) => {
					console.error('Failed to load dashboard data:', {
						status: status,
						error: error,
						response: xhr.responseText
					});
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

			// Update system status if available.
			if (data.system_status) {
				this.updateSystemStatus(data.system_status);
			}

			// Update sessions table.
			if (data.sessions) {
				this.updateSessionsTable(data.sessions);
			}

			// Update workflows table.
			if (data.workflows) {
				this.updateWorkflowsTable(data.workflows);
			}

			// Update activity feed.
			if (data.activity) {
				this.updateActivityFeed(data.activity);
			}
		},

		/**
		 * Update system status display.
		 *
		 * @param {Object} systemStatus System status data.
		 */
		updateSystemStatus: function(systemStatus) {
			// Update cron status
			if (systemStatus.cron) {
				$('[data-system-status="cron_active"]').text(systemStatus.cron.active || 0);
				$('[data-system-status="cron_pending"]').text(systemStatus.cron.pending || 0);
				$('[data-system-status="cron_failed"]').text(systemStatus.cron.failed || 0);
			}

			// Update async status
			if (systemStatus.async) {
				const asyncStatus = systemStatus.async.status || 'unknown';
				$('[data-system-status="async_status"]')
					.text(asyncStatus)
					.removeClass('status-healthy status-warning status-error')
					.addClass('status-' + asyncStatus);
				$('[data-system-status="async_stuck_jobs"]').text(systemStatus.async.stuck_jobs || 0);
				$('[data-system-status="async_long_running"]').text(systemStatus.async.long_running || 0);
			}

			// Update SSE status
			if (systemStatus.sse) {
				const sseAvailable = systemStatus.sse.available ? 'Yes' : 'No';
				$('[data-system-status="sse_available"]')
					.text(sseAvailable)
					.removeClass('status-yes status-no')
					.addClass('status-' + (systemStatus.sse.available ? 'yes' : 'no'));
				$('[data-system-status="sse_endpoint"]').text(systemStatus.sse.endpoint || 'N/A');
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
			
			// Escape session_id for safe insertion into HTML attributes
			const escapedSessionId = this.escapeHtmlAttribute(session.session_id);
			
			if (session.status === 'active') {
				actions += '<button class="button button-small session-action" data-session="' + escapedSessionId + '" data-action="pause">⏸ Pause</button> ';
			} else if (session.status === 'paused') {
				actions += '<button class="button button-small session-action" data-session="' + escapedSessionId + '" data-action="resume">▶ Resume</button> ';
			}
			
			actions += '<button class="button button-small button-link-delete session-action" data-session="' + escapedSessionId + '" data-action="stop">⏹ Stop</button>';
			
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
		},

		/**
		 * Escape HTML attribute value
		 * 
		 * @param {string} text Text to escape for use in HTML attribute.
		 * @return {string} Escaped text safe for HTML attributes.
		 */
		escapeHtmlAttribute: function(text) {
			return String(text)
				.replace(/&/g, '&amp;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;');
		},

		updateWorkflowsTable: function(workflows) {
			const $tbody = $('#workflows-table-body');
			
			if (workflows.length === 0) {
				$tbody.html('<tr class="no-items"><td colspan="8">' + (this.config.strings.noWorkflows || 'No workflows found') + '</td></tr>');
				return;
			}

			let html = '';
			workflows.forEach(workflow => {
				const stateClass = workflow.is_stale ? 'status-warning' : 'status-' + workflow.state;
				const stateBadge = workflow.is_stale ? 
					'<span class="status-badge ' + stateClass + '" title="Stuck in initialized state">' + workflow.state + ' (STALE)</span>' :
					'<span class="status-badge ' + stateClass + '">' + workflow.state + '</span>';

				html += '<tr' + (workflow.is_stale ? ' class="workflow-stale"' : '') + '>';
				html += '<td><code>' + this.escapeHtml(workflow.workflow_id.substring(0, 20)) + '...</code></td>';
				html += '<td><code>' + this.escapeHtml(workflow.team_id) + '</code></td>';
				html += '<td>' + this.escapeHtml(workflow.task_type) + '</td>';
				html += '<td>' + stateBadge + '</td>';
				html += '<td>' + this.escapeHtml(workflow.age_display) + '</td>';
				html += '<td>' + workflow.tasks_done + ' / ' + workflow.tasks_total + '</td>';
				html += '<td>' + this.escapeHtml(workflow.created_at) + '</td>';
				html += '<td>' + this.getWorkflowActions(workflow) + '</td>';
				html += '</tr>';
			});

			$tbody.html(html);
		},

		getWorkflowActions: function(workflow) {
			let actions = '';
			
			// Escape workflow_id for safe insertion into HTML attributes
			const escapedWorkflowId = this.escapeHtmlAttribute(workflow.workflow_id);
			
			// Show "Continue" button for initialized or failed workflows
			if (workflow.state === 'initialized' || workflow.state === 'failed') {
				const buttonText = workflow.is_stale ? 
					'🚀 ' + (this.config.strings.startWorkflow || 'Continue') :
					'▶ ' + (this.config.strings.startWorkflow || 'Continue');
				const buttonClass = workflow.is_stale ? 'button-primary' : 'button';
				actions += '<button class="button button-small ' + buttonClass + ' workflow-trigger" ' +
					'data-workflow="' + escapedWorkflowId + '" ' +
					'title="Continue this workflow">' +
					buttonText + '</button> ';
			}
			
			// Show "Restart" button for completed or failed workflows
			if (workflow.state === 'completed' || workflow.state === 'failed') {
				actions += '<button class="button button-small workflow-restart" ' +
					'data-workflow="' + escapedWorkflowId + '" ' +
					'title="Restart this workflow from beginning">' +
					'<span class="dashicons dashicons-update"></span> Restart</button>';
			}
			
			// Show running indicator for active workflows
			if (workflow.state === 'running') {
				actions += '<span class="description">' +
					'<span class="dashicons dashicons-update-alt" style="animation: rotation 2s infinite linear;"></span> ' +
					'Running...</span>';
			}
			
			// View details link (future enhancement)
			// actions += '<button class="button button-small button-link workflow-view" data-workflow="' + escapedWorkflowId + '">' + (this.config.strings.viewWorkflow || 'View') + '</button>';
			
			return actions;
		},

		handleWorkflowTrigger: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			const workflowId = $button.data('workflow');

			// Confirm before starting
			if (!confirm(this.config.strings.confirmStart || 'Are you sure you want to start this workflow?')) {
				return;
			}

			$button.prop('disabled', true).text('Starting...');

			$.ajax({
				url: this.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_trigger_workflow',
					nonce: this.config.nonce,
					workflow_id: workflowId
				},
				success: (response) => {
					if (response.success) {
						alert(this.config.strings.workflowStarted || 'Workflow started successfully');
						this.loadDashboardData(); // Reload to show updated state
					} else {
						alert((this.config.strings.workflowError || 'Error starting workflow') + ': ' + (response.data.message || 'Unknown error'));
					}
				},
				error: (xhr, status, error) => {
					alert((this.config.strings.workflowError || 'Error starting workflow') + ': ' + error);
				},
				complete: () => {
					$button.prop('disabled', false).text(this.config.strings.startWorkflow || 'Start Workflow');
				}
			});
		},

		handleWorkflowRestart: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			const workflowId = $button.data('workflow');

			// Confirm before restarting
			if (!confirm('Are you sure you want to restart this workflow? This will reset all tasks and start from the beginning.')) {
				return;
			}

			const originalHtml = $button.html();
			$button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt" style="animation: rotation 2s infinite linear;"></span> Restarting...');

			$.ajax({
				url: this.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_restart_workflow',
					nonce: this.config.nonce,
					workflow_id: workflowId
				},
				success: (response) => {
					if (response.success) {
						alert('Workflow reset successfully! You can now continue it.');
						this.loadDashboardData(); // Reload to show updated state
					} else {
						alert('Error restarting workflow: ' + (response.data.message || 'Unknown error'));
						$button.prop('disabled', false).html(originalHtml);
					}
				},
				error: (xhr, status, error) => {
					alert('Error restarting workflow: ' + error);
					$button.prop('disabled', false).html(originalHtml);
				}
			});
		}
	};

	// Initialize on document ready.
	$(document).ready(function() {
		if ($('.wp-mcp-ai-orchestration-dashboard').length) {
			OrchestrationDashboard.init();
		}
	});

})(jQuery);
