/**
 * Token Manager Charts Integration
 *
 * Handles Chart.js visualizations for the Token Manager section.
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
		 * Current period for charts (days).
		 */
		currentPeriod: 7,

		/**
		 * Initialize charts.
		 */
		init: function() {
			this.initUsageTrendChart();
			this.initTierDistributionChart();
			this.initToolBreakdownChart();
			this.initProviderDistributionChart();
			this.initModelDistributionChart();
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
			
			// Initial configuration - will be populated from server data
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
			
			// Initial configuration - will be populated from server data
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
		 * Initialize tool breakdown bar chart.
		 */
		initToolBreakdownChart: function() {
			var canvas = document.getElementById('wp-mcp-ai-tool-breakdown-chart');
			if (!canvas) {
				return;
			}

			var ctx = canvas.getContext('2d');
			
			// Initial configuration - will be populated from server data
			var config = {
				type: 'bar',
				data: {
					labels: [],
					datasets: [{
						label: 'Token Usage by Tool',
						data: [],
						backgroundColor: 'rgba(54, 162, 235, 0.8)',
						borderColor: 'rgba(54, 162, 235, 1)',
						borderWidth: 1
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					indexAxis: 'y',
					plugins: {
						legend: {
							display: false
						},
						title: {
							display: true,
							text: 'Top Tools by Token Usage'
						}
					},
					scales: {
						x: {
							beginAtZero: true,
							title: {
								display: true,
								text: 'Tokens'
							}
						}
					}
				}
			};

			this.charts.toolBreakdown = new Chart(ctx, config);
			this.loadToolBreakdownData();
		},

		/**
		 * Initialize provider distribution pie chart.
		 */
		initProviderDistributionChart: function() {
			var canvas = document.getElementById('wp-mcp-ai-provider-distribution-chart');
			if (!canvas) {
				return;
			}

			var ctx = canvas.getContext('2d');
			
			// Initial configuration - will be populated from server data
			var config = {
				type: 'doughnut',
				data: {
					labels: [],
					datasets: [{
						data: [],
						backgroundColor: [],
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
							text: 'Usage by Provider'
						}
					}
				}
			};

			this.charts.providerDistribution = new Chart(ctx, config);
			this.loadProviderDistributionData();
		},

		/**
		 * Initialize model distribution pie chart.
		 */
		initModelDistributionChart: function() {
			var canvas = document.getElementById('wp-mcp-ai-model-distribution-chart');
			if (!canvas) {
				return;
			}

			var ctx = canvas.getContext('2d');
			
			// Initial configuration - will be populated from server data
			var config = {
				type: 'doughnut',
				data: {
					labels: [],
					datasets: [{
						data: [],
						backgroundColor: [],
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
							text: 'Usage by Model (Top 10)'
						}
					}
				}
			};

			this.charts.modelDistribution = new Chart(ctx, config);
			this.loadModelDistributionData();
		},

		/**
		 * Load usage trend data via AJAX.
		 */
		loadUsageTrendData: function() {
			var self = this;

			// Fetch token usage trend data from the server.
			if (typeof wpMcpAiChartData === 'undefined') {
				console.warn('Chart data not available. wpMcpAiChartData not defined.');
				return;
			}

			// The endpoint should return data in the following format:
			// {
			//   success: true,
			//   data: {
			//     labels: ['2024-01-01', '2024-01-02', ...],  // Array of dates
			//     datasets: [{
			//       label: 'Token Usage',
			//       data: [1000, 1500, 2000, ...],  // Array of token counts per day
			//       borderColor: 'rgba(75, 192, 192, 1)',
			//       backgroundColor: 'rgba(75, 192, 192, 0.2)'
			//     }]
			//   }
			// }
			$.ajax({
				url: wpMcpAiChartData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_usage_trend',
					nonce: wpMcpAiChartData.nonce,
					days: this.currentPeriod
				},
				success: function(response) {
					if (response.success && self.charts.usageTrend) {
						self.charts.usageTrend.data = response.data;
						self.charts.usageTrend.update();
					}
				}
			});
		},

		/**
		 * Load tier distribution data via AJAX.
		 */
		loadTierDistributionData: function() {
			var self = this;

			// Fetch tier distribution data from the server.
			if (typeof wpMcpAiChartData === 'undefined') {
				console.warn('Chart data not available. wpMcpAiChartData not defined.');
				return;
			}

			// The endpoint should return user counts per tier in the following format:
			// {
			//   success: true,
			//   data: {
			//     values: [120, 45, 8],  // Array of user counts for [Free, Pro, Enterprise]
			//     labels: ['Free', 'Pro', 'Enterprise']  // Optional, can use chart's existing labels
			//   }
			// }
			$.ajax({
				url: wpMcpAiChartData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_tier_distribution',
					nonce: wpMcpAiChartData.nonce
				},
				success: function(response) {
					if (response.success && self.charts.tierDistribution) {
						self.charts.tierDistribution.data.datasets[0].data = response.data.values;
						self.charts.tierDistribution.update();
					}
				}
			});
		},

		/**
		 * Load tool breakdown data via AJAX.
		 */
		loadToolBreakdownData: function() {
			var self = this;

			// Fetch tool breakdown data from the server.
			if (typeof wpMcpAiChartData === 'undefined') {
				console.warn('Chart data not available. wpMcpAiChartData not defined.');
				return;
			}

			$.ajax({
				url: wpMcpAiChartData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_tool_breakdown',
					nonce: wpMcpAiChartData.nonce,
					days: this.currentPeriod,
					limit: 10
				},
				success: function(response) {
					if (response.success && self.charts.toolBreakdown) {
						self.charts.toolBreakdown.data.labels = response.data.labels;
						self.charts.toolBreakdown.data.datasets[0].data = response.data.values;
						self.charts.toolBreakdown.update();
					}
				}
			});
		},

		/**
		 * Load provider distribution data via AJAX.
		 */
		loadProviderDistributionData: function() {
			var self = this;

			// Fetch provider distribution data from the server.
			if (typeof wpMcpAiChartData === 'undefined') {
				console.warn('Chart data not available. wpMcpAiChartData not defined.');
				return;
			}

			$.ajax({
				url: wpMcpAiChartData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_provider_distribution',
					nonce: wpMcpAiChartData.nonce
				},
				success: function(response) {
					if (response.success && self.charts.providerDistribution) {
						self.charts.providerDistribution.data.labels = response.data.labels;
						self.charts.providerDistribution.data.datasets[0].data = response.data.values;
						self.charts.providerDistribution.data.datasets[0].backgroundColor = response.data.colors;
						self.charts.providerDistribution.update();
					}
				}
			});
		},

		/**
		 * Load model distribution data via AJAX.
		 */
		loadModelDistributionData: function() {
			var self = this;

			// Fetch model distribution data from the server.
			if (typeof wpMcpAiChartData === 'undefined') {
				console.warn('Chart data not available. wpMcpAiChartData not defined.');
				return;
			}

			$.ajax({
				url: wpMcpAiChartData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_model_distribution',
					nonce: wpMcpAiChartData.nonce,
					limit: 10
				},
				success: function(response) {
					if (response.success && self.charts.modelDistribution) {
						self.charts.modelDistribution.data.labels = response.data.labels;
						self.charts.modelDistribution.data.datasets[0].data = response.data.values;
						self.charts.modelDistribution.data.datasets[0].backgroundColor = response.data.colors;
						self.charts.modelDistribution.update();
					}
				}
			});
		},

		/**
		 * Bind UI events.
		 */
		bindEvents: function() {
			var self = this;
			
			// Refresh charts when time period changes
			$(document).on('change', '.wp-mcp-ai-chart-period-select', function() {
				var period = parseInt($(this).val(), 10);
				self.currentPeriod = period;
				self.refreshCharts();
			});

			// Refresh charts when refresh button is clicked
			$(document).on('click', '#wp-mcp-ai-refresh-charts', function() {
				self.refreshCharts();
			});
		},

		/**
		 * Refresh all charts with new data.
		 */
		refreshCharts: function() {
			// Update chart title with period
			var periodText = this.currentPeriod === 1 ? 'Today' :
							 this.currentPeriod === 7 ? '7 Days' : 
							 this.currentPeriod === 30 ? '30 Days' : '90 Days';
			
			if (this.charts.usageTrend) {
				this.charts.usageTrend.options.plugins.title.text = 'Token Usage Trend (' + periodText + ')';
			}
			
			// Reload data
			this.loadUsageTrendData();
			this.loadToolBreakdownData();
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
