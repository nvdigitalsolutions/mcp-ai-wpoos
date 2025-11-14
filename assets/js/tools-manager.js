/**
 * Tools Manager JavaScript
 *
 * Handles tool enable/disable toggle interactions.
 *
 * @package WP_MCP_AI
 */

(function ($) {
	'use strict';

	/**
	 * Initialize tools manager functionality.
	 */
	function initToolsManager() {
		// Handle tool toggle switches.
		$(document).on('change', '.wp-mcp-ai-tool-toggle', function () {
			const $toggle = $(this);
			const $row = $toggle.closest('tr');
			const toolSlug = $toggle.data('tool-slug');
			const isChecked = $toggle.prop('checked');
			const action = isChecked ? 'enable' : 'disable';

			// Disable toggle during request.
			$toggle.prop('disabled', true);

			// Send AJAX request.
			$.ajax({
				url: wpMcpAiAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_toggle_tool',
					nonce: wpMcpAiAdmin.nonce,
					tool_slug: toolSlug,
					tool_action: action
				},
				success: function (response) {
					if (response.success) {
						// Update status badge.
						const $status = $row.find('.wp-mcp-ai-tool-status');
						const statusText = response.data.enabled 
							? wpMcpAiAdmin.i18n.enabled || 'Enabled'
							: wpMcpAiAdmin.i18n.disabled || 'Disabled';
						const statusColor = response.data.enabled ? '#46b450' : '#999';

						$status.text(statusText).css('background', statusColor);

						// Show success message.
						showNotice(response.data.message, 'success');
					} else {
						// Revert toggle state on error.
						$toggle.prop('checked', !isChecked);
						showNotice(response.data.message || 'Failed to update tool status.', 'error');
					}
				},
				error: function () {
					// Revert toggle state on error.
					$toggle.prop('checked', !isChecked);
					showNotice('An error occurred while updating tool status.', 'error');
				},
				complete: function () {
					// Re-enable toggle.
					$toggle.prop('disabled', false);
				}
			});
		});

		/**
		 * Show admin notice.
		 *
		 * @param {string} message Notice message.
		 * @param {string} type    Notice type (success, error, warning, info).
		 */
		function showNotice(message, type) {
			const $notice = $('<div>')
				.addClass('notice notice-' + type + ' is-dismissible')
				.append($('<p>').text(message));

			// Find the best place to insert the notice.
			const $target = $('.wp-mcp-ai-tools-manager').length 
				? $('.wp-mcp-ai-tools-manager').first()
				: $('.wrap').first();

			if ($target.length) {
				$notice.prependTo($target);

				// Auto-dismiss after 5 seconds.
				setTimeout(function () {
					$notice.fadeOut(function () {
						$(this).remove();
					});
				}, 5000);

				// Make dismissible.
				if (typeof wp !== 'undefined' && wp.notices) {
					wp.notices.initialize();
				}
			}
		}
	}

	// Initialize on document ready.
	$(document).ready(function () {
		if ($('.wp-mcp-ai-tools-manager').length) {
			initToolsManager();
		}
	});

})(jQuery);
