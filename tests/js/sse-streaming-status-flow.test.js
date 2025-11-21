/**
 * Integration test simulating real SSE event flow for streaming status
 * 
 * This test simulates the exact sequence of events that happens during a real
 * streaming chat session to reproduce the issue where status gets stuck on "thinking".
 *
 * @package WP_MCP_AI
 */

// Import the UI utilities service
import '../../assets/js/chat-ui-utilities-service.js';

describe('SSE Streaming Status Flow', () => {
	let container;
	let statusEl;

	beforeEach(() => {
		// Create a complete chat container structure
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
	});

	afterEach(() => {
		document.body.removeChild(container);
	});

	it('should handle the complete SSE event flow from start to streaming', () => {
		// Phase 1: Initial send (sendChat sets this)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Sending…',
			type: 'processing',
			showTime: true,
			startTime: Date.now()
		});

		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(true);
		expect(statusEl.querySelector('.wp-mcp-ai-chat__status-spinner')).not.toBeNull();

		// Phase 2: Server sends a "status" event with type "thinking" (handleStatusEvent)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Model is analyzing your request...',
			type: 'thinking',
			showTime: true,
			startTime: Date.now()
		});

		// Verify thinking status is active
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(true);
		const thinkingText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(thinkingText.textContent).toBe('Model is analyzing your request...');

		// Phase 3: Thinking chunks start arriving (line 8058-8071 in chat.js)
		// These should update to text-stream status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Let me think about this...',
			type: 'text-stream',
			showTime: false
		});

		// THIS IS THE KEY TEST: Status should transition from "thinking" to "text-stream"
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
		
		const streamText1 = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(streamText1.textContent).toBe('Let me think about this...');

		// Phase 4: More thinking chunks arrive
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Let me think about this... I need to consider...',
			type: 'text-stream',
			showTime: false
		});

		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
		const streamText2 = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(streamText2.textContent).toBe('Let me think about this... I need to consider...');

		// Phase 5: Regular content starts arriving (updateStreamingMessage -> updateStreamingStatus)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Based on your question, here is my response...',
			type: 'text-stream',
			showTime: false
		});

		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
		const streamText3 = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(streamText3.textContent).toBe('Based on your question, here is my response...');
	});

	it('should handle streaming without thinking event', () => {
		// Phase 1: Initial send
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Sending…',
			type: 'processing',
			showTime: true,
			startTime: Date.now()
		});

		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(true);

		// Phase 2: Streaming content arrives directly (no thinking event)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Hello',
			type: 'text-stream',
			showTime: false
		});

		// Should transition directly from processing to text-stream
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);

		// Phase 3: Progressive streaming updates
		const chunks = ['Hello', 'Hello, ', 'Hello, world', 'Hello, world!'];
		chunks.forEach(chunk => {
			window.wpMcpAiChatUIUtils.setStatus(container, {
				message: chunk,
				type: 'text-stream',
				showTime: false
			});

			expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
			const text = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
			expect(text.textContent).toBe(chunk);
		});
	});

	it('should handle tool execution interrupting streaming', () => {
		// Streaming is happening
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Generating response...',
			type: 'text-stream',
			showTime: false
		});

		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);

		// Tool execution starts (handleToolExecutionEvent)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Executing tools: search_web',
			type: 'processing',
			showTime: true,
			startTime: Date.now()
		});

		// Should transition to processing
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(true);

		// Streaming resumes after tool execution
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Continuing response after tool execution...',
			type: 'text-stream',
			showTime: false
		});

		// Should return to text-stream
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
	});

	it('should properly clean up when clearing status after streaming', () => {
		// Set up streaming status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming content...',
			type: 'text-stream',
			showTime: false
		});

		expect(statusEl.hidden).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);

		// Clear status (what happens when streaming completes)
		window.wpMcpAiChatUIUtils.clearStatus(container);

		// Should be completely reset
		expect(statusEl.hidden).toBe(true);
		expect(statusEl.className).toBe('wp-mcp-ai-chat__status');
		expect(statusEl.innerHTML).toBe('');
	});
});
