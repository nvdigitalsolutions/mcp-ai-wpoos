/**
 * REST API helpers for demo video scripts.
 *
 * CommonJS port of tests/qa/playwright/utils/wp-helpers.ts.
 * Provides typed helpers for calling the NV oOS MCP REST endpoints
 * with proper authentication.
 */

const API_PREFIX = '/wp-json/mcp-ai/v1';

/**
 * Make an authenticated request to the MCP API.
 *
 * @param {import('playwright').APIRequestContext} request
 * @param {'GET'|'POST'} method
 * @param {string} endpoint - API path (e.g., '/assistants').
 * @param {object} [options]
 * @param {string} [options.nonce] - WordPress REST nonce.
 * @param {string} [options.bearerToken] - Bearer token for assistant creds.
 * @param {object} [options.data] - Request body (for POST).
 * @returns {Promise<import('playwright').APIResponse>}
 */
async function mcpApiRequest(request, method, endpoint, options = {}) {
	const headers = { 'Content-Type': 'application/json' };

	if (options.nonce) {
		headers['X-WP-Nonce'] = options.nonce;
	}
	if (options.bearerToken) {
		headers['Authorization'] = `Bearer ${options.bearerToken}`;
	}

	const url = `${API_PREFIX}${endpoint}`;

	if (method === 'GET') {
		return request.get(url, { headers });
	}
	return request.post(url, { headers, data: options.data });
}

/**
 * List all assistants via the MCP API.
 *
 * @param {import('playwright').APIRequestContext} request
 * @param {string} nonce
 * @returns {Promise<Array>}
 */
async function listAssistants(request, nonce) {
	const response = await mcpApiRequest(request, 'GET', '/assistants', { nonce });
	if (response.status() !== 200) {
		throw new Error(`listAssistants failed: ${response.status()}`);
	}
	return response.json();
}

/**
 * List all tools via the MCP API.
 *
 * @param {import('playwright').APIRequestContext} request
 * @param {string} nonce
 * @returns {Promise<Array>}
 */
async function listTools(request, nonce) {
	const response = await mcpApiRequest(request, 'GET', '/tools/list', { nonce });
	if (response.status() !== 200) {
		throw new Error(`listTools failed: ${response.status()}`);
	}
	return response.json();
}

/**
 * Execute a tool via the MCP API.
 *
 * @param {import('playwright').APIRequestContext} request
 * @param {string} nonce
 * @param {string} toolSlug
 * @param {object} [args]
 * @returns {Promise<import('playwright').APIResponse>}
 */
async function executeTool(request, nonce, toolSlug, args = {}) {
	return mcpApiRequest(request, 'POST', '/tools/run', {
		nonce,
		data: { tool_slug: toolSlug, arguments: args },
	});
}

/**
 * Send a chat message via the MCP API (non-streaming).
 *
 * @param {import('playwright').APIRequestContext} request
 * @param {string} message
 * @param {object} [options]
 * @param {string} [options.nonce]
 * @param {string} [options.guestToken]
 * @param {number} [options.assistantId]
 * @returns {Promise<import('playwright').APIResponse>}
 */
async function sendChatMessage(request, message, options = {}) {
	const headers = { 'Content-Type': 'application/json' };

	if (options.nonce) {
		headers['X-WP-Nonce'] = options.nonce;
	}
	if (options.guestToken) {
		headers['X-WP-MCP-AI-Guest'] = options.guestToken;
	}

	return request.post(`${API_PREFIX}/chat`, {
		headers,
		data: {
			message,
			assistant_id: options.assistantId,
			stream: false,
		},
	});
}

module.exports = {
	mcpApiRequest,
	listAssistants,
	listTools,
	executeTool,
	sendChatMessage,
};
