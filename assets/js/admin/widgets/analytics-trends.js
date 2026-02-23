/**
 * Analytics Trends Widget Chart Initialization
 *
 * Handles Chart.js initialization for usage trend visualization with linear regression.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Initialize trend chart with provided data.
	 */
	function initializeTrendChart() {
		if (typeof Chart === 'undefined' || !window.wpMcpAiTrendData) {
			return;
		}

		const trendData = window.wpMcpAiTrendData;
		const ctx = document.getElementById('wp-mcp-ai-analytics-trend-chart');

		if (!ctx || !trendData.dailyUsage || !trendData.trend) {
			return;
		}

		// Prepare data points for chart.
		const labels = [];
		const dataPoints = [];
		const trendLine = [];
		let dayIndex = 0;

		for (const date in trendData.dailyUsage) {
			labels.push(date);
			dataPoints.push(trendData.dailyUsage[date]);

			// Calculate trend line point: y = slope * x + intercept
			const trendValue = trendData.trend.slope * dayIndex + trendData.trend.intercept;
			trendLine.push(Math.max(0, trendValue));
			dayIndex++;
		}

		try {
		new Chart(ctx.getContext('2d'), {
			type: 'line',
			data: {
				labels: labels,
				datasets: [
					{
						label: trendData.labels.actualUsage,
						data: dataPoints,
						borderColor: 'rgba(54, 162, 235, 1)',
						backgroundColor: 'rgba(54, 162, 235, 0.1)',
						fill: true,
						tension: 0.4,
						pointRadius: 3,
						pointHoverRadius: 5
					},
					{
						label: trendData.labels.trendLine,
						data: trendLine,
						borderColor: 'rgba(255, 99, 132, 1)',
						borderDash: [5, 5],
						borderWidth: 2,
						fill: false,
						pointRadius: 0
					}
				]
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
						text: trendData.labels.chartTitle
					},
					tooltip: {
						callbacks: {
							label: function(context) {
								let label = context.dataset.label || '';
								if (label) {
									label += ': ';
								}
								label += context.parsed.y.toLocaleString() + ' tokens';
								return label;
							}
						}
					}
				},
				scales: {
					y: {
						beginAtZero: true,
						ticks: {
							callback: function(value) {
								if (value >= 1000000) {
									return (value / 1000000).toFixed(1) + 'M';
								} else if (value >= 1000) {
									return (value / 1000).toFixed(1) + 'K';
								}
								return value;
							}
						}
					},
					x: {
						ticks: {
							maxRotation: 45,
							minRotation: 45
						}
					}
				}
			}
		});
		} catch (e) {
			// Chart initialization failed; log error but prevent disruption of other scripts.
			if (window.console && console.error) {
				console.error('WP MCP AI: Analytics trends chart initialization failed:', e);
			}
		}
	}

	$(document).ready(initializeTrendChart);

})(jQuery);
