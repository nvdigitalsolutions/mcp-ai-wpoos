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
			this.initMeshPeers();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Form submission - use event delegation to handle dynamically loaded forms
			// Only attach handler to POST forms since GET forms (like filters) should use default browser navigation
			// Using multiple selectors for broader browser compatibility
			$(document).on('submit', 'form[method="post"], form[method="POST"]', this.handleFormSubmit.bind(this));

			// Tab switching
			$('.nav-tab').on('click', this.handleTabSwitch.bind(this));

			// Sub-tab switching
			$('.wp-mcp-ai-subtab').on('click', this.handleSubTabSwitch.bind(this));
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
			// Save all tool settings (new enhanced button with multipliers)
			$('#wp-mcp-ai-save-all-tool-settings').on('click', this.handleSaveToolSettings.bind(this));

			// Export token usage to CSV
			$('#wp-mcp-ai-export-usage-csv').on('click', this.handleExportUsageCSV.bind(this));

			// Bulk tier assignment
			$('#wp-mcp-ai-select-all-users').on('change', this.handleSelectAllUsers.bind(this));
			$('.wp-mcp-ai-user-checkbox').on('change', this.handleUserCheckboxChange.bind(this));
			$('#bulk-tier-selector').on('change', this.handleBulkTierSelectorChange.bind(this));
			$('#wp-mcp-ai-apply-bulk-tier').on('click', this.handleApplyBulkTier.bind(this));

			// Tool recommendations
			$('#wp-mcp-ai-view-recommendations').on('click', this.handleViewRecommendations.bind(this));
			$('#wp-mcp-ai-apply-all-recommendations').on('click', this.handleApplyAllRecommendations.bind(this));
			$('#wp-mcp-ai-apply-preset').on('click', this.handleApplyPreset.bind(this));
			$('.wp-mcp-ai-modal-close, .wp-mcp-ai-modal-overlay').on('click', this.handleCloseModal.bind(this));

			// Preset description update
			$('#wp-mcp-ai-preset-selector').on('change', this.handlePresetChange.bind(this));
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
						// Check if this is a "no changes" response
						if (response.data.no_changes) {
							alert(response.data.message);
							$button.prop('disabled', false).text('Save All Tool Limits');
						} else {
							$button.text('Saved!');
							setTimeout(function() {
								window.location.reload();
							}, 1000);
						}
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
		 * Handle save tool settings (limits + multipliers + model preferences).
		 *
		 * @param {Event} e Click event.
		 */
		handleSaveToolSettings: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			const $spinner = $button.next('.spinner');
			const $message = $('#wp-mcp-ai-tool-settings-message');
			const limits = {};
			const multipliers = {};
			const modelPreferences = {};

			// Collect all limits
			$('.wp-mcp-ai-tool-limit-input').each(function() {
				const $input = $(this);
				limits[$input.data('tool-slug')] = $input.val();
			});

			// Collect all multipliers
			$('.wp-mcp-ai-tool-multiplier-input').each(function() {
				const $input = $(this);
				multipliers[$input.data('tool-slug')] = $input.val();
			});

			// Collect all model preferences
			$('.wp-mcp-ai-tool-model-input').each(function() {
				const $select = $(this);
				modelPreferences[$select.data('tool-slug')] = $select.val();
			});

			$button.prop('disabled', true);
			$spinner.addClass('is-active');
			$message.text('').removeClass('error success');

			// Use the error service for consistent error handling
			$.wpMcpAiAjax({
				url: wpMcpAiDashboard.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_save_tool_limits',
					nonce: wpMcpAiDashboard.nonce,
					limits: limits,
					multipliers: multipliers,
					model_preferences: modelPreferences
				}
			}, {
				success: function(response) {
					$spinner.removeClass('is-active');
					if (response.success) {
						// Check if this is a "no changes" response
						if (response.data.no_changes) {
							$message.text(response.data.message).addClass('notice notice-info');
							$button.prop('disabled', false);
						} else {
							$message.text(response.data.message).addClass('notice notice-success');
							setTimeout(function() {
								window.location.reload();
							}, 1500);
						}
					} else {
						$message.text(response.data.message || 'Failed to save tool settings.').addClass('notice notice-error');
						$button.prop('disabled', false);
					}
				},
				error: function(error) {
					$spinner.removeClass('is-active');
					$message.text(error.userMessage || 'An error occurred while saving tool settings.').addClass('notice notice-error');
					$button.prop('disabled', false);
				}
			});
		},

		/**
		 * Handle export token usage to CSV.
		 *
		 * @param {Event} e Click event.
		 */
		handleExportUsageCSV: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);

			$button.prop('disabled', true).text('Exporting...');

			// Create a form to submit the export request
			const $form = $('<form>', {
				method: 'POST',
				action: wpMcpAiDashboard.ajaxUrl,
				target: '_blank'
			});

			// Add hidden fields
			$form.append($('<input>', {
				type: 'hidden',
				name: 'action',
				value: 'wp_mcp_ai_export_token_usage_csv'
			}));

			$form.append($('<input>', {
				type: 'hidden',
				name: 'nonce',
				value: wpMcpAiDashboard.nonce
			}));

			// TODO: Add filter inputs when filter UI is implemented
			// For now, export all users

			// Append form to body and submit
			$form.appendTo('body').submit();

			// Clean up and reset button
			setTimeout(function() {
				$form.remove();
				$button.prop('disabled', false).text('Export to CSV');
			}, 1000);
		},

		/**
		 * Handle select all users checkbox.
		 *
		 * @param {Event} e Change event.
		 */
		handleSelectAllUsers: function(e) {
			const checked = $(e.currentTarget).prop('checked');
			$('.wp-mcp-ai-user-checkbox').prop('checked', checked);
			this.updateBulkActionButton();
		},

		/**
		 * Handle individual user checkbox change.
		 */
		handleUserCheckboxChange: function() {
			// Update select all checkbox state
			const totalCheckboxes = $('.wp-mcp-ai-user-checkbox').length;
			const checkedCheckboxes = $('.wp-mcp-ai-user-checkbox:checked').length;

			$('#wp-mcp-ai-select-all-users').prop('checked', totalCheckboxes === checkedCheckboxes);
			this.updateBulkActionButton();
		},

		/**
		 * Handle bulk tier selector change.
		 */
		handleBulkTierSelectorChange: function() {
			this.updateBulkActionButton();
		},

		/**
		 * Update bulk action button state.
		 */
		updateBulkActionButton: function() {
			const hasSelection = $('.wp-mcp-ai-user-checkbox:checked').length > 0;
			const hasTier = $('#bulk-tier-selector').val() !== '';
			$('#wp-mcp-ai-apply-bulk-tier').prop('disabled', !hasSelection || !hasTier);
		},

		/**
		 * Handle apply bulk tier action.
		 *
		 * @param {Event} e Click event.
		 */
		handleApplyBulkTier: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			const tier = $('#bulk-tier-selector').val();
			const userIds = [];

			$('.wp-mcp-ai-user-checkbox:checked').each(function() {
				userIds.push($(this).val());
			});

			if (userIds.length === 0) {
				alert('Please select at least one user.');
				return;
			}

			if (!tier) {
				alert('Please select a tier.');
				return;
			}

			const tierName = $('#bulk-tier-selector option:selected').text();
			if (!confirm('Are you sure you want to assign ' + tierName + ' to ' + userIds.length + ' user(s)?')) {
				return;
			}

			$button.prop('disabled', true).text('Applying...');

			// Use the error service for consistent error handling
			$.wpMcpAiAjax({
				url: wpMcpAiDashboard.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_bulk_assign_tier',
					nonce: wpMcpAiDashboard.nonce,
					user_ids: userIds,
					tier: tier
				}
			}, {
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						window.location.reload();
					} else {
						alert(response.data.message || 'Failed to assign tiers.');
						$button.prop('disabled', false).text('Apply');
					}
				},
				error: function(error) {
					alert(error.userMessage || 'An error occurred while assigning tiers.');
					$button.prop('disabled', false).text('Apply');
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
			
			// Get form data for logging.
			const formData = new FormData($form[0]);
			const activeTab = formData.get('active_tab');
			const settingsData = {};
			let fieldCount = 0;
			
			// Extract wp_mcp_ai_settings fields for logging.
			for (const [key, value] of formData.entries()) {
				if (key.startsWith('wp_mcp_ai_settings[')) {
					const fieldName = key.match(/wp_mcp_ai_settings\[([^\]]+)\]/)[1];
					settingsData[fieldName] = value;
					fieldCount++;
				}
			}
			
			// Log form submission details to console.
			console.log('[WP oOS Settings] Form submission initiated');
			console.log('[WP oOS Settings] Active tab:', activeTab);
			console.log('[WP oOS Settings] Fields being submitted:', fieldCount);
			console.log('[WP oOS Settings] Field names:', Object.keys(settingsData).join(', '));
			console.log('[WP oOS Settings] Form action:', $form.attr('action'));
			
			// Check for potential issues.
			if (fieldCount === 0) {
				console.warn('[WP oOS Settings] WARNING: No settings fields found in form data!');
			}
			
			if (!activeTab) {
				console.warn('[WP oOS Settings] WARNING: No active_tab value found!');
			}

			// Add loading state.
			$submit.prop('disabled', true);
			$form.addClass('loading');
			
			console.log('[WP oOS Settings] Form is now submitting...');

			// Original text will be restored on page reload after redirect.
			// Note: This is a standard POST submission, not AJAX.
			// The page will reload after the server processes and redirects.
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
		 * Handle sub-tab switching (provider sub-tabs).
		 *
		 * @param {Event} e Click event.
		 */
		handleSubTabSwitch: function(e) {
			// Allow default navigation for sub-tabs.
			const $subtab = $(e.currentTarget);
			$subtab.addClass('loading');
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
							// Update the hidden field value immediately so if user clicks "Save Changes" 
							// before reload, the correct preset will be saved
							$('#orchestration_preset').val(presetId);
							
							// Update the active preset indicator text immediately
							const presetName = $('.preset-card[data-preset="' + presetId + '"] h4').text();
							$('.current-preset-name').text(presetName);
							
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
		},

		/**
		 * Initialize mesh peer sites functionality.
		 */
		initMeshPeers: function() {
			const $meshPeers = $('#wp-mcp-ai-mesh-peers');
			if ($meshPeers.length === 0) {
				return;
			}

			let peerIndex = parseInt($meshPeers.data('peer-index'), 10) || 0;
			const $addButton = $('#wp-mcp-ai-add-peer');
			const optionName = $meshPeers.data('option-name');
			const placeholderName = $addButton.data('placeholder-name');
			const placeholderUrl = $addButton.data('placeholder-url');
			const placeholderKey = $addButton.data('placeholder-key');
			const btnRemove = $addButton.data('btn-remove');

			// Add peer site
			$addButton.on('click', function() {
				const newRow = $('<tr class="wp-mcp-ai-mesh-peer-row">' +
					'<td><input type="text" name="' + optionName + '[mesh_peer_sites][' + peerIndex + '][name]" value="" class="regular-text" placeholder="' + placeholderName + '" /></td>' +
					'<td><input type="url" name="' + optionName + '[mesh_peer_sites][' + peerIndex + '][url]" value="" class="regular-text" placeholder="' + placeholderUrl + '" /></td>' +
					'<td><input type="text" name="' + optionName + '[mesh_peer_sites][' + peerIndex + '][api_key]" value="" class="regular-text" placeholder="' + placeholderKey + '" /></td>' +
					'<td><button type="button" class="button wp-mcp-ai-remove-peer">' + btnRemove + '</button></td>' +
					'</tr>');
				$meshPeers.find('tbody').append(newRow);
				peerIndex++;
			});

			// Remove peer site (delegated event)
			$meshPeers.on('click', '.wp-mcp-ai-remove-peer', function() {
				$(this).closest('tr').remove();
			});
		},

		/**
		 * Handle view recommendations modal.
		 *
		 * @param {Event} e Click event.
		 */
		handleViewRecommendations: function(e) {
			e.preventDefault();
			$('#wp-mcp-ai-recommendations-modal').fadeIn(200);
		},

		/**
		 * Handle apply all recommendations.
		 *
		 * @param {Event} e Click event.
		 */
		handleApplyAllRecommendations: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);

			if (!confirm('Are you sure you want to apply recommended settings to all tools? This will overwrite your current multiplier and model preference settings.')) {
				return;
			}

			$button.prop('disabled', true).text('Applying...');

			$.wpMcpAiAjax({
				url: wpMcpAiDashboard.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_apply_all_recommendations',
					nonce: wpMcpAiDashboard.nonce
				}
			}, {
				success: function(response) {
					if (response.success) {
						alert(response.data.message || 'Recommendations applied successfully!');
						window.location.reload();
					} else {
						alert(response.data.message || 'Failed to apply recommendations.');
						$button.prop('disabled', false).text('Apply Recommended Settings to All Tools');
					}
				},
				error: function(error) {
					alert(error.userMessage || 'An error occurred while applying recommendations.');
					$button.prop('disabled', false).text('Apply Recommended Settings to All Tools');
				}
			});
		},

		/**
		 * Handle close modal.
		 *
		 * @param {Event} e Click event.
		 */
		handleCloseModal: function(e) {
			e.preventDefault();
			$('#wp-mcp-ai-recommendations-modal').fadeOut(200);
		},

		/**
		 * Handle preset change.
		 *
		 * @param {Event} e Change event.
		 */
		handlePresetChange: function(e) {
			const preset = $(e.currentTarget).val();
			const descriptions = {
				'conservative': 'Lower token limits for cost control. Best for budget-conscious deployments.',
				'balanced': 'Optimal balance between performance and cost. Uses our analyzed recommendations.',
				'performance': 'Higher token limits for maximum performance. Best for high-traffic or demanding applications.',
				'aggressive': 'Maximum token limits for complex operations. Use when cost is not a concern.'
			};

			$('#wp-mcp-ai-preset-description').text(descriptions[preset] || descriptions['balanced']);
		},

		/**
		 * Handle apply preset.
		 *
		 * @param {Event} e Click event.
		 */
		handleApplyPreset: function(e) {
			e.preventDefault();
			const $button = $(e.currentTarget);
			const preset = $('#wp-mcp-ai-preset-selector').val();

			if (!preset) {
				alert('Please select a preset.');
				return;
			}

			const presetNames = {
				'conservative': 'Conservative',
				'balanced': 'Balanced',
				'performance': 'Performance',
				'aggressive': 'Aggressive'
			};

			if (!confirm('Apply the ' + (presetNames[preset] || preset) + ' preset to all tools? This will overwrite your current settings.')) {
				return;
			}

			$button.prop('disabled', true).text('Applying...');

			$.wpMcpAiAjax({
				url: wpMcpAiDashboard.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_apply_preset',
					nonce: wpMcpAiDashboard.nonce,
					preset: preset
				}
			}, {
				success: function(response) {
					if (response.success) {
						alert(response.data.message || 'Preset applied successfully!');
						window.location.reload();
					} else {
						alert(response.data.message || 'Failed to apply preset.');
						$button.prop('disabled', false).text('Apply Preset');
					}
				},
				error: function(error) {
					alert(error.userMessage || 'An error occurred while applying the preset.');
					$button.prop('disabled', false).text('Apply Preset');
				}
			});
		}
	};

	/**
	 * Initialize Brave Search connection test handlers.
	 */
	function initBraveSearchHandlers() {
		// Test Brave Search connection
		$('#wp-mcp-ai-test-brave-search-connection').on('click', function (e) {
			e.preventDefault();
			const $button = $(this);
			const $result = $('#wp-mcp-ai-brave-search-test-result');
			const apiKey = $('input[name="wp_mcp_ai_settings[brave_search_api_key]"]').val();

			if (!apiKey) {
				$result.html('<span style="color: #d63638;">Please enter an API key first.</span>');
				return;
			}

			$button.prop('disabled', true).text('Testing...');
			$result.html('<span style="color: #3c434a;">Connecting to Brave Search...</span>');

			// Use the error service for consistent error handling
			$.wpMcpAiAjax({
				url: wpMcpAiAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_test_brave_search_connection',
					nonce: wpMcpAiAdmin.nonce,
					api_key: apiKey
				}
			}, {
				success: function (response) {
					if (response.success) {
						$result.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
					} else {
						$result.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
					}
				},
				error: function (error) {
					$result.html('<span style="color: #d63638;">✗ ' + (error.userMessage || 'Connection failed') + '</span>');
				},
				complete: function () {
					$button.prop('disabled', false).text('Test Connection');
				}
			});
		});
	}

	/**
	 * Initialize Cloudflare connection test handlers.
	 */
	function initCloudflareHandlers() {
		$('#wp-mcp-ai-test-cloudflare-connection').on('click', function (e) {
			e.preventDefault();
			const $button = $(this);
			const $result = $('#wp-mcp-ai-cloudflare-test-result');
			const $zoneInfo = $('#wp-mcp-ai-cloudflare-zone-info');
			const zoneId = $('input[name="wp_mcp_ai_settings[cloudflare_zone_id]"]').val();
			const apiToken = $('input[name="wp_mcp_ai_settings[cloudflare_api_token]"]').val();

			if (!zoneId || !apiToken) {
				$result.html('<span style="color: #d63638;">Please enter both Zone ID and API Token first.</span>');
				return;
			}

			$button.prop('disabled', true).text('Testing...');
			$result.html('<span style="color: #3c434a;">Connecting to Cloudflare...</span>');
			$zoneInfo.html('');

			$.wpMcpAiAjax({
				url: wpMcpAiAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_test_cloudflare_connection',
					nonce: wpMcpAiAdmin.nonce,
					zone_id: zoneId,
					api_token: apiToken
				}
			}, {
				success: function (response) {
					if (response.success) {
						$result.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
						if (response.data.zone_info) {
							const zoneData = response.data.zone_info;
							let html = '<div style="background: #f0f0f1; padding: 10px; border-radius: 4px; margin-top: 10px;">';
							html += '<p style="margin: 0 0 5px 0;"><strong>Zone Information:</strong></p>';
							html += '<ul style="margin: 0; padding-left: 20px;">';
							if (zoneData.name) {
								html += '<li><strong>Domain:</strong> ' + zoneData.name + '</li>';
							}
							if (zoneData.status) {
								html += '<li><strong>Status:</strong> ' + zoneData.status + '</li>';
							}
							if (zoneData.plan) {
								html += '<li><strong>Plan:</strong> ' + zoneData.plan + '</li>';
							}
							html += '</ul></div>';
							$zoneInfo.html(html);
						}
					} else {
						$result.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
						$zoneInfo.html('');
					}
				},
				error: function (error) {
					$result.html('<span style="color: #d63638;">✗ ' + (error.userMessage || 'Connection failed') + '</span>');
					$zoneInfo.html('');
				},
				complete: function () {
					$button.prop('disabled', false).text('Test Connection');
				}
			});
		});
	}

	/**
	 * Initialize Cloudways connection test handlers.
	 */
	function initCloudwaysHandlers() {
		$('#wp-mcp-ai-fetch-cloudways-data').on('click', function (e) {
			e.preventDefault();
			const $button = $(this);
			const $result = $('#wp-mcp-ai-cloudways-fetch-result');
			const $serversList = $('#wp-mcp-ai-cloudways-servers-list');
			const $appsList = $('#wp-mcp-ai-cloudways-apps-list');
			const email = $('input[name="wp_mcp_ai_settings[cloudways_email]"]').val();
			const apiKey = $('input[name="wp_mcp_ai_settings[cloudways_api_key]"]').val();

			if (!email || !apiKey) {
				$result.html('<span style="color: #d63638;">Please enter both email and API key first.</span>');
				return;
			}

			$button.prop('disabled', true).text('Fetching...');
			$result.html('<span style="color: #3c434a;">Connecting to Cloudways...</span>');
			$serversList.html('');
			$appsList.html('');

			$.wpMcpAiAjax({
				url: wpMcpAiAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_fetch_cloudways_data',
					nonce: wpMcpAiAdmin.nonce,
					email: email,
					api_key: apiKey
				}
			}, {
				success: function (response) {
					if (response.success) {
						$result.html('<span style="color: #00a32a;">✓ Successfully fetched Cloudways data</span>');
						
						// Display servers
						if (response.data.servers && response.data.servers.length > 0) {
							const $list = $('#wp-mcp-ai-cloudways-servers-list');
							$list.empty();
							const $title = $('<p><strong>Select a server:</strong></p>');
							const $ul = $('<ul style="list-style: disc; margin-left: 20px;"></ul>');
							response.data.servers.forEach(function (server) {
								const $li = $('<li style="margin-bottom: 5px;"></li>');
								const $link = $('<a href="#" class="wp-mcp-ai-select-cloudways-server"></a>');
								$link.attr('data-server-id', server.id);
								$link.text(server.label + ' (ID: ' + server.id + ', Status: ' + server.status + ')');
								$li.append($link);
								$ul.append($li);
							});
							$list.append($title).append($ul);
						}
						
						// Display apps
						if (response.data.apps && response.data.apps.length > 0) {
							const $list = $('#wp-mcp-ai-cloudways-apps-list');
							$list.empty();
							const $title = $('<p><strong>Select an application:</strong></p>');
							const $ul = $('<ul style="list-style: disc; margin-left: 20px;"></ul>');
							response.data.apps.forEach(function (app) {
								const $li = $('<li style="margin-bottom: 5px;"></li>');
								const $link = $('<a href="#" class="wp-mcp-ai-select-cloudways-app"></a>');
								$link.attr('data-app-id', app.id);
								$link.attr('data-server-id', app.server_id);
								$link.text(app.label + ' (ID: ' + app.id + ')');
								$li.append($link);
								$ul.append($li);
							});
							$list.append($title).append($ul);
						}
					} else {
						$result.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
					}
				},
				error: function (error) {
					$result.html('<span style="color: #d63638;">✗ ' + (error.userMessage || 'Failed to connect to Cloudways') + '</span>');
				},
				complete: function () {
					$button.prop('disabled', false).text('Fetch Cloudways Data');
				}
			});
		});
		
		// Handle server selection
		$(document).on('click', '.wp-mcp-ai-select-cloudways-server', function (e) {
			e.preventDefault();
			const serverId = $(this).data('server-id');
			$('input[name="wp_mcp_ai_settings[cloudways_server_id]"]').val(serverId);
			const $message = $('<p style="color: #00a32a; font-weight: bold;"></p>');
			$message.text('Selected Server ID: ' + serverId);
			$('#wp-mcp-ai-cloudways-servers-list').prepend($message);
		});
		
		// Handle app selection
		$(document).on('click', '.wp-mcp-ai-select-cloudways-app', function (e) {
			e.preventDefault();
			const appId = $(this).data('app-id');
			const serverId = $(this).data('server-id');
			$('input[name="wp_mcp_ai_settings[cloudways_app_id]"]').val(appId);
			$('input[name="wp_mcp_ai_settings[cloudways_server_id]"]').val(serverId);
			const $message = $('<p style="color: #00a32a; font-weight: bold;"></p>');
			$message.text('Selected App ID: ' + appId + ' (Server ID: ' + serverId + ')');
			$('#wp-mcp-ai-cloudways-apps-list').prepend($message);
		});
	}

	// Initialize when DOM is ready.
	$(document).ready(function() {
		// eslint-disable-next-line camelcase
		WP_MCP_AI_Dashboard.init();
		
		// Initialize connection test handlers if wpMcpAiAdmin is available
		if (typeof wpMcpAiAdmin !== 'undefined') {
			initBraveSearchHandlers();
			initCloudflareHandlers();
			initCloudwaysHandlers();
		}
	});

	// Expose to global scope if needed.
	// eslint-disable-next-line camelcase
	window.WP_MCP_AI_Dashboard = WP_MCP_AI_Dashboard;

})(jQuery);
