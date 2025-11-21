/**
 * Test for improved streaming status behavior with empty content
 * 
 * Ensures that streaming status is shown immediately when streaming starts,
 * even before content arrives, to prevent status from staying stuck on "thinking".
 *
 * @package WP_MCP_AI
 */

// Import the UI utilities service
import '../../assets/js/chat-ui-utilities-service.js';

describe('Streaming Status with Empty Content', () => {
	let container;
	let statusEl;
	let mockGetString;

	beforeEach(() => {
		container = document.createElement('div');
		container.className = 'wp-mcp-ai-chat';
		
		const messagesEl = document.createElement('div');
		messagesEl.className = 'wp-mcp-ai-chat__messages';
		container.appendChild(messagesEl);
		
		statusEl = document.createElement('div');
		statusEl.className = 'wp-mcp-ai-chat__status';
		statusEl.setAttribute('role', 'status');
		statusEl.setAttribute('aria-live', 'polite');
		statusEl.hidden = true;
		container.appendChild(statusEl);
		
		document.body.appendChild(container);

		// Mock getString for the test (simulating chat.js getString function)
		mockGetString = jest.fn((key, fallback) => fallback);
		global.getString = mockGetString;
	});

	afterEach(() => {
		document.body.removeChild(container);
		delete global.getString;
	});

	it('should transition from thinking to streaming status even with empty initial content', () => {
		// Simulate thinking status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Model is thinking...',
			type: 'thinking',
			showTime: true,
			startTime: Date.now()
		});

		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(true);

		// When streaming callback is triggered with empty content
		// (this would happen in updateStreamingMessage when streamingMessageElement is created)
		// The fix ensures we still show streaming status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming response...',
			type: 'streaming',
			showTime: false
		});

		// Status should transition to streaming (generic streaming indicator)
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--streaming')).toBe(true);
	});

	it('should transition from streaming to text-stream when content arrives', () => {
		// Start with generic streaming status (when content is empty)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming response...',
			type: 'streaming',
			showTime: false
		});

		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--streaming')).toBe(true);

		// Then actual content arrives and status updates to text-stream
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Hello, this is the actual response...',
			type: 'text-stream',
			showTime: false
		});

		// Should transition to text-stream with content preview
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--streaming')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
		
		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Hello, this is the actual response...');
	});

	it('should handle the complete flow: thinking -> streaming -> text-stream', () => {
		// Phase 1: Thinking
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Analyzing request...',
			type: 'thinking',
			showTime: true,
			startTime: Date.now()
		});

		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(true);
		expect(statusEl.hidden).toBe(false);

		// Phase 2: Streaming starts but no content yet (generic streaming)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming response...',
			type: 'streaming',
			showTime: false
		});

		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--streaming')).toBe(true);

		// Phase 3: First content chunk arrives (text-stream with preview)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Based on your question...',
			type: 'text-stream',
			showTime: false
		});

		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--streaming')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
	});

	it('should show streaming status for whitespace-only content', () => {
		// When content is just whitespace (edge case)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: '   ',
			type: 'streaming',
			showTime: false
		});

		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--streaming')).toBe(true);
		expect(statusEl.hidden).toBe(false);
	});
});
