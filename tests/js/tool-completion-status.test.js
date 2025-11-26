/**
 * Test for tool completion status updates
 * 
 * Tests that when tools complete, the status text correctly shows 
 * "Tool completed successfully" instead of being cleared immediately.
 * This addresses the issue where wp-mcp-ai-chat__status-text was not 
 * updating to the final completion message in chat-client with tools.
 *
 * @package WP_MCP_AI
 */

// Import the UI utilities service
import '../../assets/js/chat-ui-utilities-service.js';

describe('Tool Completion Status Updates', () => {
	let container;
	let statusEl;

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
	});

	afterEach(() => {
		document.body.removeChild(container);
	});

	it('should show "Tool completed successfully" status with tool type', () => {
		// Simulate tool completion status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Tool completed successfully.',
			type: 'tool',
			showTime: false
		});
		
		// Verify status is visible
		expect(statusEl.hidden).toBe(false);
		
		// Verify status has the correct class
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--tool')).toBe(true);
		
		// Verify status text is correct
		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText).not.toBeNull();
		expect(statusText.textContent).toBe('Tool completed successfully.');
		
		// Verify spinner indicator is present
		const spinner = statusEl.querySelector('.wp-mcp-ai-chat__status-spinner');
		expect(spinner).not.toBeNull();
	});

	it('should transition from processing to completed status', () => {
		// Step 1: Processing status (tool is running)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Tool is processing…',
			type: 'processing',
			showTime: true,
			startTime: Date.now()
		});
		
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(true);
		let statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Tool is processing…');
		
		// Step 2: Tool completed
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Tool completed successfully.',
			type: 'tool',
			showTime: false
		});
		
		// Processing class should be removed
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(false);
		// Tool class should be added
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--tool')).toBe(true);
		
		// Status text should now show completion message
		statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Tool completed successfully.');
	});

	it('should not have time display when showTime is false for completed status', () => {
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Tool completed successfully.',
			type: 'tool',
			showTime: false
		});
		
		// Time element should not be present
		const timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
		expect(timeEl).toBeNull();
	});

	it('should handle transition from text-stream to tool completion', () => {
		// Step 1: Text streaming
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming response text...',
			type: 'text-stream',
			showTime: false
		});
		
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
		
		// Step 2: Tool completed (after streaming)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Tool completed successfully.',
			type: 'tool',
			showTime: false
		});
		
		// Text-stream class should be removed
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(false);
		// Tool class should be added
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--tool')).toBe(true);
		
		// Status text should show completion
		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Tool completed successfully.');
	});

	it('should properly clear status after showing completion message', () => {
		// Show completion status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Tool completed successfully.',
			type: 'tool',
			showTime: false
		});
		
		// Verify it's visible
		expect(statusEl.hidden).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--tool')).toBe(true);
		
		// Clear status
		window.wpMcpAiChatUIUtils.clearStatus(container);
		
		// Status should be hidden and cleared
		expect(statusEl.hidden).toBe(true);
		expect(statusEl.innerHTML).toBe('');
		expect(statusEl.className).toBe('wp-mcp-ai-chat__status');
	});

	it('should handle full agentic workflow with tool completion', () => {
		// Step 1: Thinking
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Analyzing your request...',
			type: 'thinking',
			showTime: true,
			startTime: Date.now()
		});
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(true);

		// Step 2: Processing tool
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Executing generate_image...',
			type: 'processing',
			showTime: true,
			startTime: Date.now()
		});
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(true);

		// Step 3: Streaming response
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Here is the generated image...',
			type: 'text-stream',
			showTime: false
		});
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);

		// Step 4: Tool completed
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Tool completed successfully.',
			type: 'tool',
			showTime: false
		});
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--tool')).toBe(true);
		
		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Tool completed successfully.');
	});
});
