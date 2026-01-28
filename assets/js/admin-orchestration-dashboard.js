/**
 * Orchestration Dashboard Admin JavaScript
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 */

(function($) {
	'use strict';

	const OrchestrationDashboard = {
		/**
		 * Initialize dashboard interactions.
		 */
		init: function() {
			this.bindEvents();
			this.loadWorkflows();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Run seeder buttons
			$('#run-seeder-btn, #action-run-seeder').on('click', this.runSeeder.bind(this));

			// Refresh stats button
			$('#refresh-stats-btn').on('click', this.refreshStats.bind(this));

			// Refresh workflows button
			$('#refresh-workflows-btn').on('click', this.loadWorkflows.bind(this));

			// Workflow action buttons (delegated for dynamically created elements)
			$(document).on('click', '.workflow-action-continue', this.handleContinueWorkflow.bind(this));
			$(document).on('click', '.workflow-action-restart', this.handleRestartWorkflow.bind(this));
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
