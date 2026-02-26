/**
 * Cost Breakdown Widget Chart Initialization
 *
 * Handles Chart.js doughnut chart for provider cost breakdown.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Initialize cost breakdown chart.
	 */
	function initializeCostChart() {
		if (typeof Chart === 'undefined' || !window.wpMcpAiCostData) {
			return;
		}

		const costData = window.wpMcpAiCostData;
		const ctx = document.getElementById('wp-mcp-ai-dashboard-cost-breakdown');

		if (!ctx || !costData.providers || !costData.costs) {
			return;
		}

		try {
		new Chart(ctx.getContext('2d'), {
			type: 'doughnut',
			data: {
				labels: costData.providers,
				datasets: [{
					data: costData.costs,
					backgroundColor: [
						'rgba(54, 162, 235, 0.8)',
						'rgba(75, 192, 192, 0.8)',
						'rgba(153, 102, 255, 0.8)',
						'rgba(255, 159, 64, 0.8)',
						'rgba(255, 99, 132, 0.8)'
					],
					borderColor: [
						'rgba(54, 162, 235, 1)',
						'rgba(75, 192, 192, 1)',
						'rgba(153, 102, 255, 1)',
						'rgba(255, 159, 64, 1)',
						'rgba(255, 99, 132, 1)'
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
						position: 'bottom'
					},
					title: {
						display: true,
						text: costData.labels.chartTitle
					},
					tooltip: {
						callbacks: {
							label: function(context) {
								let label = context.label || '';
								if (label) {
									label += ': ';
								}
								label += '$' + context.parsed.toFixed(4);
								return label;
							}
						}
					}
				}
			}
		});
		} catch (e) {
			// Chart initialization failed; log error but prevent disruption of other scripts.
			if (window.console && console.error) {
				console.error('WP MCP AI: Cost breakdown chart initialization failed:', e);
			}
		}
	}

	$(document).ready(initializeCostChart);

})(jQuery);
