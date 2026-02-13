/**
 * Mesh Peer Connection Testing
 *
 * Handles testing mesh peer connections from the admin interface.
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	/**
	 * Mesh Peer Test Handler
	 */
	const MeshPeerTest = {
		/**
		 * Initialize the test handler.
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Handle test button clicks.
			$(document).on('click', '.wp-mcp-ai-test-mesh-peer', this.handleTestClick.bind(this));
		},

		/**
		 * Handle test button click.
		 *
		 * @param {Event} e Click event.
		 */
		handleTestClick: function(e) {
			e.preventDefault();

			const $button = $(e.currentTarget);
			const $row = $button.closest('tr');
			const index = $row.index();

			// Get peer data from the row.
			const name = $row.find('input[name*="[name]"]').val();
			const url = $row.find('input[name*="[url]"]').val();
			const apiKey = $row.find('input[name*="[api_key]"]').val();

			// Validate required fields.
			if (!url) {
				this.showError($button, wpMcpAiMeshTest.errorNoUrl);
				return;
			}

			// Generate peer ID based on URL.
			const peerId = 'mesh_' + this.md5(url);

			// Show loading state.
			this.setButtonLoading($button, true);

			// Make AJAX request to test the connection.
			$.ajax({
				url: wpMcpAiMeshTest.restUrl + 'mcp-ai/v1/mesh/test-peer',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpMcpAiMeshTest.nonce);
				},
				data: {
					name: name,
					url: url,
					api_key: apiKey,
					peer_id: peerId
				},
				success: this.handleTestSuccess.bind(this, $button),
				error: this.handleTestError.bind(this, $button),
				complete: function() {
					// Reset button state after a delay.
					setTimeout(function() {
						this.setButtonLoading($button, false);
					}.bind(this), 2000);
				}.bind(this)
			});
		},

		/**
		 * Handle successful test response.
		 *
		 * @param {jQuery} $button Button element.
		 * @param {Object} response Response data.
		 */
		handleTestSuccess: function($button, response) {
			// Show success message.
			const message = this.buildSuccessMessage(response);
			this.showSuccess($button, message);

			// Log details to console for debugging.
			console.log('Mesh peer test results:', response);
		},

		/**
		 * Handle test error response.
		 *
		 * @param {jQuery} $button Button element.
		 * @param {Object} xhr XHR object.
		 */
		handleTestError: function($button, xhr) {
			let message = wpMcpAiMeshTest.errorGeneric;

			if (xhr.responseJSON && xhr.responseJSON.message) {
				message = xhr.responseJSON.message;
			}

			this.showError($button, message);

			// Log error to console for debugging.
			console.error('Mesh peer test error:', xhr);
		},

		/**
		 * Build success message from test results.
		 *
		 * @param {Object} results Test results.
		 * @return {string} Success message.
		 */
		buildSuccessMessage: function(results) {
			let message = wpMcpAiMeshTest.successMessage;

			if (results.site_name) {
				message += ' (' + results.site_name + ')';
			}

			const details = [];

			if (results.reachable) {
				details.push(wpMcpAiMeshTest.reachable);
			}

			if (results.wellknown) {
				details.push(wpMcpAiMeshTest.federationEnabled);
			}

			if (results.authenticated) {
				details.push(wpMcpAiMeshTest.authSuccess);
			} else if (results.details && results.details.authentication && results.details.authentication.status === 'skipped') {
				details.push(wpMcpAiMeshTest.authSkipped);
			} else {
				details.push(wpMcpAiMeshTest.authFailed);
			}

			if (details.length > 0) {
				message += '\n• ' + details.join('\n• ');
			}

			return message;
		},

		/**
		 * Set button loading state.
		 *
		 * @param {jQuery} $button Button element.
		 * @param {boolean} loading Whether button is loading.
		 */
		setButtonLoading: function($button, loading) {
			if (loading) {
				$button.prop('disabled', true);
				$button.data('original-text', $button.text());
				$button.html('<span class="dashicons dashicons-update-alt" style="animation: rotation 1s infinite linear;"></span> ' + wpMcpAiMeshTest.testing);
			} else {
				$button.prop('disabled', false);
				const originalText = $button.data('original-text');
				if (originalText) {
					$button.text(originalText);
				}
			}
		},

		/**
		 * Show success message.
		 *
		 * @param {jQuery} $button Button element.
		 * @param {string} message Success message.
		 */
		showSuccess: function($button, message) {
			// Create success notice.
			const $notice = $('<div class="notice notice-success is-dismissible" style="margin: 10px 0;"><p>' + this.escapeHtml(message).replace(/\n/g, '<br>') + '</p></div>');

			// Insert after the button's row.
			const $row = $button.closest('tr');
			const $noticeRow = $('<tr class="wp-mcp-ai-test-notice"><td colspan="4"></td></tr>');
			$noticeRow.find('td').append($notice);

			// Remove any existing notice for this row.
			$row.next('.wp-mcp-ai-test-notice').remove();

			// Insert new notice.
			$row.after($noticeRow);

			// Auto-dismiss after 5 seconds.
			setTimeout(function() {
				$noticeRow.fadeOut(300, function() {
					$noticeRow.remove();
				});
			}, 5000);
		},

		/**
		 * Show error message.
		 *
		 * @param {jQuery} $button Button element.
		 * @param {string} message Error message.
		 */
		showError: function($button, message) {
			// Create error notice.
			const $notice = $('<div class="notice notice-error is-dismissible" style="margin: 10px 0;"><p>' + this.escapeHtml(message) + '</p></div>');

			// Insert after the button's row.
			const $row = $button.closest('tr');
			const $noticeRow = $('<tr class="wp-mcp-ai-test-notice"><td colspan="4"></td></tr>');
			$noticeRow.find('td').append($notice);

			// Remove any existing notice for this row.
			$row.next('.wp-mcp-ai-test-notice').remove();

			// Insert new notice.
			$row.after($noticeRow);

			// Auto-dismiss after 7 seconds.
			setTimeout(function() {
				$noticeRow.fadeOut(300, function() {
					$noticeRow.remove();
				});
			}, 7000);
		},

		/**
		 * Escape HTML.
		 *
		 * @param {string} text Text to escape.
		 * @return {string} Escaped text.
		 */
		escapeHtml: function(text) {
			const map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};
			return text.replace(/[&<>"']/g, function(m) { return map[m]; });
		},

		/**
		 * Simple MD5 hash (for generating peer ID).
		 *
		 * @param {string} str String to hash.
		 * @return {string} MD5 hash.
		 */
		md5: function(str) {
			// Simple hash function (not cryptographic).
			// In production, this matches the PHP md5() on the backend.
			let hash = 0;
			if (str.length === 0) return hash.toString(16);
			for (let i = 0; i < str.length; i++) {
				const char = str.charCodeAt(i);
				hash = ((hash << 5) - hash) + char;
				hash = hash & hash;
			}
			return Math.abs(hash).toString(16);
		}
	};

	// Initialize when document is ready.
	$(document).ready(function() {
		MeshPeerTest.init();
	});

})(jQuery);

// Add CSS for spinner animation.
jQuery(document).ready(function($) {
	if (!$('#wp-mcp-ai-mesh-test-styles').length) {
		$('<style id="wp-mcp-ai-mesh-test-styles">@keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>').appendTo('head');
	}
});
