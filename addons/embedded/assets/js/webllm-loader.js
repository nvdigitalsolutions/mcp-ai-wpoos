/**
 * WebLLM Loader Script
 * 
 * Loads WebLLM library as an ES module using dynamic import().
 * This script handles the async loading and provides events for other scripts to wait for.
 * 
 * Best Practice: Separate loader script instead of inline JavaScript.
 * Reference: https://yourwpweb.com/2025/09/26/how-to-enqueue-es-module-scripts-and-use-dynamic-import-in-wp-in-wordpress/
 * 
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

(function() {
	'use strict';

	// Prevent duplicate loading if script is loaded multiple times.
	if (window.wpMcpAiWebLLMLoading) {
		return;
	}

	window.wpMcpAiWebLLMLoading = true;

	/**
	 * Load WebLLM using dynamic import()
	 * 
	 * Dynamic import() is the recommended way to load ES modules in WordPress.
	 * Benefits:
	 * - Works with WordPress's script loading system
	 * - Avoids CORS issues
	 * - Provides proper error handling
	 * - Allows conditional loading
	 */
	import('https://esm.run/@mlc-ai/web-llm')
		.then(function(webLLM) {
			// Store WebLLM in global scope for access by other scripts.
			window.webLLM = webLLM;
			window.wpMcpAiWebLLMLoaded = true;

			console.log('[NV oOS] WebLLM loaded successfully from CDN');

			// Dispatch custom event to notify other scripts that WebLLM is ready.
			// This allows the embedded-llm-client.js to wait for this event.
			if (typeof Event === 'function') {
				window.dispatchEvent(new Event('webllm-ready'));
			} else {
				// Fallback for older browsers.
				const event = document.createEvent('Event');
				event.initEvent('webllm-ready', true, true);
				window.dispatchEvent(event);
			}
		})
		.catch(function(error) {
			console.error('[NV oOS] Failed to load WebLLM from CDN:', error);
			window.wpMcpAiWebLLMError = error;
			window.wpMcpAiWebLLMLoading = false;

			// Dispatch error event so other scripts can handle it.
			if (typeof CustomEvent === 'function') {
				window.dispatchEvent(new CustomEvent('webllm-error', { 
					detail: {
						error: error,
						message: 'Failed to load WebLLM: ' + error.message,
						suggestions: [
							'Check your internet connection',
							'Verify your browser supports ES modules',
							'Check browser console for CORS or network errors',
							'Try using a different browser (Chrome, Edge, or Safari recommended)'
						]
					}
				}));
			} else {
				// Fallback for older browsers.
				const event = document.createEvent('CustomEvent');
				event.initCustomEvent('webllm-error', true, true, { error: error });
				window.dispatchEvent(event);
			}
		});
})();
