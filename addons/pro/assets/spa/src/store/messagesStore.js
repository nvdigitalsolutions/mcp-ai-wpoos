/**
 * Messages Store — Messages by thread ID.
 */

import { create } from 'zustand';
import { apiGet } from '../services/api';

export const useMessagesStore = create((set, get) => ({
	/** Map of threadId -> { messages: [], total: 0, streaming: null } */
	threads: {},

	getMessages: (threadId) => get().threads[threadId] || { messages: [], total: 0 },

	/** Set messages for a thread (from API). */
	setMessages: (threadId, messages, total) =>
		set((s) => ({
			threads: { ...s.threads, [threadId]: { ...s.threads[threadId], messages, total } },
		})),

	/** Add a user message optimistically. */
	addUserMessage: (threadId, content) =>
		set((s) => {
			const current = s.threads[threadId] || { messages: [], total: 0 };
			return {
				threads: {
					...s.threads,
					[threadId]: {
						...current,
						messages: [...current.messages, { role: 'user', content, id: `temp-${Date.now()}` }],
						total: current.total + 1,
					},
				},
			};
		}),

	/** Start a streaming assistant message. */
	startStream: (threadId) =>
		set((s) => {
			const current = s.threads[threadId] || { messages: [], total: 0 };
			return {
				threads: {
					...s.threads,
					[threadId]: {
						...current,
						streaming: { role: 'assistant', content: '', id: 'streaming' },
						messages: [...current.messages, { role: 'assistant', content: '', id: 'streaming' }],
					},
				},
			};
		}),

	/** Append a chunk to the streaming message. */
	appendChunk: (threadId, chunk) =>
		set((s) => {
			const current = s.threads[threadId];
			if (!current) return s;
			const msgs = [...current.messages];
			const last = msgs[msgs.length - 1];
			if (last?.id === 'streaming') {
				msgs[msgs.length - 1] = { ...last, content: last.content + (chunk.content || '') };
			}
			return { threads: { ...s.threads, [threadId]: { ...current, messages: msgs, streaming: msgs[msgs.length - 1] } } };
		}),

	/** Finalize the streaming message. */
	endStream: (threadId, messageId, checkpointId) =>
		set((s) => {
			const current = s.threads[threadId];
			if (!current) return s;
			const msgs = current.messages.map((m) =>
				m.id === 'streaming' ? { ...m, id: messageId, checkpoint_id: checkpointId } : m
			);
			return { threads: { ...s.threads, [threadId]: { ...current, messages: msgs, streaming: null } } };
		}),

	/** Fetch messages from API. */
	fetchMessages: async (threadId, page = 1) => {
		const res = await apiGet(`/mcp-ai/v1/threads/${threadId}/messages`, { page, per_page: 100 });
		if (res.success) {
			get().setMessages(threadId, res.data.messages, res.data.total);
		}
	},
}));
