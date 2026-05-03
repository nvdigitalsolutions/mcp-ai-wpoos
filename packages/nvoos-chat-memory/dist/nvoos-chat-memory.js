let _config = { endpoints: null, headers: {}, fetch: null, credentials: 'same-origin' };

/**
 * Configure the chat memory client.
 *
 * @param {Object} options
 * @param {Object} options.endpoints - Endpoint URL map. Required keys:
 *   - wakeUp, recall, store, itemBase, preferences (and optional: audit).
 *   itemBase MUST end with a slash and accepts an appended URL-encoded contextId.
 * @param {Object} [options.headers] - Extra request headers (e.g. auth tokens).
 * @param {Function} [options.fetch] - Custom fetch implementation. Defaults to globalThis.fetch.
 * @param {RequestCredentials} [options.credentials] - fetch credentials mode. Default: 'same-origin'.
 */
function configure(options) {
	options = options || {};
	if (options.endpoints) _config.endpoints = options.endpoints;
	if (options.headers) _config.headers = options.headers;
	if (typeof options.fetch === 'function') _config.fetch = options.fetch;
	if (options.credentials) _config.credentials = options.credentials;
}

/**
 * Chat Memory Service for NV oOS Chat
 *
 * Thin client for the `/mcp-ai/v1/chat-memory/*` REST proxy. Provides
 * promise-based wrappers around the four memory verbs the chat client cares
 * about: wakeUp, recall, store, update, delete — plus per-user preferences.
 *
 * Design notes:
 *  - Uses `fetch` directly (no ky dependency) to keep the bundle slim and to
 *    work in jsdom-based unit tests without polyfilling.
 *  - All requests are nonce-authenticated via `X-WP-Nonce`. The nonce is read
 *    from `window.wpMcpAiChat.nonce`, mirroring the rest of the chat surface.
 *  - Endpoint URLs are read from `window.wpMcpAiChat.memoryEndpoints`. When the
 *    surface is disabled (the localized config omits the block), `isAvailable()`
 *    returns false and every call resolves with a structured "disabled" error
 *    so the UI can degrade gracefully.
 *
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */



	function getEndpoints() {
		return (_config && _config.endpoints) || null;
	}

	function getExtraHeaders() {
		return (_config && _config.headers) || {};
	}

	function getFetch() {
		if (_config && typeof _config.fetch === 'function') {
			return _config.fetch;
		}
		if (typeof globalThis !== 'undefined' && typeof globalThis.fetch === 'function') {
			return globalThis.fetch.bind(globalThis);
		}
		throw new Error('nvoos-chat-memory: no fetch implementation available. Pass one via configure({ fetch }).');
	}

	function getCredentials() {
		return _config && _config.credentials ? _config.credentials : 'same-origin';
	}

	/**
	 * Check whether the chat-memory surface is available in this page context.
	 *
	 * @return {boolean}
	 */
	function isAvailable() {
		const eps = getEndpoints();
		return !!(eps && eps.recall && eps.wakeUp && eps.store);
	}

	/**
	 * Wraps fetch with REST conventions used by NV oOS.
	 *
	 * @param {string} url
	 * @param {Object} options
	 * @return {Promise<Object>}
	 */
	function request(url, options) {
		options = options || {};
		const headers = Object.assign(
			{ Accept: 'application/json' },
			getExtraHeaders(),
			options.headers || {}
		);

		let body;
		if (options.body && typeof options.body === 'object') {
			headers['Content-Type'] = 'application/json';
			body = JSON.stringify(options.body);
		} else {
			body = options.body;
		}

		const fetchImpl = getFetch();
		return fetchImpl(url, {
			method: options.method || 'GET',
			credentials: getCredentials(),
			headers: headers,
			body: body
		}).then(function(response) {
			return response.json().then(
				function(data) {
					if (!response.ok) {
						const error = new Error(
							(data && (data.message || data.code)) || ('HTTP ' + response.status)
						);
						error.status = response.status;
						error.data = data;
						throw error;
					}
					return data;
				},
				function() {
					if (!response.ok) {
						const error = new Error('HTTP ' + response.status);
						error.status = response.status;
						throw error;
					}
					return null;
				}
			);
		});
	}

	function disabledError() {
		const error = new Error('Chat memory surface is not enabled.');
		error.code = 'chat_memory_disabled';
		return error;
	}

	function buildQuery(params) {
		const usp = new URLSearchParams();
		Object.keys(params || {}).forEach(function(key) {
			const value = params[key];
			if (value === undefined || value === null || value === '') {
				return;
			}
			usp.append(key, value);
		});
		const qs = usp.toString();
		return qs ? '?' + qs : '';
	}

	/**
	 * Build a wake-up system block for an agent/wing/room.
	 *
	 * @param {Object} params { agentId, wing, room }
	 * @return {Promise<Object>}
	 */
	function wakeUp(params) {
		if (!isAvailable()) {
			return Promise.reject(disabledError());
		}
		const eps = getEndpoints();
		const qs = buildQuery({
			agent_id: params && params.agentId,
			wing: params && params.wing,
			room: params && params.room
		});
		return request(eps.wakeUp + qs, { method: 'GET' });
	}

	/**
	 * Recall memories matching a query/scope.
	 *
	 * @param {string} query
	 * @param {Object} filters { agentId, wing, room, limit }
	 * @return {Promise<Object>}
	 */
	function recall(query, filters) {
		if (!isAvailable()) {
			return Promise.reject(disabledError());
		}
		const eps = getEndpoints();
		const qs = buildQuery({
			agent_id: filters && filters.agentId,
			wing: filters && filters.wing,
			room: filters && filters.room,
			query: query || '',
			limit: filters && filters.limit
		});
		return request(eps.recall + qs, { method: 'GET' });
	}

	/**
	 * Store a verbatim user-driven memory.
	 *
	 * @param {Object} payload { agentId, wing, room, title, content, tags, importance, contextType, verbatim }
	 * @return {Promise<Object>}
	 */
	function store(payload) {
		if (!isAvailable()) {
			return Promise.reject(disabledError());
		}
		const eps = getEndpoints();
		const body = {
			agent_id: payload && payload.agentId,
			wing: payload && payload.wing,
			room: payload && payload.room,
			title: payload && payload.title,
			content: payload && payload.content,
			tags: payload && payload.tags,
			importance: payload && payload.importance,
			context_type: payload && payload.contextType,
			verbatim: payload && payload.verbatim !== undefined ? !!payload.verbatim : true,
			summarize: payload && payload.summarize !== undefined ? !!payload.summarize : false
		};
		return request(eps.store, { method: 'POST', body: body });
	}

	/**
	 * Store an entry using `fetch(..., { keepalive: true })` so the request
	 * survives a page-unload event (pagehide / visibilitychange→hidden).
	 *
	 * Used by the drawer's auto-summary capture (G6) which needs to fire a
	 * single store() as the user leaves the page. Returns a Promise that
	 * may resolve after the page is gone — callers should not await it.
	 *
	 * @param {Object} payload Same shape as {@link store}.
	 * @return {Promise<Object|null>}
	 */
	function storeBeacon(payload) {
		if (!isAvailable()) {
			return Promise.reject(disabledError());
		}
		const eps = getEndpoints();
		const body = {
			agent_id: payload && payload.agentId,
			wing: payload && payload.wing,
			room: payload && payload.room,
			title: payload && payload.title,
			content: payload && payload.content,
			tags: payload && payload.tags,
			importance: payload && payload.importance,
			context_type: payload && payload.contextType,
			verbatim: payload && payload.verbatim !== undefined ? !!payload.verbatim : true,
			summarize: payload && payload.summarize !== undefined ? !!payload.summarize : false
		};
		const fetchImpl = getFetch();
		return fetchImpl(eps.store, {
			method: 'POST',
			credentials: getCredentials(),
			keepalive: true,
			headers: Object.assign(
				{ Accept: 'application/json', 'Content-Type': 'application/json' },
				getExtraHeaders()
			),
			body: JSON.stringify(body)
		}).then(function(response) {
			if (!response.ok) {
				const error = new Error('HTTP ' + response.status);
				error.status = response.status;
				throw error;
			}
			return response.json().catch(function() { return null; });
		});
	}

	/**
	 * Update an existing memory record.
	 *
	 * @param {string} contextId
	 * @param {Object} patch { agentId, title, content, tags, importance }
	 * @return {Promise<Object>}
	 */
	function update(contextId, patch) {
		if (!isAvailable()) {
			return Promise.reject(disabledError());
		}
		if (!contextId) {
			return Promise.reject(new Error('contextId is required.'));
		}
		const eps = getEndpoints();
		const body = Object.assign(
			{ agent_id: patch && patch.agentId },
			patch || {}
		);
		// Strip aliased keys to avoid double-send.
		delete body.agentId;
		return request(eps.itemBase + encodeURIComponent(contextId), {
			method: 'PUT',
			body: body
		});
	}

	/**
	 * Delete a memory record.
	 *
	 * @param {string} contextId
	 * @param {Object} options { agentId }
	 * @return {Promise<Object>}
	 */
	function remove(contextId, options) {
		if (!isAvailable()) {
			return Promise.reject(disabledError());
		}
		if (!contextId) {
			return Promise.reject(new Error('contextId is required.'));
		}
		const eps = getEndpoints();
		const qs = buildQuery({ agent_id: options && options.agentId });
		return request(eps.itemBase + encodeURIComponent(contextId) + qs, {
			method: 'DELETE'
		});
	}

	/**
	 * Read per-user preferences (enabled, autosummarize).
	 *
	 * @return {Promise<{enabled:boolean, autosummarize:boolean}>}
	 */
	function getPreferences() {
		if (!isAvailable()) {
			return Promise.reject(disabledError());
		}
		const eps = getEndpoints();
		return request(eps.preferences, { method: 'GET' });
	}

	/**
	 * Update per-user preferences.
	 *
	 * @param {{enabled?:boolean, autosummarize?:boolean}} prefs
	 * @return {Promise<Object>}
	 */
	function setPreferences(prefs) {
		if (!isAvailable()) {
			return Promise.reject(disabledError());
		}
		const eps = getEndpoints();
		return request(eps.preferences, { method: 'POST', body: prefs || {} });
	}

	/**
	 * Read the audit-log feed for the active agent. Returns the most recent
	 * create/update/delete/access events. Used by the Memory Drawer's "Audit"
	 * tab so users can see how their memories have changed over time without
	 * needing generic tool-execution permission.
	 *
	 * @since 1.6.0
	 *
	 * @param {Object} options { agentId, limit, actionType }
	 * @return {Promise<Object>} Resolves with `{ entries, total_entries, ... }`.
	 */
	function audit(options) {
		if (!isAvailable()) {
			return Promise.reject(disabledError());
		}
		const eps = getEndpoints();
		if (!eps.audit) {
			// Older sites that haven't refreshed the localized endpoints yet.
			return Promise.reject(disabledError());
		}
		const qs = buildQuery({
			agent_id: options && options.agentId,
			limit: options && options.limit,
			action_type: options && options.actionType
		});
		return request(eps.audit + qs, { method: 'GET' });
	}

	/**
	 * Detect whether a tool result describes memory retrieval, mirroring the
	 * detection in chat.js so the in-chat "🧠 Memory" badge can also surface
	 * server-pushed retrieval events without re-implementing the logic.
	 *
	 * @param {Object} result
	 * @return {boolean}
	 */
	function isMemoryRetrievalResult(result) {
		return !!(
			result &&
			typeof result === 'object' &&
			(
				Array.isArray(result.contexts) ||
				Array.isArray(result.results) ||
				Array.isArray(result.memories)
			)
		);
	}

export {
	configure,
	isAvailable,
	wakeUp,
	recall,
	store,
	storeBeacon,
	update,
	remove,
	remove as delete_,
	audit,
	getPreferences,
	setPreferences,
	isMemoryRetrievalResult
};

export default {
	configure: configure,
	isAvailable: isAvailable,
	wakeUp: wakeUp,
	recall: recall,
	store: store,
	storeBeacon: storeBeacon,
	update: update,
	remove: remove,
	'delete': remove,
	audit: audit,
	getPreferences: getPreferences,
	setPreferences: setPreferences,
	isMemoryRetrievalResult: isMemoryRetrievalResult
};
