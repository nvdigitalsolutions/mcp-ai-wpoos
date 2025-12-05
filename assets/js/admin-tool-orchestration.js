/**
 * Tool Orchestration Settings - Inline Editing
 *
 * Handles inline editing of capability flags and force-sync settings.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

(function ($) {
	'use strict';

	/**
	 * Initialize tool orchestration inline editing.
	 */
	function initToolOrchestrationEditing() {
		// Edit button click handler
		$(document).on('click', '.wp-mcp-ai-edit-tool', function (e) {
			e.preventDefault();
			const toolSlug = $(this).data('tool-slug');
			const $row = $(this).closest('tr');
			
			// Switch to edit mode
			$row.find('.wp-mcp-ai-capability-flags-view').hide();
			$row.find('.wp-mcp-ai-capability-flags-edit').show();
			$row.find('.wp-mcp-ai-edit-tool').hide();
			$row.find('.wp-mcp-ai-edit-actions').show();
			
			// Highlight row
			$row.addClass('wp-mcp-ai-editing');
		});

		// Cancel button click handler
		$(document).on('click', '.wp-mcp-ai-cancel-edit', function (e) {
			e.preventDefault();
			const toolSlug = $(this).data('tool-slug');
			const $row = $(this).closest('tr');
			
			// Reload page to reset changes
			window.location.reload();
		});

		// Save button click handler
		$(document).on('click', '.wp-mcp-ai-save-tool', function (e) {
			e.preventDefault();
			const toolSlug = $(this).data('tool-slug');
			const $row = $(this).closest('tr');
			const $button = $(this);
			
			// Collect selected capability flags
			const capabilityFlags = [];
			$row.find('.wp-mcp-ai-capability-flag-checkbox:checked').each(function() {
				capabilityFlags.push($(this).val());
			});
			
			// Get force-sync setting
			const forceSync = $row.find('.wp-mcp-ai-force-sync-checkbox').is(':checked');
			
			// Disable button and show loading state
			$button.prop('disabled', true).text(wp.i18n.__('Saving...', 'wp-mcp-ai'));
			
			// Send AJAX request
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_save_tool_settings',
					nonce: wpMcpAiDashboard.nonce,
					tool_slug: toolSlug,
					capability_flags: capabilityFlags,
					force_sync: forceSync ? 'true' : 'false'
				},
				success: function(response) {
					if (response.success) {
						// Show success message
						showNotice('success', response.data.message || wp.i18n.__('Tool settings saved successfully.', 'wp-mcp-ai'));
						
						// Reload page to show updated settings
						setTimeout(function() {
							window.location.reload();
						}, 500);
					} else {
						// Show error message
						showNotice('error', response.data.message || wp.i18n.__('Failed to save tool settings.', 'wp-mcp-ai'));
						$button.prop('disabled', false).text(wp.i18n.__('Save', 'wp-mcp-ai'));
					}
				},
				error: function(xhr, status, error) {
					showNotice('error', wp.i18n.__('An error occurred while saving tool settings.', 'wp-mcp-ai'));
					$button.prop('disabled', false).text(wp.i18n.__('Save', 'wp-mcp-ai'));
				}
			});
		});

		// Reset button click handler
		$(document).on('click', '.wp-mcp-ai-reset-tool', function (e) {
			e.preventDefault();
			const toolSlug = $(this).data('tool-slug');
			const $button = $(this);
			
			if (!confirm(wp.i18n.__('Are you sure you want to reset this tool to default settings? This will remove all custom capability flags and force-sync settings.', 'wp-mcp-ai'))) {
				return;
			}
			
			// Disable button and show loading state
			$button.prop('disabled', true).text(wp.i18n.__('Resetting...', 'wp-mcp-ai'));
			
			// Send AJAX request with empty flags to reset
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_save_tool_settings',
					nonce: wpMcpAiDashboard.nonce,
					tool_slug: toolSlug,
					capability_flags: [],
					force_sync: 'false'
				},
				success: function(response) {
					if (response.success) {
						// Show success message
						showNotice('success', wp.i18n.__('Tool reset to default settings.', 'wp-mcp-ai'));
						
						// Reload page to show updated settings
						setTimeout(function() {
							window.location.reload();
						}, 500);
					} else {
						// Show error message
						showNotice('error', response.data.message || wp.i18n.__('Failed to reset tool settings.', 'wp-mcp-ai'));
						$button.prop('disabled', false).text(wp.i18n.__('Reset to Default', 'wp-mcp-ai'));
					}
				},
				error: function(xhr, status, error) {
					showNotice('error', wp.i18n.__('An error occurred while resetting tool settings.', 'wp-mcp-ai'));
					$button.prop('disabled', false).text(wp.i18n.__('Reset to Default', 'wp-mcp-ai'));
				}
			});
		});
	}

	/**
	 * Show admin notice.
	 *
	 * @param {string} type Type of notice (success, error, warning, info).
	 * @param {string} message Message to display.
	 */
	function showNotice(type, message) {
		const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
		$('.wp-mcp-ai-orchestration-section, .wp-mcp-ai-token-manager').prepend($notice);
		
		// Auto-dismiss after 5 seconds
		setTimeout(function() {
			$notice.fadeOut(function() {
				$(this).remove();
			});
		}, 5000);
	}

	// Initialize on document ready
	$(document).ready(function() {
		initToolOrchestrationEditing();
	});

})(jQuery);
