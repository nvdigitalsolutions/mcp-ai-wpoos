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

			$button.prop('disabled', true).text(wpMcpAiPerformance.runningText || 'Running...');

			$.ajax({
				url: wpMcpAiPerformance.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_run_performance_test',
					nonce: wpMcpAiPerformance.nonce,
					test_type: testType
				},
				success: function(response) {
					if (response.success) {
						$resultsContainer.show();
						$results.html('<div class="notice notice-info"><p>' + response.data.message + '</p></div>');
					} else {
						alert('Error: ' + (response.data.message || 'Unknown error'));
					}
				},
				error: function() {
					alert('AJAX request failed');
				},
				complete: function() {
					$button.prop('disabled', false).text($button.text().replace('Running...', 'Run ' + testType.charAt(0).toUpperCase() + testType.slice(1) + ' Test'));
				}
			});
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
						// Show component details in an alert for now.
						// TODO: Consider implementing a modal dialog for better UX.
						alert('Component Details:\n\n' + 
							'Component: ' + component + '\n' +
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
