/**
 * SSE Service — Server-Sent Events stream manager.
 *
 * Opens EventSource connections for streaming AI responses.
 * Supports chunk, tool_call, memory_event, and done events.
 */

const { nonce, restUrl } = window.wpMcpAiPro || {};

/**
 * Open an SSE stream for a chat message.
 *
 * @param {string} endpoint POST endpoint for the message.
 * @param {object} body     Request body ({ content, context_mentions, thread_id }).
 * @param {object} handlers Event handlers: onChunk, onToolCall, onToolResult, onMemory, onDone, onError.
 * @returns {{ abort: Function }} Abort function.
 */
export function createSSEStream(endpoint, body, handlers = {}) {
	// We use fetch() with streaming rather than EventSource for POST support.
	const controller = new AbortController();
	const { onChunk, onToolCall, onToolResult, onMemory, onDone, onError } = handlers;

	fetch(restUrl + endpoint.replace(/^\//, ''), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
			'Accept': 'text/event-stream',
		},
		body: JSON.stringify(body),
		signal: controller.signal,
	})
		.then(async (response) => {
			if (!response.ok) {
				const err = await response.json().catch(() => ({}));
				onError?.(new Error(err.message || `SSE error ${response.status}`));
				return;
			}

			const reader = response.body.getReader();
			const decoder = new TextDecoder();
			let buffer = '';

			while (true) {
				const { done, value } = await reader.read();
				if (done) break;

				buffer += decoder.decode(value, { stream: true });
				const lines = buffer.split('\n');
				buffer = lines.pop() || '';

				let eventType = 'message';
				let eventData = '';

				for (const line of lines) {
					if (line.startsWith('event:')) {
						eventType = line.slice(6).trim();
					} else if (line.startsWith('data:')) {
						eventData = line.slice(5).trim();
					} else if (line === '' && eventData) {
						// Empty line = end of event.
						dispatchSSE(eventType, eventData, handlers);
						eventType = 'message';
						eventData = '';
					}
				}
			}
		})
		.catch((err) => {
			if (err.name !== 'AbortError') {
				onError?.(err);
			}
		});

	return {
		abort: () => controller.abort(),
	};
}

function dispatchSSE(type, data, handlers) {
	if (data === '[DONE]') {
		handlers.onDone?.({});
		return;
	}

	try {
		const parsed = JSON.parse(data);

		switch (type) {
			case 'chunk':
				handlers.onChunk?.(parsed);
				break;
			case 'tool_call':
				handlers.onToolCall?.(parsed);
				break;
			case 'tool_result':
				handlers.onToolResult?.(parsed);
				break;
			case 'memory_event':
				handlers.onMemory?.(parsed);
				break;
			case 'done':
				handlers.onDone?.(parsed);
				break;
			default:
				handlers.onChunk?.(parsed);
		}
	} catch {
		// Non-JSON data — treat as chunk.
		handlers.onChunk?.({ content: data });
	}
}
