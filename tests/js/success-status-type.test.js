/**
 * Tests for success status type in UI utilities service
 *
 * @package WP_MCP_AI
 */

// Import the UI utilities service
import '../../assets/js/chat-ui-utilities-service.js';

describe('Success Status Type', () => {
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

	it('should handle success type in setStatus', () => {
		// Call setStatus with success type
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Tool completed successfully.',
			type: 'success',
			showTime: false
		});

		// Verify status element is visible
		expect(statusEl.hidden).toBe(false);

		// Verify correct class is applied
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--success')).toBe(true);

		// Verify status text is set correctly
		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText).not.toBeNull();
		expect(statusText.textContent).toBe('Tool completed successfully.');

		// Verify indicator is present
		const indicator = statusEl.querySelector('.wp-mcp-ai-chat__status-indicator');
		expect(indicator).not.toBeNull();

		// Verify SVG icon is present (checkmark icon, not spinner)
		const icon = statusEl.querySelector('.wp-mcp-ai-chat__status-icon');
		expect(icon).not.toBeNull();
		
		// Verify no spinner is present (success shows checkmark, not spinner)
		const spinner = statusEl.querySelector('.wp-mcp-ai-chat__status-spinner');
		expect(spinner).toBeNull();
	});

	it('should not show time for success status when showTime is false', () => {
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Tool completed successfully.',
			type: 'success',
			showTime: false
		});

		// Verify no time element is present
		const timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
		expect(timeEl).toBeNull();
	});

	it('should clear success status when message is empty', () => {
		// Set initial status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Tool completed successfully.',
			type: 'success',
			showTime: false
		});

		expect(statusEl.hidden).toBe(false);

		// Clear status by passing empty message
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: '',
			type: 'success',
			showTime: false
		});

		expect(statusEl.hidden).toBe(true);
		expect(statusEl.innerHTML).toBe('');
	});

	it('should escape HTML in success status text', () => {
		const htmlText = '<script>alert("XSS")</script>';

		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: htmlText,
			type: 'success',
			showTime: false
		});

		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		// Text should be escaped, not rendered as HTML
		expect(statusText.textContent).toBe(htmlText);
		expect(statusText.innerHTML).toContain('&lt;');
		expect(statusText.innerHTML).toContain('&gt;');
	});

	it('should handle success with backward compatible string parameter', () => {
		// Test backward compatibility with string message parameter
		window.wpMcpAiChatUIUtils.setStatus(container, 'Operation successful!', { type: 'success' });

		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Operation successful!');
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--success')).toBe(true);
	});
	
	it('should display checkmark icon without animation for success status', () => {
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Tool completed successfully.',
			type: 'success',
			showTime: false
		});

		const icon = statusEl.querySelector('.wp-mcp-ai-chat__status-icon');
		expect(icon).not.toBeNull();
		
		// Verify no animateTransform element (checkmark should be static)
		const animation = icon.querySelector('animateTransform');
		expect(animation).toBeNull();
	});
});
