/**
 * WP oOS Settings Dashboard JavaScript
 *
 * Handles tab switching, AJAX operations, and UI interactions.
 */

(function($) {
	'use strict';

	const WP_MCP_AI_Dashboard = {
		/**
		 * Initialize the dashboard.
		 */
		init: function() {
			this.bindEvents();
			this.initTooltips();
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
		}
	};

	// Initialize when DOM is ready.
	$(document).ready(function() {
		WP_MCP_AI_Dashboard.init();
	});

	// Expose to global scope if needed.
	window.WP_MCP_AI_Dashboard = WP_MCP_AI_Dashboard;

})(jQuery);
