/**
 * Tests for streaming status independence from message bubble
 *
 * @package WP_MCP_AI
 */

describe('Streaming Status Independence', () => {
	let container;
	let statusEl;

	beforeEach(() => {
		// Create a container with status element (but no messages container)
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

	it('should update status even if messages container is missing', () => {
		// Import the UI utilities service
		require('../../assets/js/chat-ui-utilities-service.js');

		// Call setStatus with text-stream type
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming text...',
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
		expect(statusText.textContent).toBe('Streaming text...');
	});

	it('should update status progressively without message bubble', () => {
		// Import the UI utilities service
		require('../../assets/js/chat-ui-utilities-service.js');

		// First update
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Hello',
			type: 'text-stream',
			showTime: false
		});

		let statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Hello');

		// Progressive update
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Hello, world!',
			type: 'text-stream',
			showTime: false
		});

		statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Hello, world!');
	});

	it('should clear status when message is empty', () => {
		// Import the UI utilities service
		require('../../assets/js/chat-ui-utilities-service.js');

		// Set initial status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Streaming...',
			type: 'text-stream',
			showTime: false
		});

		expect(statusEl.hidden).toBe(false);

		// Clear status
		window.wpMcpAiChatUIUtils.clearStatus(container);

		expect(statusEl.hidden).toBe(true);
		expect(statusEl.innerHTML).toBe('');
	});
});
