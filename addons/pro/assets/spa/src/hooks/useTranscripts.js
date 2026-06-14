/**
 * useTranscripts — Conversation session management hook.
 *
 * Owns the session key lifecycle (generate, persist, load), the
 * conversation list for the sidebar, and the initialMessages for
 * hydrating the chat surface. Follows the chat-spa addon's pattern.
 *
 * @param {object}  options
 * @param {string}  options.endpoint     Transcripts REST endpoint.
 * @param {number}  options.assistantId  Current assistant ID.
 * @param {boolean} options.disabled     True when transcripts unavailable.
 * @returns {object} Session state and actions.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import {
	TranscriptsClient,
	generateSessionKey,
	getStoredSessionKey,
	storeSessionKey,
} from '../services/transcripts';

export function useTranscripts({ endpoint, assistantId = 0, disabled = false }) {
	const client = useMemo(
		() => new TranscriptsClient(endpoint),
		[endpoint]
	);

	// Lazily compute the initial session key from localStorage or generate new.
	const [sessionKey, setSessionKey] = useState(() => {
		if (disabled) return generateSessionKey();
		return getStoredSessionKey(assistantId) || generateSessionKey();
	});

	const [initialMessages, setInitialMessages] = useState([]);
	const [isLoading, setIsLoading] = useState(false);
	const [sessions, setSessions] = useState(null);
	const [unavailableMessage, setUnavailableMessage] = useState(null);
	const [error, setError] = useState(null);

	// Persist session key changes to localStorage.
	useEffect(() => {
		if (!disabled && sessionKey) {
			storeSessionKey(assistantId, sessionKey);
		}
	}, [sessionKey, assistantId, disabled]);

	const abortRef = useRef(null);

	/** Refresh the conversation list from the server. */
	const refreshList = useCallback(async () => {
		if (disabled) {
			setSessions([]);
			return;
		}
		try {
			const data = await client.list();
			if (data.message) {
				setUnavailableMessage(data.message);
				setSessions([]);
				return;
			}
			setUnavailableMessage(null);
			setSessions(data.sessions);
		} catch (err) {
			setError(err instanceof Error ? err.message : String(err));
			setSessions([]);
		}
	}, [client, disabled]);

	// Load session list on mount.
	useEffect(() => {
		void refreshList();
	}, [refreshList]);

	/** Switch to an existing conversation, loading its messages. */
	const selectSession = useCallback(async (nextKey) => {
		if (disabled || !nextKey || nextKey === sessionKey) return;
		abortRef.current?.abort();
		const controller = new AbortController();
		abortRef.current = controller;
		setIsLoading(true);
		setError(null);
		try {
			const detail = await client.get(nextKey);
			if (controller.signal.aborted) return;
			const messages = normaliseMessages(detail?.session?.messages);
			setInitialMessages(messages);
			setSessionKey(nextKey);
		} catch (err) {
			if (!controller.signal.aborted) {
				setError(err instanceof Error ? err.message : String(err));
			}
		} finally {
			if (!controller.signal.aborted) setIsLoading(false);
		}
	}, [client, disabled, sessionKey]);

	/** Start a brand-new conversation. */
	const startNewSession = useCallback(() => {
		abortRef.current?.abort();
		setError(null);
		setInitialMessages([]);
		setSessionKey(generateSessionKey());
	}, []);

	/** Delete a conversation from the server and sidebar. */
	const deleteSession = useCallback(async (target) => {
		if (disabled || !target) return;
		try {
			await client.delete(target);
			setSessions((prev) =>
				Array.isArray(prev) ? prev.filter((s) => s.session_key !== target) : prev
			);
			if (target === sessionKey) {
				startNewSession();
			}
		} catch (err) {
			setError(err instanceof Error ? err.message : String(err));
		}
	}, [client, disabled, sessionKey, startNewSession]);

	/** Save messages after a turn completes. */
	const saveTranscript = useCallback(async (messages, metadata) => {
		if (disabled || !sessionKey) return;
		try {
			await client.save(sessionKey, messages, metadata);
			void refreshList();
		} catch {
			// Soft-fail — don't block the chat surface.
		}
	}, [client, disabled, sessionKey, refreshList]);

	return {
		sessionKey,
		isLoading,
		initialMessages,
		sessions,
		unavailableMessage,
		error,
		refreshList,
		selectSession,
		startNewSession,
		deleteSession,
		saveTranscript,
	};
}

/**
 * Normalise raw server messages for useChat initialMessages.
 * Deduplicates consecutive identical messages (self-healing).
 */
function normaliseMessages(raw) {
	if (!Array.isArray(raw)) return [];
	const out = [];
	for (const item of raw) {
		if (!item || typeof item !== 'object') continue;
		const role = typeof item.role === 'string' ? item.role : '';
		if (!['user', 'assistant', 'system', 'tool'].includes(role)) continue;
		const content = typeof item.content === 'string' ? item.content : '';
		const prev = out[out.length - 1];
		if (prev && prev.role === role && prev.content === content) continue;
		out.push({ ...item, role, content });
	}
	return out;
}
