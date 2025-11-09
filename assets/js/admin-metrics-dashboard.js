/**
 * Admin Metrics Dashboard JavaScript
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	let trendsChart = null;
	let assistantsChart = null;

	/**
	 * Initialize the metrics dashboard.
	 */
	function initDashboard() {
		// Initialize charts.
		initTrendsChart();
		initAssistantsChart();

		// Load initial data.
		refreshAllMetrics();

		// Set up event handlers.
		setupEventHandlers();

		// Auto-refresh every 60 seconds.
		setInterval(refreshAllMetrics, 60000);
	}

	/**
	 * Set up event handlers.
	 */
	function setupEventHandlers() {
		// Refresh button.
		$('#refresh-metrics').on('click', function() {
			refreshAllMetrics();
		});

		// Trends controls.
		$('#trends-period, #trends-metric').on('change', function() {
			updateTrendsChart();
		});

		// Export button.
		$('#export-metrics').on('click', function() {
			exportMetrics();
		});
	}

	/**
	 * Refresh all metrics data.
	 */
	function refreshAllMetrics() {
		updateOverview();
		updateTrendsChart();
		updateAssistantsMetrics();
		updateCostAnalysis();
	}

	/**
	 * Update overview metrics.
	 */
	function updateOverview() {
		$.ajax({
			url: wpMcpAiMetrics.restUrl + 'overview',
			method: 'GET',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', wpMcpAiMetrics.restNonce);
			},
			success: function(response) {
				$('.metrics-cards .metric-card').each(function(index) {
					const $card = $(this);
					const $value = $card.find('.metric-value');

					switch(index) {
						case 0: // Total Requests
							$value.text(formatNumber(response.total_requests));
							break;
						case 1: // Total Tokens
							$value.text(formatNumber(response.total_tokens));
							break;
						case 2: // Avg Response Time
							$value.text(response.avg_response_time + 's');
							break;
						case 3: // Success Rate
							$value.text(response.success_rate + '%');
							break;
					}
				});
			},
			error: function(xhr, status, error) {
				console.error('Error updating overview:', error);
			}
		});
	}

	/**
	 * Initialize trends chart.
	 */
	function initTrendsChart() {
		const ctx = document.getElementById('trends-chart');
		if (!ctx) return;

		trendsChart = new Chart(ctx, {
			type: 'line',
			data: {
				labels: [],
				datasets: [{
					label: 'Token Usage',
					data: [],
					borderColor: 'rgb(75, 192, 192)',
					backgroundColor: 'rgba(75, 192, 192, 0.1)',
					tension: 0.4,
					fill: true
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: {
						display: true,
						position: 'top'
					},
					tooltip: {
						mode: 'index',
						intersect: false
					}
				},
				scales: {
					y: {
						beginAtZero: true,
						ticks: {
							callback: function(value) {
								return formatNumber(value);
							}
						}
					}
				}
			}
		});
	}

	/**
	 * Update trends chart data.
	 */
	function updateTrendsChart() {
		const period = $('#trends-period').val();
		const metric = $('#trends-metric').val();

		$.ajax({
			url: wpMcpAiMetrics.restUrl + 'trends',
			method: 'GET',
			data: {
				period: period,
				metric: metric
			},
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', wpMcpAiMetrics.restNonce);
			},
			success: function(response) {
				if (!trendsChart) return;

				const labels = response.data_points.map(point => point.timestamp);
				const data = response.data_points.map(point => point.value);

				trendsChart.data.labels = labels;
				trendsChart.data.datasets[0].data = data;
				trendsChart.data.datasets[0].label = getMetricLabel(metric);
				trendsChart.update();
			},
			error: function(xhr, status, error) {
				console.error('Error updating trends chart:', error);
			}
		});
	}

	/**
	 * Initialize assistants chart.
	 */
	function initAssistantsChart() {
		const ctx = document.getElementById('assistants-chart');
		if (!ctx) return;

		assistantsChart = new Chart(ctx, {
			type: 'bar',
			data: {
				labels: [],
				datasets: [{
					label: 'Token Usage',
					data: [],
					backgroundColor: 'rgba(54, 162, 235, 0.5)',
					borderColor: 'rgb(54, 162, 235)',
					borderWidth: 1
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: {
						display: false
					}
				},
				scales: {
					y: {
						beginAtZero: true,
						ticks: {
							callback: function(value) {
								return formatNumber(value);
							}
						}
					}
				}
			}
		});
	}

	/**
	 * Update assistants metrics.
	 */
	function updateAssistantsMetrics() {
		const period = $('#trends-period').val();

		$.ajax({
			url: wpMcpAiMetrics.restUrl + 'assistants',
			method: 'GET',
			data: {
				period: period
			},
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', wpMcpAiMetrics.restNonce);
			},
			success: function(response) {
				if (!response.assistants || response.assistants.length === 0) {
					updateAssistantsTable([]);
					return;
				}

				// Update chart.
				if (assistantsChart) {
					const labels = response.assistants.map(a => 'Assistant ' + a.assistant_id);
					const data = response.assistants.map(a => a.tokens);

					assistantsChart.data.labels = labels;
					assistantsChart.data.datasets[0].data = data;
					assistantsChart.update();
				}

				// Update table.
				updateAssistantsTable(response.assistants);
			},
			error: function(xhr, status, error) {
				console.error('Error updating assistants metrics:', error);
			}
		});
	}

	/**
	 * Update assistants table.
	 *
	 * @param {Array} assistants Assistants data.
	 */
	function updateAssistantsTable(assistants) {
		const $tbody = $('#assistants-metrics-table tbody');
		$tbody.empty();

		if (assistants.length === 0) {
			$tbody.append('<tr><td colspan="5">No data available</td></tr>');
			return;
		}

		assistants.forEach(function(assistant) {
			const row = $('<tr>').append(
				$('<td>').text('Assistant ' + assistant.assistant_id),
				$('<td>').text(formatNumber(assistant.requests)),
				$('<td>').text(formatNumber(assistant.tokens)),
				$('<td>').text(assistant.avg_response_time + 's'),
				$('<td>').text(assistant.success_rate + '%')
			);
			$tbody.append(row);
		});
	}

	/**
	 * Update cost analysis.
	 */
	function updateCostAnalysis() {
		$.ajax({
			url: wpMcpAiMetrics.restUrl + 'cost',
			method: 'GET',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', wpMcpAiMetrics.restNonce);
			},
			success: function(response) {
				// Update cost summary.
				const $summary = $('#cost-summary');
				$summary.html(
					'<p><strong>Total Tokens (30d):</strong> ' + formatNumber(response.total_tokens) + '</p>' +
					'<p><strong>Estimated Cost:</strong> $' + response.estimated_cost.toFixed(2) + '</p>' +
					'<p><strong>Rate:</strong> $' + response.cost_per_1k_tokens.toFixed(3) + ' per 1K tokens</p>'
				);

				// Update recommendations.
				const $recommendations = $('#optimization-recommendations');
				$recommendations.empty();

				if (response.recommendations && response.recommendations.length > 0) {
					$recommendations.append('<h3>Optimization Recommendations</h3>');
					response.recommendations.forEach(function(rec) {
						const impactClass = 'impact-' + rec.impact;
						const $card = $('<div>')
							.addClass('recommendation-card ' + impactClass)
							.append(
								$('<h4>').text(rec.title),
								$('<p>').text(rec.description),
								$('<span>').addClass('impact-badge').text('Impact: ' + rec.impact)
							);
						$recommendations.append($card);
					});
				}
			},
			error: function(xhr, status, error) {
				console.error('Error updating cost analysis:', error);
			}
		});
	}

	/**
	 * Export metrics data.
	 */
	function exportMetrics() {
		const format = $('#export-format').val();
		const range = $('#export-range').val();

		$.ajax({
			url: wpMcpAiMetrics.restUrl + 'export',
			method: 'GET',
			data: {
				format: format,
				range: range
			},
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', wpMcpAiMetrics.restNonce);
			},
			success: function(response) {
				let dataStr, dataType;

				if (format === 'csv') {
					dataStr = 'data:text/csv;charset=utf-8,' + encodeURIComponent(response.data);
					dataType = 'text/csv';
				} else {
					dataStr = 'data:application/json;charset=utf-8,' + encodeURIComponent(JSON.stringify(response.data, null, 2));
					dataType = 'application/json';
				}

				const downloadLink = document.createElement('a');
				downloadLink.setAttribute('href', dataStr);
				downloadLink.setAttribute('download', response.filename);
				downloadLink.click();
			},
			error: function(xhr, status, error) {
				console.error('Error exporting metrics:', error);
				alert('Error exporting metrics. Please try again.');
			}
		});
	}

	/**
	 * Get metric label.
	 *
	 * @param {string} metric Metric type.
	 * @return {string} Label.
	 */
	function getMetricLabel(metric) {
		const labels = {
			'tokens': 'Token Usage',
			'requests': 'Request Count',
			'response_time': 'Avg Response Time (s)',
			'errors': 'Error Count'
		};
		return labels[metric] || metric;
	}

	/**
	 * Format number with commas.
	 *
	 * @param {number} num Number to format.
	 * @return {string} Formatted number.
	 */
	function formatNumber(num) {
		return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	// Initialize on document ready.
	$(document).ready(function() {
		if ($('.wp-mcp-ai-metrics-dashboard').length) {
			initDashboard();
		}
	});

})(jQuery);
