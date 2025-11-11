/**
 * Performance Monitoring Admin JavaScript
 *
 * Handles performance test execution and data visualization in the admin dashboard.
 *
 * @package WP_MCP_AI
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
					$resultsContainer.show();
					
					if (response.success) {
						let html = '<div class="notice notice-success"><p><strong>✓ ' + response.data.message + '</strong></p></div>';
						
						// Show summary if available
						if (response.data.summary) {
							html += '<div class="test-summary"><p>' + response.data.summary + '</p></div>';
						}
						
						// Show detailed output if available
						if (response.data.output) {
							html += '<details class="test-output"><summary>View Detailed Output</summary><pre>' + 
									PerformanceAdmin.escapeHtml(response.data.output) + '</pre></details>';
						}
						
						$results.html(html);
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
						
						// Show output if available
						if (response.data.output) {
							html += '<details class="test-output"><summary>View Error Output</summary><pre>' + 
									PerformanceAdmin.escapeHtml(response.data.output) + '</pre></details>';
						}
						
						$results.html(html);
					}
				},
				error: function(jqXHR, textStatus) {
					$resultsContainer.show();
					let errorMsg = 'AJAX request failed';
					
					if (textStatus === 'timeout') {
						errorMsg = 'Test execution timed out. This may be a server configuration issue or the test is taking too long.';
					}
					
					$results.html('<div class="notice notice-error"><p><strong>✗ ' + errorMsg + '</strong></p></div>');
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
						let detailsHTML = '<div class="component-details">';
						detailsHTML += '<h3>Component: ' + component + '</h3>';
						detailsHTML += '<p><strong>Trend:</strong> ' + (data.trend || 'N/A') + '</p>';
						detailsHTML += '<p><strong>Avg Response Time:</strong> ' + (data.avg_response_time || 0).toFixed(2) + ' ms</p>';
						detailsHTML += '<p><strong>Avg Memory Usage:</strong> ' + (data.avg_memory_usage || 0).toFixed(2) + ' MB</p>';
						detailsHTML += '<p><strong>Avg DB Queries:</strong> ' + (data.avg_db_queries || 0) + '</p>';
						detailsHTML += '<p><strong>Total Tests:</strong> ' + (data.total_tests || 0) + '</p>';
						detailsHTML += '</div>';

						// Show in a modal or alert for now.
						alert('Component Details:\n\n' + 
							'Trend: ' + (data.trend || 'N/A') + '\n' +
							'Avg Response Time: ' + (data.avg_response_time || 0).toFixed(2) + ' ms\n' +
							'Avg Memory: ' + (data.avg_memory_usage || 0).toFixed(2) + ' MB\n' +
							'Avg DB Queries: ' + (data.avg_db_queries || 0) + '\n' +
							'Total Tests: ' + (data.total_tests || 0)
						);
					}
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
