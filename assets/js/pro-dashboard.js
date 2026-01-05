/**
 * NV oOS Pro Dashboard JavaScript
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 */

(function($) {
	'use strict';

	const ProDashboard = {
		/**
		 * Initialize Pro Dashboard functionality.
		 */
		init: function() {
			this.setupEventListeners();
			this.initializeComponents();
		},

		/**
		 * Setup event listeners.
		 */
		setupEventListeners: function() {
			// Add event listeners for Pro Dashboard interactions
			$(document).on('click', '.wp-mcp-ai-pro-notice .notice-dismiss', this.dismissProNotice);
		},

		/**
		 * Initialize dashboard components.
		 */
		initializeComponents: function() {
			// Animate progress bars on load
			this.animateProgressBars();
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
		 * Dismiss pro notice.
		 */
		dismissProNotice: function() {
			const $notice = $(this).closest('.wp-mcp-ai-pro-notice');
			$notice.fadeOut();
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
		ProDashboard.init();
	});

})(jQuery);
