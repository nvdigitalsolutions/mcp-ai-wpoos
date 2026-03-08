/**
 * Asset Inventory Admin JavaScript
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

(function($) {
	'use strict';

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

			// Show loading state
			$button.addClass('loading').text(wpMcpAiAssetInventory.strings.discovering);
			$notice.hide().removeClass('notice-success notice-error');

			// Make API request
			$.ajax({
				url: wpMcpAiAssetInventory.apiUrl + '/discover',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpMcpAiAssetInventory.nonce);
				},
				success: function(response) {
					if (response.success) {
						$notice
							.addClass('notice notice-success')
							.html('<p>' + wpMcpAiAssetInventory.strings.discoverySuccess + ' (' + parseInt(response.count, 10) + ' assets)</p>')
							.show();

						// Reload page to show updated inventory
						setTimeout(function() {
							window.location.reload();
						}, 1500);
					} else {
						AssetInventoryManager.showError(response.message);
					}
				},
				error: function(xhr, status, error) {
					AssetInventoryManager.showError(wpMcpAiAssetInventory.strings.discoveryError + ' ' + error);
				},
				complete: function() {
					$button.removeClass('loading').text('Discover Assets');
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
			console.log('Showing ' + visibleCount + ' of ' + totalCount + ' assets');
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
		AssetInventoryManager.init();
	});

})(jQuery);
