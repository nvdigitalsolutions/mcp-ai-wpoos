/**
 * NV oOS Pro Dashboard JavaScript
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 */

(function($) {
	'use strict';

	const ProDashboard = {
		charts: {},
		refreshInterval: null,

		/**
		 * Initialize Pro Dashboard functionality.
		 */
		init: function() {
			this.setupEventListeners();
			this.initializeComponents();
			this.loadComplianceData();
			this.initializeCharts();
			this.startAutoRefresh();
		},

		/**
		 * Setup event listeners.
		 */
		setupEventListeners: function() {
			// Add event listeners for Pro Dashboard interactions
			$(document).on('click', '.wp-mcp-ai-pro-notice .notice-dismiss', this.dismissProNotice);
			$(document).on('click', '.wp-mcp-ai-refresh-dashboard', this.refreshDashboard.bind(this));
			$(document).on('click', '.wp-mcp-ai-control-filter', this.filterControls.bind(this));
		},

		/**
		 * Initialize dashboard components.
		 */
		initializeComponents: function() {
			// Animate progress bars on load
			this.animateProgressBars();
			// Initialize tooltips if available
			if (typeof $.fn.tooltip !== 'undefined') {
				$('[data-toggle="tooltip"]').tooltip();
			}
		},

		/**
		 * Load compliance data from REST API.
		 */
		loadComplianceData: function() {
			if (!wpMcpAiProDashboard.restUrl) {
				return;
			}

			const self = this;

			$.ajax({
				url: wpMcpAiProDashboard.restUrl + 'mcp-ai/v1/pro/compliance/status',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpMcpAiProDashboard.restNonce);
				},
				success: function(data) {
					self.updateDashboardMetrics(data);
				},
				error: function(xhr, status, error) {
					console.error('Failed to load compliance data:', error);
				}
			});
		},

		/**
		 * Update dashboard metrics with loaded data.
		 */
		updateDashboardMetrics: function(data) {
			// Update control implementation stats
			if (data.controls) {
				$('.wp-mcp-ai-stat-implemented').text(data.controls.implemented || 0);
				$('.wp-mcp-ai-stat-partial').text(data.controls.partial || 0);
				$('.wp-mcp-ai-stat-planned').text(data.controls.planned || 0);
				$('.wp-mcp-ai-stat-total').text(data.controls.total || 93);
			}

			// Update recent activity if present
			if (data.recent_events && data.recent_events.length > 0) {
				this.updateRecentActivity(data.recent_events);
			}

			// Update charts with new data
			this.updateCharts(data);
		},

		/**
		 * Update recent activity list.
		 */
		updateRecentActivity: function(events) {
			const $list = $('.wp-mcp-ai-activity-list');
			if ($list.length === 0) {
				return;
			}

			$list.empty();
			events.slice(0, 5).forEach(function(event) {
				const $item = $('<li class="wp-mcp-ai-activity-item">');
				$item.append('<span class="wp-mcp-ai-activity-icon dashicons dashicons-' + (event.icon || 'info') + '"></span>');
				$item.append('<span class="wp-mcp-ai-activity-text">' + event.message + '</span>');
				$item.append('<span class="wp-mcp-ai-activity-time">' + event.time + '</span>');
				$list.append($item);
			});
		},

		/**
		 * Initialize Chart.js charts.
		 */
		initializeCharts: function() {
			// Controls implementation pie chart
			this.initControlsChart();
			// Security metrics line chart
			this.initMetricsChart();
			// Risk distribution chart
			this.initRiskChart();
		},

		/**
		 * Initialize controls implementation chart.
		 */
		initControlsChart: function() {
			const canvas = document.getElementById('wpMcpAiControlsChart');
			if (!canvas || typeof Chart === 'undefined') {
				return;
			}

			// Get chart data from localized script variable
			const chartData = wpMcpAiProDashboard.chartData || {};
			const controls = chartData.controls || {
				implemented: 0,
				partial: 0,
				planned: 0,
				not_applicable: 0
			};

			const ctx = canvas.getContext('2d');
			this.charts.controls = new Chart(ctx, {
				type: 'doughnut',
				data: {
					labels: ['Implemented', 'Partial', 'Planned', 'N/A'],
					datasets: [{
						data: [
							controls.implemented,
							controls.partial,
							controls.planned,
							controls.not_applicable
						],
						backgroundColor: [
							'#4caf50',
							'#ff9800',
							'#2196f3',
							'#9e9e9e'
						],
						borderWidth: 2,
						borderColor: '#fff'
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							position: 'bottom'
						},
						title: {
							display: true,
							text: 'Control Implementation Status'
						}
					}
				}
			});
		},

		/**
		 * Initialize security metrics chart.
		 */
		initMetricsChart: function() {
			const canvas = document.getElementById('wpMcpAiMetricsChart');
			if (!canvas || typeof Chart === 'undefined') {
				return;
			}

			// Get chart data from localized script variable
			const chartData = wpMcpAiProDashboard.chartData || {};
			const metrics = chartData.metrics || {
				incidents: [5, 3, 2, 4, 1, 2],
				vulnerabilities_fixed: [8, 12, 10, 15, 14, 12]
			};

			const ctx = canvas.getContext('2d');
			this.charts.metrics = new Chart(ctx, {
				type: 'line',
				data: {
					labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
					datasets: [{
						label: 'Security Incidents',
						data: metrics.incidents,
						borderColor: '#f44336',
						backgroundColor: 'rgba(244, 67, 54, 0.1)',
						tension: 0.4
					}, {
						label: 'Vulnerabilities Fixed',
						data: metrics.vulnerabilities_fixed,
						borderColor: '#4caf50',
						backgroundColor: 'rgba(76, 175, 80, 0.1)',
						tension: 0.4
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							position: 'top'
						},
						title: {
							display: true,
							text: 'Security Metrics Trends (Last 6 Months)'
						}
					},
					scales: {
						y: {
							beginAtZero: true
						}
					}
				}
			});
		},

		/**
		 * Initialize risk distribution chart.
		 */
		initRiskChart: function() {
			const canvas = document.getElementById('wpMcpAiRiskChart');
			if (!canvas || typeof Chart === 'undefined') {
				return;
			}

			// Get chart data from localized script variable
			const chartData = wpMcpAiProDashboard.chartData || {};
			const risks = chartData.risks || {
				critical: 0,
				high: 3,
				medium: 12,
				low: 8
			};

			const ctx = canvas.getContext('2d');
			this.charts.risk = new Chart(ctx, {
				type: 'bar',
				data: {
					labels: ['Critical', 'High', 'Medium', 'Low'],
					datasets: [{
						label: 'Open Risks',
						data: [
							risks.critical,
							risks.high,
							risks.medium,
							risks.low
						],
						backgroundColor: [
							'#f44336',
							'#ff9800',
							'#ffc107',
							'#8bc34a'
						]
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: false
						},
						title: {
							display: true,
							text: 'Risk Distribution by Severity'
						}
					},
					scales: {
						y: {
							beginAtZero: true,
							ticks: {
								stepSize: 1
							}
						}
					}
				}
			});
		},

		/**
		 * Update charts with new data.
		 */
		updateCharts: function(data) {
			if (data.controls && this.charts.controls) {
				this.charts.controls.data.datasets[0].data = [
					data.controls.implemented || 0,
					data.controls.partial || 0,
					data.controls.planned || 0,
					data.controls.not_applicable || 0
				];
				this.charts.controls.update();
			}

			if (data.metrics && this.charts.metrics) {
				this.charts.metrics.data.datasets[0].data = data.metrics.incidents || [];
				this.charts.metrics.data.datasets[1].data = data.metrics.vulnerabilities_fixed || [];
				this.charts.metrics.update();
			}

			if (data.risks && this.charts.risk) {
				this.charts.risk.data.datasets[0].data = [
					data.risks.critical || 0,
					data.risks.high || 0,
					data.risks.medium || 0,
					data.risks.low || 0
				];
				this.charts.risk.update();
			}
		},

		/**
		 * Animate progress bars.
		 */
		animateProgressBars: function() {
			$('.wp-mcp-ai-progress').each(function() {
				const $progress = $(this);
				const targetWidth = $progress.css('width');
				$progress.css('width', '0');
				setTimeout(function() {
					$progress.css('width', targetWidth);
				}, 100);
			});
		},

		/**
		 * Refresh dashboard data.
		 */
		refreshDashboard: function(e) {
			if (e) {
				e.preventDefault();
			}

			const $button = $('.wp-mcp-ai-refresh-dashboard');
			$button.addClass('spinning');

			this.loadComplianceData();

			setTimeout(function() {
				$button.removeClass('spinning');
			}, 1000);
		},

		/**
		 * Filter controls table.
		 */
		filterControls: function(e) {
			e.preventDefault();
			
			const $button = $(e.currentTarget);
			const filter = $button.data('filter');

			$('.wp-mcp-ai-control-filter').removeClass('active');
			$button.addClass('active');

			const $rows = $('.wp-mcp-ai-controls-table tbody tr');

			if (filter === 'all') {
				$rows.show();
			} else {
				$rows.hide();
				$rows.filter('[data-status="' + filter + '"]').show();
			}
		},

		/**
		 * Start auto-refresh interval.
		 */
		startAutoRefresh: function() {
			const self = this;
			// Refresh every 5 minutes
			this.refreshInterval = setInterval(function() {
				self.loadComplianceData();
			}, 300000);
		},

		/**
		 * Stop auto-refresh interval.
		 */
		stopAutoRefresh: function() {
			if (this.refreshInterval) {
				clearInterval(this.refreshInterval);
			}
		},

		/**
		 * Dismiss pro notice.
		 */
		dismissProNotice: function() {
			const $notice = $(this).closest('.wp-mcp-ai-pro-notice');
			$notice.fadeOut();
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
		ProDashboard.init();
	});

	// Cleanup on page unload
	$(window).on('beforeunload', function() {
		ProDashboard.stopAutoRefresh();
	});

})(jQuery);
