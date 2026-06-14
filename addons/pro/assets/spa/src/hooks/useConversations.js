/**
 * useConversations — Chat transport via /mcp-ai/v1/chat-client.
 *
 * Mirrors the chat-spa's pattern: all new messages are sent through
 * the conversation transport (chat-client SSE), keeping threads as a
 * read-only historical view.  This avoids coupling the chat transport
 * to the OOS engine's thread pipeline and matches the architecture
 * used by the NV oOS Chat SPA addon.
 *
 * @since 1.1.30
 */

import { useCallback } from '@wordpress/element';
import { useMessagesStore } from '../store/messagesStore';
import { useModelStore } from '../store/modelStore';
import { useSettingsStore } from '../store/settingsStore';
import { createSSEStream } from '../services/sse';

/** Synthetic thread ID used for in-memory conversation messages. */
const CONVERSATION_THREAD_ID = '__conversation__';

export function useConversations() {
	const messagesStore = useMessagesStore();
	const { model } = useModelStore();
	const { settings } = useSettingsStore();

	const sendMessage = useCallback(async (content, mentions = [], options = {}) => {
		const { assistantId = 0 } = options;

		// Add user message optimistically in the conversation buffer.
		messagesStore.addUserMessage(CONVERSATION_THREAD_ID, content);

		// Start streaming placeholder.
		messagesStore.startStream(CONVERSATION_THREAD_ID);

		// Build the messages array — just the current turn since
		// /chat-client manages its own context on the server side.
		const messages = [
			{ role: 'user', content },
		];

		// Open SSE stream via the conversation transport.
		createSSEStream(
			'/mcp-ai/v1/chat-client',
			{
				assistant_id: assistantId,
				messages,
				stream: true,
				context_mentions: mentions,
				model: model?.name || '',
				provider: model?.provider || '',
			},
			{
				onChunk: (chunk) => {
					messagesStore.appendChunk(CONVERSATION_THREAD_ID, chunk);
				},
				onToolCall: (toolCall) => {
					// Tool call cards are handled inline in message content.
				},
				onDone: (data) => {
					messagesStore.endStream(
						CONVERSATION_THREAD_ID,
						data.message_id,
						data.checkpoint_id
					);
				},
				onError: (err) => {
					messagesStore.appendChunk(CONVERSATION_THREAD_ID, {
						content: `\n\n[Error: ${err.message}]`,
					});
					messagesStore.endStream(CONVERSATION_THREAD_ID);
				},
			}
		);
	}, [messagesStore, model]);

	/** Retrieve the conversation messages from the store. */
	const getMessages = useCallback(() => {
		return messagesStore.getMessages(CONVERSATION_THREAD_ID);
	}, [messagesStore]);

	/** Clear the conversation buffer (e.g., on "New Chat"). */
	const clearMessages = useCallback(() => {
		messagesStore.setMessages(CONVERSATION_THREAD_ID, [], 0);
	}, [messagesStore]);

	return {
		sendMessage,
		getMessages,
		clearMessages,
	};
}
