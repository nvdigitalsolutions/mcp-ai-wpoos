/**
 * Token Manager Charts Integration
 *
 * Handles Chart.js visualizations for the Token Manager section.
 * Charts are initialized with placeholder configurations and can be
 * populated with data via AJAX when the backend endpoints are implemented.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Token Manager Charts object.
	 */
	var TokenCharts = {
		/**
		 * Chart instances.
		 */
		charts: {},

		/**
		 * Initialize charts.
		 */
		init: function() {
			this.initUsageTrendChart();
			this.initTierDistributionChart();
			this.bindEvents();
		},

		/**
		 * Initialize usage trend line chart.
		 */
		initUsageTrendChart: function() {
			var canvas = document.getElementById('wp-mcp-ai-usage-trend-chart');
			if (!canvas) {
				return;
			}

			var ctx = canvas.getContext('2d');
			
			// Initial configuration - can be populated with data from server
			var config = {
				type: 'line',
				data: {
					labels: [],
					datasets: []
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: true,
							position: 'top'
						},
						title: {
							display: true,
							text: 'Token Usage Trend (7 Days)'
						}
					},
					scales: {
						y: {
							beginAtZero: true,
							title: {
								display: true,
								text: 'Tokens'
							}
						}
					}
				}
			};

			this.charts.usageTrend = new Chart(ctx, config);
			this.loadUsageTrendData();
		},

		/**
		 * Initialize tier distribution pie chart.
		 */
		initTierDistributionChart: function() {
			var canvas = document.getElementById('wp-mcp-ai-tier-distribution-chart');
			if (!canvas) {
				return;
			}

			var ctx = canvas.getContext('2d');
			
			// Initial configuration - can be populated with data from server
			var config = {
				type: 'pie',
				data: {
					labels: ['Free', 'Pro', 'Enterprise'],
					datasets: [{
						data: [0, 0, 0],
						backgroundColor: [
							'rgba(54, 162, 235, 0.8)',
							'rgba(75, 192, 192, 0.8)',
							'rgba(153, 102, 255, 0.8)'
						],
						borderColor: [
							'rgba(54, 162, 235, 1)',
							'rgba(75, 192, 192, 1)',
							'rgba(153, 102, 255, 1)'
						],
						borderWidth: 1
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: true,
							position: 'right'
						},
						title: {
							display: true,
							text: 'User Tier Distribution'
						}
					}
				}
			};

			this.charts.tierDistribution = new Chart(ctx, config);
			this.loadTierDistributionData();
		},

		/**
		 * Load usage trend data via AJAX.
		 */
		loadUsageTrendData: function() {
			var self = this;

			// Check if chart data configuration is available
			if (typeof wpMcpAiChartData === 'undefined') {
				console.warn('Chart data not available. wpMcpAiChartData not defined.');
				return;
			}

			// TODO: Implement AJAX endpoint for fetching usage trend data
			// When implementing, use the following structure:
			// $.ajax({
			//     url: wpMcpAiChartData.ajaxUrl,
			//     type: 'POST',
			//     data: {
			//         action: 'wp_mcp_ai_get_usage_trend',
			//         nonce: wpMcpAiChartData.nonce,
			//         days: 7
			//     },
			//     success: function(response) {
			//         if (response.success && self.charts.usageTrend) {
			//             self.charts.usageTrend.data = response.data;
			//             self.charts.usageTrend.update();
			//         }
			//     }
			// });
		},

		/**
		 * Load tier distribution data via AJAX.
		 */
		loadTierDistributionData: function() {
			var self = this;

			// Check if chart data configuration is available
			if (typeof wpMcpAiChartData === 'undefined') {
				console.warn('Chart data not available. wpMcpAiChartData not defined.');
				return;
			}

			// TODO: Implement AJAX endpoint for fetching tier distribution data
			// When implementing, use the following structure:
			// $.ajax({
			//     url: wpMcpAiChartData.ajaxUrl,
			//     type: 'POST',
			//     data: {
			//         action: 'wp_mcp_ai_get_tier_distribution',
			//         nonce: wpMcpAiChartData.nonce
			//     },
			//     success: function(response) {
			//         if (response.success && self.charts.tierDistribution) {
			//             self.charts.tierDistribution.data.datasets[0].data = response.data.values;
			//             self.charts.tierDistribution.update();
			//         }
			//     }
			// });
		},

		/**
		 * Bind UI events.
		 */
		bindEvents: function() {
			// Refresh charts when time period changes
			$(document).on('change', '.wp-mcp-ai-chart-period-select', function() {
				var period = $(this).val();
				TokenCharts.refreshCharts(period);
			});
		},

		/**
		 * Refresh all charts with new data.
		 *
		 * @param {string} period Time period (7d, 30d, 90d)
		 */
		refreshCharts: function(period) {
			this.loadUsageTrendData();
			// Tier distribution doesn't change with time period
		}
	};

	/**
	 * Initialize when DOM is ready.
	 */
	$(document).ready(function() {
		// Only initialize if Chart.js is loaded
		if (typeof Chart !== 'undefined') {
			TokenCharts.init();
		} else {
			console.warn('Chart.js library not loaded. Token Manager charts disabled.');
		}
	});

})(jQuery);
