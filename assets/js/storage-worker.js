/**
 * Web Worker for handling heavy localStorage operations.
 * 
 * This worker handles JSON parsing/stringifying of large conversation data
 * to prevent blocking the main thread and causing requestIdleCallback violations.
 * 
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @since     1.1.62 Wired via wpMcpAiChat.storageWorkerUrl (proposal 032).
 */

/* global self */

self.addEventListener('message', function(e) {
	const { action, data, id } = e.data;

	try {
		let result;

		switch (action) {
			case 'parse':
				// Parse JSON string to object
				result = JSON.parse(data);
				self.postMessage({ id: id, success: true, result: result });
				break;

			case 'stringify':
				// Stringify object to JSON
				result = JSON.stringify(data);
				self.postMessage({ id: id, success: true, result: result });
				break;

			default:
				self.postMessage({ 
					id: id, 
					success: false, 
					error: 'Unknown action: ' + action 
				});
		}
	} catch (error) {
		self.postMessage({ 
			id: id, 
			success: false, 
			error: error.message || 'Operation failed' 
		});
	}
});
