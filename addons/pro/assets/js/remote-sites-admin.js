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
		$('.wp-mcp-ai-copy-redirect-uri').on('click', async function(e) {
			e.preventDefault();
			
			const $button = $(this);
			const textToCopy = $button.data('clipboard-text');
			const $dashicon = $button.find('.dashicons');
			const originalIcon = $dashicon.attr('class');
			const originalText = getButtonText($button);
			
			// Try to copy to clipboard.
			const success = await copyToClipboard(textToCopy);
			
			if (success) {
				// Show success feedback.
				$dashicon.removeClass('dashicons-clipboard').addClass('dashicons-yes');
				$button.addClass('button-primary');
				setButtonText($button, wpMcpAiRemoteSites.copiedText);
				
				// Reset after 2 seconds.
				setTimeout(function() {
					$dashicon.attr('class', originalIcon);
					$button.removeClass('button-primary');
					setButtonText($button, originalText);
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
				const $input = $button.closest('td').find('.wp-mcp-ai-oauth-redirect-uri');
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
	 * @return {Promise<boolean>} Promise that resolves to true if successful, false otherwise.
	 */
	async function copyToClipboard(text) {
		// Try modern Clipboard API first.
		if (navigator.clipboard && window.isSecureContext) {
			try {
				await navigator.clipboard.writeText(text);
				return true;
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error('Clipboard API failed:', err);
			}
		}
		
		// Fallback to legacy method.
		try {
			const textArea = document.createElement('textarea');
			textArea.value = text;
			textArea.style.position = 'fixed';
			textArea.style.left = '-999999px';
			textArea.style.top = '-999999px';
			document.body.appendChild(textArea);
			textArea.focus();
			textArea.select();
			
			const successful = document.execCommand('copy');
			document.body.removeChild(textArea);
			
			return successful;
		} catch (err) {
			// eslint-disable-next-line no-console
			console.error('Legacy copy method failed:', err);
			return false;
		}
	}

	/**
	 * Get text node content from button.
	 *
	 * @param {jQuery} $button Button element.
	 * @return {string} Text node content.
	 */
	function getButtonText($button) {
		return $button.contents().filter(function() {
			return this.nodeType === 3; // Text node
		}).text();
	}

	/**
	 * Set text node content for button.
	 *
	 * @param {jQuery} $button Button element.
	 * @param {string} text    New text content.
	 */
	function setButtonText($button, text) {
		$button.contents().filter(function() {
			return this.nodeType === 3;
		}).replaceWith(' ' + text);
	}

	/**
	 * Initialize on document ready.
	 */
	$(document).ready(function() {
		initCopyToClipboard();
	});

})(jQuery);
