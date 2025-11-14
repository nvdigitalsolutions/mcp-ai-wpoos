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
			// Initialize charts.
			this.initCharts();
			// Bind UI events.
			this.bindEvents();
		},

		/**
		 * Initialize charts.
		 *
		 * Separation of Concerns: Chart initialization logic lives in JS, not in PHP templates.
		 */
		initCharts: function() {
			this.initGaugeChart();
			this.initUsageTrendChart();
		},

		/**
		 * Initialize gauge chart for current usage percentage.
		 */
		initGaugeChart: function() {
			var gaugeCanvas = document.getElementById('wp-mcp-ai-dashboard-usage-gauge');
			if (!gaugeCanvas || typeof Chart === 'undefined') {
				return;
			}

			// Get gauge data from data attribute.
			var gaugeData = $(gaugeCanvas).data('gauge-data');
			if (!gaugeData) {
				return;
			}

			// Create gauge chart (doughnut with half circle).
			var gaugeChart = new Chart(gaugeCanvas.getContext('2d'), {
				type: 'doughnut',
				data: {
					labels: [gaugeData.label || 'Usage', 'Available'],
					datasets: gaugeData.datasets || [{
						data: [0, 100],
						backgroundColor: ['rgba(201, 203, 207, 0.2)', 'rgba(201, 203, 207, 0.2)'],
						borderWidth: 0
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					circumference: 180,
					rotation: 270,
					cutout: '70%',
					plugins: {
						legend: {
							display: false
						},
						tooltip: {
							enabled: false
						}
					}
				}
			});

			// Register gauge chart.
			this.registerChart('wp-mcp-ai-dashboard-usage-gauge', gaugeChart);
		},

		/**
		 * Initialize usage trend line chart.
		 */
		initUsageTrendChart: function() {
			var trendCanvas = document.getElementById('wp-mcp-ai-dashboard-usage-trend');
			if (!trendCanvas || typeof Chart === 'undefined') {
				return;
			}

			// Get chart data from data attribute.
			var chartData = $(trendCanvas).data('chart-data');
			if (!chartData) {
				return;
			}

			// Create chart instance with enhanced tooltips.
			var chart = new Chart(trendCanvas.getContext('2d'), {
				type: 'line',
				data: chartData,
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: {
						mode: 'index',
						intersect: false
					},
					plugins: {
						legend: {
							display: false
						},
						title: {
							display: true,
							text: '7-Day Token Usage Trend'
						},
						tooltip: {
							enabled: true,
							backgroundColor: 'rgba(0, 0, 0, 0.8)',
							titleColor: '#fff',
							bodyColor: '#fff',
							borderColor: '#2271b1',
							borderWidth: 1,
							padding: 12,
							displayColors: false,
							callbacks: {
								title: function(tooltipItems) {
									return tooltipItems[0].label;
								},
								label: function(context) {
									var label = context.dataset.label || '';
									if (label) {
										label += ': ';
									}
									label += new Intl.NumberFormat().format(context.parsed.y) + ' tokens';
									return label;
								},
								afterLabel: function(context) {
									// Add percentage of peak.
									var dataset = context.dataset.data;
									var maxValue = Math.max.apply(null, dataset);
									var percentage = ((context.parsed.y / maxValue) * 100).toFixed(1);
									return 'Peak: ' + percentage + '%';
								}
							}
						}
					},
					scales: {
						y: {
							beginAtZero: true,
							title: {
								display: true,
								text: 'Tokens'
							},
							ticks: {
								callback: function(value) {
									// Format large numbers with K/M suffixes.
									if (value >= 1000000) {
										return (value / 1000000).toFixed(1) + 'M';
									}
									if (value >= 1000) {
										return (value / 1000).toFixed(1) + 'K';
									}
									return value;
								}
							}
						},
						x: {
							grid: {
								display: false
							}
						}
					}
				}
			});

			// Register chart.
			this.registerChart('wp-mcp-ai-dashboard-usage-trend', chart);
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
