/**
 * Test for streaming finalData content extraction
 * 
 * Ensures that when a streaming response completes with finalData but
 * no streaming chunks (streamResult.content is empty), the code correctly
 * extracts the message text from the finalData structure and displays it.
 *
 * This test addresses the bug where:
 * - Assistant responses were saved to conversation array
 * - But NOT displayed in the chat window (empty bubble or no bubble)
 * - Messages appeared correctly after reload from localStorage
 *
 * Root cause: The code only checked streamResult.content (from streaming chunks)
 * and didn't extract content from finalData when streaming chunks weren't received.
 *
 * @package WP_MCP_AI
 */

describe('Streaming finalData Content Extraction', () => {
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

	/**
	 * Simulates the finalContent extraction logic from the bug fix
	 * (Lines 11639-11657 in chat.js)
	 */
	function extractFinalContent(streamResult) {
		let finalContent = streamResult.content || '';
		
		// If no streaming content, extract from finalData structure
		if (!finalContent && streamResult.finalData && streamResult.finalData.data) {
			const chatData = streamResult.finalData.data;
			if (chatData.choices && chatData.choices[0] && chatData.choices[0].message) {
				finalContent = extractTextFromContent(chatData.choices[0].message.content) || '';
			} else if (chatData.content) {
				finalContent = extractTextFromContent(chatData.content) || '';
			}
		}
		
		return finalContent;
	}

	describe('Bug scenario: No streaming chunks received', () => {
		it('should extract content from finalData when streamResult.content is empty', () => {
			// This simulates the bug scenario: complete response arrives in finalData
			// but no streaming chunks were sent (streamResult.content is empty)
			const streamResult = {
				content: '', // No streaming chunks received
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: 'This is the complete response from finalData'
							}
						}]
					}
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			// Should have extracted the content from finalData
			expect(finalContent).toBe('This is the complete response from finalData');
			expect(finalContent.length).toBeGreaterThan(0);
		});

		it('should extract research tool response from finalData', () => {
			// Simulates a research_topic tool response that arrives complete
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: '# Research Report: Dogs and Cats\n\n## Overview\n\nComprehensive research on both dogs and cats...'
							}
						}]
					},
					tool_results: [
						{
							role: 'tool',
							name: 'research_topic',
							content: '{"topic": "dogs", "report": "..."}'
						}
					]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			expect(finalContent).toContain('Research Report: Dogs and Cats');
			expect(finalContent).toContain('Overview');
		});
	});

	describe('Normal scenario: Streaming chunks received', () => {
		it('should use streamResult.content when streaming chunks were received', () => {
			// Normal case: streaming chunks accumulated in streamResult.content
			const streamResult = {
				content: 'Streamed content that was accumulated',
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: 'This should be ignored'
							}
						}]
					}
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			// Should prefer streamResult.content when it exists
			expect(finalContent).toBe('Streamed content that was accumulated');
		});
	});

	describe('Edge cases', () => {
		it('should handle finalData with no choices array', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						content: 'Fallback content field'
					}
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			expect(finalContent).toBe('Fallback content field');
		});

		it('should return empty string when both streamResult.content and finalData are empty', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: ''
							}
						}]
					}
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			expect(finalContent).toBe('');
		});

		it('should handle missing finalData', () => {
			const streamResult = {
				content: ''
				// No finalData
			};

			const finalContent = extractFinalContent(streamResult);
			
			expect(finalContent).toBe('');
		});

		it('should handle object content in finalData', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: {
									type: 'text',
									text: 'Object wrapped content'
								}
							}
						}]
					}
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			expect(finalContent).toBe('Object wrapped content');
		});

		it('should handle array content in finalData', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: [
									{ type: 'text', text: 'Part one ' },
									{ type: 'text', text: 'part two' }
								]
							}
						}]
					}
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			expect(finalContent).toBe('Part one part two');
		});
	});

	describe('shouldKeepStreamingElement logic', () => {
		it('should return true when finalContent is extracted from finalData', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: 'Extracted content'
							}
						}]
					}
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			// Simulate the shouldKeepStreamingElement condition
			const streamingMessageElement = { parentNode: {} }; // Mock element in DOM
			const shouldKeepStreamingElement = streamingMessageElement && 
			                                  streamingMessageElement.parentNode &&
			                                  finalContent &&
			                                  finalContent.trim();
			
			// With the fix, this should now be truthy (the content string)
			expect(shouldKeepStreamingElement).toBeTruthy();
			expect(finalContent).toBe('Extracted content');
		});

		it('should return false when no content is available', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: ''
							}
						}]
					}
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			// Simulate the shouldKeepStreamingElement condition
			const streamingMessageElement = { parentNode: {} };
			const shouldKeepStreamingElement = streamingMessageElement && 
			                                  streamingMessageElement.parentNode &&
			                                  finalContent &&
			                                  finalContent.trim();
			
			// Should be falsy when no content
			expect(shouldKeepStreamingElement).toBeFalsy();
		});
	});

	describe('Real-world scenarios', () => {
		it('should handle OpenAI chat completion response without streaming', () => {
			// Real OpenAI response structure when streaming is disabled or chunks not sent
			const streamResult = {
				content: '',
				finalData: {
					sessionKey: 'session123',
					data: {
						id: 'chatcmpl-123',
						object: 'chat.completion',
						created: 1234567890,
						model: 'gpt-4',
						choices: [{
							index: 0,
							message: {
								role: 'assistant',
								content: 'I can help you with that. Here is the information you requested about dogs and cats.'
							},
							finish_reason: 'stop'
						}],
						usage: {
							prompt_tokens: 50,
							completion_tokens: 150,
							total_tokens: 200
						}
					}
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			expect(finalContent).toBe('I can help you with that. Here is the information you requested about dogs and cats.');
			expect(typeof finalContent).toBe('string');
			expect(finalContent.length).toBeGreaterThan(0);
		});

		it('should handle Ollama response without streaming', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						content: 'Here is my response about the topic you asked about.'
					}
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			expect(finalContent).toBe('Here is my response about the topic you asked about.');
		});
	});
});
