/**
 * Test for streaming status transition from "thinking" to "text-stream"
 * 
 * This test reproduces the issue where the status doesn't update from
 * "thinking" status to "text-stream" status when streaming content arrives.
 *
 * @package WP_MCP_AI
 */

// Import the UI utilities service
import '../../assets/js/chat-ui-utilities-service.js';

describe('Streaming Status Transition', () => {
	let container;
	let statusEl;

	beforeEach(() => {
		// Create a container with status element (simulating shortcode structure)
		container = document.createElement('div');
		container.className = 'wp-mcp-ai-chat';
		
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

	it('should transition from "thinking" status to "text-stream" status when streaming starts', () => {
		// Step 1: Initially show "thinking" status (from handleStatusEvent)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Model is thinking...',
			type: 'thinking',
			showTime: true,
			startTime: Date.now()
		});

		// Verify "thinking" status is shown
		expect(statusEl.hidden).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(true);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(false);
		
		const thinkingText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(thinkingText.textContent).toBe('Model is thinking...');
		
		// Verify spinner is present for thinking status
		const thinkingSpinner = statusEl.querySelector('.wp-mcp-ai-chat__status-spinner');
		expect(thinkingSpinner).not.toBeNull();
		
		// Step 2: Streaming content arrives, update to "text-stream" status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Hello, this is streaming content...',
			type: 'text-stream',
			showTime: false
		});

		// Verify status transitioned correctly
		expect(statusEl.hidden).toBe(false);
		
		// Should NOT have "thinking" class anymore
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(false);
		
		// Should have "text-stream" class
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
		
		// Verify text updated
		const streamingText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(streamingText.textContent).toBe('Hello, this is streaming content...');
		
		// Verify icon (not spinner) is present for text-stream status
		const spinner = statusEl.querySelector('.wp-mcp-ai-chat__status-spinner');
		expect(spinner).toBeNull(); // No spinner for text-stream
		
		const icon = statusEl.querySelector('.wp-mcp-ai-chat__status-icon');
		expect(icon).not.toBeNull(); // SVG icon instead
	});

	it('should transition from "processing" status to "text-stream" status', () => {
		// Step 1: Show "processing" status (from sendChat)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Sending…',
			type: 'processing',
			showTime: true,
			startTime: Date.now()
		});

		// Verify "processing" status
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(true);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(false);

		// Step 2: Streaming content arrives
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming response text...',
			type: 'text-stream',
			showTime: false
		});

		// Verify transition
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
	});

	it('should maintain only the base class and current status class', () => {
		// Set thinking status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Thinking...',
			type: 'thinking',
			showTime: true,
			startTime: Date.now()
		});

		// Should have exactly 2 classes
		expect(statusEl.className).toBe('wp-mcp-ai-chat__status wp-mcp-ai-chat__status--thinking');

		// Transition to text-stream
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming...',
			type: 'text-stream',
			showTime: false
		});

		// Should have exactly 2 classes (base + text-stream), no leftover thinking class
		expect(statusEl.className).toBe('wp-mcp-ai-chat__status wp-mcp-ai-chat__status--text-stream');
	});

	it('should handle rapid status transitions without class accumulation', () => {
		const transitions = [
			{ message: 'Processing...', type: 'processing' },
			{ message: 'Thinking...', type: 'thinking' },
			{ message: 'Stream chunk 1', type: 'text-stream' },
			{ message: 'Stream chunk 2', type: 'text-stream' },
			{ message: 'Tool executing', type: 'tool' },
			{ message: 'Stream chunk 3', type: 'text-stream' }
		];

		transitions.forEach((status) => {
			window.wpMcpAiChatUIUtils.setStatus(container, {
				message: status.message,
				type: status.type,
				showTime: false
			});

			// Should only have base class + current status class
			const classList = statusEl.className.split(' ');
			expect(classList.length).toBe(2);
			expect(classList[0]).toBe('wp-mcp-ai-chat__status');
			expect(classList[1]).toBe(`wp-mcp-ai-chat__status--${status.type}`);
		});
	});

	it('should clear time interval when transitioning from timed status to text-stream', () => {
		// Set thinking status with timer
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Thinking...',
			type: 'thinking',
			showTime: true,
			startTime: Date.now()
		});

		// Verify timer element exists
		let timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
		expect(timeEl).not.toBeNull();
		expect(statusEl._timeInterval).toBeDefined();

		// Transition to text-stream (no timer)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming...',
			type: 'text-stream',
			showTime: false
		});

		// Verify timer element is gone
		timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
		expect(timeEl).toBeNull();
		
		// Interval should be cleared
		expect(statusEl._timeInterval).toBeNull();
	});
});
