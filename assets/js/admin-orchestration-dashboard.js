/**
 * Orchestration Dashboard Admin JavaScript
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 */

(function($) {
	'use strict';

	const OrchestrationDashboard = {
		autoRefreshInterval: null,
		autoRefreshEnabled: true,
		refreshIntervalMs: 5000, // 5 seconds

		/**
		 * Initialize dashboard interactions.
		 */
		init: function() {
			this.bindEvents();
			this.updateStats(); // Load initial system status data
			this.loadWorkflows();
			this.setupAutoRefresh();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Run seeder buttons
			$('#run-seeder-btn, #action-run-seeder').on('click', this.runSeeder.bind(this));

			// Refresh stats button
			$('#refresh-stats-btn').on('click', this.refreshStats.bind(this));

			// Manual refresh button
			$('#manual-refresh-btn').on('click', this.manualRefresh.bind(this));

			// Auto-refresh toggle
			$('#toggle-auto-refresh').on('change', this.toggleAutoRefresh.bind(this));

			// Refresh workflows button
			$('#refresh-workflows-btn').on('click', this.loadWorkflows.bind(this));

			// Workflow action buttons (delegated for dynamically created elements)
			$(document).on('click', '.workflow-action-continue', this.handleContinueWorkflow.bind(this));
			$(document).on('click', '.workflow-action-restart', this.handleRestartWorkflow.bind(this));
		},

		/**
		 * Setup auto-refresh functionality.
		 */
		setupAutoRefresh: function() {
			const self = this;
			
			// Check if auto-refresh is enabled
			const toggleCheckbox = $('#toggle-auto-refresh');
			if (toggleCheckbox.length && toggleCheckbox.is(':checked')) {
				this.autoRefreshEnabled = true;
				this.startAutoRefresh();
			}
		},

		/**
		 * Start auto-refresh interval.
		 */
		startAutoRefresh: function() {
			const self = this;
			
			if (this.autoRefreshInterval) {
				clearInterval(this.autoRefreshInterval);
			}

			this.autoRefreshInterval = setInterval(function() {
				if (self.autoRefreshEnabled) {
					self.refreshStatsAndWorkflows();
				}
			}, this.refreshIntervalMs);
		},

		/**
		 * Stop auto-refresh interval.
		 */
		stopAutoRefresh: function() {
			if (this.autoRefreshInterval) {
				clearInterval(this.autoRefreshInterval);
				this.autoRefreshInterval = null;
			}
		},

		/**
		 * Toggle auto-refresh on/off.
		 */
		toggleAutoRefresh: function(e) {
			this.autoRefreshEnabled = $(e.currentTarget).is(':checked');
			
			if (this.autoRefreshEnabled) {
				this.startAutoRefresh();
			} else {
				this.stopAutoRefresh();
			}
		},

		/**
		 * Manual refresh button handler.
		 */
		manualRefresh: function(e) {
			e.preventDefault();
			this.refreshStatsAndWorkflows();
		},

		/**
		 * Refresh both stats and workflows without page reload.
		 */
		refreshStatsAndWorkflows: function() {
			this.updateStats();
			this.loadWorkflows();
		},

		/**
		 * Update statistics without page reload.
		 */
		updateStats: function() {
			const self = this;

			$.ajax({
				url: wpMcpAiOrchestration.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_orchestration_stats',
					nonce: wpMcpAiOrchestration.nonce
				},
				success: function(response) {
					console.log('[Admin Dashboard] AJAX response received:', response);
					
					if (response.success && response.data) {
						const stats = response.data;
						
						console.log('[Admin Dashboard] Stats data:', stats);
						console.log('[Admin Dashboard] Has system_status:', !!stats.system_status);
						if (stats.system_status) {
							console.log('[Admin Dashboard] System status keys:', Object.keys(stats.system_status));
							console.log('[Admin Dashboard] System status data:', stats.system_status);
						}
						
						// Update stat cards
						$('[data-stat="total_professions"]').text(stats.total_professions || 0);
						$('[data-stat="seeded_professions"]').text(stats.seeded_professions || 0);
						$('[data-stat="with_task_patterns"]').text(stats.with_task_patterns || 0);
						
						// Update system status if available
						if (stats.system_status) {
							self.updateSystemStatus(stats.system_status);
						} else {
							console.warn('[Admin Dashboard] No system_status in response');
						}
						
						// Update last refresh time
						self.updateLastRefreshTime();
					} else {
						console.warn('[Admin Dashboard] Response not successful or no data', response);
					}
				},
				error: function(xhr, status, error) {
					console.error('[Orchestration Dashboard] Error updating stats:', error);
				}
			});
		},

		/**
		 * Update system status display.
		 *
		 * @param {Object} systemStatus System status data.
		 */
		updateSystemStatus: function(systemStatus) {
			console.log('[Admin Dashboard] updateSystemStatus called with:', systemStatus);
			
			// Update cron status
			if (systemStatus.cron) {
				console.log('[Admin Dashboard] Updating cron status:', systemStatus.cron);
				
				// Defensive check - verify elements exist
				const $cronActive = $('[data-system-status="cron_active"]');
				const $cronPending = $('[data-system-status="cron_pending"]');
				const $cronFailed = $('[data-system-status="cron_failed"]');
				
				console.log('[Admin Dashboard] Found cron elements:', {
					active: $cronActive.length,
					pending: $cronPending.length,
					failed: $cronFailed.length
				});
				
				if ($cronActive.length) {
					$cronActive.text(systemStatus.cron.active || 0);
					console.log('[Admin Dashboard] Set cron_active to', systemStatus.cron.active || 0);
				} else {
					console.error('[Admin Dashboard] Element [data-system-status="cron_active"] not found in DOM!');
				}
				
				if ($cronPending.length) {
					$cronPending.text(systemStatus.cron.pending || 0);
					console.log('[Admin Dashboard] Set cron_pending to', systemStatus.cron.pending || 0);
				} else {
					console.error('[Admin Dashboard] Element [data-system-status="cron_pending"] not found in DOM!');
				}
				
				if ($cronFailed.length) {
					$cronFailed.text(systemStatus.cron.failed || 0);
					console.log('[Admin Dashboard] Set cron_failed to', systemStatus.cron.failed || 0);
				} else {
					console.error('[Admin Dashboard] Element [data-system-status="cron_failed"] not found in DOM!');
				}
			} else {
				console.warn('[Admin Dashboard] No cron data in systemStatus');
			}

			// Update async status
			if (systemStatus.async) {
				console.log('[Admin Dashboard] Updating async status:', systemStatus.async);
				
				const asyncStatus = systemStatus.async.status || 'unknown';
				const $asyncStatus = $('[data-system-status="async_status"]');
				const $asyncStuckJobs = $('[data-system-status="async_stuck_jobs"]');
				const $asyncLongRunning = $('[data-system-status="async_long_running"]');
				
				console.log('[Admin Dashboard] Found async elements:', {
					status: $asyncStatus.length,
					stuck_jobs: $asyncStuckJobs.length,
					long_running: $asyncLongRunning.length
				});
				
				if ($asyncStatus.length) {
					$asyncStatus
						.text(asyncStatus)
						.removeClass('status-healthy status-warning status-error')
						.addClass('status-' + asyncStatus);
					console.log('[Admin Dashboard] Set async_status to', asyncStatus);
				} else {
					console.error('[Admin Dashboard] Element [data-system-status="async_status"] not found in DOM!');
				}
				
				if ($asyncStuckJobs.length) {
					$asyncStuckJobs.text(systemStatus.async.stuck_jobs || 0);
					console.log('[Admin Dashboard] Set async_stuck_jobs to', systemStatus.async.stuck_jobs || 0);
				} else {
					console.error('[Admin Dashboard] Element [data-system-status="async_stuck_jobs"] not found in DOM!');
				}
				
				if ($asyncLongRunning.length) {
					$asyncLongRunning.text(systemStatus.async.long_running || 0);
					console.log('[Admin Dashboard] Set async_long_running to', systemStatus.async.long_running || 0);
				} else {
					console.error('[Admin Dashboard] Element [data-system-status="async_long_running"] not found in DOM!');
				}
			} else {
				console.warn('[Admin Dashboard] No async data in systemStatus');
			}

			// Update health status
			if (systemStatus.health) {
				console.log('[Admin Dashboard] Updating health status:', systemStatus.health);
				
				const healthStatus = systemStatus.health.status || 'unknown';
				const $healthStatus = $('[data-system-status="health_status"]');
				const $healthLabel = $('[data-system-status="health_label"]');
				
				console.log('[Admin Dashboard] Found health elements:', {
					status: $healthStatus.length,
					label: $healthLabel.length
				});
				
				if ($healthStatus.length) {
					$healthStatus
						.text(systemStatus.health.icon + ' ' + healthStatus)
						.removeClass('status-healthy status-good status-fair status-poor')
						.addClass('status-' + healthStatus);
					console.log('[Admin Dashboard] Set health_status to', systemStatus.health.icon + ' ' + healthStatus);
				} else {
					console.error('[Admin Dashboard] Element [data-system-status="health_status"] not found in DOM!');
				}
				
				if ($healthLabel.length) {
					$healthLabel.text(systemStatus.health.label || 'Unknown');
					console.log('[Admin Dashboard] Set health_label to', systemStatus.health.label || 'Unknown');
				} else {
					console.error('[Admin Dashboard] Element [data-system-status="health_label"] not found in DOM!');
				}
			} else {
				console.warn('[Admin Dashboard] No health data in systemStatus');
			}

			// Update SSE status
			if (systemStatus.sse) {
				console.log('[Admin Dashboard] Updating SSE status:', systemStatus.sse);
				
				const sseAvailable = systemStatus.sse.available ? 'Yes' : 'No';
				const $sseAvailable = $('[data-system-status="sse_available"]');
				const $sseEndpoint = $('[data-system-status="sse_endpoint"]');
				
				console.log('[Admin Dashboard] Found SSE elements:', {
					available: $sseAvailable.length,
					endpoint: $sseEndpoint.length
				});
				
				if ($sseAvailable.length) {
					$sseAvailable
						.text(sseAvailable)
						.removeClass('status-yes status-no')
						.addClass('status-' + (systemStatus.sse.available ? 'yes' : 'no'));
					console.log('[Admin Dashboard] Set sse_available to', sseAvailable);
				} else {
					console.error('[Admin Dashboard] Element [data-system-status="sse_available"] not found in DOM!');
				}
				
				if ($sseEndpoint.length) {
					$sseEndpoint.text(systemStatus.sse.endpoint || 'N/A');
					console.log('[Admin Dashboard] Set sse_endpoint to', systemStatus.sse.endpoint || 'N/A');
				} else {
					console.error('[Admin Dashboard] Element [data-system-status="sse_endpoint"] not found in DOM!');
				}
			} else {
				console.warn('[Admin Dashboard] No sse data in systemStatus');
			}
			
			console.log('[Admin Dashboard] System status update complete');
		},

		/**
		 * Update last refresh timestamp.
		 */
		updateLastRefreshTime: function() {
			const now = new Date();
			const timeString = now.toLocaleTimeString();
			$('#last-refresh-time').text(timeString);
		},

		/**
		 * Run the orchestration seeder.
		 *
		 * @param {Event} e Click event.
		 */
		runSeeder: function(e) {
			e.preventDefault();

			const $button = $(e.currentTarget);
			const originalText = $button.html();

			// Confirm action
			if (!confirm('This will assign agent roles and task patterns to all professions. Continue?')) {
				return;
			}

			// Show loading state
			$button.prop('disabled', true);
			$button.html('<span class="dashicons dashicons-update-alt" style="animation: rotation 2s infinite linear;"></span> Running...');

			// Make AJAX request
			$.ajax({
				url: wpMcpAiOrchestration.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_run_orchestration_seeder',
					nonce: wpMcpAiOrchestration.nonce,
					force: false
				},
				success: function(response) {
					if (response.success) {
						// Show success message
						$button.html('<span class="dashicons dashicons-yes-alt"></span> Success!');
						
						// Show result details
						const message = response.data.message + '\n\n' +
							'Agent roles seeded: ' + response.data.roles_seeded + '\n' +
							'Task patterns seeded: ' + response.data.patterns_seeded;
						
						alert(message);

						// Reload page after 1 second
						setTimeout(function() {
							window.location.reload();
						}, 1000);
					} else {
						// Show error
						alert('Error: ' + (response.data.message || 'Unknown error'));
						$button.prop('disabled', false);
						$button.html(originalText);
					}
				},
				error: function(xhr, status, error) {
					console.error('AJAX Error:', status, error);
					alert('Error running seeder. Check console for details.');
					$button.prop('disabled', false);
					$button.html(originalText);
				}
			});
		},

		/**
		 * Refresh statistics display.
		 *
		 * @param {Event} e Click event.
		 */
		refreshStats: function(e) {
			e.preventDefault();

			const $button = $(e.currentTarget);
			const originalText = $button.html();

			// Show loading state
			$button.prop('disabled', true);
			$button.html('<span class="dashicons dashicons-update-alt" style="animation: rotation 2s infinite linear;"></span> Refreshing...');

			// Make AJAX request
			$.ajax({
				url: wpMcpAiOrchestration.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_orchestration_stats',
					nonce: wpMcpAiOrchestration.nonce
				},
				success: function(response) {
					if (response.success) {
						// Reload page to show updated stats
						window.location.reload();
					} else {
						alert('Error: ' + (response.data.message || 'Unknown error'));
						$button.prop('disabled', false);
						$button.html(originalText);
					}
				},
				error: function(xhr, status, error) {
					console.error('AJAX Error:', status, error);
					alert('Error refreshing stats. Check console for details.');
					$button.prop('disabled', false);
					$button.html(originalText);
				}
			});
		},

		/**
		 * Load and display recent workflows.
		 *
		 * @param {Event} e Click event (optional).
		 */
		loadWorkflows: function(e) {
			if (e) {
				e.preventDefault();
			}

			const $container = $('#workflows-list-content');
			const $button = $('#refresh-workflows-btn');
			const originalText = $button.html();

			// Show loading state
			$button.prop('disabled', true);
			$button.html('<span class="dashicons dashicons-update-alt" style="animation: rotation 2s infinite linear;"></span> Loading...');
			
			$container.html('<div class="workflows-loading"><span class="spinner is-active"></span><p>Loading workflows...</p></div>');

			// Make AJAX request
			$.ajax({
				url: wpMcpAiOrchestration.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_recent_workflows',
					nonce: wpMcpAiOrchestration.nonce
				},
				success: function(response) {
					if (response.success) {
						OrchestrationDashboard.renderWorkflows(response.data);
					} else {
						$container.html('<div class="workflows-error"><p>Error: ' + (response.data.message || 'Unknown error') + '</p></div>');
					}
					$button.prop('disabled', false);
					$button.html(originalText);
				},
				error: function(xhr, status, error) {
					console.error('AJAX Error:', status, error);
					$container.html('<div class="workflows-error"><p>Error loading workflows. Check console for details.</p></div>');
					$button.prop('disabled', false);
					$button.html(originalText);
				}
			});
		},

		/**
		 * Render workflows table.
		 *
		 * @param {Array} workflows List of workflow objects.
		 */
		renderWorkflows: function(workflows) {
			const $container = $('#workflows-list-content');

			console.log('WP MCP AI: Rendering ' + (workflows ? workflows.length : 0) + ' workflows');

			if (!workflows || workflows.length === 0) {
				$container.html('<div class="workflows-empty"><p>No workflows found. Use the <code>create_agent_team</code> tool to create multi-agent teams, which will appear here as workflows.</p></div>');
				return;
			}

			let html = '<table class="wp-list-table widefat fixed striped workflows-table">';
			html += '<thead><tr>';
			html += '<th class="workflow-id-col">Workflow ID</th>';
			html += '<th class="workflow-type-col">Type</th>';
			html += '<th class="workflow-state-col">State</th>';
			html += '<th class="workflow-progress-col">Progress</th>';
			html += '<th class="workflow-created-col">Created</th>';
			html += '<th class="workflow-updated-col">Updated</th>';
			html += '<th class="workflow-actions-col">Actions</th>';
			html += '</tr></thead><tbody>';

			workflows.forEach(function(workflow) {
				const stateClass = workflow.state === 'completed' ? 'success' : 
								   workflow.state === 'failed' ? 'error' : 
								   workflow.state === 'running' ? 'info' : 
								   workflow.state === 'initialized' ? 'warning' : 'default';
				const progress = workflow.tasks_total > 0 ? Math.round((workflow.tasks_done / workflow.tasks_total) * 100) : 0;
				
				// Escape all user-controllable data to prevent XSS
				const escapedWorkflowId = OrchestrationDashboard.escapeHtml(workflow.workflow_id || '');
				const escapedTeamId = OrchestrationDashboard.escapeHtml(workflow.team_id || '');
				const escapedTaskType = OrchestrationDashboard.escapeHtml(workflow.task_type || 'generic');
				const escapedState = OrchestrationDashboard.escapeHtml(workflow.state || 'unknown');
				
				html += '<tr>';
				html += '<td class="workflow-id"><code>' + escapedWorkflowId + '</code>';
				if (workflow.team_id) {
					html += '<br><small class="description">Team: ' + escapedTeamId + '</small>';
				}
				html += '</td>';
				html += '<td class="workflow-type">' + escapedTaskType + '</td>';
				html += '<td class="workflow-state"><span class="workflow-status-badge status-' + stateClass + '">' + escapedState + '</span></td>';
				html += '<td class="workflow-progress">';
				html += '<div class="progress-bar-container">';
				html += '<div class="progress-bar" style="width: ' + progress + '%;"></div>';
				html += '</div>';
				html += '<span class="progress-text">' + workflow.tasks_done + '/' + workflow.tasks_total + ' (' + progress + '%)</span>';
				html += '</td>';
				html += '<td class="workflow-created">' + OrchestrationDashboard.formatDate(workflow.created_at) + '</td>';
				html += '<td class="workflow-updated">' + OrchestrationDashboard.formatDate(workflow.updated_at) + '</td>';
				html += '<td class="workflow-actions">';
				
				// Show appropriate actions based on state
				if (workflow.state === 'initialized' || workflow.state === 'failed') {
					html += '<button type="button" class="button button-small workflow-action-continue" data-workflow-id="' + escapedWorkflowId + '">';
					html += '<span class="dashicons dashicons-controls-play"></span> Continue';
					html += '</button> ';
				}
				
				if (workflow.state === 'completed' || workflow.state === 'failed') {
					html += '<button type="button" class="button button-small workflow-action-restart" data-workflow-id="' + escapedWorkflowId + '">';
					html += '<span class="dashicons dashicons-update"></span> Restart';
					html += '</button>';
				}
				
				if (workflow.state === 'running') {
					html += '<span class="description"><span class="dashicons dashicons-update-alt" style="animation: rotation 2s infinite linear;"></span> Running...</span>';
				}
				
				// Fallback for unknown states or states without actions
				if (workflow.state !== 'initialized' && workflow.state !== 'failed' && workflow.state !== 'completed' && workflow.state !== 'running') {
					html += '<span class="description">—</span>';
				}
				
				html += '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			$container.html(html);
		},

		/**
		 * Handle Continue Workflow button click.
		 *
		 * @param {Event} e Click event.
		 */
		handleContinueWorkflow: function(e) {
			e.preventDefault();
			
			const $button = $(e.currentTarget);
			const workflowId = $button.data('workflow-id');
			
			if (!workflowId) {
				alert('Invalid workflow ID');
				return;
			}
			
			if (!confirm('Are you sure you want to continue this workflow? This will start executing the remaining tasks.')) {
				return;
			}
			
			const originalHtml = $button.html();
			$button.prop('disabled', true);
			$button.html('<span class="dashicons dashicons-update-alt" style="animation: rotation 2s infinite linear;"></span> Starting...');
			
			$.ajax({
				url: wpMcpAiOrchestration.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_execute_workflow',
					nonce: wpMcpAiOrchestration.nonce,
					workflow_id: workflowId
				},
				success: function(response) {
					if (response.success) {
						// Build success message with metrics if available
						let message = 'Workflow started successfully!';
						if (response.data.metrics) {
							const metrics = response.data.metrics;
							message += '\n\nMetrics:';
							if (metrics.duration) {
								message += '\n• Duration: ' + metrics.duration + 's';
							}
							if (metrics.tasks_executed) {
								message += '\n• Tasks executed: ' + metrics.tasks_executed;
							}
							if (metrics.tokens_used) {
								message += '\n• Tokens used: ' + metrics.tokens_used.toLocaleString();
							}
							if (metrics.estimated_cost) {
								message += '\n• Estimated cost: $' + metrics.estimated_cost.toFixed(4);
							}
						}
						alert(message);
						// Reload workflows to show updated state
						OrchestrationDashboard.loadWorkflows();
					} else {
						let errorMsg = 'Error: ' + (response.data.message || 'Unknown error');
						if (response.data.duration) {
							errorMsg += '\n\nFailed after ' + response.data.duration + 's';
						}
						alert(errorMsg);
						$button.prop('disabled', false);
						$button.html(originalHtml);
					}
				},
				error: function(xhr, status, error) {
					console.error('AJAX Error:', status, error);
					alert('Error starting workflow. Check console for details.');
					$button.prop('disabled', false);
					$button.html(originalHtml);
				}
			});
		},

		/**
		 * Handle Restart Workflow button click.
		 *
		 * @param {Event} e Click event.
		 */
		handleRestartWorkflow: function(e) {
			e.preventDefault();
			
			const $button = $(e.currentTarget);
			const workflowId = $button.data('workflow-id');
			
			if (!workflowId) {
				alert('Invalid workflow ID');
				return;
			}
			
			if (!confirm('Are you sure you want to restart this workflow? This will reset all tasks and start from the beginning.')) {
				return;
			}
			
			const originalHtml = $button.html();
			$button.prop('disabled', true);
			$button.html('<span class="dashicons dashicons-update-alt" style="animation: rotation 2s infinite linear;"></span> Restarting...');
			
			$.ajax({
				url: wpMcpAiOrchestration.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_restart_workflow',
					nonce: wpMcpAiOrchestration.nonce,
					workflow_id: workflowId
				},
				success: function(response) {
					if (response.success) {
						// Build success message with metrics if available
						let message = 'Workflow reset successfully! You can now continue it.';
						if (response.data.metrics) {
							const metrics = response.data.metrics;
							message += '\n\nReset Details:';
							if (metrics.original_state) {
								message += '\n• Previous state: ' + metrics.original_state;
							}
							if (metrics.tasks_reset) {
								message += '\n• Tasks reset: ' + metrics.tasks_reset;
							}
						}
						alert(message);
						// Reload workflows to show updated state
						OrchestrationDashboard.loadWorkflows();
					} else {
						alert('Error: ' + (response.data.message || 'Unknown error'));
						$button.prop('disabled', false);
						$button.html(originalHtml);
					}
				},
				error: function(xhr, status, error) {
					console.error('AJAX Error:', status, error);
					alert('Error restarting workflow. Check console for details.');
					$button.prop('disabled', false);
					$button.html(originalHtml);
				}
			});
		},

		/**
		 * Format date string.
		 *
		 * @param {string} dateString MySQL datetime string.
		 * @return {string} Formatted date.
		 */
		formatDate: function(dateString) {
			if (!dateString) {
				return '—';
			}
			const date = new Date(dateString);
			return date.toLocaleString();
		},

		/**
		 * Escape HTML to prevent XSS.
		 *
		 * @param {string} text Text to escape.
		 * @return {string} Escaped text.
		 */
		escapeHtml: function(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
		console.log('WP MCP AI: Initializing Orchestration Dashboard');
		OrchestrationDashboard.init();
	});

	// Add rotation animation for loading spinner
	const style = document.createElement('style');
	style.textContent = `
		@keyframes rotation {
			from {
				transform: rotate(0deg);
			}
			to {
				transform: rotate(359deg);
			}
		}
	`;
	document.head.appendChild(style);

})(jQuery);
