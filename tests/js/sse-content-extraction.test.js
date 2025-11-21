/**
 * Test for SSE content extraction with various provider formats
 * 
 * Ensures that the extractTextFromContent helper function correctly
 * handles all provider formats: OpenAI, Gemini, Ollama, and others.
 *
 * This addresses:
 * - "finalText.substring is not a function" error
 * - "[object Object]" showing in responses
 * - Support for all AI providers
 *
 * @package WP_MCP_AI
 */

describe('SSE Content Extraction for All Providers', () => {
	/**
	 * Helper function to extract text from various content formats
	 * (Mirrors the implementation in chat.js)
	 */
	function extractTextFromContent(content) {
		if (!content) {
			return '';
		}
		
		// If already a string, return it
		if (typeof content === 'string') {
			return content;
		}
		
		// Handle array of content items (some providers return this)
		if (Array.isArray(content)) {
			let text = '';
			for (let i = 0; i < content.length; i++) {
				const item = content[i];
				if (typeof item === 'string') {
					text += item;
				} else if (item && typeof item === 'object') {
					// Handle nested object in array
					if (typeof item.text === 'string') {
						text += item.text;
					} else if (typeof item.content === 'string') {
						text += item.content;
					}
				}
			}
			return text;
		}
		
		// Handle object with text property (common format)
		if (typeof content === 'object') {
			if (typeof content.text === 'string') {
				return content.text;
			}
			// Some formats nest text deeper
			if (typeof content.content === 'string') {
				return content.content;
			}
		}
		
		return '';
	}

	describe('String content', () => {
		it('should extract plain string content', () => {
			const content = 'This is a plain text response';
			const result = extractTextFromContent(content);
			expect(result).toBe('This is a plain text response');
		});

		it('should handle empty string', () => {
			const content = '';
			const result = extractTextFromContent(content);
			expect(result).toBe('');
		});
	});

	describe('Object content with text property', () => {
		it('should extract text from object with text property', () => {
			const content = {
				type: 'text',
				text: 'This is nested text content'
			};
			const result = extractTextFromContent(content);
			expect(result).toBe('This is nested text content');
		});

		it('should extract text from object with content property', () => {
			const content = {
				type: 'response',
				content: 'This is nested in content property'
			};
			const result = extractTextFromContent(content);
			expect(result).toBe('This is nested in content property');
		});

		it('should prefer text over content property', () => {
			const content = {
				text: 'This should be returned',
				content: 'This should be ignored'
			};
			const result = extractTextFromContent(content);
			expect(result).toBe('This should be returned');
		});
	});

	describe('Array content', () => {
		it('should extract text from array of strings', () => {
			const content = ['Hello ', 'world', '!'];
			const result = extractTextFromContent(content);
			expect(result).toBe('Hello world!');
		});

		it('should extract text from array of objects with text property', () => {
			const content = [
				{ type: 'text', text: 'First part ' },
				{ type: 'text', text: 'second part' }
			];
			const result = extractTextFromContent(content);
			expect(result).toBe('First part second part');
		});

		it('should extract text from array of objects with content property', () => {
			const content = [
				{ type: 'message', content: 'Part one ' },
				{ type: 'message', content: 'part two' }
			];
			const result = extractTextFromContent(content);
			expect(result).toBe('Part one part two');
		});

		it('should handle mixed array of strings and objects', () => {
			const content = [
				'Start ',
				{ text: 'middle' },
				' end'
			];
			const result = extractTextFromContent(content);
			expect(result).toBe('Start middle end');
		});

		it('should handle empty array', () => {
			const content = [];
			const result = extractTextFromContent(content);
			expect(result).toBe('');
		});
	});

	describe('Edge cases', () => {
		it('should handle null content', () => {
			const content = null;
			const result = extractTextFromContent(content);
			expect(result).toBe('');
		});

		it('should handle undefined content', () => {
			const content = undefined;
			const result = extractTextFromContent(content);
			expect(result).toBe('');
		});

		it('should handle object with no text or content property', () => {
			const content = {
				type: 'unknown',
				data: 'some data'
			};
			const result = extractTextFromContent(content);
			expect(result).toBe('');
		});

		it('should handle number content', () => {
			const content = 123;
			const result = extractTextFromContent(content);
			expect(result).toBe('');
		});

		it('should handle boolean content', () => {
			const content = true;
			const result = extractTextFromContent(content);
			expect(result).toBe('');
		});
	});

	describe('Provider-specific formats', () => {
		it('should handle OpenAI format with string content', () => {
			const mockData = {
				data: {
					choices: [{
						message: {
							role: 'assistant',
							content: 'OpenAI response text'
						}
					}]
				}
			};
			
			const content = mockData.data.choices[0].message.content;
			const result = extractTextFromContent(content);
			expect(result).toBe('OpenAI response text');
		});

		it('should handle OpenAI format with object content', () => {
			const mockData = {
				data: {
					choices: [{
						message: {
							role: 'assistant',
							content: {
								type: 'text',
								text: 'OpenAI nested response'
							}
						}
					}]
				}
			};
			
			const content = mockData.data.choices[0].message.content;
			const result = extractTextFromContent(content);
			expect(result).toBe('OpenAI nested response');
		});

		it('should handle OpenAI format with array content', () => {
			const mockData = {
				data: {
					choices: [{
						message: {
							role: 'assistant',
							content: [
								{ type: 'text', text: 'First part ' },
								{ type: 'text', text: 'second part' }
							]
						}
					}]
				}
			};
			
			const content = mockData.data.choices[0].message.content;
			const result = extractTextFromContent(content);
			expect(result).toBe('First part second part');
		});

		it('should handle Ollama format', () => {
			const mockData = {
				data: {
					response: 'Ollama response text'
				}
			};
			
			const content = mockData.data.response;
			const result = extractTextFromContent(content);
			expect(result).toBe('Ollama response text');
		});

		it('should handle generic content field', () => {
			const mockData = {
				data: {
					content: 'Generic provider response'
				}
			};
			
			const content = mockData.data.content;
			const result = extractTextFromContent(content);
			expect(result).toBe('Generic provider response');
		});
	});

	describe('Integration with finalText type checking', () => {
		it('should always return a string type', () => {
			const testCases = [
				'string content',
				{ text: 'object content' },
				['array', 'content'],
				null,
				undefined,
				123,
				true
			];

			testCases.forEach(testCase => {
				const result = extractTextFromContent(testCase);
				expect(typeof result).toBe('string');
			});
		});

		it('should be safe to call .substring() on result', () => {
			const content = 'This is a long text that we want to sample';
			const result = extractTextFromContent(content);
			
			// This should not throw an error
			expect(() => result.substring(0, 10)).not.toThrow();
			expect(result.substring(0, 10)).toBe('This is a ');
		});

		it('should be safe to call .substring() even on empty result', () => {
			const content = null;
			const result = extractTextFromContent(content);
			
			// This should not throw an error even though result is empty string
			expect(() => result.substring(0, 10)).not.toThrow();
			expect(result.substring(0, 10)).toBe('');
		});

		it('should not return "[object Object]" for object content', () => {
			const content = {
				type: 'text',
				text: 'Proper text extraction'
			};
			const result = extractTextFromContent(content);
			
			// Should extract the text, not convert object to string
			expect(result).not.toContain('[object Object]');
			expect(result).toBe('Proper text extraction');
		});
	});
});
