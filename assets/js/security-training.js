/**
 * Security Training Admin JavaScript
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

(function($) {
	'use strict';

	/**
	 * Security Training Manager
	 */
	const TrainingManager = {
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
			// View module button
			$('.wp-mcp-ai-view-module').on('click', this.viewModule.bind(this));

			// Complete module button
			$('.wp-mcp-ai-complete-module').on('click', this.completeModule.bind(this));
		},

		/**
		 * View module content
		 */
		viewModule: function(e) {
			const $button = $(e.currentTarget);
			const $module = $button.closest('.wp-mcp-ai-training-module');
			const $content = $module.find('.wp-mcp-ai-module-full-content');

			$content.slideToggle();
			$button.text($content.is(':visible') ? 'Hide Module' : 'View Module');
		},

		/**
		 * Mark module as complete
		 */
		completeModule: function(e) {
			const $button = $(e.currentTarget);
			const moduleId = $button.data('module-id');
			const $notice = $('.wp-mcp-ai-training-notice');

			// Show loading state
			$button.prop('disabled', true).text('Completing...');
			$notice.hide().removeClass('notice-success notice-error');

			// Make API request
			$.ajax({
				url: wpMcpAiTraining.apiUrl + '/complete',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpMcpAiTraining.nonce);
				},
				data: {
					module_id: moduleId
				},
				success: function(response) {
					if (response.success) {
						$notice
							.addClass('notice notice-success')
							.html('<p>' + wpMcpAiTraining.strings.completeSuccess + '</p>')
							.show();

						// Reload page to update progress
						setTimeout(function() {
							window.location.reload();
						}, 1500);
					} else {
						TrainingManager.showError(wpMcpAiTraining.strings.completeError);
						$button.prop('disabled', false).text('Mark as Complete');
					}
				},
				error: function() {
					TrainingManager.showError(wpMcpAiTraining.strings.completeError);
					$button.prop('disabled', false).text('Mark as Complete');
				}
			});
		},

		/**
		 * Show error notice
		 *
		 * @param {string} message Error message
		 */
		showError: function(message) {
			$('.wp-mcp-ai-training-notice')
				.addClass('notice notice-error')
				.empty()
				.append($('<p>').text(message))
				.show();
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
		TrainingManager.init();
	});

})(jQuery);
