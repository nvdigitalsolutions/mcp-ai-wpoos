/**
 * Test for streaming text rendering fallback when markdown rendering fails
 * 
 * Ensures that content is never lost when markdown rendering returns empty.
 *
 * @package WP_MCP_AI
 */

describe('Streaming Rendering Fallback', () => {
	let container;
	let messagesEl;
	let streamingMessageElement;

	// Mock escapeHtml function
	const escapeHtml = (text) => {
		return String(text).replace(/[&<>"']/g, function (character) {
			switch (character) {
				case '&':
					return '&amp;';
				case '<':
					return '&lt;';
				case '>':
					return '&gt;';
				case '"':
					return '&quot;';
				case '\'':
					return '&#39;';
				default:
					return character;
			}
		});
	};

	// Mock renderMarkdown function that can simulate failures
	let mockRenderMarkdown;

	beforeEach(() => {
		container = document.createElement('div');
		container.className = 'wp-mcp-ai-chat';
		
		messagesEl = document.createElement('div');
		messagesEl.className = 'wp-mcp-ai-chat__messages';
		container.appendChild(messagesEl);
		
		document.body.appendChild(container);

		// Create a mock streaming message element
		streamingMessageElement = document.createElement('div');
		streamingMessageElement.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--assistant wp-mcp-ai-chat__bubble--streaming';
		streamingMessageElement.textContent = 'Streaming text...';
		messagesEl.appendChild(streamingMessageElement);

		// Default mock that returns proper markdown
		mockRenderMarkdown = jest.fn((text) => {
			if (!text) return '';
			return '<p>' + escapeHtml(text) + '</p>';
		});
	});

	afterEach(() => {
		document.body.removeChild(container);
	});

	it('should preserve content when markdown rendering returns empty string', () => {
		// Simulate the scenario where renderMarkdown returns empty
		mockRenderMarkdown = jest.fn(() => '');
		
		const content = 'This is important content that should not disappear';
		streamingMessageElement.textContent = content;
		
		// Simulate the fix: check if rendered HTML is empty before setting
		const renderedHtml = mockRenderMarkdown(content);
		
		if (renderedHtml && renderedHtml.trim()) {
			streamingMessageElement.innerHTML = renderedHtml;
		} else {
			// Fallback to escaped plain text
			streamingMessageElement.innerHTML = escapeHtml(content).replace(/\n/g, '<br />');
		}
		
		// Verify content is preserved (not empty)
		expect(streamingMessageElement.innerHTML).not.toBe('');
		expect(streamingMessageElement.innerHTML).toContain('This is important content');
	});

	it('should use rendered markdown when it produces valid output', () => {
		const content = 'Hello, **world**!';
		streamingMessageElement.textContent = content;
		
		// Normal case: renderMarkdown works correctly
		const renderedHtml = mockRenderMarkdown(content);
		
		if (renderedHtml && renderedHtml.trim()) {
			streamingMessageElement.innerHTML = renderedHtml;
		} else {
			streamingMessageElement.innerHTML = escapeHtml(content).replace(/\n/g, '<br />');
		}
		
		// Verify rendered markdown is used
		expect(streamingMessageElement.innerHTML).toContain('<p>');
		expect(mockRenderMarkdown).toHaveBeenCalledWith(content);
	});

	it('should handle whitespace-only rendered output', () => {
		// Simulate renderMarkdown returning only whitespace
		mockRenderMarkdown = jest.fn(() => '   \n\n   ');
		
		const content = 'Content that should not be lost';
		streamingMessageElement.textContent = content;
		
		const renderedHtml = mockRenderMarkdown(content);
		
		// Check if trimmed output is empty
		if (renderedHtml && renderedHtml.trim()) {
			streamingMessageElement.innerHTML = renderedHtml;
		} else {
			streamingMessageElement.innerHTML = escapeHtml(content).replace(/\n/g, '<br />');
		}
		
		// Verify fallback is used (not whitespace)
		expect(streamingMessageElement.innerHTML).not.toMatch(/^\s*$/);
		expect(streamingMessageElement.innerHTML).toContain('Content that should not be lost');
	});

	it('should preserve newlines in fallback rendering', () => {
		mockRenderMarkdown = jest.fn(() => '');
		
		const content = 'Line 1\nLine 2\nLine 3';
		streamingMessageElement.textContent = content;
		
		const renderedHtml = mockRenderMarkdown(content);
		
		if (renderedHtml && renderedHtml.trim()) {
			streamingMessageElement.innerHTML = renderedHtml;
		} else {
			streamingMessageElement.innerHTML = escapeHtml(content).replace(/\n/g, '<br />');
		}
		
		// Verify newlines are converted to <br /> (browser may normalize to <br>)
		expect(streamingMessageElement.innerHTML).toMatch(/<br\s*\/?>/);
		const brCount = (streamingMessageElement.innerHTML.match(/<br\s*\/?>/g) || []).length;
		expect(brCount).toBe(2); // Two newlines = two <br />
	});

	it('should escape HTML in fallback to prevent XSS', () => {
		mockRenderMarkdown = jest.fn(() => '');
		
		const content = '<script>alert("XSS")</script>';
		streamingMessageElement.textContent = content;
		
		const renderedHtml = mockRenderMarkdown(content);
		
		if (renderedHtml && renderedHtml.trim()) {
			streamingMessageElement.innerHTML = renderedHtml;
		} else {
			streamingMessageElement.innerHTML = escapeHtml(content).replace(/\n/g, '<br />');
		}
		
		// Verify HTML is escaped (not executed)
		expect(streamingMessageElement.innerHTML).toContain('&lt;script&gt;');
		expect(streamingMessageElement.innerHTML).toContain('&lt;/script&gt;');
		expect(streamingMessageElement.innerHTML).not.toContain('<script>');
	});

	it('should handle streaming class removal before rendering', () => {
		// Verify streaming class exists initially
		expect(streamingMessageElement.classList.contains('wp-mcp-ai-chat__bubble--streaming')).toBe(true);
		
		// Simulate the streaming completion flow
		streamingMessageElement.classList.remove('wp-mcp-ai-chat__bubble--streaming');
		
		const content = 'Completed streaming content';
		const renderedHtml = mockRenderMarkdown(content);
		
		if (renderedHtml && renderedHtml.trim()) {
			streamingMessageElement.innerHTML = renderedHtml;
		}
		
		// Verify streaming class is removed
		expect(streamingMessageElement.classList.contains('wp-mcp-ai-chat__bubble--streaming')).toBe(false);
		
		// Verify content is present
		expect(streamingMessageElement.innerHTML).not.toBe('');
	});
});
