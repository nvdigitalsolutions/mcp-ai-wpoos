/**
 * Asset Inventory Admin JavaScript
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

(function($) {
	'use strict';

	const config = window.wpMcpAiAssetInventory || null;

	/**
	 * Asset Inventory Manager
	 */
	const AssetInventoryManager = {
		/**
		 * Escape HTML special characters to prevent XSS.
		 *
		 * @param {string} text Text to escape.
		 * @return {string} Escaped text.
		 */
		escapeHtml: function(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		/**
		 * Initialize
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind DOM events
		 */
		bindEvents: function() {
			// Discover assets button
			$('#wp-mcp-ai-discover-assets').on('click', this.discoverAssets.bind(this));

			// Filter dropdowns
			$('#wp-mcp-ai-filter-classification, #wp-mcp-ai-filter-type').on('change', this.filterAssets.bind(this));
		},

		/**
		 * Discover assets via REST API
		 */
		discoverAssets: function() {
			const $button = $('#wp-mcp-ai-discover-assets');
			const $notice = $('.wp-mcp-ai-inventory-notice');

			if (!config) {
				console.error('[WP MCP AI] Asset discovery: configuration object (wpMcpAiAssetInventory) is missing.');
				this.showError('Asset inventory configuration is missing.');
				return;
			}

			console.log('[WP MCP AI] Asset discovery starting. Endpoint:', config.apiUrl + '/discover');

			// Show loading state
			$button.addClass('loading').text(config.strings.discovering);
			$notice.hide().removeClass('notice-success notice-error');

			// Make API request
			$.ajax({
				url: config.apiUrl + '/discover',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', config.nonce);
				},
				success: function(response) {
					if (response.success) {
						const assetCount = parseInt(response.count, 10) || 0;
						console.log('[WP MCP AI] Asset discovery succeeded.', assetCount, 'assets found.');
						$notice
							.addClass('notice notice-success')
							.empty()
							.append(
								$('<p>').text(
									config.strings.discoverySuccess + ' (' + assetCount + ' assets)'
								)
							)
							.show();

						// Reload page to show updated inventory
						setTimeout(function() {
							window.location.reload();
						}, 1500);
					} else {
						console.warn('[WP MCP AI] Asset discovery returned failure.', response.message || '(no message)');
						AssetInventoryManager.showError(response.message || config.strings.discoveryError);
					}
				},
				error: function(xhr, status, error) {
					console.error('[WP MCP AI] Asset discovery AJAX error.', {
						status: status,
						error: error,
						httpStatus: xhr.status,
						responseText: String(xhr.responseText || '').substring(0, 200)
					});
					AssetInventoryManager.showError(config.strings.discoveryError + ' ' + error);
				},
				complete: function() {
					$button.removeClass('loading').text(config.strings.discoverButton);
				}
			});
		},

		/**
		 * Show error notice
		 *
		 * @param {string} message Error message
		 */
		showError: function(message) {
			$('.wp-mcp-ai-inventory-notice')
				.addClass('notice notice-error')
				.empty()
				.append($('<p>').text(message))
				.show();
		},

		/**
		 * Filter assets table
		 */
		filterAssets: function() {
			const classification = $('#wp-mcp-ai-filter-classification').val();
			const type = $('#wp-mcp-ai-filter-type').val();

			$('#wp-mcp-ai-assets-table tbody tr').each(function() {
				const $row = $(this);
				const rowClassification = $row.data('classification');
				const rowType = $row.data('type');

				let showRow = true;

				// Filter by classification
				if (classification && rowClassification !== classification) {
					showRow = false;
				}

				// Filter by type
				if (type && rowType !== type) {
					showRow = false;
				}

				// Show/hide row
				if (showRow) {
					$row.removeClass('hidden');
				} else {
					$row.addClass('hidden');
				}
			});

			// Update visible count
			this.updateVisibleCount();
		},

		/**
		 * Update visible asset count
		 */
		updateVisibleCount: function() {
			const visibleCount = $('#wp-mcp-ai-assets-table tbody tr:not(.hidden)').length;
			const totalCount = $('#wp-mcp-ai-assets-table tbody tr').length;

			// Could add a counter display here if needed
			console.log('[WP MCP AI] Asset filter: showing ' + visibleCount + ' of ' + totalCount + ' assets');
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
		AssetInventoryManager.init();
	});

})(jQuery);
