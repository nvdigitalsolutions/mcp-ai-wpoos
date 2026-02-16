/**
 * Analytics Anomalies Widget Chart Initialization
 *
 * Handles Chart.js scatter plot for anomaly detection with Z-score analysis.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Initialize anomaly scatter chart.
	 */
	function initializeAnomalyChart() {
		if (typeof Chart === 'undefined' || !window.wpMcpAiAnomalyData) {
			return;
		}

		const anomalyData = window.wpMcpAiAnomalyData;
		const ctx = document.getElementById('wp-mcp-ai-anomaly-scatter-chart');

		if (!ctx || !anomalyData.anomalies) {
			return;
		}

		// Prepare scatter plot data.
		const scatterData = anomalyData.anomalies.map(function(anomaly, index) {
			return {
				x: index,
				y: parseFloat(anomaly.z_score),
				date: anomaly.date,
				tokens: anomaly.tokens,
				severity: anomaly.severity
			};
		});

		// Color points by severity.
		const pointColors = scatterData.map(function(point) {
			return anomalyData.severityColors[point.severity] || '#666';
		});

		new Chart(ctx.getContext('2d'), {
			type: 'scatter',
			data: {
				datasets: [{
					label: anomalyData.labels.anomalies,
					data: scatterData,
					backgroundColor: pointColors,
					borderColor: pointColors,
					pointRadius: 6,
					pointHoverRadius: 8
				}, {
					label: anomalyData.labels.thresholdPositive,
					data: [
						{x: 0, y: anomalyData.threshold},
						{x: scatterData.length - 1, y: anomalyData.threshold}
					],
					borderColor: 'rgba(255, 99, 132, 0.5)',
					borderDash: [5, 5],
					borderWidth: 2,
					pointRadius: 0,
					showLine: true,
					fill: false
				}, {
					label: anomalyData.labels.thresholdNegative,
					data: [
						{x: 0, y: -anomalyData.threshold},
						{x: scatterData.length - 1, y: -anomalyData.threshold}
					],
					borderColor: 'rgba(255, 99, 132, 0.5)',
					borderDash: [5, 5],
					borderWidth: 2,
					pointRadius: 0,
					showLine: true,
					fill: false
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
					title: {
						display: true,
						text: anomalyData.labels.chartTitle
					},
					tooltip: {
						callbacks: {
							label: function(context) {
								if (context.datasetIndex === 0) {
									const point = context.raw;
									return [
										'Date: ' + point.date,
										'Z-Score: ' + point.y.toFixed(2),
										'Tokens: ' + point.tokens.toLocaleString(),
										'Severity: ' + point.severity
									];
								}
								return '';
							}
						}
					}
				},
				scales: {
					y: {
						title: {
							display: true,
							text: anomalyData.labels.yAxisTitle
						}
					},
					x: {
						title: {
							display: true,
							text: anomalyData.labels.xAxisTitle
						}
					}
				}
			}
		});
	}

	$(document).ready(initializeAnomalyChart);

})(jQuery);
