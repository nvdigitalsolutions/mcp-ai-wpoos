/**
 * Multi-Agent Dashboard JavaScript
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Multi-Agent Dashboard Manager
	 */
	const MultiAgentDashboard = {
		autoRefreshInterval: null,
		refreshIntervalMs: 10000, // 10 seconds

		/**
		 * Initialize the dashboard
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind event handlers
		 */
		bindEvents: function() {
			// Auto-refresh toggle
			$('#toggle-auto-refresh').on('change', this.toggleAutoRefresh.bind(this));

			// Manual refresh button
			$('#manual-refresh-btn').on('click', this.refreshData.bind(this));

			// Reinstall agents button
			$('#reinstall-agents-btn').on('click', this.reinstallAgents.bind(this));
		},

		/**
		 * Toggle auto-refresh
		 */
		toggleAutoRefresh: function(e) {
			if ($(e.target).is(':checked')) {
				this.startAutoRefresh();
			} else {
				this.stopAutoRefresh();
			}
		},

		/**
		 * Start auto-refresh
		 */
		startAutoRefresh: function() {
			this.refreshData();
			this.autoRefreshInterval = setInterval(
				this.refreshData.bind(this),
				this.refreshIntervalMs
			);
		},

		/**
		 * Stop auto-refresh
		 */
		stopAutoRefresh: function() {
			if (this.autoRefreshInterval) {
				clearInterval(this.autoRefreshInterval);
				this.autoRefreshInterval = null;
			}
		},

		/**
		 * Refresh dashboard data
		 */
		refreshData: function() {
			const self = this;

			$.ajax({
				url: wpMcpAiMultiAgent.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_get_multi_agent_stats',
					nonce: wpMcpAiMultiAgent.nonce
				},
				beforeSend: function() {
					$('#manual-refresh-btn').prop('disabled', true);
					$('#manual-refresh-btn .dashicons').addClass('spin');
				},
				success: function(response) {
					if (response.success && response.data) {
						self.updateDashboard(response.data);
					}
					self.updateRefreshTime();
				},
				error: function() {
					console.error('Failed to refresh multi-agent dashboard');
				},
				complete: function() {
					$('#manual-refresh-btn').prop('disabled', false);
					$('#manual-refresh-btn .dashicons').removeClass('spin');
				}
			});
		},

		/**
		 * Update dashboard with new data
		 */
		updateDashboard: function(data) {
			// Update stats
			this.updateStats(data);

			// Update agent cards
			this.updateAgentCards(data);
		},

		/**
		 * Update statistics
		 */
		updateStats: function(data) {
			// Update stat card values
			$('.stat-card').each(function() {
				const $card = $(this);
				const $value = $card.find('.stat-value');
				const $label = $card.find('.stat-label').text().toLowerCase();

				if ($label.includes('total agents')) {
					$value.text(data.total_agents || 0);
				} else if ($label.includes('active agents')) {
					$value.text(data.active_agents || 0);
				} else if ($label.includes('total tools')) {
					$value.text(data.total_tools || 0);
				} else if ($label.includes('version')) {
					$value.text(data.is_pro_active ? 'Pro' : 'Base');
				}
			});
		},

		/**
		 * Update agent cards
		 */
		updateAgentCards: function(data) {
			if (!data.agents || !data.agents.length) {
				return;
			}

			data.agents.forEach(function(agent) {
				const $card = $(`.agent-card[data-agent-id="${agent.id}"]`);
				if (!$card.length) {
					return;
				}

				// Update status
				const $status = $card.find('.agent-status');
				if (agent.status === 'publish') {
					$status.removeClass('status-inactive').addClass('status-active');
					$status.text('Active');
				} else {
					$status.removeClass('status-active').addClass('status-inactive');
					$status.text('Inactive');
				}

				// Update last used if available
				if (agent.last_used) {
					const $lastUsed = $card.find('.meta-row:has(.meta-label:contains("Last Used")) .meta-value');
					if ($lastUsed.length) {
						// Calculate time diff (simplified)
						const lastUsedDate = new Date(agent.last_used);
						const now = new Date();
						const diffMinutes = Math.floor((now - lastUsedDate) / 60000);
						
						let timeAgo;
						if (diffMinutes < 60) {
							timeAgo = diffMinutes + ' minutes ago';
						} else if (diffMinutes < 1440) {
							timeAgo = Math.floor(diffMinutes / 60) + ' hours ago';
						} else {
							timeAgo = Math.floor(diffMinutes / 1440) + ' days ago';
						}
						
						$lastUsed.text(timeAgo);
					}
				}
			});
		},

		/**
		 * Update refresh time
		 */
		updateRefreshTime: function() {
			const now = new Date();
			const timeString = now.toLocaleTimeString('en-US', {
				hour12: false,
				hour: '2-digit',
				minute: '2-digit',
				second: '2-digit'
			});
			$('#last-refresh-time').text(timeString);
		},

		/**
		 * Reinstall agents
		 */
		reinstallAgents: function(e) {
			e.preventDefault();

			if (!confirm(wpMcpAiMultiAgent.strings.confirmReinstall)) {
				return;
			}

			const $button = $(e.currentTarget);

			$.ajax({
				url: wpMcpAiMultiAgent.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_reinstall_agents',
					nonce: wpMcpAiMultiAgent.nonce
				},
				beforeSend: function() {
					$button.prop('disabled', true);
					$button.find('.dashicons').addClass('spin');
					$button.find('span:not(.dashicons)').text(wpMcpAiMultiAgent.strings.reinstalling);
				},
				success: function(response) {
					if (response.success) {
						alert(wpMcpAiMultiAgent.strings.reinstallSuccess);
						location.reload();
					} else {
						alert(wpMcpAiMultiAgent.strings.reinstallError + '\n' + (response.data?.message || ''));
					}
				},
				error: function() {
					alert(wpMcpAiMultiAgent.strings.reinstallError);
				},
				complete: function() {
					$button.prop('disabled', false);
					$button.find('.dashicons').removeClass('spin');
				}
			});
		}
	};

	/**
	 * Initialize on document ready
	 */
	$(document).ready(function() {
		MultiAgentDashboard.init();
	});

})(jQuery);
