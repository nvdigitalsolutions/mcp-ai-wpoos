/**
 * Threads Store — Zustand store for agent conversation threads.
 */

import { create } from 'zustand';
import { apiGet, apiPost, apiPut, apiDelete } from '../services/api';

export const useThreadsStore = create((set, get) => ({
	threads: [],
	total: 0,
	activeThreadId: null,
	loading: false,

	/** Set threads from bootstrap or API response. */
	setThreads: (threads, total) => set({ threads, total }),

	/** Set the active thread ID (switches the Agent Panel view). */
	setActiveThread: (id) => set({ activeThreadId: id }),

	/** Fetch threads from the REST API. */
	fetchThreads: async (status = 'active') => {
		set({ loading: true });
		try {
			const res = await apiGet('/mcp-ai/v1/threads', { status, per_page: 50 });
			if (res.success) {
				set({ threads: res.data.threads, total: res.data.total, loading: false });
			}
		} catch (err) {
			set({ loading: false });
		}
	},

	/** Create a new thread. */
	createThread: async (assistantId = 0, model = {}, profile = 'write', scope = {}) => {
		const res = await apiPost('/mcp-ai/v1/threads', {
			assistant_id: assistantId,
			model,
			profile,
			scope,
		});
		if (res.success) {
			const thread = res.data;
			set((state) => ({
				threads: [thread, ...state.threads],
				total: state.total + 1,
				activeThreadId: thread.id,
			}));
			return thread;
		}
		throw new Error(res.message);
	},

	/** Archive a thread. */
	archiveThread: async (id) => {
		await apiDelete(`/mcp-ai/v1/threads/${id}`);
		set((state) => ({
			threads: state.threads.filter((t) => t.id !== id),
			total: state.total - 1,
			activeThreadId: state.activeThreadId === id ? null : state.activeThreadId,
		}));
	},

	/** Restore an archived thread. */
	restoreThread: async (id) => {
		await apiPost(`/mcp-ai/v1/threads/${id}/restore`);
		// Refetch to get the restored thread.
		get().fetchThreads('active');
	},

	/** Compact (summarize) a thread. */
	summarizeThread: async (id) => {
		const res = await apiPost(`/mcp-ai/v1/threads/${id}/summarize`);
		if (res.success) {
			// Refetch — the old thread is archived, new one is created.
			get().fetchThreads('active');
			if (res.data?.new_thread_id) {
				set({ activeThreadId: res.data.new_thread_id });
			}
		}
		return res;
	},
}));
