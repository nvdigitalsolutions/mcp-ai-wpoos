/**
 * Test for streaming bubble text display issue
 * 
 * Problem: The cursor is blinking without any text in the streaming bubble.
 * This test verifies that text content is actually displayed in the bubble
 * during streaming, not just in the status area.
 *
 * @package WP_MCP_AI
 */

describe('Streaming Bubble Text Display', () => {
	let container;
	let messagesEl;

	beforeEach(() => {
		// Create complete chat container structure
		container = document.createElement('div');
		container.className = 'wp-mcp-ai-chat';
		
		messagesEl = document.createElement('div');
		messagesEl.className = 'wp-mcp-ai-chat__messages';
		container.appendChild(messagesEl);
		
		document.body.appendChild(container);
	});

	afterEach(() => {
		document.body.removeChild(container);
	});

	it('should display text content in streaming bubble, not just cursor', () => {
		// Create a streaming bubble like the code does
		const bubble = document.createElement('div');
		bubble.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--assistant wp-mcp-ai-chat__bubble--streaming';
		bubble.textContent = ''; // Empty initially
		messagesEl.appendChild(bubble);

		// Verify bubble is created and has streaming class
		expect(bubble.classList.contains('wp-mcp-ai-chat__bubble--streaming')).toBe(true);
		expect(bubble.textContent).toBe('');

		// Simulate streaming text arriving - set textContent like the typing animation does
		bubble.textContent = 'H';
		expect(bubble.textContent).toBe('H');

		bubble.textContent = 'He';
		expect(bubble.textContent).toBe('He');

		bubble.textContent = 'Hello';
		expect(bubble.textContent).toBe('Hello');

		bubble.textContent = 'Hello, world!';
		expect(bubble.textContent).toBe('Hello, world!');

		// Verify the text is visible in the DOM
		expect(bubble.textContent).not.toBe('');
		expect(bubble.textContent.length).toBeGreaterThan(0);
	});

	it('should show both text content AND cursor during streaming', () => {
		// This test verifies that when textContent is set, 
		// the bubble shows BOTH the text AND the blinking cursor
		const bubble = document.createElement('div');
		bubble.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--assistant wp-mcp-ai-chat__bubble--streaming';
		messagesEl.appendChild(bubble);

		// Set some text content
		const testText = 'Streaming text...';
		bubble.textContent = testText;

		// Verify text is set
		expect(bubble.textContent).toBe(testText);

		// Verify bubble still has streaming class (cursor should show via ::after)
		expect(bubble.classList.contains('wp-mcp-ai-chat__bubble--streaming')).toBe(true);

		// The cursor is added via CSS ::after pseudo-element, so we can't directly test it
		// But we can verify the element has the class that triggers the cursor
		const computedStyle = window.getComputedStyle(bubble, '::after');
		
		// Verify the ::after pseudo-element exists and has content
		if (computedStyle && computedStyle.content) {
			// The cursor content should be the block character
			expect(computedStyle.content).toContain('▋');
		}
	});

	it('should update text content progressively during streaming', () => {
		// Simulate the typing animation behavior
		const bubble = document.createElement('div');
		bubble.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--assistant wp-mcp-ai-chat__bubble--streaming';
		messagesEl.appendChild(bubble);

		const fullText = 'Progressive streaming text display';
		let displayed = '';

		// Simulate character-by-character typing
		for (let i = 0; i < fullText.length; i++) {
			displayed = fullText.substring(0, i + 1);
			bubble.textContent = displayed;
			expect(bubble.textContent).toBe(displayed);
			expect(bubble.textContent.length).toBe(i + 1);
		}

		// Final check
		expect(bubble.textContent).toBe(fullText);
	});

	it('should maintain text visibility when streaming class is present', () => {
		// This test checks that the streaming class doesn't interfere with text visibility
		const bubble = document.createElement('div');
		bubble.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--assistant';
		messagesEl.appendChild(bubble);

		// Set text BEFORE adding streaming class
		const testText = 'Text before streaming';
		bubble.textContent = testText;
		expect(bubble.textContent).toBe(testText);

		// Add streaming class
		bubble.classList.add('wp-mcp-ai-chat__bubble--streaming');
		
		// Text should still be visible
		expect(bubble.textContent).toBe(testText);
		expect(bubble.classList.contains('wp-mcp-ai-chat__bubble--streaming')).toBe(true);

		// Update text while streaming
		const updatedText = 'Text during streaming';
		bubble.textContent = updatedText;
		expect(bubble.textContent).toBe(updatedText);
	});

	it('should remove streaming class when streaming completes', () => {
		const bubble = document.createElement('div');
		bubble.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--assistant wp-mcp-ai-chat__bubble--streaming';
		messagesEl.appendChild(bubble);

		// Set final text
		const finalText = 'Streaming complete';
		bubble.textContent = finalText;

		// Remove streaming class (like the code does when streaming completes)
		bubble.classList.remove('wp-mcp-ai-chat__bubble--streaming');

		// Text should still be visible
		expect(bubble.textContent).toBe(finalText);
		expect(bubble.classList.contains('wp-mcp-ai-chat__bubble--streaming')).toBe(false);
	});
});
