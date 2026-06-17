import { useCallback } from '@wordpress/element';
import { useThreadsStore } from '../store/threadsStore';
import { useMessagesStore } from '../store/messagesStore';
import { createSSEStream } from '../services/sse';
import { apiPost } from '../services/api';

/**
 * Threads hook — read-only historical view.
 *
 * Threads are treated as a read-only history of past conversations.
 * To browse a thread, select it; messages are loaded via GET.
 *
 * Sending a new message goes through the conversation transport
 * (/mcp-ai/v1/chat-client), not the thread endpoint.  This keeps
 * threads as a safe, immutable archive and avoids coupling the chat
 * transport to the OOS engine's thread pipeline.
 *
 * @param {number} threadId Thread ID from URL params (optional).
 * @returns {object} Thread store methods (read operations only).
 */
export function useThreads(threadId) {
	const threadsStore = useThreadsStore();
	const messagesStore = useMessagesStore();

	return {
		threads: threadsStore.threads,
		activeThread: threadsStore.threads.find((t) => t.id === parseInt(threadId, 10)),
		activeThreadId: threadsStore.activeThreadId,
		setActiveThread: threadsStore.setActiveThread,
		createThread: threadsStore.createThread,
		archiveThread: threadsStore.archiveThread,
		restoreThread: threadsStore.restoreThread,
		summarizeThread: threadsStore.summarizeThread,
	};
}
