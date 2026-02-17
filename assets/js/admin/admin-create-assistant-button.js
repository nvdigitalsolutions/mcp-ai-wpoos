/**
 * Build AI Assistant Button Script
 *
 * @package WP_MCP_AI
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		if (!window.wpMcpAiCreateAssistantButton) {
			return;
		}

		// Add link button after the page title that navigates to the Build Assistant page.
		const button = '<a href="' + wpMcpAiCreateAssistantButton.buildUrl + '" class="page-title-action wp-mcp-ai-create-assistant-btn">' + wpMcpAiCreateAssistantButton.buttonText + '</a>';
		$('.wrap h1.wp-heading-inline').after(button);
	});

})(jQuery);
