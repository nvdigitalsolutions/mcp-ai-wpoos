/**
 * Remote Sites Admin JavaScript
 *
 * Handles copy-to-clipboard functionality for OAuth redirect URIs and other
 * interactive features on the Remote Sites admin page.
 *
 * @package WP_MCP_AI_Pro
 */

(function($) {
	'use strict';

	/**
	 * Initialize copy-to-clipboard functionality.
	 */
	function initCopyToClipboard() {
		$('.wp-mcp-ai-copy-redirect-uri').on('click', function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var textToCopy = $button.data('clipboard-text');
			var $dashicon = $button.find('.dashicons');
			var originalIcon = $dashicon.attr('class');
			
			// Try to copy to clipboard.
			if (copyToClipboard(textToCopy)) {
				// Show success feedback.
				$dashicon.removeClass('dashicons-clipboard').addClass('dashicons-yes');
				$button.addClass('button-primary');
				
				// Show temporary "Copied!" message.
				var originalText = $button.contents().filter(function() {
					return this.nodeType === 3; // Text node
				}).text();
				
				$button.contents().filter(function() {
					return this.nodeType === 3;
				}).replaceWith(' ' + wpMcpAiRemoteSites.copiedText);
				
				// Reset after 2 seconds.
				setTimeout(function() {
					$dashicon.attr('class', originalIcon);
					$button.removeClass('button-primary');
					$button.contents().filter(function() {
						return this.nodeType === 3;
					}).replaceWith(' ' + originalText);
				}, 2000);
			} else {
				// Show error feedback.
				$dashicon.removeClass('dashicons-clipboard').addClass('dashicons-warning');
				alert(wpMcpAiRemoteSites.copyError);
				
				// Reset icon after 2 seconds.
				setTimeout(function() {
					$dashicon.attr('class', originalIcon);
				}, 2000);
				
				// Select the text input as fallback.
				var $input = $button.closest('td').find('.wp-mcp-ai-oauth-redirect-uri');
				$input.select();
			}
		});
	}

	/**
	 * Copy text to clipboard.
	 *
	 * Uses modern Clipboard API if available, falls back to legacy method.
	 *
	 * @param {string} text Text to copy.
	 * @return {boolean} True if successful, false otherwise.
	 */
	function copyToClipboard(text) {
		// Try modern Clipboard API first.
		if (navigator.clipboard && window.isSecureContext) {
			try {
				navigator.clipboard.writeText(text);
				return true;
			} catch (err) {
				console.error('Clipboard API failed:', err);
			}
		}
		
		// Fallback to legacy method.
		try {
			var textArea = document.createElement('textarea');
			textArea.value = text;
			textArea.style.position = 'fixed';
			textArea.style.left = '-999999px';
			textArea.style.top = '-999999px';
			document.body.appendChild(textArea);
			textArea.focus();
			textArea.select();
			
			var successful = document.execCommand('copy');
			document.body.removeChild(textArea);
			
			return successful;
		} catch (err) {
			console.error('Legacy copy method failed:', err);
			return false;
		}
	}

	/**
	 * Initialize on document ready.
	 */
	$(document).ready(function() {
		initCopyToClipboard();
	});

})(jQuery);
