/**
 * Pro Incidents Admin JavaScript
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

(function () {
	'use strict';

	// Auto-focus the message input when phase transition buttons are clicked.
	document.addEventListener('DOMContentLoaded', function () {
		var buttons = document.querySelectorAll('.wp-mcp-ai-incident-actions button[name="new_phase"]');
		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var messageInput = document.querySelector('.wp-mcp-ai-incident-actions input[name="message"]');
				if (messageInput && messageInput.value.trim() === '') {
					messageInput.focus();
				}
			});
		});
	});

})();
