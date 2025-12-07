/**
 * Web Worker for handling heavy localStorage operations.
 * 
 * This worker handles JSON parsing/stringifying of large conversation data
 * to prevent blocking the main thread and causing requestIdleCallback violations.
 * 
 * @package WP_MCP_AI
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
