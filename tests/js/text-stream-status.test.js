/**
 * Tests for text-stream status type in UI utilities service
 *
 * @package WP_MCP_AI
 */

// Import the UI utilities service
import '../../assets/js/chat-ui-utilities-service.js';

describe('Text Stream Status Type', () => {
	let container;
	let statusEl;

	beforeEach(() => {
		// Create a container with status element
		container = document.createElement('div');
		statusEl = document.createElement('div');
		statusEl.className = 'wp-mcp-ai-chat__status';
		statusEl.hidden = true;
		container.appendChild(statusEl);
		document.body.appendChild(container);
	});

	afterEach(() => {
		document.body.removeChild(container);
	});

	it('should handle text-stream type in setStatus', () => {
		// Call setStatus with text-stream type
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming text preview...',
			type: 'text-stream',
			showTime: false
		});

		// Verify status element is visible
		expect(statusEl.hidden).toBe(false);

		// Verify correct class is applied
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);

		// Verify status text is set correctly
		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText).not.toBeNull();
		expect(statusText.textContent).toBe('Streaming text preview...');

		// Verify indicator is present
		const indicator = statusEl.querySelector('.wp-mcp-ai-chat__status-indicator');
		expect(indicator).not.toBeNull();

		// Verify SVG icon is present
		const icon = statusEl.querySelector('.wp-mcp-ai-chat__status-icon');
		expect(icon).not.toBeNull();
	});

	it('should update text-stream status content progressively', () => {
		// Initial update
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Hello',
			type: 'text-stream',
			showTime: false
		});

		let statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Hello');

		// Progressive update 1
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Hello, world',
			type: 'text-stream',
			showTime: false
		});

		statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Hello, world');

		// Progressive update 2
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Hello, world!',
			type: 'text-stream',
			showTime: false
		});

		statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Hello, world!');
	});

	it('should handle long streaming text in status', () => {
		const longText = 'A'.repeat(150);

		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: longText,
			type: 'text-stream',
			showTime: false
		});

		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe(longText);
		expect(statusText.textContent.length).toBe(150);
	});

	it('should clear text-stream status when message is empty', () => {
		// Set initial status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming...',
			type: 'text-stream',
			showTime: false
		});

		expect(statusEl.hidden).toBe(false);

		// Clear status by passing empty message
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: '',
			type: 'text-stream',
			showTime: false
		});

		expect(statusEl.hidden).toBe(true);
		expect(statusEl.innerHTML).toBe('');
	});

	it('should escape HTML in text-stream status text', () => {
		const htmlText = '<script>alert("XSS")</script>';

		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: htmlText,
			type: 'text-stream',
			showTime: false
		});

		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		// Text should be escaped, not rendered as HTML
		expect(statusText.textContent).toBe(htmlText);
		expect(statusText.innerHTML).toContain('&lt;');
		expect(statusText.innerHTML).toContain('&gt;');
	});

	it('should handle text-stream with backward compatible string parameter', () => {
		// Test backward compatibility with string message parameter
		window.wpMcpAiChatUIUtils.setStatus(container, 'Streaming...', { type: 'text-stream' });

		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Streaming...');
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
	});
});
