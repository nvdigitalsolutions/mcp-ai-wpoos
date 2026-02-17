/**
 * Analytics Patterns Widget Chart Initialization
 *
 * Handles Chart.js initialization for hourly and daily usage pattern visualization.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Initialize pattern charts with provided data.
	 */
	function initializePatternCharts() {
		if (typeof Chart === 'undefined' || !window.wpMcpAiPatternData) {
			return;
		}

		const patternData = window.wpMcpAiPatternData;

		// Hourly Pattern Chart.
		const hourlyCtx = document.getElementById('wp-mcp-ai-hourly-pattern-chart');
		if (hourlyCtx && patternData.hourlyPattern) {
			const hourLabels = [];
			for (let i = 0; i < 24; i++) {
				hourLabels.push(i.toString().padStart(2, '0') + ':00');
			}

			new Chart(hourlyCtx.getContext('2d'), {
				type: 'bar',
				data: {
					labels: hourLabels,
					datasets: [{
						label: patternData.labels.tokensUsed,
						data: patternData.hourlyPattern,
						backgroundColor: 'rgba(54, 162, 235, 0.6)',
						borderColor: 'rgba(54, 162, 235, 1)',
						borderWidth: 1
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
							text: patternData.labels.hourlyTitle
						},
						tooltip: {
							callbacks: {
								label: function(context) {
									return context.parsed.y.toLocaleString() + ' tokens';
								}
							}
						}
					},
					scales: {
						y: {
							beginAtZero: true,
							ticks: {
								callback: function(value) {
									if (value >= 1000) {
										return (value / 1000).toFixed(1) + 'K';
									}
									return value;
								}
							}
						}
					}
				}
			});
		}

		// Daily Pattern Chart.
		const dailyCtx = document.getElementById('wp-mcp-ai-daily-pattern-chart');
		if (dailyCtx && patternData.dailyPattern) {
			new Chart(dailyCtx.getContext('2d'), {
				type: 'bar',
				data: {
					labels: patternData.labels.dayLabels,
					datasets: [{
						label: patternData.labels.tokensUsed,
						data: patternData.dailyPattern,
						backgroundColor: 'rgba(75, 192, 192, 0.6)',
						borderColor: 'rgba(75, 192, 192, 1)',
						borderWidth: 1
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
							text: patternData.labels.dailyTitle
						},
						tooltip: {
							callbacks: {
								label: function(context) {
									return context.parsed.y.toLocaleString() + ' tokens';
								}
							}
						}
					},
					scales: {
						y: {
							beginAtZero: true,
							ticks: {
								callback: function(value) {
									if (value >= 1000) {
										return (value / 1000).toFixed(1) + 'K';
									}
									return value;
								}
							}
						}
					}
				}
			});
		}
	}

	$(document).ready(initializePatternCharts);

})(jQuery);
