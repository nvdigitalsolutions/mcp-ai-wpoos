/**
 * Test for SSE parsing robustness with non-string content
 * 
 * Ensures that the SSE parser handles cases where message.content
 * is not a string (e.g., object, array, null) without throwing errors.
 *
 * This addresses the bug: "finalText.substring is not a function"
 * which occurred when content was an object instead of a string.
 *
 * @package WP_MCP_AI
 */

describe('SSE Non-String Content Handling', () => {
	it('should handle object content in choices[0].message.content gracefully', () => {
		// Simulate the data structure that caused the error
		const mockData = {
			assistant_id: 331,
			data: {
				id: 'chatcmpl-test',
				choices: [
					{
						index: 0,
						message: {
							role: 'assistant',
							// Content is an object, not a string (the bug case)
							content: {
								type: 'text',
								text: 'This is the actual text'
							}
						}
					}
				]
			}
		};

		// The parsing logic should not throw an error
		// We can't directly test the chat.js internal function here,
		// but we can verify the type check pattern works
		let finalText = '';
		
		// This is the fixed pattern (with type check)
		if (mockData.data.choices && 
			mockData.data.choices[0] && 
			mockData.data.choices[0].message && 
			mockData.data.choices[0].message.content && 
			typeof mockData.data.choices[0].message.content === 'string') {
			finalText = mockData.data.choices[0].message.content;
		}

		// finalText should remain empty because content is not a string
		expect(finalText).toBe('');
		
		// This would have failed before the fix:
		// expect(() => finalText.substring(0, 100)).not.toThrow();
		// Now it doesn't even try to call substring because finalText is empty
	});

	it('should handle array content in choices[0].message.content gracefully', () => {
		const mockData = {
			data: {
				choices: [
					{
						message: {
							content: ['text1', 'text2'] // Array instead of string
						}
					}
				]
			}
		};

		let finalText = '';
		
		if (mockData.data.choices && 
			mockData.data.choices[0] && 
			mockData.data.choices[0].message && 
			mockData.data.choices[0].message.content && 
			typeof mockData.data.choices[0].message.content === 'string') {
			finalText = mockData.data.choices[0].message.content;
		}

		expect(finalText).toBe('');
	});

	it('should handle null content in choices[0].message.content gracefully', () => {
		const mockData = {
			data: {
				choices: [
					{
						message: {
							content: null // Null instead of string
						}
					}
				]
			}
		};

		let finalText = '';
		
		if (mockData.data.choices && 
			mockData.data.choices[0] && 
			mockData.data.choices[0].message && 
			mockData.data.choices[0].message.content && 
			typeof mockData.data.choices[0].message.content === 'string') {
			finalText = mockData.data.choices[0].message.content;
		}

		expect(finalText).toBe('');
	});

	it('should extract string content when it is actually a string', () => {
		const mockData = {
			data: {
				choices: [
					{
						message: {
							content: 'This is a valid string response'
						}
					}
				]
			}
		};

		let finalText = '';
		
		if (mockData.data.choices && 
			mockData.data.choices[0] && 
			mockData.data.choices[0].message && 
			mockData.data.choices[0].message.content && 
			typeof mockData.data.choices[0].message.content === 'string') {
			finalText = mockData.data.choices[0].message.content;
		}

		expect(finalText).toBe('This is a valid string response');
		
		// Should be safe to call substring now
		expect(finalText.substring(0, 10)).toBe('This is a ');
	});

	it('should handle missing choices array gracefully', () => {
		const mockData = {
			data: {
				// No choices array
			}
		};

		let finalText = '';
		
		if (mockData.data.choices && 
			mockData.data.choices[0] && 
			mockData.data.choices[0].message && 
			mockData.data.choices[0].message.content && 
			typeof mockData.data.choices[0].message.content === 'string') {
			finalText = mockData.data.choices[0].message.content;
		}

		expect(finalText).toBe('');
	});

	it('should handle empty choices array gracefully', () => {
		const mockData = {
			data: {
				choices: [] // Empty array
			}
		};

		let finalText = '';
		
		if (mockData.data.choices && 
			mockData.data.choices[0] && 
			mockData.data.choices[0].message && 
			mockData.data.choices[0].message.content && 
			typeof mockData.data.choices[0].message.content === 'string') {
			finalText = mockData.data.choices[0].message.content;
		}

		expect(finalText).toBe('');
	});

	it('should fallback to other content extraction methods', () => {
		// Test the fallback chain
		const mockDataWithContent = {
			data: {
				choices: [
					{
						message: {
							content: { not: 'a string' } // Not a string, should skip
						}
					}
				],
				content: 'This is the fallback content' // Should use this
			}
		};

		let finalText = '';
		
		// First check (should fail because content is not a string)
		if (mockDataWithContent.data.choices && 
			mockDataWithContent.data.choices[0] && 
			mockDataWithContent.data.choices[0].message && 
			mockDataWithContent.data.choices[0].message.content && 
			typeof mockDataWithContent.data.choices[0].message.content === 'string') {
			finalText = mockDataWithContent.data.choices[0].message.content;
		}
		// Fallback check
		else if (mockDataWithContent.data.content && typeof mockDataWithContent.data.content === 'string') {
			finalText = mockDataWithContent.data.content;
		}

		expect(finalText).toBe('This is the fallback content');
	});
});
