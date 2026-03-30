/**
 * Create AI Team Button Script
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		if (!window.wpMcpAiCreateTeamButton) {
			return;
		}

		// Add button after the Create AI Assistant button.
		const button = '<button type="button" class="page-title-action wp-mcp-ai-create-team-btn" id="wp-mcp-ai-open-create-team-modal">' + wpMcpAiCreateTeamButton.buttonText + '</button>';
		$('#wp-mcp-ai-open-create-modal').after(button);
	});

})(jQuery);
