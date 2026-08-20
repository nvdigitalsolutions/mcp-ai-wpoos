/**
 * Agent Command Center JavaScript
 *
 * Real-time dashboard for managing, monitoring, and analyzing AI agents.
 * Handles live data polling, Chart.js charts, activity feed updates,
 * approval workflows, and analytics visualization.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */
( function( $ ) {
	'use strict';

	var AgentCommandCenter = {
		refreshTimer: null,
		config: typeof wpMcpAiCommandCenter !== 'undefined' ? wpMcpAiCommandCenter : {},
		charts: {},

		/**
		 * Initialize the command center.
		 */
		init: function() {
			if ( ! this.config.ajaxUrl || ! this.config.nonce ) {
				return;
			}

			this.bindEvents();
			this.initCharts();
			this.startAutoRefresh();
			this.loadInitialData();
		},

		/**
		 * Bind DOM events.
		 */
		bindEvents: function() {
			var self = this;

			// Refresh button.
			$( '#acc-refresh-btn' ).on( 'click', function() {
				self.refreshData();
			} );

			// Activity filters.
			$( '.acc-filter' ).on( 'change', function() {
				self.loadActivityLog();
			} );

			$( '#acc-filter-search' ).on( 'input', $.proxy( self.debounce( function() {
				self.loadActivityLog();
			}, 400 ), self ) );

			// Approval buttons.
			$( document ).on( 'click', '.acc-approval-btn', function( e ) {
				e.preventDefault();
				self.handleApproval( $( this ) );
			} );

			// Analytics range change.
			$( '#acc-analytics-range' ).on( 'change', function() {
				self.loadAnalytics( $( this ).val() );
			} );

			// Restriction lift buttons.
			$( document ).on( 'click', '.acc-lift-restriction', function( e ) {
				e.preventDefault();
				self.handleLiftRestriction( $( this ) );
			} );
		},

		/**
		 * Start auto-refresh polling.
		 */
		startAutoRefresh: function() {
			var interval = this.config.refreshInterval || 10000;
			var self = this;

			this.refreshTimer = setInterval( function() {
				self.refreshData();
			}, interval );
		},

		/**
		 * Load initial data based on current tab.
		 */
		loadInitialData: function() {
			var tab = this.getCurrentTab();

			switch ( tab ) {
				case 'activity':
					this.loadActivityLog();
					break;
				case 'analytics':
					this.loadAnalytics( $( '#acc-analytics-range' ).val() || '7d' );
					break;
				default:
					this.refreshData();
					break;
			}
		},

		/**
		 * Get current tab from URL.
		 *
		 * @return {string} Current tab slug.
		 */
		getCurrentTab: function() {
			var params = new URLSearchParams( window.location.search );
			return params.get( 'tab' ) || 'overview';
		},

		/**
		 * Refresh dashboard data via AJAX.
		 */
		refreshData: function() {
			var self = this;
			var $btn = $( '#acc-refresh-btn' );

			$btn.addClass( 'spinning' );

			$.ajax( {
				url: self.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_acc_get_dashboard_data',
					nonce: self.config.nonce,
				},
				success: function( response ) {
					if ( response.success && response.data ) {
						self.updateKPIs( response.data.kpis );
						self.updateAgentStatuses( response.data.agent_statuses );
						self.updateActivityFeed( response.data.recent_events );
					}
				},
				error: function() {
					// Silently handle errors during auto-refresh.
				},
				complete: function() {
					$btn.removeClass( 'spinning' );
				},
			} );
		},

		/**
		 * Update KPI values.
		 *
		 * @param {Object} kpis KPI data object.
		 */
		updateKPIs: function( kpis ) {
			if ( ! kpis ) {
				return;
			}

			this.animateValue( '#kpi-total-agents', kpis.total_agents );
			this.animateValue( '#kpi-agents-online', kpis.agents_online );
			this.animateValue( '#kpi-active-tasks', kpis.active_tasks );
			this.animateValue( '#kpi-pending-approvals', kpis.pending_approvals );
			$( '#kpi-tokens-today' ).text( kpis.tokens_today );
			$( '#kpi-uptime' ).text( kpis.uptime );
		},

		/**
		 * Animate a numeric value change.
		 *
		 * @param {string} selector Element selector.
		 * @param {number} newValue New value to display.
		 */
		animateValue: function( selector, newValue ) {
			var $el = $( selector );
			var current = parseInt( $el.text(), 10 ) || 0;
			var target = parseInt( newValue, 10 ) || 0;

			if ( current === target ) {
				return;
			}

			$el.text( target );
			$el.addClass( 'acc-value-changed' );
			setTimeout( function() {
				$el.removeClass( 'acc-value-changed' );
			}, 600 );
		},

		/**
		 * Update agent status indicators.
		 *
		 * @param {Object} statuses Agent status map.
		 */
		updateAgentStatuses: function( statuses ) {
			if ( ! statuses ) {
				return;
			}

			$.each( statuses, function( agentId, data ) {
				var $card = $( '.acc-agent-card[data-agent-id="' + agentId + '"]' );
				if ( $card.length ) {
					// Remove old status classes.
					$card.removeClass( 'agent-status-online agent-status-idle agent-status-offline' );
					$card.addClass( 'agent-status-' + data.status );

					// Update status dot.
					$card.find( '.acc-status-dot' )
						.removeClass( 'agent-status-online agent-status-idle agent-status-offline' )
						.addClass( 'agent-status-' + data.status );
				}
			} );
		},

		/**
		 * Update live activity feed.
		 *
		 * @param {Array} events Activity events.
		 */
		updateActivityFeed: function( events ) {
			var self = this;
			var $feed = $( '#acc-live-activity' );
			if ( ! $feed.length || ! events || ! events.length ) {
				return;
			}

			var html = '';
			var typeIcons = {
				tool_execution: 'admin-tools',
				tool_error: 'warning',
				chat_response: 'format-chat',
				chat_interaction: 'admin-comments',
				api_request: 'cloud',
				api_response: 'cloud',
				schedule_run: 'calendar-alt',
				session_start: 'migrate',
				session_end: 'dismiss',
				approval_requested: 'clock',
				approval_resolved: 'yes-alt',
				error: 'warning',
				system: 'info',
			};

			$.each( events, function( _, event ) {
				var icon = typeIcons[ event.type ] || 'info';
				var agentHtml = event.agent_name
					? '<span class="acc-activity-agent">' + self.escapeHtml( event.agent_name ) + '</span>'
					: '';

				html += '<div class="acc-activity-item acc-event-' + self.escapeHtml( event.type ) + '">';
				html += '<div class="acc-activity-icon"><span class="dashicons dashicons-' + icon + '"></span></div>';
				html += '<div class="acc-activity-body">' + agentHtml;
				html += '<span class="acc-activity-message">' + self.escapeHtml( event.message ) + '</span></div>';
				html += '<div class="acc-activity-time">' + self.timeAgo( event.timestamp ) + '</div>';
				html += '</div>';
			} );

			$feed.html( html );
		},

		/**
		 * Load activity log with filters.
		 */
		loadActivityLog: function() {
			var self = this;
			var $stream = $( '#acc-activity-stream' );

			if ( ! $stream.length ) {
				return;
			}

			$stream.html( '<div class="acc-loading"><span class="spinner is-active"></span> ' + self.config.strings.loading + '</div>' );

			$.ajax( {
				url: self.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_acc_get_activity_log',
					nonce: self.config.nonce,
					event_type: $( '#acc-filter-type' ).val() || '',
					agent_id: $( '#acc-filter-agent' ).val() || '',
					timeframe: $( '#acc-filter-timeframe' ).val() || '24h',
					search: $( '#acc-filter-search' ).val() || '',
				},
				success: function( response ) {
					if ( response.success && response.data ) {
						self.renderActivityStream( response.data.events );
						self.updateActivitySummary( response.data.summary );
					} else {
						$stream.html( '<div class="acc-empty-state"><p>' + self.config.strings.noActivity + '</p></div>' );
					}
				},
				error: function() {
					$stream.html( '<div class="acc-empty-state"><p>' + self.config.strings.error + '</p></div>' );
				},
			} );
		},

		/**
		 * Render activity stream from events.
		 *
		 * @param {Array} events Activity events.
		 */
		renderActivityStream: function( events ) {
			var $stream = $( '#acc-activity-stream' );
			var self = this;

			if ( ! events || ! events.length ) {
				$stream.html( '<div class="acc-empty-state"><span class="dashicons dashicons-format-aside"></span><p>' + self.config.strings.noActivity + '</p></div>' );
				return;
			}

			var typeIcons = {
				tool_execution: 'admin-tools',
				tool_error: 'warning',
				chat_response: 'format-chat',
				chat_interaction: 'admin-comments',
				api_request: 'cloud',
				api_response: 'cloud',
				schedule_run: 'calendar-alt',
				session_start: 'migrate',
				session_end: 'dismiss',
				approval_requested: 'clock',
				approval_resolved: 'yes-alt',
				error: 'warning',
				system: 'info',
			};

			var html = '';
			$.each( events, function( _, event ) {
				var icon = typeIcons[ event.type ] || 'info';
				var agentHtml = event.agent_name
					? '<span class="acc-activity-agent">' + self.escapeHtml( event.agent_name ) + '</span>'
					: '';

				html += '<div class="acc-activity-item acc-event-' + self.escapeHtml( event.type ) + '">';
				html += '<div class="acc-activity-icon"><span class="dashicons dashicons-' + icon + '"></span></div>';
				html += '<div class="acc-activity-body">' + agentHtml;
				html += '<span class="acc-activity-message">' + self.escapeHtml( event.message ) + '</span></div>';
				html += '<div class="acc-activity-time">' + self.timeAgo( event.timestamp ) + '</div>';
				html += '</div>';
			} );

			$stream.html( html );
		},

		/**
		 * Update activity summary stats.
		 *
		 * @param {Object} summary Summary data.
		 */
		updateActivitySummary: function( summary ) {
			if ( ! summary ) {
				return;
			}

			$( '#activity-total-events' ).text( summary.total || 0 );
			$( '#activity-tool-calls' ).text( summary.tool_calls || 0 );
			$( '#activity-chat-responses' ).text( summary.chat_responses || 0 );
			$( '#activity-chat-interactions' ).text( summary.chat_interactions || 0 );
			$( '#activity-api-requests' ).text( summary.api_requests || 0 );
			$( '#activity-schedule-runs' ).text( summary.schedule_runs || 0 );
			$( '#activity-errors' ).text( summary.errors || 0 );
		},

		/**
		 * Handle a restriction lift click.
		 *
		 * @param {jQuery} $button Clicked button.
		 */
		handleLiftRestriction: function( $button ) {
			var self = this;
			var userId = parseInt( $button.data( 'user-id' ), 10 );
			var type = $button.data( 'type' ) || 'all';

			// eslint-disable-next-line no-alert
			if ( ! window.confirm( this.config.strings.confirmLift ) ) {
				return;
			}

			$button.prop( 'disabled', true );

			$.ajax( {
				url: self.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_acc_lift_restriction',
					nonce: self.config.nonce,
					user_id: userId,
					type: type
				},
				success: function( response ) {
					if ( response && response.success ) {
						// Remove the lifted row; reload when the table empties.
						$button.closest( 'tr' ).fadeOut( 200, function() {
							$( this ).remove();
							if ( ! $( '#acc-restrictions-table tbody tr' ).length ) {
								window.location.reload();
							}
						} );
						if ( typeof response.data === 'object' && response.data.rows ) {
							$( '#kpi-restrictions-total' ).text( response.data.rows.total );
							$( '#acc-restrictions-kpi-row' ).find( '.acc-kpi-card' ).each( function() {
								$( this ).find( '.acc-kpi-value' ).text( '…' );
							} );
						}
					} else {
						// eslint-disable-next-line no-alert
						window.alert( self.config.strings.liftFailed );
						$button.prop( 'disabled', false );
					}
				},
				error: function() {
					// eslint-disable-next-line no-alert
					window.alert( self.config.strings.liftFailed );
					$button.prop( 'disabled', false );
				}
			} );
		},

		/**
		 * Handle an approval decision click.
		 *
		 * @param {jQuery} $button Clicked button.
		 */
		handleApproval: function( $button ) {
			var self = this;
			var action = $button.data( 'action' );
			var id = $button.data( 'id' );
			var confirmMsg = 'approve' === action
				? self.config.strings.confirmApprove
				: self.config.strings.confirmReject;

			// eslint-disable-next-line no-alert
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			$button.prop( 'disabled', true );

			$.ajax( {
				url: self.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_acc_handle_approval',
					nonce: self.config.nonce,
					approval_id: id,
					decision: 'approve' === action ? 'approved' : 'rejected',
					reason: '',
				},
				success: function( response ) {
					if ( response.success ) {
						var $card = $button.closest( '.acc-approval-card' );
						$card.fadeOut( 300, function() {
							$card.remove();
							// Update pending count.
							var $badge = $( '.acc-panel-header .acc-badge.accent-amber' );
							var count = parseInt( $badge.text(), 10 ) || 0;
							if ( count > 0 ) {
								$badge.text( count - 1 );
							}
						} );
					} else {
						// eslint-disable-next-line no-alert
						window.alert( self.config.strings.operationFailed );
						$button.prop( 'disabled', false );
					}
				},
				error: function() {
					// eslint-disable-next-line no-alert
					window.alert( self.config.strings.operationFailed );
					$button.prop( 'disabled', false );
				},
			} );
		},

		// =====================================================================
		// Charts
		// =====================================================================

		/**
		 * Initialize Chart.js instances.
		 */
		initCharts: function() {
			if ( typeof Chart === 'undefined' ) {
				return;
			}

			// Set global Chart.js defaults.
			Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
			Chart.defaults.font.size = 12;

			this.initAnalyticsCharts();
			this.initUptimeChart();
			this.initStrategyCharts();
		},

		/**
		 * Initialize analytics charts.
		 */
		initAnalyticsCharts: function() {
			var usageCtx = document.getElementById( 'acc-chart-usage-timeline' );
			if ( usageCtx ) {
				this.charts.usageTimeline = new Chart( usageCtx, {
					type: 'line',
					data: {
						labels: [],
						datasets: [
							{
								label: 'Tokens',
								data: [],
								borderColor: '#2271b1',
								backgroundColor: 'rgba(34, 113, 177, 0.1)',
								fill: true,
								tension: 0.4,
							},
							{
								label: 'API Calls',
								data: [],
								borderColor: '#059669',
								backgroundColor: 'rgba(5, 150, 105, 0.1)',
								fill: true,
								tension: 0.4,
								yAxisID: 'y1',
							},
						],
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						interaction: { intersect: false, mode: 'index' },
						scales: {
							y: { beginAtZero: true, title: { display: true, text: 'Tokens' } },
							y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'API Calls' }, grid: { drawOnChartArea: false } },
						},
					},
				} );
			}

			var tokensCtx = document.getElementById( 'acc-chart-tokens-by-agent' );
			if ( tokensCtx ) {
				this.charts.tokensByAgent = new Chart( tokensCtx, {
					type: 'doughnut',
					data: {
						labels: [],
						datasets: [ {
							data: [],
							backgroundColor: [ '#2271b1', '#059669', '#d97706', '#7c3aed', '#dc2626', '#0891b2', '#be185d' ],
						} ],
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						plugins: {
							legend: { position: 'bottom' },
						},
					},
				} );
			}

			var toolCtx = document.getElementById( 'acc-chart-tool-distribution' );
			if ( toolCtx ) {
				this.charts.toolDistribution = new Chart( toolCtx, {
					type: 'bar',
					data: {
						labels: [],
						datasets: [ {
							label: 'Usage Count',
							data: [],
							backgroundColor: '#2271b1',
							borderRadius: 4,
						} ],
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						indexAxis: 'y',
						scales: {
							x: { beginAtZero: true },
						},
						plugins: {
							legend: { display: false },
						},
					},
				} );
			}

			var responseCtx = document.getElementById( 'acc-chart-response-times' );
			if ( responseCtx ) {
				this.charts.responseTimes = new Chart( responseCtx, {
					type: 'line',
					data: {
						labels: [],
						datasets: [ {
							label: 'Response Time (ms)',
							data: [],
							borderColor: '#d97706',
							backgroundColor: 'rgba(217, 119, 6, 0.1)',
							fill: true,
							tension: 0.4,
						} ],
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						scales: {
							y: { beginAtZero: true, title: { display: true, text: 'ms' } },
						},
					},
				} );
			}
		},

		/**
		 * Initialize uptime chart.
		 */
		initUptimeChart: function() {
			var uptimeCtx = document.getElementById( 'acc-chart-uptime' );
			if ( ! uptimeCtx ) {
				return;
			}

			// Generate 30-day labels.
			var labels = [];
			var data = [];
			for ( var i = 29; i >= 0; i-- ) {
				var d = new Date();
				d.setDate( d.getDate() - i );
				labels.push( d.toLocaleDateString( 'en-US', { month: 'short', day: 'numeric' } ) );
				data.push( 100 ); // Default 100% uptime.
			}

			this.charts.uptime = new Chart( uptimeCtx, {
				type: 'bar',
				data: {
					labels: labels,
					datasets: [ {
						label: 'Uptime %',
						data: data,
						backgroundColor: data.map( function( v ) {
							return v >= 99.9 ? '#059669' : ( v >= 95 ? '#d97706' : '#dc2626' );
						} ),
						borderRadius: 2,
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					scales: {
						y: { min: 90, max: 100, title: { display: true, text: 'Uptime %' } },
						x: { ticks: { maxRotation: 45 } },
					},
					plugins: {
						legend: { display: false },
					},
				},
			} );
		},

		/**
		 * Initialize strategy charts.
		 */
		initStrategyCharts: function() {
			var loadCtx = document.getElementById( 'acc-chart-load-gauge' );
			if ( loadCtx ) {
				this.charts.loadGauge = new Chart( loadCtx, {
					type: 'doughnut',
					data: {
						labels: [ 'Used', 'Available' ],
						datasets: [ {
							data: [ 30, 70 ],
							backgroundColor: [ '#2271b1', '#f0f0f1' ],
							borderWidth: 0,
						} ],
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						cutout: '70%',
						rotation: -90,
						circumference: 180,
						plugins: {
							legend: { display: false },
						},
					},
				} );
			}

			var peakCtx = document.getElementById( 'acc-chart-peak-usage' );
			if ( peakCtx ) {
				var hours = [];
				var peakData = [];
				for ( var h = 0; h < 24; h++ ) {
					hours.push( h + ':00' );
					peakData.push( Math.floor( Math.random() * 20 ) );
				}

				this.charts.peakUsage = new Chart( peakCtx, {
					type: 'bar',
					data: {
						labels: hours,
						datasets: [ {
							label: 'Requests',
							data: peakData,
							backgroundColor: '#2271b1',
							borderRadius: 2,
						} ],
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						scales: {
							y: { beginAtZero: true },
						},
						plugins: {
							legend: { display: false },
						},
					},
				} );
			}

			var growthCtx = document.getElementById( 'acc-chart-growth-trend' );
			if ( growthCtx ) {
				var weeks = [];
				var growthData = [];
				for ( var w = 11; w >= 0; w-- ) {
					var gd = new Date();
					gd.setDate( gd.getDate() - ( w * 7 ) );
					weeks.push( 'W' + ( 12 - w ) );
					growthData.push( Math.floor( Math.random() * 50 ) + 10 );
				}

				this.charts.growthTrend = new Chart( growthCtx, {
					type: 'line',
					data: {
						labels: weeks,
						datasets: [ {
							label: 'Usage Growth',
							data: growthData,
							borderColor: '#7c3aed',
							backgroundColor: 'rgba(124, 58, 237, 0.1)',
							fill: true,
							tension: 0.4,
						} ],
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						scales: {
							y: { beginAtZero: true },
						},
					},
				} );
			}
		},

		/**
		 * Load analytics data.
		 *
		 * @param {string} range Time range.
		 */
		loadAnalytics: function( range ) {
			var self = this;

			$.ajax( {
				url: self.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_acc_get_analytics',
					nonce: self.config.nonce,
					range: range,
				},
				success: function( response ) {
					if ( response.success && response.data ) {
						self.updateAnalyticsDisplay( response.data );
					}
				},
			} );
		},

		/**
		 * Update analytics display with data.
		 *
		 * @param {Object} data Analytics data.
		 */
		updateAnalyticsDisplay: function( data ) {
			// Update KPIs.
			if ( data.summary ) {
				$( '#analytics-total-tokens' ).text( data.summary.total_tokens );
				$( '#analytics-total-calls' ).text( data.summary.total_calls );
				$( '#analytics-avg-response' ).text( data.summary.avg_response_ms );
				$( '#analytics-success-rate' ).text( data.summary.success_rate );
			}

			// Update timeline chart.
			if ( data.timeline && this.charts.usageTimeline ) {
				var labels = data.timeline.map( function( d ) { return d.date; } );
				var tokens = data.timeline.map( function( d ) { return d.tokens; } );
				var calls = data.timeline.map( function( d ) { return d.calls; } );

				this.charts.usageTimeline.data.labels = labels;
				this.charts.usageTimeline.data.datasets[ 0 ].data = tokens;
				this.charts.usageTimeline.data.datasets[ 1 ].data = calls;
				this.charts.usageTimeline.update();
			}

			// Update agent performance table.
			if ( data.agent_performance ) {
				this.updatePerformanceTable( data.agent_performance );
			}
		},

		/**
		 * Update agent performance table.
		 *
		 * @param {Array} agents Agent performance data.
		 */
		updatePerformanceTable: function( agents ) {
			var $tbody = $( '#acc-performance-tbody' );
			var self = this;

			if ( ! agents || ! agents.length ) {
				$tbody.html( '<tr><td colspan="7">' + self.config.strings.noAgents + '</td></tr>' );
				return;
			}

			var html = '';
			$.each( agents, function( _, agent ) {
				html += '<tr>';
				html += '<td><strong>' + self.escapeHtml( agent.name ) + '</strong></td>';
				html += '<td>' + ( agent.sessions || 0 ) + '</td>';
				html += '<td>' + ( agent.tokens || 0 ) + '</td>';
				html += '<td>' + ( agent.tool_calls || 0 ) + '</td>';
				html += '<td>' + ( agent.avg_response || '0ms' ) + '</td>';
				html += '<td>' + ( agent.success_rate || '100%' ) + '</td>';
				html += '<td><span class="acc-status-badge status-' + self.escapeHtml( agent.status ) + '">' + self.escapeHtml( agent.status ) + '</span></td>';
				html += '</tr>';
			} );

			$tbody.html( html );
		},

		// =====================================================================
		// Utility Methods
		// =====================================================================

		/**
		 * Calculate time ago string.
		 *
		 * @param {number} timestamp Unix timestamp.
		 * @return {string} Time ago string.
		 */
		timeAgo: function( timestamp ) {
			var now = Math.floor( Date.now() / 1000 );
			var diff = now - timestamp;
			var strings = this.config.strings || {};

			if ( diff < 10 ) {
				return strings.justNow || 'Just now';
			}
			if ( diff < 60 ) {
				return ( strings.seconds || '%ds' ).replace( '%d', diff );
			}
			if ( diff < 3600 ) {
				return ( strings.minutes || '%dm' ).replace( '%d', Math.floor( diff / 60 ) );
			}
			if ( diff < 86400 ) {
				return ( strings.hours || '%dh' ).replace( '%d', Math.floor( diff / 3600 ) );
			}
			return ( strings.days || '%dd' ).replace( '%d', Math.floor( diff / 86400 ) );
		},

		/**
		 * Escape HTML for safe insertion.
		 *
		 * @param {string} str String to escape.
		 * @return {string} Escaped string.
		 */
		escapeHtml: function( str ) {
			if ( ! str ) {
				return '';
			}
			var div = document.createElement( 'div' );
			div.appendChild( document.createTextNode( str ) );
			return div.innerHTML;
		},

		/**
		 * Debounce function calls.
		 *
		 * @param {Function} func Function to debounce.
		 * @param {number}   wait Wait time in ms.
		 * @return {Function} Debounced function.
		 */
		debounce: function( func, wait ) {
			var timeout;
			return function() {
				var context = this;
				var args = arguments;
				clearTimeout( timeout );
				timeout = setTimeout( function() {
					func.apply( context, args );
				}, wait );
			};
		},
	};

	// Initialize on DOM ready.
	$( document ).ready( function() {
		AgentCommandCenter.init();
	} );
}( jQuery ) );
