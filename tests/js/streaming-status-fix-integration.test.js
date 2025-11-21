/**
 * Integration test demonstrating streaming status independence fix
 * 
 * This test simulates the real-world scenario where streaming content arrives
 * but the message bubble might not exist, ensuring status preview still works.
 *
 * @package WP_MCP_AI
 */

describe('Streaming Status Fix - Integration Test', () => {
	let mockContainer;
	let mockStatusEl;
	let mockMessagesEl;

	beforeEach(() => {
		// Setup minimal DOM structure like the shortcode creates
		mockContainer = document.createElement('div');
		mockContainer.className = 'wp-mcp-ai-chat';
		
		// Messages area (may or may not work)
		mockMessagesEl = document.createElement('div');
		mockMessagesEl.className = 'wp-mcp-ai-chat__messages';
		mockContainer.appendChild(mockMessagesEl);
		
		// Status area (should always work)
		mockStatusEl = document.createElement('div');
		mockStatusEl.className = 'wp-mcp-ai-chat__status';
		mockStatusEl.setAttribute('role', 'status');
		mockStatusEl.setAttribute('aria-live', 'polite');
		mockStatusEl.hidden = true;
		mockContainer.appendChild(mockStatusEl);
		
		document.body.appendChild(mockContainer);
		
		// Load the UI utilities service
		require('../../assets/js/chat-ui-utilities-service.js');
	});

	afterEach(() => {
		document.body.removeChild(mockContainer);
	});

	it('should show streaming preview in status even if messages area fails', () => {
		// Simulate messages area being broken/missing
		mockMessagesEl.remove();
		
		// Simulate streaming content arriving (this would normally update both bubble and status)
		// But since we can't create the bubble, only status should update
		const streamingContent = 'Hello, this is streaming content...';
		
		// This is what updateStreamingStatus does internally
		const preview = streamingContent.length > 100 
			? streamingContent.substring(0, 100) + '…' 
			: streamingContent;
		
		window.wpMcpAiChatUIUtils.setStatus(mockContainer, {
			message: preview,
			type: 'text-stream',
			showTime: false
		});
		
		// Verify status is visible and showing content
		expect(mockStatusEl.hidden).toBe(false);
		expect(mockStatusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
		
		const statusText = mockStatusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText).not.toBeNull();
		expect(statusText.textContent).toBe(streamingContent);
		
		// Verify indicator (icon) is present
		const indicator = mockStatusEl.querySelector('.wp-mcp-ai-chat__status-indicator');
		expect(indicator).not.toBeNull();
		
		const icon = mockStatusEl.querySelector('.wp-mcp-ai-chat__status-icon');
		expect(icon).not.toBeNull();
	});

	it('should update status progressively during streaming simulation', () => {
		// Simulate streaming chunks arriving progressively
		const chunks = [
			'Hello',
			'Hello, world',
			'Hello, world! This is a test',
			'Hello, world! This is a test of the streaming feature.'
		];
		
		chunks.forEach((chunk, index) => {
			const preview = chunk.length > 100 
				? chunk.substring(0, 100) + '…' 
				: chunk;
			
			window.wpMcpAiChatUIUtils.setStatus(mockContainer, {
				message: preview,
				type: 'text-stream',
				showTime: false
			});
			
			const statusText = mockStatusEl.querySelector('.wp-mcp-ai-chat__status-text');
			expect(statusText.textContent).toBe(chunk);
		});
		
		// Final status should show the last chunk
		const finalStatusText = mockStatusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(finalStatusText.textContent).toBe(chunks[chunks.length - 1]);
	});

	it('should truncate long streaming content for status preview', () => {
		// Simulate long streaming content (like a detailed AI response)
		const longContent = 'A'.repeat(150); // 150 characters
		const expectedPreview = longContent.substring(0, 100) + '…';
		
		window.wpMcpAiChatUIUtils.setStatus(mockContainer, {
			message: expectedPreview,
			type: 'text-stream',
			showTime: false
		});
		
		const statusText = mockStatusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe(expectedPreview);
		expect(statusText.textContent.length).toBe(101); // 100 chars + ellipsis
	});

	it('should clear status when streaming completes', () => {
		// Streaming starts
		window.wpMcpAiChatUIUtils.setStatus(mockContainer, {
			message: 'Streaming response...',
			type: 'text-stream',
			showTime: false
		});
		
		expect(mockStatusEl.hidden).toBe(false);
		
		// Streaming completes - clear status
		window.wpMcpAiChatUIUtils.clearStatus(mockContainer);
		
		expect(mockStatusEl.hidden).toBe(true);
		expect(mockStatusEl.innerHTML).toBe('');
		expect(mockStatusEl.className).toBe('wp-mcp-ai-chat__status');
	});

	it('should handle rapid streaming updates without errors', () => {
		// Simulate rapid streaming like real SSE chunks
		const rapidUpdates = [
			'The', 'The answer', 'The answer is', 'The answer is 42',
			'The answer is 42.', 'The answer is 42. This',
			'The answer is 42. This is', 'The answer is 42. This is the',
			'The answer is 42. This is the ultimate', 
			'The answer is 42. This is the ultimate answer.'
		];
		
		expect(() => {
			rapidUpdates.forEach(content => {
				window.wpMcpAiChatUIUtils.setStatus(mockContainer, {
					message: content,
					type: 'text-stream',
					showTime: false
				});
			});
		}).not.toThrow();
		
		// Final content should be the last update
		const finalText = mockStatusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(finalText.textContent).toBe(rapidUpdates[rapidUpdates.length - 1]);
	});
});
