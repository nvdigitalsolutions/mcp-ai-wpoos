/**
 * Analytics Dashboard JavaScript
 *
 * Handles interactions and chart updates for the WP oOS Analytics Dashboard.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Analytics Dashboard object.
	 */
	var AnalyticsDashboard = {
		/**
		 * Chart instances.
		 */
		charts: {},

		/**
		 * Initialize the dashboard.
		 */
		init: function() {
			// Charts are initialized inline in widget templates.
			// This file handles additional interactions and updates.
			this.bindEvents();
		},

		/**
		 * Bind UI events.
		 */
		bindEvents: function() {
			var self = this;

			// Refresh chart data when requested.
			$(document).on('click', '.wp-mcp-ai-refresh-chart', function(e) {
				e.preventDefault();
				var chartId = $(this).data('chart-id');
				self.refreshChart(chartId);
			});

			// Handle chart period changes.
			$(document).on('change', '.wp-mcp-ai-chart-period', function() {
				var chartId = $(this).data('chart-id');
				var period = $(this).val();
				self.updateChartPeriod(chartId, period);
			});

			// Export chart as PNG.
			$(document).on('click', '.wp-mcp-ai-export-chart', function(e) {
				e.preventDefault();
				var chartId = $(this).data('chart-id');
				self.exportChart(chartId);
			});
		},

		/**
		 * Refresh chart data via AJAX.
		 *
		 * @param {string} chartId Chart identifier.
		 */
		refreshChart: function(chartId) {
			if (!wpMcpAiAnalytics) {
				console.error('wpMcpAiAnalytics not defined.');
				return;
			}

			var self = this;

			$.ajax({
				url: wpMcpAiAnalytics.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_refresh_chart',
					nonce: wpMcpAiAnalytics.nonce,
					chart_id: chartId
				},
				beforeSend: function() {
					$('.wp-mcp-ai-chart-loading').show();
				},
				success: function(response) {
					if (response.success && response.data) {
						self.updateChartData(chartId, response.data);
					}
				},
				error: function() {
					console.error('Failed to refresh chart data.');
				},
				complete: function() {
					$('.wp-mcp-ai-chart-loading').hide();
				}
			});
		},

		/**
		 * Update chart period.
		 *
		 * @param {string} chartId Chart identifier.
		 * @param {string} period Time period (7d, 30d, 90d).
		 */
		updateChartPeriod: function(chartId, period) {
			if (!wpMcpAiAnalytics) {
				console.error('wpMcpAiAnalytics not defined.');
				return;
			}

			var self = this;

			$.ajax({
				url: wpMcpAiAnalytics.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_update_chart_period',
					nonce: wpMcpAiAnalytics.nonce,
					chart_id: chartId,
					period: period
				},
				success: function(response) {
					if (response.success && response.data) {
						self.updateChartData(chartId, response.data);
					}
				},
				error: function() {
					console.error('Failed to update chart period.');
				}
			});
		},

		/**
		 * Update chart data.
		 *
		 * @param {string} chartId Chart identifier.
		 * @param {Object} data New chart data.
		 */
		updateChartData: function(chartId, data) {
			var chart = this.charts[chartId];
			if (chart && data) {
				chart.data = data;
				chart.update();
			}
		},

		/**
		 * Export chart as PNG.
		 *
		 * @param {string} chartId Chart identifier.
		 */
		exportChart: function(chartId) {
			var chart = this.charts[chartId];
			if (!chart) {
				console.error('Chart not found:', chartId);
				return;
			}

			// Get canvas element.
			var canvas = chart.canvas;
			if (!canvas) {
				console.error('Canvas not found for chart:', chartId);
				return;
			}

			// Convert to data URL.
			var url = canvas.toDataURL('image/png');

			// Create download link.
			var link = document.createElement('a');
			link.download = chartId + '-' + Date.now() + '.png';
			link.href = url;
			link.click();
		},

		/**
		 * Register a chart instance.
		 *
		 * @param {string} chartId Chart identifier.
		 * @param {Object} chartInstance Chart.js instance.
		 */
		registerChart: function(chartId, chartInstance) {
			this.charts[chartId] = chartInstance;
		}
	};

	/**
	 * Initialize when DOM is ready.
	 */
	$(document).ready(function() {
		// Only initialize if Chart.js is loaded.
		if (typeof Chart !== 'undefined') {
			AnalyticsDashboard.init();
		} else {
			console.warn('Chart.js library not loaded. Analytics dashboard disabled.');
		}
	});

	// Expose to global scope for widget templates.
	window.WpMcpAiAnalyticsDashboard = AnalyticsDashboard;

})(jQuery);
