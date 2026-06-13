import { useCallback } from '@wordpress/element';
import { useThreadsStore } from '../store/threadsStore';
import { useMessagesStore } from '../store/messagesStore';
import { createSSEStream } from '../services/sse';
import { apiPost } from '../services/api';

export function useThreads(threadId) {
	const threadsStore = useThreadsStore();
	const messagesStore = useMessagesStore();

	const sendMessage = useCallback(async (content, mentions = []) => {
		if (!threadId) return;

		// Add user message.
		messagesStore.addUserMessage(threadId, content);

		// Start streaming.
		messagesStore.startStream(threadId);

		// Open SSE stream.
		createSSEStream(
			`/mcp-ai/v1/threads/${threadId}/messages`,
			{ content, context_mentions: mentions },
			{
				onChunk: (chunk) => {
					messagesStore.appendChunk(threadId, chunk);
				},
				onToolCall: (toolCall) => {
					// Tool call cards handled inline in message content.
				},
				onDone: (data) => {
					messagesStore.endStream(threadId, data.message_id, data.checkpoint_id);
				},
				onError: (err) => {
					messagesStore.appendChunk(threadId, { content: `\n\n[Error: ${err.message}]` });
					messagesStore.endStream(threadId);
				},
			}
		);
	}, [threadId, messagesStore]);

	return {
		threads: threadsStore.threads,
		activeThread: threadsStore.threads.find((t) => t.id === parseInt(threadId, 10)),
		activeThreadId: threadsStore.activeThreadId,
		setActiveThread: threadsStore.setActiveThread,
		createThread: threadsStore.createThread,
		archiveThread: threadsStore.archiveThread,
		restoreThread: threadsStore.restoreThread,
		summarizeThread: threadsStore.summarizeThread,
		sendMessage,
	};
}
