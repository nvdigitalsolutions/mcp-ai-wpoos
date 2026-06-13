/**
 * Performance Monitoring Admin JavaScript
 *
 * Handles performance test execution and data visualization in the admin dashboard.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

(function($) {
	'use strict';

	const PerformanceAdmin = {
		/**
		 * Initialize the admin interface.
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Test execution buttons.
			$('.test-controls button').on('click', this.runTest.bind(this));

			// View details buttons.
			$('.view-details').on('click', this.viewDetails.bind(this));

			// Export buttons.
			$('#export-results-json').on('click', this.exportJSON.bind(this));
			$('#export-results-csv').on('click', this.exportCSV.bind(this));

			// PHPUnit instructions toggle.
			$('#show-phpunit-instructions').on('click', this.togglePHPUnitInstructions.bind(this));
		},

		/**
		 * Toggle PHPUnit installation instructions.
		 *
		 * @param {Event} e Click event.
		 */
		togglePHPUnitInstructions: function(e) {
			const $button = $(e.currentTarget);
			const $instructions = $('#phpunit-instructions');

			$instructions.slideToggle(300);

			// Update button text.
			const isVisible = $instructions.is(':visible');
			const icon = isVisible ? 'arrow-up-alt2' : 'download';
			const text = isVisible ? 'Hide Instructions' : 'How to Enable Full Tests';

			$button.find('.dashicons').removeClass().addClass('dashicons dashicons-' + icon);
			$button.contents().last()[0].textContent = ' ' + text;
		},

		/**
		 * Run a performance test.
		 *
		 * @param {Event} e Click event.
		 */
		runTest: function(e) {
			const $button = $(e.currentTarget);
			const testType = $button.data('test-type');
			const $resultsContainer = $('.test-results-container');
			const $results = $('.test-results');
			const originalText = $button.text();

			// Clear previous results and hide container
			$results.html('');
			$resultsContainer.hide();

			// Update button state
			$button.prop('disabled', true).text(wpMcpAiPerformance.runningText || 'Running...');

			$.ajax({
				url: wpMcpAiPerformance.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_run_performance_test',
					nonce: wpMcpAiPerformance.nonce,
					test_type: testType
				},
				timeout: 65000, // 65 second timeout for test execution
				success: function(response) {
						
						if (response.success) {
							let html = '<div class="notice notice-success"><p><strong>✓ ' + PerformanceAdmin.escapeHtml(response.data.message) + '</strong></p></div>';
							
							// Show notice if present (e.g., when falling back to lightweight checks)
							if (response.data.notice) {
								html += '<div class="notice notice-info inline"><p>' + PerformanceAdmin.escapeHtml(response.data.notice) + '</p></div>';
							}
							
							// Show summary if available
							if (response.data.summary) {
								html += '<div class="test-summary"><p>' + PerformanceAdmin.escapeHtml(response.data.summary) + '</p></div>';
							}
							
							// Show check results from lightweight checks
							if (response.data.test_results && response.data.test_results.checks) {
								html += '<details class="test-output"><summary>View Individual Check Results</summary>';
								html += '<table class="wp-list-table widefat fixed striped"><thead><tr>';
								html += '<th>Check</th><th>Status</th><th>Message</th>';
								html += '</tr></thead><tbody>';
								response.data.test_results.checks.forEach(function(check) {
									const statusClass = check.status === 'pass' ? 'notice-success' : (check.status === 'fail' ? 'notice-error' : 'notice-warning');
									html += '<tr><td>' + PerformanceAdmin.escapeHtml(check.name) + '</td>';
									html += '<td><span class="' + statusClass + '">' + PerformanceAdmin.escapeHtml(check.status) + '</span></td>';
									html += '<td>' + PerformanceAdmin.escapeHtml(check.message || '') + '</td></tr>';
								});
								html += '</tbody></table></details>';
							}
							
							// Show detailed output if available
							if (response.data.output) {
								html += '<details class="test-output"><summary>View Detailed Output</summary><pre>' + 
										PerformanceAdmin.escapeHtml(response.data.output) + '</pre></details>';
							}
							
							$results.html(html);
							$resultsContainer.slideDown(300);
						} else {
							let html = '<div class="notice notice-error"><p><strong>✗ ' + 
									   PerformanceAdmin.escapeHtml(response.data.message) + '</strong></p></div>';
							
							// Show CLI command if available
							if (response.data.cli_command) {
								html += '<div class="cli-command"><p><strong>Run via CLI:</strong></p>' +
										'<code>' + PerformanceAdmin.escapeHtml(response.data.cli_command) + '</code></div>';
							}
							
							// Show setup command if needed
							if (response.data.setup_command) {
								html += '<div class="setup-command"><p><strong>Setup Required:</strong></p>' +
										'<code>' + PerformanceAdmin.escapeHtml(response.data.setup_command) + '</code></div>';
							}
							
							// Show details if available
							if (response.data.details) {
								html += '<div class="test-details"><p>' + PerformanceAdmin.escapeHtml(response.data.details) + '</p></div>';
							}
							
							// Show individual check results even for failed tests
							if (response.data.test_results && response.data.test_results.checks) {
								html += '<details class="test-output"><summary>View Check Results</summary>';
								html += '<table class="wp-list-table widefat fixed striped"><thead><tr>';
								html += '<th>Check</th><th>Status</th><th>Message</th>';
								html += '</tr></thead><tbody>';
								response.data.test_results.checks.forEach(function(check) {
									html += '<tr><td>' + PerformanceAdmin.escapeHtml(check.name) + '</td>';
									html += '<td>' + PerformanceAdmin.escapeHtml(check.status) + '</td>';
									html += '<td>' + PerformanceAdmin.escapeHtml(check.message || '') + '</td></tr>';
								});
								html += '</tbody></table></details>';
							}
							
							// Show output if available
							if (response.data.output) {
								html += '<details class="test-output"><summary>View Error Output</summary><pre>' + 
										PerformanceAdmin.escapeHtml(response.data.output) + '</pre></details>';
							}
							
							$results.html(html);
							$resultsContainer.slideDown(300);
						}
				},
				error: function(jqXHR, textStatus) {
					let errorMsg = 'AJAX request failed';
					
					if (textStatus === 'timeout') {
						errorMsg = 'Test execution timed out. This may be a server configuration issue or the test is taking too long.';
					}
					
					$results.html('<div class="notice notice-error"><p><strong>✗ ' + errorMsg + '</strong></p></div>');
					$resultsContainer.slideDown(300);
				},
				complete: function() {
					$button.prop('disabled', false).text(originalText);
				}
			});
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
		},

		/**
		 * View component details.
		 *
		 * @param {Event} e Click event.
		 */
		viewDetails: function(e) {
			const $button = $(e.currentTarget);
			const component = $button.data('component');

			$button.prop('disabled', true).text('Loading...');

			$.ajax({
				url: wpMcpAiPerformance.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_performance_metrics',
					nonce: wpMcpAiPerformance.nonce,
					component: component
				},
				success: function(response) {
					if (response.success) {
						const data = response.data;
						let detailsHtml = '<div style="text-align:left;">';
						detailsHtml += '<p><strong>Trend:</strong> ' + PerformanceAdmin.escapeHtml(data.trend || 'N/A') + '</p>';
						detailsHtml += '<p><strong>Avg Response Time:</strong> ' + (data.avg_response_time || 0).toFixed(2) + ' ms</p>';
						detailsHtml += '<p><strong>Avg Memory:</strong> ' + (data.avg_memory_usage || 0).toFixed(2) + ' MB</p>';
						detailsHtml += '<p><strong>Avg DB Queries:</strong> ' + (data.avg_db_queries || 0) + '</p>';
						detailsHtml += '<p><strong>Total Tests:</strong> ' + (data.total_tests || 0) + '</p>';
						
						// Show status distribution if available.
						if (data.status_distribution && Object.keys(data.status_distribution).length > 0) {
							detailsHtml += '<p><strong>Status Distribution:</strong></p><ul>';
							Object.keys(data.status_distribution).forEach(function(status) {
								detailsHtml += '<li>' + PerformanceAdmin.escapeHtml(status) + ': ' + data.status_distribution[status] + '</li>';
							});
							detailsHtml += '</ul>';
						}
						detailsHtml += '</div>';
						
						// Replace alert with inline display.
						let $detailDiv = $('#component-detail-' + component);
						if ($detailDiv.length === 0) {
							$detailDiv = $('<div id="component-detail-' + component + '" class="notice notice-info inline" style="margin-top:10px;"></div>');
							$button.closest('tr').after('<tr class="component-detail-row"><td colspan="5"></td></tr>');
							$button.closest('tr').next().find('td').append($detailDiv);
						}
						$detailDiv.html(detailsHtml).slideDown(200);
					} else {
						alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
					}
				},
				error: function() {
					alert('Failed to load component metrics.');
				},
				complete: function() {
					$button.prop('disabled', false).text('View Details');
				}
			});
		},

		/**
		 * Export results as JSON.
		 */
		exportJSON: function() {
			$.ajax({
				url: wpMcpAiPerformance.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_export_test_results',
					nonce: wpMcpAiPerformance.nonce,
					format: 'json'
				},
				success: function(response) {
					if (response.success) {
						const dataStr = JSON.stringify(response.data, null, 2);
						const dataBlob = new Blob([dataStr], {type: 'application/json'});
						const url = URL.createObjectURL(dataBlob);
						const link = document.createElement('a');
						link.href = url;
						link.download = 'wp-mcp-ai-performance-report.json';
						link.click();
						URL.revokeObjectURL(url);
					}
				}
			});
		},

		/**
		 * Export results as CSV.
		 */
		exportCSV: function() {
			alert('CSV export coming soon');
		}
	};

	// Initialize when document is ready.
	$(document).ready(function() {
		PerformanceAdmin.init();
	});

})(jQuery);
