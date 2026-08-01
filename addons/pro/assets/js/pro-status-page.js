/**
 * Pro Status Dashboard JavaScript
 *
 * Handles live-refresh status grid, manual health checks, component
 * visibility toggling, and the uptime history Chart.js graph.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

(function ($, dashboard) {
	'use strict';

	var refreshTimer = null;
	var uptimeChart = null;

	/**
	 * Initialize the status dashboard.
	 */
	function init() {
		// Initial data load.
		loadStatusData();

		// Set up auto-refresh.
		if (dashboard.refreshInterval > 0) {
			refreshTimer = setInterval(loadStatusData, dashboard.refreshInterval);
		}

		// Refresh button.
		$('.wp-mcp-ai-status-refresh-btn').on('click', function () {
			loadStatusData();
		});

		// Health check button.
		$('.wp-mcp-ai-status-health-check-btn').on('click', function () {
			triggerHealthCheck();
		});

		// Delegate toggle click.
		$('#wp-mcp-ai-status-grid').on('change', '.wp-mcp-ai-pro-status-toggle input', function () {
			var $toggle = $(this);
			var slug = $toggle.data('slug');
			var isPublic = $toggle.is(':checked');
			toggleVisibility(slug, isPublic, $toggle);
		});
	}

	/**
	 * Load status data via AJAX.
	 */
	function loadStatusData() {
		$.ajax({
			url: dashboard.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wp_mcp_ai_status_refresh',
				nonce: dashboard.nonce
			},
			success: function (response) {
				if (response.success && response.data) {
					renderStatus(response.data);
				} else {
					showError(response.data && response.data.message ? response.data.message : dashboard.strings.error);
				}
			},
			error: function () {
				showError(dashboard.strings.error);
			}
		});
	}

	/**
	 * Render status data into the dashboard.
	 *
	 * @param {Object} data Status data from the server.
	 */
	function renderStatus(data) {
		// Update overall status banner.
		var overallBanner = $('#wp-mcp-ai-status-overall');
		var statusClass = 'wp-mcp-ai-pro-status-overall--' + (data.overall || 'unknown');
		overallBanner.removeClass().addClass('wp-mcp-ai-pro-status-overall ' + statusClass);

		var indicatorClass = 'wp-mcp-ai-pro-status-indicator--' + (data.overall || 'unknown');
		overallBanner.html(
			'<span class="wp-mcp-ai-pro-status-indicator ' + indicatorClass + '"></span>' +
			'<span>' + getStatusLabel(data.overall) + '</span>'
		);

		// Update last refreshed text.
		var lastText = dashboard.strings.never;
		if (data.last_checked > 0) {
			lastText = dashboard.strings.lastChecked + ' ' + data.last_checked_text + ' ' + dashboard.strings.timeAgo.replace('%s', '');
		}
		$('.wp-mcp-ai-status-last-refreshed').text(lastText);

		// Render component grid.
		renderComponentGrid(data.components || {});

		// Render uptime chart.
		renderUptimeChart(data.history || {});
	}

	/**
	 * Render the component status grid.
	 *
	 * @param {Object} components Map of slug => component data.
	 */
	function renderComponentGrid(components) {
		var grid = $('#wp-mcp-ai-status-grid');
		var slugs = Object.keys(components);

		if (slugs.length === 0) {
			grid.html('<div class="notice notice-info inline"><p>' + dashboard.strings.noComponents + '</p></div>');
			return;
		}

		var html = '';
		slugs.forEach(function (slug) {
			var comp = components[slug];
			var status = comp.status || 'unknown';
			var cardClass = 'wp-mcp-ai-pro-status-card--' + status;

			html += '<div class="wp-mcp-ai-pro-status-card ' + cardClass + '">';

			// Group label.
			if (comp.group) {
				html += '<div class="wp-mcp-ai-pro-status-card-group">' + escapeHtml(comp.group) + '</div>';
			}

			// Header.
			html += '<div class="wp-mcp-ai-pro-status-card-header">';
			html += '<div class="wp-mcp-ai-pro-status-card-title">';
			html += '<span class="wp-mcp-ai-pro-status-indicator wp-mcp-ai-pro-status-indicator--' + status + '"></span>';
			html += escapeHtml(comp.name || slug);
			html += '</div>';
			html += '<span class="wp-mcp-ai-pro-status-badge wp-mcp-ai-pro-status-badge--' + status + '">' + getStatusLabel(status) + '</span>';
			html += '</div>';

			// Message.
			if (comp.message) {
				html += '<div class="wp-mcp-ai-pro-status-card-message">' + escapeHtml(comp.message) + '</div>';
			}

			// Meta.
			html += '<div class="wp-mcp-ai-pro-status-card-meta">';
			html += '<span>' + (comp.checked_at ? dashboard.strings.lastChecked + ' ' + timeAgo(comp.checked_at) : dashboard.strings.never) + '</span>';
			html += '<div class="wp-mcp-ai-pro-status-card-actions">';
			html += '<label class="wp-mcp-ai-pro-status-toggle" title="' + dashboard.strings.public + '">';
			html += '<input type="checkbox" data-slug="' + escapeAttr(slug) + '" ' + (comp.public ? 'checked' : '') + '>';
			html += '<span class="wp-mcp-ai-pro-status-toggle-slider"></span>';
			html += '</label>';
			html += '<span style="font-size:11px;color:#6c757d;">' + (comp.public ? dashboard.strings.public : dashboard.strings.private) + '</span>';
			html += '</div>';
			html += '</div>';

			html += '</div>';
		});

		grid.html(html);
	}

	/**
	 * Render the uptime history chart using Chart.js.
	 *
	 * @param {Object} history Map of date => uptime percentage.
	 */
	function renderUptimeChart(history) {
		var dates = Object.keys(history).sort();
		if (dates.length === 0) {
			return;
		}

		var labels = dates.map(function (d) {
			var date = new Date(d + 'T00:00:00');
			return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
		});

		var data = dates.map(function (d) {
			return parseFloat(history[d]) || 0;
		});

		var ctx = document.getElementById('wp-mcp-ai-uptime-chart');
		if (!ctx) {
			return;
		}

		if (uptimeChart) {
			uptimeChart.destroy();
		}

		uptimeChart = new Chart(ctx, {
			type: 'line',
			data: {
				labels: labels,
				datasets: [{
					label: 'Uptime %',
					data: data,
					borderColor: '#28a745',
					backgroundColor: 'rgba(40, 167, 69, 0.1)',
					fill: true,
					tension: 0.3,
					pointRadius: 2,
					pointHoverRadius: 4
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				scales: {
					y: {
						min: 90,
						max: 100,
						ticks: {
							callback: function (value) {
								return value + '%';
							}
						}
					}
				},
				plugins: {
					legend: {
						display: false
					},
					tooltip: {
						callbacks: {
							label: function (context) {
								return context.parsed.y.toFixed(2) + '%';
							}
						}
					}
				}
			}
		});
	}

	/**
	 * Trigger a manual health check.
	 */
	function triggerHealthCheck() {
		var $btn = $('.wp-mcp-ai-status-health-check-btn');
		var originalText = $btn.html();
		$btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span> ' + dashboard.strings.healthCheckRunning);

		$.ajax({
			url: dashboard.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wp_mcp_ai_status_health_check',
				nonce: dashboard.nonce
			},
			success: function (response) {
				$btn.prop('disabled', false).html(originalText);
				if (response.success) {
					loadStatusData();
				}
			},
			error: function () {
				$btn.prop('disabled', false).html(originalText);
			}
		});
	}

	/**
	 * Toggle a component's public visibility.
	 *
	 * @param {string}  slug     Component slug.
	 * @param {boolean} isPublic New visibility.
	 * @param {jQuery}  $toggle  The toggle element.
	 */
	function toggleVisibility(slug, isPublic, $toggle) {
		$.ajax({
			url: dashboard.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wp_mcp_ai_status_toggle_public',
				nonce: dashboard.nonce,
				slug: slug,
				is_public: isPublic
			},
			success: function (response) {
				if (!response.success) {
					// Revert toggle on failure.
					$toggle.prop('checked', !isPublic);
				}
			},
			error: function () {
				$toggle.prop('checked', !isPublic);
			}
		});
	}

	/**
	 * Get a human-readable status label.
	 *
	 * @param {string} status Status slug.
	 * @return {string} Label.
	 */
	function getStatusLabel(status) {
		var labels = {
			'operational': dashboard.strings.operational,
			'under_maintenance': dashboard.strings.maintenance,
			'degraded_performance': dashboard.strings.degraded,
			'partial_outage': dashboard.strings.partialOutage,
			'major_outage': dashboard.strings.majorOutage
		};
		return labels[status] || status;
	}

	/**
	 * Compute a human-readable "time ago" string.
	 *
	 * @param {number} timestamp Unix timestamp.
	 * @return {string}
	 */
	function timeAgo(timestamp) {
		var seconds = Math.floor((Date.now() / 1000) - timestamp);
		if (seconds < 60) {
			return dashboard.strings.justNow;
		}
		var minutes = Math.floor(seconds / 60);
		if (minutes < 60) {
			return minutes + ' ' + dashboard.strings.timeAgo.replace('%s', dashboard.strings.timeAgo.indexOf('minute') > -1 ? 'min' : 'min');
		}
		var hours = Math.floor(minutes / 60);
		if (hours < 24) {
			return hours + ' ' + dashboard.strings.timeAgo.replace('%s', 'hr');
		}
		var days = Math.floor(hours / 24);
		return days + ' ' + dashboard.strings.timeAgo.replace('%s', 'day' + (days > 1 ? 's' : ''));
	}

	/**
	 * Escape HTML entities.
	 *
	 * @param {string} text Raw text.
	 * @return {string} Escaped text.
	 */
	function escapeHtml(text) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(text));
		return div.innerHTML;
	}

	/**
	 * Escape an HTML attribute value.
	 *
	 * @param {string} text Raw text.
	 * @return {string} Escaped text.
	 */
	function escapeAttr(text) {
		return String(text).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	/**
	 * Show an error message in the overall banner.
	 *
	 * @param {string} message Error message.
	 */
	function showError(message) {
		var banner = $('#wp-mcp-ai-status-overall');
		banner.removeClass().addClass('wp-mcp-ai-pro-status-overall wp-mcp-ai-pro-status-overall--major_outage');
		banner.html('<span>' + escapeHtml(message) + '</span>');
	}

	// Initialize on document ready.
	$(init);

}(jQuery, window.wpMcpAiStatusDashboard || {}));
