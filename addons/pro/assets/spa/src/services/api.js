/**
 * API Service — REST client for NV oOS endpoints.
 *
 * Wraps fetch() with WordPress nonce injection, JSON parsing,
 * and consistent error handling.
 */

const { nonce, restUrl } = window.wpMcpAiPro || {};

/**
 * Send a GET request to a REST endpoint.
 *
 * @param {string} path   Endpoint path (e.g., '/mcp-ai/v1/threads').
 * @param {object} params Query parameters.
 * @returns {Promise<object>} Parsed JSON response.
 */
export async function apiGet(path, params = {}) {
	const url = new URL(restUrl + path.replace(/^\//, ''));
	Object.entries(params).forEach(([k, v]) => {
		if (v !== undefined && v !== null && v !== '') {
			url.searchParams.set(k, v);
		}
	});

	const response = await fetch(url.toString(), {
		headers: {
			'X-WP-Nonce': nonce,
		},
	});

	if (!response.ok) {
		const error = await response.json().catch(() => ({}));
		throw new Error(error.message || `HTTP ${response.status}`);
	}

	return response.json();
}

/**
 * Send a POST/PUT/DELETE request.
 *
 * @param {string} method HTTP method.
 * @param {string} path   Endpoint path.
 * @param {object} body   Request body.
 * @returns {Promise<object>}
 */
export async function apiMutate(method, path, body = {}) {
	const response = await fetch(restUrl + path.replace(/^\//, ''), {
		method,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		body: method !== 'DELETE' ? JSON.stringify(body) : undefined,
	});

	if (!response.ok) {
		const error = await response.json().catch(() => ({}));
		throw new Error(error.message || `HTTP ${response.status}`);
	}

	return response.json();
}

export const apiPost = (path, body) => apiMutate('POST', path, body);
export const apiPut = (path, body) => apiMutate('PUT', path, body);
export const apiDelete = (path, body) => apiMutate('DELETE', path, body);
