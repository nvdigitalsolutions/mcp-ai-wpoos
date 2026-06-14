/**
 * Transcripts Client — typed wrappers around mcp-ai/v1/chat-transcripts.
 *
 * Ported from the chat-spa addon (src/api/transcripts.ts). Uses the
 * existing api.js helpers for WordPress nonce injection.
 *
 *   GET    /chat-transcripts                — list sessions
 *   POST   /chat-transcripts                — save session
 *   GET    /chat-transcripts/{key}          — load session
 *   DELETE /chat-transcripts/{key}          — delete session
 */

import { apiGet, apiPost, apiDelete } from './api';

const STORAGE_KEY_PREFIX = 'nvoos-pro-spa.active-session';

/**
 * Generate a stable per-conversation session key matching the legacy
 * format (wp-mcp-ai-session-<hex>).
 */
export function generateSessionKey() {
	if (
		typeof window !== 'undefined' &&
		window.crypto &&
		typeof window.crypto.getRandomValues === 'function'
	) {
		const array = new Uint8Array(16);
		window.crypto.getRandomValues(array);
		return (
			'wp-mcp-ai-session-' +
			Array.from(array, (byte) => byte.toString(16).padStart(2, '0')).join('')
		);
	}
	return 'wp-mcp-ai-session-' + Date.now().toString(16);
}

export function getStoredSessionKey(assistantId) {
	try {
		const stored = window.localStorage.getItem(
			`${STORAGE_KEY_PREFIX}.${assistantId || 0}`
		);
		if (stored && /^[a-zA-Z0-9_-]+$/.test(stored)) {
			return stored;
		}
	} catch {
		// Ignore storage errors.
	}
	return null;
}

export function storeSessionKey(assistantId, key) {
	try {
		window.localStorage.setItem(
			`${STORAGE_KEY_PREFIX}.${assistantId || 0}`,
			key
		);
	} catch {
		// Ignore storage errors.
	}
}

export class TranscriptsClient {
	constructor(endpoint) {
		this.endpoint = endpoint.replace(/\/+$/, '');
	}

	async list(signal) {
		const params = { per_page: 50 };
		const data = await apiGet(this.endpoint, params, signal);
		return {
			sessions: Array.isArray(data?.sessions) ? data.sessions : [],
			total: typeof data?.total === 'number' ? data.total : 0,
			message: typeof data?.message === 'string' ? data.message : null,
		};
	}

	async get(sessionKey, signal) {
		const data = await apiGet(
			`${this.endpoint}/${encodeURIComponent(sessionKey)}`,
			{},
			signal
		);
		return {
			session: data?.session || null,
			message: typeof data?.message === 'string' ? data.message : null,
		};
	}

	async save(sessionKey, messages, metadata) {
		await apiPost(this.endpoint, {
			assistant_id: 0,
			session_key: sessionKey,
			messages,
			response_metadata: metadata || {},
		});
	}

	async delete(sessionKey) {
		await apiDelete(this.endpoint + '/' + encodeURIComponent(sessionKey));
	}
}
