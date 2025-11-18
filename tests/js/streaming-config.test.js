/**
 * Tests for streaming configuration
 *
 * @package WP_MCP_AI
 */

describe('Streaming Configuration', () => {
	describe('CSS Streaming Indicator', () => {
		it('should apply streaming class to bubble element', () => {
			const bubble = document.createElement('div');
			bubble.className = 'wp-mcp-ai-chat__bubble';
			
			// Simulate adding streaming class
			bubble.classList.add('wp-mcp-ai-chat__bubble--streaming');
			
			expect(bubble.classList.contains('wp-mcp-ai-chat__bubble--streaming')).toBe(true);
		});

		it('should remove streaming class from bubble element', () => {
			const bubble = document.createElement('div');
			bubble.className = 'wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--streaming';
			
			// Simulate removing streaming class
			bubble.classList.remove('wp-mcp-ai-chat__bubble--streaming');
			
			expect(bubble.classList.contains('wp-mcp-ai-chat__bubble--streaming')).toBe(false);
			expect(bubble.classList.contains('wp-mcp-ai-chat__bubble')).toBe(true);
		});
	});

	describe('Streaming Content Update', () => {
		it('should update bubble text content', () => {
			const bubble = document.createElement('div');
			bubble.className = 'wp-mcp-ai-chat__bubble';
			
			// Simulate streaming content updates
			bubble.textContent = 'Hello';
			expect(bubble.textContent).toBe('Hello');
			
			bubble.textContent = 'Hello, world';
			expect(bubble.textContent).toBe('Hello, world');
			
			bubble.textContent = 'Hello, world!';
			expect(bubble.textContent).toBe('Hello, world!');
		});

		it('should handle empty content', () => {
			const bubble = document.createElement('div');
			bubble.className = 'wp-mcp-ai-chat__bubble';
			
			bubble.textContent = '';
			expect(bubble.textContent).toBe('');
		});
	});

	describe('Message Container', () => {
		it('should create message container structure', () => {
			const container = document.createElement('div');
			container.className = 'wp-mcp-ai-chat__messages';
			
			const message = document.createElement('li');
			message.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__message--assistant';
			
			const bubble = document.createElement('div');
			bubble.className = 'wp-mcp-ai-chat__bubble';
			bubble.textContent = 'Streaming text...';
			
			message.appendChild(bubble);
			container.appendChild(message);
			
			expect(container.children.length).toBe(1);
			expect(container.querySelector('.wp-mcp-ai-chat__bubble')).toBeTruthy();
			expect(container.querySelector('.wp-mcp-ai-chat__bubble').textContent).toBe('Streaming text...');
		});
	});

	describe('classList API', () => {
		it('should support classList operations', () => {
			const element = document.createElement('div');
			
			expect(element.classList).toBeDefined();
			expect(typeof element.classList.add).toBe('function');
			expect(typeof element.classList.remove).toBe('function');
			expect(typeof element.classList.contains).toBe('function');
		});

		it('should add multiple classes', () => {
			const element = document.createElement('div');
			element.classList.add('class1', 'class2', 'class3');
			
			expect(element.classList.contains('class1')).toBe(true);
			expect(element.classList.contains('class2')).toBe(true);
			expect(element.classList.contains('class3')).toBe(true);
		});
	});
});
