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
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Run seeder buttons
			$('#run-seeder-btn, #action-run-seeder').on('click', this.runSeeder.bind(this));

			// Refresh stats button
			$('#refresh-stats-btn').on('click', this.refreshStats.bind(this));
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
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
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
