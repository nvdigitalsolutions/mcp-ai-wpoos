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
			this.waitForChartJS();
			this.startAutoRefresh();
		},

		/**
		 * Wait for Chart.js to be loaded before initializing charts.
		 */
		waitForChartJS: function() {
			const self = this;
		const restEndpoint = wpMcpAiProDashboard.restUrl + 'mcp-ai/v1/pro/compliance/status';
		
		console.log('Loading compliance data from:', restEndpoint);
			let attempts = 0;
			const maxAttempts = 50; // 5 seconds max wait time

			const checkChartJS = function() {
				if (typeof Chart !== 'undefined') {
				console.log('Chart.js loaded successfully');
					self.initializeCharts();
				} else if (attempts < maxAttempts) {
					attempts++;
					setTimeout(checkChartJS, 100);
				} else {
					console.error('Chart.js failed to load after 5 seconds. Charts will not be displayed.');
					self.showChartError();
				}
			};

			checkChartJS();
		},

		/**
		 * Show error message when charts fail to load.
		 */
		showChartError: function() {
			$('.wp-mcp-ai-chart-container, .wp-mcp-ai-pro-chart-container').each(function() {
				const $container = $(this);
				const $card = $container.closest('.wp-mcp-ai-chart-card');
				const $fallback = $card.find('.wp-mcp-ai-chart-fallback');
				
				// Hide the chart container
				$container.hide();
				
				// Show fallback if available, otherwise show error message
				if ($fallback.length > 0) {
					$fallback.show();
				} else {
					$container.html('<div class="wp-mcp-ai-chart-error"><span class="dashicons dashicons-warning"></span><p>Charts could not be loaded. Please refresh the page.</p></div>').show();
				}
			});
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
			// Add loading indicators to charts
			this.showChartLoading();
			// Animate progress bars on load
			this.animateProgressBars();
			// Initialize tooltips if available
			if (typeof $.fn.tooltip !== 'undefined') {
				$('[data-toggle="tooltip"]').tooltip();
			}
		},

		/**
		 * Show loading state for charts.
		 */
		showChartLoading: function() {
			$('.wp-mcp-ai-chart-container, .wp-mcp-ai-pro-chart-container').each(function() {
				if ($(this).children('canvas').length > 0) {
					$(this).prepend('<div class="wp-mcp-ai-chart-loading"><span class="dashicons dashicons-update"></span><p>Loading chart...</p></div>');
				}
			});
		},

		/**
		 * Hide chart loading indicators.
		 */
		hideChartLoading: function() {
			$('.wp-mcp-ai-chart-loading').fadeOut(300, function() {
				$(this).remove();
			});
		},

		/**
		 * Load compliance data from REST API.
		 */
		loadComplianceData: function() {
			if (!wpMcpAiProDashboard.restUrl) {
			console.warn('REST URL not configured');
				return;
			}

			const self = this;
		const restEndpoint = wpMcpAiProDashboard.restUrl + 'mcp-ai/v1/pro/compliance/status';
		
		console.log('Loading compliance data from:', restEndpoint);

			$.ajax({
				url: wpMcpAiProDashboard.restUrl + 'mcp-ai/v1/pro/compliance/status',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpMcpAiProDashboard.restNonce);
				},
				success: function(data) {
				console.log('Compliance data loaded successfully:', data);
					self.updateDashboardMetrics(data);
				},
			error: function(xhr, status, error) {
				console.error('Failed to load compliance data:', {
					status: status,
					error: error,
					response: xhr.responseText
				});
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
			console.log('Initializing charts...');
			console.log('Chart.js version:', Chart.version);
			console.log('Chart data available:', wpMcpAiProDashboard.chartData);
			
			let chartsInitialized = 0;
			let chartsFailed = 0;
			
			// Controls implementation pie chart
			if (this.initControlsChart()) {
				chartsInitialized++;
			} else {
				chartsFailed++;
			}
			
			// Security metrics line chart
			if (this.initMetricsChart()) {
				chartsInitialized++;
			} else {
				chartsFailed++;
			}
			
			// Risk distribution chart
			if (this.initRiskChart()) {
				chartsInitialized++;
			} else {
				chartsFailed++;
			}
			
			// Hide loading indicators
			this.hideChartLoading();
			
			console.log('Charts initialized:', chartsInitialized, 'failed:', chartsFailed);
			
			if (chartsFailed > 0) {
				console.warn('Some charts failed to initialize. Check canvas elements and Chart.js library.');
			}
		},

		/**
		 * Initialize controls implementation chart.
		 */
		initControlsChart: function() {
			const canvas = document.getElementById('wpMcpAiControlsChart');
			if (!canvas) {
				console.error('Controls chart canvas not found');
				return false;
			}
			if (typeof Chart === 'undefined') {
				console.error('Chart.js is not loaded');
				return false;
			}

			// Get data from PHP if available
			const chartData = wpMcpAiProDashboard.chartData || {};
			const controlsData = chartData.controls || {};

			console.log('Initializing controls chart with data:', controlsData);

			try {
				const ctx = canvas.getContext('2d');
				this.charts.controls = new Chart(ctx, {
					type: 'doughnut',
					data: {
						labels: ['Implemented', 'Partial', 'Planned', 'N/A'],
						datasets: [{
							data: [
								controlsData.implemented || 55,
								controlsData.partial || 24,
								controlsData.planned || 3,
								controlsData.not_applicable || 11
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

				console.log('Controls chart initialized successfully');
				return true;
			} catch (error) {
				console.error('Failed to initialize controls chart:', error);
				return false;
			}
		},

		/**
		 * Initialize security metrics chart.
		 */
		initMetricsChart: function() {
			const canvas = document.getElementById('wpMcpAiMetricsChart');
			if (!canvas) {
				console.error('Metrics chart canvas not found');
				return false;
			}
			if (typeof Chart === 'undefined') {
				console.error('Chart.js is not loaded');
				return false;
			}

			const chartData = wpMcpAiProDashboard.chartData || {};
			const metricsData = chartData.metrics || {};

			console.log('Initializing metrics chart with data:', metricsData);

			try {
				const ctx = canvas.getContext('2d');
				this.charts.metrics = new Chart(ctx, {
					type: 'line',
					data: {
						labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
						datasets: [{
							label: 'Security Incidents',
							data: metricsData.incidents || [5, 3, 2, 4, 1, 2],
							borderColor: '#f44336',
							backgroundColor: 'rgba(244, 67, 54, 0.1)',
							tension: 0.4
						}, {
							label: 'Vulnerabilities Fixed',
							data: metricsData.vulnerabilities_fixed || [8, 12, 10, 15, 14, 12],
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

				console.log('Metrics chart initialized successfully');
				return true;
			} catch (error) {
				console.error('Failed to initialize metrics chart:', error);
				return false;
			}
		},

		/**
		 * Initialize risk distribution chart.
		 */
		initRiskChart: function() {
			const canvas = document.getElementById('wpMcpAiRiskChart');
			if (!canvas) {
				console.error('Risk chart canvas not found');
				return false;
			}
			if (typeof Chart === 'undefined') {
				console.error('Chart.js is not loaded');
				return false;
			}

			// Get data from PHP if available
			const chartData = wpMcpAiProDashboard.chartData || {};
			const risksData = chartData.risks || {};

			console.log('Initializing risk chart with data:', risksData);

			try {
				const ctx = canvas.getContext('2d');
				this.charts.risk = new Chart(ctx, {
					type: 'bar',
					data: {
						labels: ['Critical', 'High', 'Medium', 'Low'],
						datasets: [{
							label: 'Open Risks',
							data: [
								risksData.critical || 0,
								risksData.high || 3,
								risksData.medium || 12,
								risksData.low || 8
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

				console.log('Risk chart initialized successfully');
				return true;
			} catch (error) {
				console.error('Failed to initialize risk chart:', error);
				return false;
			}
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

			console.log('Refreshing dashboard...');
			const self = this;
			const $button = $('.wp-mcp-ai-refresh-dashboard');
			$button.addClass('spinning').prop('disabled', true);

			// Load compliance data and update charts
			if (!wpMcpAiProDashboard.restUrl) {
				console.warn('REST URL not configured, cannot refresh');
				$button.removeClass('spinning').prop('disabled', false);
				return;
			}

			$.ajax({
				url: wpMcpAiProDashboard.restUrl + 'mcp-ai/v1/pro/compliance/status',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpMcpAiProDashboard.restNonce);
				},
				success: function(data) {
					console.log('Dashboard data refreshed successfully:', data);
					self.updateDashboardMetrics(data);
					
					// Show success message
					if ($('.wp-mcp-ai-refresh-success').length === 0) {
						$button.after('<span class="wp-mcp-ai-refresh-success" style="margin-left: 10px; color: #46b450;">✓ Updated</span>');
						setTimeout(function() {
							$('.wp-mcp-ai-refresh-success').fadeOut(function() {
								$(this).remove();
							});
						}, 3000);
					}
				},
				error: function(xhr, status, error) {
					console.error('Failed to refresh dashboard:', {
						status: status,
						error: error,
						response: xhr.responseText
					});
					
					// Show error message
					if ($('.wp-mcp-ai-refresh-error').length === 0) {
						$button.after('<span class="wp-mcp-ai-refresh-error" style="margin-left: 10px; color: #dc3232;">✗ Failed to refresh</span>');
						setTimeout(function() {
							$('.wp-mcp-ai-refresh-error').fadeOut(function() {
								$(this).remove();
							});
						}, 5000);
					}
				},
				complete: function() {
					$button.removeClass('spinning').prop('disabled', false);
					console.log('Dashboard refresh complete');
				}
			});
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
		const restEndpoint = wpMcpAiProDashboard.restUrl + 'mcp-ai/v1/pro/compliance/status';
		
		console.log('Loading compliance data from:', restEndpoint);
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
