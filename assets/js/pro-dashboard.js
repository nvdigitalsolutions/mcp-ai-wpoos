/**
 * NV oOS Pro Dashboard JavaScript
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 */

(function($) {
	'use strict';

	// Debug: Check if script is loading
	console.log('Pro Dashboard script loaded');
	console.log('jQuery version:', $.fn.jquery);
	console.log('Dashboard config:', window.wpMcpAiProDashboard);

	// Safety check for configuration object
	if (typeof window.wpMcpAiProDashboard === 'undefined') {
		console.error('wpMcpAiProDashboard configuration object not found!');
		return;
	}

	const ProDashboard = {
		charts: {},
		refreshInterval: null,
		lastTabKey: 'wp_mcp_ai_last_dashboard_tab',

		/**
		 * Initialize Pro Dashboard functionality.
		 */
		init: function() {
			console.log('Initializing Pro Dashboard...');
			this.initTabStatePersistence();
			this.initKeyboardShortcuts();
			this.setupEventListeners();
			this.initializeComponents();
			this.loadComplianceData();
			this.waitForChartJS();
			this.startAutoRefresh();
			console.log('Pro Dashboard initialization complete');
		},

		/**
		 * Initialize tab state persistence using localStorage.
		 */
		initTabStatePersistence: function() {
			// Save current tab to localStorage when tab changes
			const self = this;
			$('.nav-tab-wrapper .nav-tab').on('click', function() {
				const href = $(this).attr('href');
				const match = href.match(/[?&]tab=([^&]+)/);
				if (match && match[1]) {
					try {
						localStorage.setItem(self.lastTabKey, match[1]);
					} catch (e) {
						console.warn('Failed to save tab state:', e);
					}
				}
			});

			// Show indicator for previously active tab
			this.highlightLastTab();
		},

		/**
		 * Highlight the last active tab with a subtle indicator.
		 */
		highlightLastTab: function() {
			try {
				const lastTab = localStorage.getItem(this.lastTabKey);
				if (lastTab) {
					const currentUrl = window.location.href;
					const currentTab = currentUrl.match(/[?&]tab=([^&]+)/);
					const isCurrentTab = currentTab && currentTab[1] === lastTab;
					
					if (!isCurrentTab) {
						$('.nav-tab[href*="tab=' + lastTab + '"]').addClass('wp-mcp-ai-recently-visited');
					}
				}
			} catch (e) {
				console.warn('Failed to retrieve last tab:', e);
			}
		},

		/**
		 * Initialize keyboard shortcuts for tab navigation.
		 */
		initKeyboardShortcuts: function() {
			const self = this;
			const tabs = ['iso27001', 'overview', 'reports', 'monitoring', 'risk', 'multi-framework'];
			
			$(document).on('keydown', function(e) {
				// Only activate on Alt+Number (1-6)
				if (e.altKey && !e.ctrlKey && !e.shiftKey) {
					const num = parseInt(String.fromCharCode(e.keyCode));
					if (num >= 1 && num <= tabs.length) {
						e.preventDefault();
						const tabName = tabs[num - 1];
						const url = wpMcpAiProDashboard.restUrl.replace('/wp-json/', '/wp-admin/admin.php?page=nvoos-pro-dashboard&tab=' + tabName);
						window.location.href = url;
					}
				}
				
				// Alt+H for help overlay
				if (e.altKey && e.keyCode === 72) { // 'H' key
					e.preventDefault();
					self.showKeyboardShortcutsHelp();
				}
			});
		},

		/**
		 * Show keyboard shortcuts help overlay.
		 */
		showKeyboardShortcutsHelp: function() {
			if ($('#wp-mcp-ai-shortcuts-help').length > 0) {
				$('#wp-mcp-ai-shortcuts-help').fadeIn();
				return;
			}

			const helpHtml = `
				<div id="wp-mcp-ai-shortcuts-help" class="wp-mcp-ai-modal-overlay">
					<div class="wp-mcp-ai-modal-content">
						<button class="wp-mcp-ai-modal-close" aria-label="Close">×</button>
						<h2>Keyboard Shortcuts</h2>
						<table class="wp-mcp-ai-shortcuts-table">
							<tr><th>Alt + 1</th><td>ISO 27001 Tab</td></tr>
							<tr><th>Alt + 2</th><td>Overview Tab</td></tr>
							<tr><th>Alt + 3</th><td>Reports Tab</td></tr>
							<tr><th>Alt + 4</th><td>Monitoring Tab</td></tr>
							<tr><th>Alt + 5</th><td>Risk Management Tab</td></tr>
							<tr><th>Alt + 6</th><td>Multi-Framework Tab</td></tr>
							<tr><th>Alt + H</th><td>Show this help</td></tr>
							<tr><th>Esc</th><td>Close dialogs</td></tr>
						</table>
					</div>
				</div>
			`;
			
			$('body').append(helpHtml);
			$('#wp-mcp-ai-shortcuts-help').fadeIn();
			
			// Close on click outside or close button
			$('#wp-mcp-ai-shortcuts-help').on('click', function(e) {
				if (e.target === this || $(e.target).hasClass('wp-mcp-ai-modal-close')) {
					$(this).fadeOut();
				}
			});
			
			// Close on Escape key
			$(document).on('keydown.shortcuts-help', function(e) {
				if (e.keyCode === 27) { // Escape
					$('#wp-mcp-ai-shortcuts-help').fadeOut();
					$(document).off('keydown.shortcuts-help');
				}
			});
		},

		/**
		 * Wait for Chart.js to be loaded before initializing charts.
		 */
		waitForChartJS: function() {
			const self = this;
			let attempts = 0;
			const maxAttempts = 50; // 5 seconds max wait time

			const checkChartJS = function() {
				if (typeof Chart !== 'undefined') {
				console.log('Chart.js loaded successfully');
					self.initializeChartsIfNeeded();
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
		 * Check if charts should be initialized (only on overview tab).
		 */
		initializeChartsIfNeeded: function() {
			// Check if we're on the overview tab by looking for chart canvas elements
			const controlsCanvas = document.getElementById('wpMcpAiControlsChart');
			if (controlsCanvas) {
				// Canvas exists, we're on the overview tab, initialize charts
				this.initializeCharts();
			} else {
				// Not on overview tab, skip chart initialization
				console.log('Not on overview tab, skipping chart initialization');
			}
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
			
			// Interactive metric cards
			$(document).on('click', '.wp-mcp-ai-metric-card.interactive', this.handleMetricCardClick.bind(this));
			$(document).on('keypress', '.wp-mcp-ai-metric-card.interactive', function(e) {
				if (e.which === 13 || e.which === 32) { // Enter or Space
					e.preventDefault();
					$(this).click();
				}
			});
			
			// Export functionality
			$(document).on('click', '.wp-mcp-ai-export-dashboard', this.exportDashboard.bind(this));
			$(document).on('click', '.wp-mcp-ai-export-controls', this.exportControls.bind(this));
			$(document).on('click', '.wp-mcp-ai-export-risks', this.exportRisks.bind(this));
			
			// Help indicator
			$(document).on('click', '.wp-mcp-ai-help-indicator', this.showKeyboardShortcutsHelp.bind(this));
		},

		/**
		 * Handle metric card click for drill-down navigation.
		 */
		handleMetricCardClick: function(e) {
			const $card = $(e.currentTarget);
			const metric = $card.data('metric');
			
			// Add visual feedback
			$card.addClass('clicked');
			setTimeout(function() {
				$card.removeClass('clicked');
			}, 200);
			
			// Navigate based on metric type
			switch (metric) {
				case 'implemented':
				case 'partial':
					// Navigate to ISO 27001 tab with filter
					window.location.href = wpMcpAiProDashboard.restUrl.replace('/wp-json/', '/wp-admin/admin.php?page=nvoos-pro-dashboard&tab=iso27001&filter=' + metric);
					break;
				case 'critical':
					// Navigate to Risk Management tab
					window.location.href = wpMcpAiProDashboard.restUrl.replace('/wp-json/', '/wp-admin/admin.php?page=nvoos-pro-dashboard&tab=risk');
					break;
				case 'compliance':
					// Navigate to ISO 27001 tab
					window.location.href = wpMcpAiProDashboard.restUrl.replace('/wp-json/', '/wp-admin/admin.php?page=nvoos-pro-dashboard&tab=iso27001');
					break;
			}
		},

		/**
		 * Export dashboard snapshot.
		 */
		exportDashboard: function(e) {
			e.preventDefault();
			console.log('Exporting dashboard snapshot...');
			
			const $button = $(e.currentTarget);
			$button.prop('disabled', true).addClass('loading');
			
			// Show export options dialog
			this.showExportDialog('dashboard');
			
			$button.prop('disabled', false).removeClass('loading');
		},

		/**
		 * Export controls table to CSV.
		 */
		exportControls: function(e) {
			e.preventDefault();
			console.log('Exporting controls to CSV...');
			
			const $button = $(e.currentTarget);
			$button.prop('disabled', true).addClass('loading');
			
			// Gather controls data from table
			const controls = [];
			$('.wp-mcp-ai-controls-table tbody tr').each(function() {
				const $row = $(this);
				controls.push({
					id: $row.find('td:eq(0)').text().trim(),
					name: $row.find('td:eq(1) strong').text().trim(),
					status: $row.find('td:eq(2)').text().trim(),
					applicable: $row.find('td:eq(3) .dashicons-yes-alt').length > 0 ? 'Yes' : 'No'
				});
			});
			
			// Convert to CSV
			const csv = this.generateCSV(controls);
			this.downloadFile(csv, 'iso27001-controls-' + this.getDateString() + '.csv', 'text/csv');
			
			$button.prop('disabled', false).removeClass('loading');
			this.showSuccessMessage('Controls exported successfully!');
		},

		/**
		 * Export risk register.
		 */
		exportRisks: function(e) {
			e.preventDefault();
			console.log('Exporting risk register...');
			
			const $button = $(e.currentTarget);
			$button.prop('disabled', true).addClass('loading');
			
			// Gather risks data from table
			const risks = [];
			$('.wp-mcp-ai-risk-register table tbody tr').each(function() {
				const $row = $(this);
				if ($row.find('td').length >= 4) {
					risks.push({
						id: $row.find('td:eq(0)').text().trim(),
						description: $row.find('td:eq(1) strong').text().trim(),
						likelihood: $row.find('td:eq(2)').text().trim(),
						impact: $row.find('td:eq(3)').text().trim(),
						level: $row.find('td:eq(4)').text().trim(),
						treatment: $row.find('td:eq(5)').text().trim()
					});
				}
			});
			
			// Convert to CSV
			const csv = this.generateCSV(risks);
			this.downloadFile(csv, 'risk-register-' + this.getDateString() + '.csv', 'text/csv');
			
			$button.prop('disabled', false).removeClass('loading');
			this.showSuccessMessage('Risk register exported successfully!');
		},

		/**
		 * Generate CSV from array of objects.
		 */
		generateCSV: function(data) {
			if (data.length === 0) return '';
			
			// Get headers from first object
			const headers = Object.keys(data[0]);
			
			// Create CSV string
			let csv = headers.join(',') + '\n';
			
			data.forEach(function(row) {
				const values = headers.map(function(header) {
					const value = row[header] || '';
					// Escape quotes and wrap in quotes if contains comma
					return value.toString().indexOf(',') > -1 ? '"' + value.replace(/"/g, '""') + '"' : value;
				});
				csv += values.join(',') + '\n';
			});
			
			return csv;
		},

		/**
		 * Download file to user's computer.
		 */
		downloadFile: function(content, filename, mimeType) {
			const blob = new Blob([content], { type: mimeType });
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = filename;
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
		},

		/**
		 * Get date string for filenames.
		 */
		getDateString: function() {
			const now = new Date();
			return now.getFullYear() + '-' + 
				String(now.getMonth() + 1).padStart(2, '0') + '-' + 
				String(now.getDate()).padStart(2, '0');
		},

		/**
		 * Show export dialog.
		 */
		showExportDialog: function(type) {
			// Placeholder for future export dialog implementation
			console.log('Export dialog for:', type);
			alert('Dashboard export feature coming soon!\n\nUse the individual tab export buttons for now.');
		},

		/**
		 * Show success message.
		 */
		showSuccessMessage: function(message) {
			const $notice = $('<div class="notice notice-success is-dismissible"><p>' + message + '</p></div>');
			$('.wp-mcp-ai-pro-dashboard h1').first().after($notice);
			
			setTimeout(function() {
				$notice.fadeOut(function() {
					$(this).remove();
				});
			}, 3000);
		},

		/**
		 * Initialize monitoring tab filtering.
		 */
		initMonitoringFiltering: function() {
			const self = this;
			
			// Event type filter
			$('#monitoring-event-type, #monitoring-severity, #monitoring-timeframe').on('change', function() {
				self.filterMonitoringEvents();
			});
			
			// Search filter with debounce
			let searchTimeout;
			$('#monitoring-search').on('keyup', function() {
				clearTimeout(searchTimeout);
				searchTimeout = setTimeout(function() {
					self.filterMonitoringEvents();
				}, 300);
			});
			
			// Clear filters
			$('.wp-mcp-ai-clear-monitoring-filters').on('click', function(e) {
				e.preventDefault();
				$('#monitoring-event-type').val('all');
				$('#monitoring-severity').val('all');
				$('#monitoring-timeframe').val('24h');
				$('#monitoring-search').val('');
				self.filterMonitoringEvents();
			});
			
			// Initialize new monitoring features
			this.initMonitoringEnhancements();
		},

		/**
		 * Filter monitoring events.
		 */
		filterMonitoringEvents: function() {
			const eventType = $('#monitoring-event-type').val();
			const severity = $('#monitoring-severity').val();
			const timeframe = $('#monitoring-timeframe').val();
			const search = $('#monitoring-search').val().toLowerCase();
			
			// Filter event table rows
			$('.wp-mcp-ai-event-row').each(function() {
				const $row = $(this);
				const rowType = $row.data('event-type');
				const rowSeverity = $row.data('event-severity');
				const rowText = $row.text().toLowerCase();
				
				let show = true;
				
				// Type filter
				if (eventType !== 'all' && rowType && rowType !== eventType) {
					show = false;
				}
				
				// Severity filter
				if (severity !== 'all' && rowSeverity && rowSeverity !== severity) {
					show = false;
				}
				
				// Search filter
				if (search && rowText.indexOf(search) === -1) {
					show = false;
				}
				
				$row.toggle(show);
			});
			
			// Update visible count
			const visibleCount = $('.wp-mcp-ai-event-row:visible').length;
			$('.wp-mcp-ai-event-count').text('Showing ' + visibleCount + ' events');
		},
		
		/**
		 * Initialize enhanced monitoring features.
		 */
		initMonitoringEnhancements: function() {
			const self = this;
			let autoRefreshInterval = null;
			
			// Manual refresh
			$('#wp-mcp-ai-refresh-monitoring').on('click', function(e) {
				e.preventDefault();
				self.refreshMonitoringData();
			});
			
			// Auto-refresh toggle
			$('#wp-mcp-ai-auto-refresh').on('change', function() {
				if ($(this).is(':checked')) {
					autoRefreshInterval = setInterval(function() {
						self.refreshMonitoringData();
					}, 30000); // 30 seconds
				} else {
					if (autoRefreshInterval) {
						clearInterval(autoRefreshInterval);
						autoRefreshInterval = null;
					}
				}
			});
			
			// Start auto-refresh by default
			if ($('#wp-mcp-ai-auto-refresh').is(':checked')) {
				autoRefreshInterval = setInterval(function() {
					self.refreshMonitoringData();
				}, 30000);
			}
			
			// Export events
			$('#wp-mcp-ai-export-events').on('click', function(e) {
				e.preventDefault();
				self.exportMonitoringEvents();
			});
			
			// Clear dismissed events
			$('#wp-mcp-ai-clear-dismissed').on('click', function(e) {
				e.preventDefault();
				self.clearDismissedEvents();
			});
			
			// Dismiss event
			$(document).on('click', '.wp-mcp-ai-dismiss-event', function(e) {
				e.preventDefault();
				const $button = $(this);
				const eventId = $button.data('event-id');
				self.dismissEvent(eventId, $button);
			});
			
			// View event details
			$(document).on('click', '.wp-mcp-ai-view-event-details', function(e) {
				e.preventDefault();
				const eventId = $(this).data('event-id');
				self.showEventDetails(eventId);
			});
			
			// Close modal
			$(document).on('click', '.wp-mcp-ai-modal-close, .wp-mcp-ai-modal', function(e) {
				if (e.target === this) {
					$('.wp-mcp-ai-modal').hide();
				}
			});
			
			// Load more events
			$('#wp-mcp-ai-load-more-events').on('click', function(e) {
				e.preventDefault();
				self.loadMoreEvents();
			});
			
			// Initialize event timeline chart
			this.initEventTimelineChart();
		},
		
		/**
		 * Refresh monitoring data.
		 */
		refreshMonitoringData: function() {
			const self = this;
			const $refreshButton = $('#wp-mcp-ai-refresh-monitoring');
			
			// Show loading state
			$refreshButton.prop('disabled', true);
			$('.wp-mcp-ai-monitoring-options').addClass('wp-mcp-ai-refreshing');
			
			// In a real implementation, this would make an AJAX call
			// For now, simulate a refresh
			setTimeout(function() {
				// Update timestamp
				const now = new Date();
				const timeString = now.getHours().toString().padStart(2, '0') + ':' +
					now.getMinutes().toString().padStart(2, '0') + ':' +
					now.getSeconds().toString().padStart(2, '0');
				$('#wp-mcp-ai-last-update-time').text(timeString);
				
				$refreshButton.prop('disabled', false);
				$('.wp-mcp-ai-monitoring-options').removeClass('wp-mcp-ai-refreshing');
			}, 1000);
		},
		
		/**
		 * Export monitoring events.
		 */
		exportMonitoringEvents: function() {
			const events = [];
			
			$('.wp-mcp-ai-event-row:visible').each(function() {
				const $row = $(this);
				events.push({
					severity: $row.data('event-severity'),
					type: $row.data('event-type'),
					message: $row.find('.wp-mcp-ai-event-message').text().trim(),
					timestamp: $row.data('event-timestamp')
				});
			});
			
			// Convert to CSV
			let csv = 'Severity,Type,Message,Timestamp\n';
			events.forEach(function(event) {
				csv += '"' + event.severity + '","' + event.type + '","' + 
					event.message.replace(/"/g, '""') + '","' + event.timestamp + '"\n';
			});
			
			// Download
			const blob = new Blob([csv], { type: 'text/csv' });
			const url = window.URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = 'monitoring-events-' + Date.now() + '.csv';
			a.click();
			window.URL.revokeObjectURL(url);
		},
		
		/**
		 * Clear dismissed events.
		 */
		clearDismissedEvents: function() {
			if (confirm('Are you sure you want to clear all dismissed events? This action cannot be undone.')) {
				// TODO: Implement AJAX call to clear dismissed events on server
				// For now, remove dismissed events from current view only
				const $dismissedRows = $('.wp-mcp-ai-event-row.dismissed');
				
				if ($dismissedRows.length > 0) {
					$dismissedRows.fadeOut(300, function() {
						$(this).remove();
						// Update count
						const visibleCount = $('.wp-mcp-ai-event-row:visible').length;
						$('.wp-mcp-ai-event-count').text('Showing ' + visibleCount + ' events');
					});
				}
			}
		},
		
		/**
		 * Dismiss an event.
		 */
		dismissEvent: function(eventId, $button) {
			if (confirm('Dismiss this event?')) {
				const $row = $button.closest('.wp-mcp-ai-event-row');
				$row.fadeOut(300, function() {
					$(this).remove();
					// Update count
					const visibleCount = $('.wp-mcp-ai-event-row:visible').length;
					$('.wp-mcp-ai-event-count').text('Showing ' + visibleCount + ' events');
				});
			}
		},
		
		/**
		 * Show event details modal.
		 */
		showEventDetails: function(eventId) {
			const $row = $('.wp-mcp-ai-event-row').filter(function() {
				return $(this).find('[data-event-id="' + eventId + '"]').length > 0;
			});
			
			if ($row.length === 0) {
				return;
			}
			
			const severity = $row.data('event-severity');
			const type = $row.data('event-type');
			const message = $row.find('.wp-mcp-ai-event-message').text().trim();
			const timestamp = $row.data('event-timestamp');
			
			const detailsHtml = '<div class="wp-mcp-ai-event-details">' +
				'<div class="wp-mcp-ai-detail-row"><strong>Severity:</strong> <span class="wp-mcp-ai-severity-badge wp-mcp-ai-severity-' + severity + '">' + severity + '</span></div>' +
				'<div class="wp-mcp-ai-detail-row"><strong>Type:</strong> ' + type + '</div>' +
				'<div class="wp-mcp-ai-detail-row"><strong>Message:</strong> ' + message + '</div>' +
				'<div class="wp-mcp-ai-detail-row"><strong>Timestamp:</strong> ' + new Date(timestamp * 1000).toLocaleString() + '</div>' +
				'</div>';
			
			$('#wp-mcp-ai-event-details-content').html(detailsHtml);
			$('#wp-mcp-ai-event-details-modal').fadeIn(200);
		},
		
		/**
		 * Load more events.
		 */
		loadMoreEvents: function() {
			// TODO: Implement AJAX pagination for loading additional events
			// For now, this is a placeholder that will be implemented in a future update
			const $button = $('#wp-mcp-ai-load-more-events');
			$button.prop('disabled', true).text('Feature coming soon...');
			
			setTimeout(function() {
				$button.prop('disabled', false).text('Load More');
			}, 2000);
		},
		
		/**
		 * Initialize event timeline chart.
		 */
		initEventTimelineChart: function() {
			const canvas = document.getElementById('wpMcpAiEventTimelineChart');
			if (!canvas || typeof Chart === 'undefined') {
				return;
			}
			
			// TODO: Replace with actual event data from PHP via wpMcpAiProDashboard.chartData
			// Sample data for demonstration - in production, this comes from server
			const hours = [];
			const eventCounts = [];
			
			for (let i = 23; i >= 0; i--) {
				const hour = new Date();
				hour.setHours(hour.getHours() - i);
				hours.push(hour.getHours() + ':00');
				// Temporary placeholder data - replace with real data
				eventCounts.push(Math.floor(Math.random() * 10));
			}
			
			new Chart(canvas, {
				type: 'line',
				data: {
					labels: hours,
					datasets: [{
						label: 'Events per Hour',
						data: eventCounts,
						borderColor: '#0073aa',
						backgroundColor: 'rgba(0, 115, 170, 0.1)',
						tension: 0.4,
						fill: true
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
								precision: 0
							}
						}
					}
				}
			});
		},

		/**
		 * Initialize framework filtering and comparison.
		 */
		initFrameworkFiltering: function() {
			const self = this;
			
			// Status and category filters
			$('#framework-status-filter, #framework-category').on('change', function() {
				self.filterFrameworks();
			});
			
			// Clear filters
			$('.wp-mcp-ai-clear-framework-filters').on('click', function(e) {
				e.preventDefault();
				$('#framework-status-filter').val('all');
				$('#framework-category').val('all');
				self.filterFrameworks();
			});
			
			// Compare button
			$('.wp-mcp-ai-compare-frameworks').on('click', function(e) {
				e.preventDefault();
				$('.wp-mcp-ai-framework-selection').slideToggle();
			});
			
			// Select all frameworks
			$('#wp-mcp-ai-select-all-frameworks').on('change', function() {
				const checked = $(this).prop('checked');
				$('.wp-mcp-ai-framework-select:visible').prop('checked', checked);
				self.updateFrameworkSelection();
			});
			
			// Individual framework selection
			$(document).on('change', '.wp-mcp-ai-framework-select', function() {
				self.updateFrameworkSelection();
			});
			
			// Generate comparison report
			$('.wp-mcp-ai-generate-comparison').on('click', function(e) {
				e.preventDefault();
				self.generateFrameworkComparison();
			});
		},

		/**
		 * Filter framework cards.
		 */
		filterFrameworks: function() {
			const status = $('#framework-status-filter').val();
			const category = $('#framework-category').val();
			
			$('.wp-mcp-ai-framework-card').each(function() {
				const $card = $(this);
				const cardStatus = $card.data('status');
				const cardCategory = $card.data('category');
				
				let show = true;
				
				// Status filter
				if (status !== 'all' && cardStatus !== status) {
					show = false;
				}
				
				// Category filter
				if (category !== 'all' && cardCategory !== category) {
					show = false;
				}
				
				$card.toggle(show);
			});
			
			// Update visible count
			const visible = $('.wp-mcp-ai-framework-card:visible').length;
			const total = $('.wp-mcp-ai-framework-card').length;
			console.log('Showing', visible, 'of', total, 'frameworks');
		},

		/**
		 * Update framework selection state.
		 */
		updateFrameworkSelection: function() {
			const selected = $('.wp-mcp-ai-framework-select:checked').length;
			
			if (selected > 0) {
				$('.wp-mcp-ai-selected-frameworks-count').text(selected + ' framework(s) selected');
			} else {
				$('.wp-mcp-ai-selected-frameworks-count').text('');
			}
		},

		/**
		 * Generate framework comparison report.
		 */
		generateFrameworkComparison: function() {
			const selected = [];
			
			$('.wp-mcp-ai-framework-select:checked').each(function() {
				const $card = $(this).closest('.wp-mcp-ai-framework-card');
				selected.push({
					id: $(this).val(),
					name: $card.find('h3').text(),
					status: $card.find('.wp-mcp-ai-framework-status').text(),
					percentage: $card.find('.wp-mcp-ai-progress').text()
				});
			});
			
			if (selected.length === 0) {
				alert('Please select at least one framework to compare.');
				return;
			}
			
			console.log('Generating comparison report for:', selected);
			
			// Show success message
			this.showSuccessMessage('Comparison report for ' + selected.length + ' framework(s) will be generated. This feature is coming soon!');
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
			// Initialize date range selector
			this.initDateRangeSelector();
			// Initialize controls filtering
			this.initControlsFiltering();
			// Initialize bulk actions
			this.initBulkActions();
			// Initialize monitoring filtering
			this.initMonitoringFiltering();
			// Initialize framework filtering
			this.initFrameworkFiltering();
		},

		/**
		 * Initialize date range selector.
		 */
		initDateRangeSelector: function() {
			const self = this;
			
			// Show/hide custom date inputs
			$('#wp-mcp-ai-date-range').on('change', function() {
				const val = $(this).val();
				if (val === 'custom') {
					$('.wp-mcp-ai-custom-date-range').slideDown();
				} else {
					$('.wp-mcp-ai-custom-date-range').slideUp();
				}
			});
			
			// Apply date range filter
			$('.wp-mcp-ai-apply-date-range').on('click', function(e) {
				e.preventDefault();
				self.applyDateRange();
			});
		},

		/**
		 * Apply date range filter.
		 */
		applyDateRange: function() {
			const range = $('#wp-mcp-ai-date-range').val();
			let startDate, endDate;
			
			if (range === 'custom') {
				startDate = $('#wp-mcp-ai-start-date').val();
				endDate = $('#wp-mcp-ai-end-date').val();
				
				if (!startDate || !endDate) {
					alert('Please select both start and end dates.');
					return;
				}
			} else {
				endDate = new Date();
				startDate = new Date();
				startDate.setDate(startDate.getDate() - parseInt(range));
			}
			
			console.log('Applying date range filter:', startDate, endDate);
			// Here you would reload dashboard data with the date range
			this.showSuccessMessage('Date range applied. Data will be updated.');
		},

		/**
		 * Initialize controls filtering.
		 */
		initControlsFiltering: function() {
			const self = this;
			
			// Category filter
			$('#controls-category-filter').on('change', function() {
				self.filterControlsTable();
			});
			
			// Clear filters button
			$('.wp-mcp-ai-clear-filters').on('click', function(e) {
				e.preventDefault();
				$('#controls-status-filter').val('all');
				$('#controls-category-filter').val('all');
				$('#controls-search').val('');
				self.filterControlsTable();
			});
		},

		/**
		 * Filter controls table with multiple criteria.
		 */
		filterControlsTable: function() {
			const status = $('#controls-status-filter').val();
			const category = $('#controls-category-filter').val();
			const search = $('#controls-search').val().toLowerCase();
			
			$('.wp-mcp-ai-control-row').each(function() {
				const $row = $(this);
				const rowStatus = $row.data('status');
				const rowCategory = $row.data('category');
				const rowText = $row.text().toLowerCase();
				
				let show = true;
				
				// Status filter
				if (status !== 'all' && rowStatus !== status) {
					show = false;
				}
				
				// Category filter
				if (category !== 'all' && !rowCategory.startsWith(category)) {
					show = false;
				}
				
				// Search filter
				if (search && rowText.indexOf(search) === -1) {
					show = false;
				}
				
				$row.toggle(show);
			});
			
			// Update visible count
			const visible = $('.wp-mcp-ai-control-row:visible').length;
			const total = $('.wp-mcp-ai-control-row').length;
			console.log('Showing', visible, 'of', total, 'controls');
		},

		/**
		 * Initialize bulk actions for controls.
		 */
		initBulkActions: function() {
			const self = this;
			
			// Select/deselect all
			$('#wp-mcp-ai-select-all-table').on('change', function() {
				const checked = $(this).prop('checked');
				$('.wp-mcp-ai-control-checkbox:visible').prop('checked', checked);
				self.updateBulkActionsState();
			});
			
			// Individual checkbox change
			$(document).on('change', '.wp-mcp-ai-control-checkbox', function() {
				self.updateBulkActionsState();
			});
			
			// Bulk export
			$('.wp-mcp-ai-bulk-export').on('click', function(e) {
				e.preventDefault();
				self.exportSelectedControls();
			});
		},

		/**
		 * Update bulk actions state based on selection.
		 */
		updateBulkActionsState: function() {
			const selected = $('.wp-mcp-ai-control-checkbox:checked').length;
			
			if (selected > 0) {
				$('.wp-mcp-ai-bulk-actions').slideDown();
				$('.wp-mcp-ai-selected-count').text(selected + ' control(s) selected');
			} else {
				$('.wp-mcp-ai-bulk-actions').slideUp();
			}
		},

		/**
		 * Export selected controls.
		 */
		exportSelectedControls: function() {
			const controls = [];
			
			$('.wp-mcp-ai-control-checkbox:checked').each(function() {
				const $row = $(this).closest('tr');
				controls.push({
					id: $row.find('td:eq(0)').text().trim(),
					name: $row.find('td:eq(1) strong').text().trim(),
					status: $row.find('td:eq(2)').text().trim(),
					applicable: $row.find('td:eq(3) .dashicons-yes-alt').length > 0 ? 'Yes' : 'No'
				});
			});
			
			if (controls.length === 0) {
				alert('No controls selected.');
				return;
			}
			
			const csv = this.generateCSV(controls);
			this.downloadFile(csv, 'selected-controls-' + this.getDateString() + '.csv', 'text/csv');
			this.showSuccessMessage(controls.length + ' control(s) exported successfully!');
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

			// Update chat statistics if present
			if (data.chatData) {
				$('.wp-mcp-ai-chat-total').text(data.chatData.total_conversations || 0);
				$('.wp-mcp-ai-chat-users').text(data.chatData.active_users || 0);
				$('.wp-mcp-ai-chat-today').text(data.chatData.today_conversations || 0);
				$('.wp-mcp-ai-chat-week').text(data.chatData.this_week_conversations || 0);
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
		try {
			console.log('Document ready, initializing Pro Dashboard...');
			ProDashboard.init();
		} catch (error) {
			console.error('Failed to initialize Pro Dashboard:', error);
		}
	});

	// Cleanup on page unload
	$(window).on('beforeunload', function() {
		ProDashboard.stopAutoRefresh();
	});

})(jQuery);
