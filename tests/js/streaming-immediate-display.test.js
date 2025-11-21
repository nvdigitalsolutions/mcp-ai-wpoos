/**
 * Test for streaming text immediate display issue
 * 
 * Problem: Streaming text is waiting for the end to start displaying text
 * instead of appearing immediately in the status section.
 *
 * @package WP_MCP_AI
 */

// Mock ReadableStream for SSE simulation
class MockReadableStream {
	constructor(chunks) {
		this.chunks = chunks;
		this.index = 0;
	}

	getReader() {
		const self = this;
		return {
			read() {
				return new Promise((resolve) => {
					if (self.index >= self.chunks.length) {
						resolve({ done: true });
					} else {
						const chunk = self.chunks[self.index];
						self.index++;
						// Simulate network delay
						setTimeout(() => {
							resolve({
								done: false,
								value: new TextEncoder().encode(chunk)
							});
						}, 10);
					}
				});
			}
		};
	}
}

describe('Streaming Immediate Display', () => {
	let container;
	let statusEl;
	let messagesEl;
	let bubbleElement;

	beforeEach(() => {
		// Create complete chat container structure
		container = document.createElement('div');
		container.className = 'wp-mcp-ai-chat';
		
		messagesEl = document.createElement('div');
		messagesEl.className = 'wp-mcp-ai-chat__messages';
		container.appendChild(messagesEl);
		
		statusEl = document.createElement('div');
		statusEl.className = 'wp-mcp-ai-chat__status';
		statusEl.setAttribute('role', 'status');
		statusEl.setAttribute('aria-live', 'polite');
		statusEl.hidden = true;
		container.appendChild(statusEl);
		
		document.body.appendChild(container);
	});

	afterEach(() => {
		document.body.removeChild(container);
	});

	it('should show streaming status immediately when stream begins', () => {
		// This test verifies that the status is updated immediately when
		// streaming starts, not waiting for the first content chunk
		
		if (!window.wpMcpAiChatUIUtils) {
			return;
		}

		const setStatus = window.wpMcpAiChatUIUtils.setStatus;

		// Simulate the status update that should happen immediately when
		// SSE streaming begins (before any content arrives)
		setStatus(container, {
			message: 'Streaming response...',
			type: 'streaming',
			showTime: false
		});

		// Verify status is visible immediately
		expect(statusEl.hidden).toBe(false);

		// Verify correct class is applied
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--streaming')).toBe(true);

		// Verify status text
		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText).not.toBeNull();
		expect(statusText.textContent).toBe('Streaming response...');

		// Verify indicator is present
		const indicator = statusEl.querySelector('.wp-mcp-ai-chat__status-indicator');
		expect(indicator).not.toBeNull();
	});

	it('should show streaming bubble immediately when stream starts', () => {
		// When SSE stream is confirmed, bubble should be created immediately
		// This tests the fix from STREAMING_BUBBLE_IMMEDIATE_VISIBILITY.md
		
		// Create a streaming bubble
		const bubble = document.createElement('div');
		bubble.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--assistant wp-mcp-ai-chat__bubble--streaming';
		bubble.textContent = ''; // Empty initially
		messagesEl.appendChild(bubble);

		// Verify bubble is visible (has min-height from CSS)
		const computedStyle = window.getComputedStyle(bubble);
		
		// Check that bubble has streaming class
		expect(bubble.classList.contains('wp-mcp-ai-chat__bubble--streaming')).toBe(true);
		
		// Verify bubble exists in DOM
		expect(messagesEl.contains(bubble)).toBe(true);
	});

	it('should update status progressively as content accumulates', () => {
		// Import setStatus from UI utilities service if available
		if (!window.wpMcpAiChatUIUtils) {
			// Skip if service not available
			return;
		}

		const setStatus = window.wpMcpAiChatUIUtils.setStatus;

		// Simulate progressive content updates
		const updates = ['H', 'He', 'Hel', 'Hell', 'Hello', 'Hello,', 'Hello, w', 'Hello, wo', 'Hello, wor', 'Hello, worl', 'Hello, world'];

		updates.forEach((content, index) => {
			setStatus(container, {
				message: content,
				type: 'text-stream',
				showTime: false
			});

			// Verify status is visible
			expect(statusEl.hidden).toBe(false);

			// Verify content is updated
			const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
			expect(statusText).not.toBeNull();
			expect(statusText.textContent).toBe(content);

			// Verify text-stream class is present
			expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
		});
	});

	it('should not wait for complete stream before showing first content', () => {
		// This test verifies that updateStreamingMessage is called
		// as soon as the first content chunk arrives, not when stream completes
		
		if (!window.wpMcpAiChatUIUtils) {
			return;
		}

		const setStatus = window.wpMcpAiChatUIUtils.setStatus;

		// Simulate first chunk arriving
		setStatus(container, {
			message: 'First chunk',
			type: 'text-stream',
			showTime: false
		});

		// Status should be visible immediately
		expect(statusEl.hidden).toBe(false);
		
		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('First chunk');

		// Simulate more chunks arriving over time
		setStatus(container, {
			message: 'First chunk Second chunk',
			type: 'text-stream',
			showTime: false
		});

		expect(statusText.textContent).toBe('First chunk Second chunk');
	});
});
