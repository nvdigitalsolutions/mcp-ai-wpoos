/**
 * WP oOS Settings Dashboard JavaScript
 *
 * Handles tab switching, AJAX operations, and UI interactions.
 */

(function($) {
	'use strict';

	// eslint-disable-next-line camelcase
	const WP_MCP_AI_Dashboard = {
		/**
		 * Initialize the dashboard.
		 */
		init: function() {
			this.bindEvents();
			this.initTooltips();
			this.initTokenManager();
			this.initProviderPriorityList();
			this.initSliders();
			this.initPresets();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Form submission
			$('form').on('submit', this.handleFormSubmit.bind(this));

			// Tab switching
			$('.nav-tab').on('click', this.handleTabSwitch.bind(this));
		},

		/**
		 * Initialize token manager functionality.
		 */
		initTokenManager: function() {
			// Reset user token usage
			$('.wp-mcp-ai-reset-user-usage').on('click', this.handleResetUserUsage.bind(this));

			// Reset all users' token usage
			$('#wp-mcp-ai-reset-all-usage').on('click', this.handleResetAllUsage.bind(this));

			// View user details
			$('.wp-mcp-ai-view-user-details').on('click', this.handleViewUserDetails.bind(this));

			// Save all tool limits
			$('#wp-mcp-ai-save-all-tool-limits').on('click', this.handleSaveToolLimits.bind(this));
		},

		/**
		 * Handle reset user token usage.
		 *
		 * @param {Event} e Click event.
		 */
		handleResetUserUsage: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			const userId = $button.data('user-id');
			const userName = $button.data('user-name');

			if (!confirm('Are you sure you want to reset token usage for ' + userName + '? This action cannot be undone.')) {
				return;
			}

			$button.prop('disabled', true).text('Resetting...');

			// Use the error service for consistent error handling
			$.wpMcpAiAjax({
				url: wpMcpAiDashboard.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_reset_user_token_usage',
					nonce: wpMcpAiDashboard.nonce,
					user_id: userId
				}
			}, {
				success: function(response) {
					if (response.success) {
						window.location.reload();
					} else {
						alert(response.data.message || 'Failed to reset user token usage.');
						$button.prop('disabled', false).text('Reset');
					}
				},
				error: function(error) {
					alert(error.userMessage || 'An error occurred while resetting user token usage.');
					$button.prop('disabled', false).text('Reset');
				}
			});
		},

		/**
		 * Handle reset all users' token usage.
		 *
		 * @param {Event} e Click event.
		 */
		handleResetAllUsage: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);

			if (!confirm('Are you sure you want to reset token usage for ALL users? This action cannot be undone.')) {
				return;
			}

			$button.prop('disabled', true).text('Resetting...');

			// Use the error service for consistent error handling
			$.wpMcpAiAjax({
				url: wpMcpAiDashboard.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_reset_all_token_usage',
					nonce: wpMcpAiDashboard.nonce
				}
			}, {
				success: function(response) {
					if (response.success) {
						window.location.reload();
					} else {
						alert(response.data.message || 'Failed to reset all token usage.');
						$button.prop('disabled', false).text('Reset All Users\' Token Usage');
					}
				},
				error: function(error) {
					alert(error.userMessage || 'An error occurred while resetting token usage.');
					$button.prop('disabled', false).text('Reset All Users\' Token Usage');
				}
			});
		},

		/**
		 * Handle view user details toggle.
		 *
		 * @param {Event} e Click event.
		 */
		handleViewUserDetails: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			const userId = $button.data('user-id');
			const $detailsRow = $('#user-details-' + userId);

			if ($detailsRow.is(':visible')) {
				$detailsRow.hide();
				$button.text('Details');
			} else {
				$detailsRow.show();
				$button.text('Hide Details');
			}
		},

		/**
		 * Handle save tool limits.
		 *
		 * @param {Event} e Click event.
		 */
		handleSaveToolLimits: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			const limits = {};

			$('.wp-mcp-ai-tool-limit-input').each(function() {
				const $input = $(this);
				limits[$input.data('tool-slug')] = $input.val();
			});

			$button.prop('disabled', true).text('Saving...');

			// Use the error service for consistent error handling
			$.wpMcpAiAjax({
				url: wpMcpAiDashboard.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_save_tool_limits',
					nonce: wpMcpAiDashboard.nonce,
					limits: limits
				}
			}, {
				success: function(response) {
					if (response.success) {
						$button.text('Saved!');
						setTimeout(function() {
							window.location.reload();
						}, 1000);
					} else {
						alert(response.data.message || 'Failed to save tool limits.');
						$button.prop('disabled', false).text('Save All Tool Limits');
					}
				},
				error: function(error) {
					alert(error.userMessage || 'An error occurred while saving tool limits.');
					$button.prop('disabled', false).text('Save All Tool Limits');
				}
			});
		},

		/**
		 * Handle form submission with loading state.
		 *
		 * @param {Event} e Submit event.
		 */
		handleFormSubmit: function(e) {
			const $form = $(e.target);
			const $submit = $form.find('input[type="submit"]');

			// Add loading state.
			$submit.prop('disabled', true);
			$form.addClass('loading');

			// Original text will be restored on page reload after redirect.
		},

		/**
		 * Handle tab switching.
		 *
		 * @param {Event} e Click event.
		 */
		handleTabSwitch: function(e) {
			// Allow default navigation - we're using server-side rendering.
			// Just add a visual loading indicator.
			const $tab = $(e.currentTarget);
			$tab.addClass('loading');
		},

		/**
		 * Initialize tooltips for help text.
		 */
		initTooltips: function() {
			// Add tooltips if WordPress tooltip library is available.
			if (typeof jQuery.fn.tooltip !== 'undefined') {
				$('.help-text').tooltip({
					position: {
						my: 'center bottom-5',
						at: 'center top'
					}
				});
			}
		},

		/**
		 * Initialize provider priority list sortable.
		 */
		initProviderPriorityList: function() {
			const $sortable = $('#wp-mcp-ai-provider-sortable');

			if ($sortable.length && typeof $sortable.sortable === 'function') {
				$sortable.sortable({
					axis: 'y',
					handle: '.dashicons-menu',
					cursor: 'move',
					placeholder: 'ui-sortable-placeholder',
					opacity: 0.8,
					tolerance: 'pointer',
					update: function() {
						// Update hidden input values to maintain order
						$sortable.find('li').each(function() {
							const $item = $(this);
							const provider = $item.data('provider');
							$item.find('input[type="hidden"]').val(provider);
						});
					}
				});
			}
		},

		/**
		 * Show a notice message.
		 *
		 * @param {string} message Notice message.
		 * @param {string} type Notice type (success, error, warning, info).
		 */
		showNotice: function(message, type) {
			type = type || 'info';
			const noticeClass = 'notice-' + type;

			const $notice = $('<div>')
				.addClass('notice ' + noticeClass + ' is-dismissible')
				.append($('<p>').text(message));

			$('.wrap h1').after($notice);

			// Auto-dismiss after 5 seconds.
			setTimeout(function() {
				$notice.fadeOut(function() {
					$(this).remove();
				});
			}, 5000);
		},

		/**
		 * Initialize range sliders.
		 */
		initSliders: function() {
			$('.wp-mcp-ai-slider').on('input', function() {
				const $slider = $(this);
				const value = $slider.val();
				const suffix = $slider.data('suffix') || '';
				const valueId = $slider.attr('id') + '-value';
				$('#' + valueId).text('[' + value + suffix + ']');
			});
		},

		/**
		 * Initialize preset selectors.
		 */
		initPresets: function() {
			const self = this;

			$('.apply-preset').on('click', function(e) {
				e.preventDefault();
				const $button = $(this);
				const presetId = $button.data('preset');

				if (!presetId) {
					return;
				}

				// Confirm before applying (except for custom preset)
				if (presetId !== 'custom' && !confirm('Apply the "' + $button.closest('.preset-card').find('h4').text() + '" preset? This will update all orchestration settings.')) {
					return;
				}

				$button.prop('disabled', true).text('Applying...');

				// Use the error service for consistent error handling
				$.wpMcpAiAjax({
					url: wpMcpAiDashboard.ajaxUrl,
					type: 'POST',
					data: {
						action: 'wp_mcp_ai_apply_orchestration_preset',
						nonce: wpMcpAiDashboard.nonce,
						preset_id: presetId
					}
				}, {
					success: function(response) {
						if (response.success) {
							self.showNotice('Preset applied successfully. Reloading page...', 'success');
							setTimeout(function() {
								window.location.reload();
							}, 1000);
						} else {
							self.showNotice(response.data.message || 'Failed to apply preset.', 'error');
							$button.prop('disabled', false).text('Apply');
						}
					},
					error: function(error) {
						self.showNotice(error.userMessage || 'An error occurred while applying the preset.', 'error');
						$button.prop('disabled', false).text('Apply');
					}
				});
			});
		}
	};

	// Initialize when DOM is ready.
	$(document).ready(function() {
		// eslint-disable-next-line camelcase
		WP_MCP_AI_Dashboard.init();
	});

	// Expose to global scope if needed.
	// eslint-disable-next-line camelcase
	window.WP_MCP_AI_Dashboard = WP_MCP_AI_Dashboard;

})(jQuery);
