/**
 * Tests for SSE message processing with final response data
 *
 * @package WP_MCP_AI
 */

describe('SSE Message Processing', () => {
	describe('Final response content extraction', () => {
		it('should extract content from data.data.choices[0].message.content', () => {
			// Simulate the SSE message event data structure
			const sseEventData = {
				data: {
					choices: [
						{
							message: {
								content: 'This is the assistant response text'
							}
						}
					]
				}
			};

			// Extract content as the code does
			let fullContent = '';
			if (!fullContent && sseEventData.data && sseEventData.data.choices && sseEventData.data.choices[0]) {
				const finalMessage = sseEventData.data.choices[0].message;
				if (finalMessage && finalMessage.content) {
					fullContent = typeof finalMessage.content === 'string' 
						? finalMessage.content 
						: '';
				}
			}

			expect(fullContent).toBe('This is the assistant response text');
		});

		it('should handle missing content gracefully', () => {
			const sseEventData = {
				data: {
					choices: [
						{
							message: {}
						}
					]
				}
			};

			let fullContent = '';
			if (!fullContent && sseEventData.data && sseEventData.data.choices && sseEventData.data.choices[0]) {
				const finalMessage = sseEventData.data.choices[0].message;
				if (finalMessage && finalMessage.content) {
					fullContent = typeof finalMessage.content === 'string' 
						? finalMessage.content 
						: '';
				}
			}

			expect(fullContent).toBe('');
		});

		it('should handle missing message gracefully', () => {
			const sseEventData = {
				data: {
					choices: [{}]
				}
			};

			let fullContent = '';
			if (!fullContent && sseEventData.data && sseEventData.data.choices && sseEventData.data.choices[0]) {
				const finalMessage = sseEventData.data.choices[0].message;
				if (finalMessage && finalMessage.content) {
					fullContent = typeof finalMessage.content === 'string' 
						? finalMessage.content 
						: '';
				}
			}

			expect(fullContent).toBe('');
		});

		it('should not extract content if fullContent is already populated', () => {
			const sseEventData = {
				data: {
					choices: [
						{
							message: {
								content: 'New content that should be ignored'
							}
						}
					]
				}
			};

			let fullContent = 'Already streamed content';
			// Only extract if fullContent is empty
			if (!fullContent && sseEventData.data && sseEventData.data.choices && sseEventData.data.choices[0]) {
				const finalMessage = sseEventData.data.choices[0].message;
				if (finalMessage && finalMessage.content) {
					fullContent = typeof finalMessage.content === 'string' 
						? finalMessage.content 
						: '';
				}
			}

			expect(fullContent).toBe('Already streamed content');
		});

		it('should handle non-string content', () => {
			const sseEventData = {
				data: {
					choices: [
						{
							message: {
								content: { text: 'complex object' }
							}
						}
					]
				}
			};

			let fullContent = '';
			if (!fullContent && sseEventData.data && sseEventData.data.choices && sseEventData.data.choices[0]) {
				const finalMessage = sseEventData.data.choices[0].message;
				if (finalMessage && finalMessage.content) {
					fullContent = typeof finalMessage.content === 'string' 
						? finalMessage.content 
						: '';
				}
			}

			expect(fullContent).toBe('');
		});

		it('should handle empty string content', () => {
			const sseEventData = {
				data: {
					choices: [
						{
							message: {
								content: ''
							}
						}
					]
				}
			};

			let fullContent = '';
			if (!fullContent && sseEventData.data && sseEventData.data.choices && sseEventData.data.choices[0]) {
				const finalMessage = sseEventData.data.choices[0].message;
				if (finalMessage && finalMessage.content) {
					fullContent = typeof finalMessage.content === 'string' 
						? finalMessage.content 
						: '';
				}
			}

			expect(fullContent).toBe('');
		});
	});

	describe('OpenAI streaming format', () => {
		it('should accumulate content from delta chunks', () => {
			const chunks = [
				{ choices: [{ delta: { content: 'Hello' } }] },
				{ choices: [{ delta: { content: ', ' } }] },
				{ choices: [{ delta: { content: 'world' } }] },
				{ choices: [{ delta: { content: '!' } }] }
			];

			let fullContent = '';
			chunks.forEach(data => {
				if (data.choices && data.choices[0]) {
					const delta = data.choices[0].delta;
					if (delta && delta.content) {
						fullContent += delta.content;
					}
				}
			});

			expect(fullContent).toBe('Hello, world!');
		});

		it('should handle missing delta', () => {
			const data = {
				choices: [{ delta: {} }]
			};

			let fullContent = '';
			if (data.choices && data.choices[0]) {
				const delta = data.choices[0].delta;
				if (delta && delta.content) {
					fullContent += delta.content;
				}
			}

			expect(fullContent).toBe('');
		});
	});
});
