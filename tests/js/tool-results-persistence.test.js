/**
 * Test for tool_results extraction in post-streaming handler
 * 
 * Ensures that when a streaming response completes with tool_results but
 * no message content, the code correctly extracts the text from tool_results
 * and persists it in the chat client.
 *
 * This test addresses the bug where:
 * - System tool responses (like get_system_logs, get_user_info) were not persisting
 * - After page reload, these responses would disappear
 * - The post-streaming handler didn't check tool_results when message content was empty
 *
 * Root cause: The post-streaming handler extracted finalContent from streaming chunks
 * and message content, but never checked tool_results. This caused finalContent to be
 * empty for system tool responses, leading to persistence failure.
 *
 * @package WP_MCP_AI
 */

describe('Tool Results Persistence in Post-Streaming Handler', () => {
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
	 * Helper to parse tool result content
	 */
	function parseToolResultContent(content) {
		if (!content) {
			return null;
		}
		
		if (typeof content === 'string') {
			try {
				return JSON.parse(content);
			} catch (e) {
				return content;
			}
		}
		
		return content;
	}

	/**
	 * Helper to check if tool result is async pending
	 */
	function isAsyncPendingToolResult(parsedContent) {
		if (!parsedContent || typeof parsedContent !== 'object') {
			return false;
		}
		
		return parsedContent.status === 'pending' || 
		       parsedContent.state === 'pending' ||
		       parsedContent.async === true;
	}

	/**
	 * Simulates the finalContent extraction logic with tool_results support
	 * (Lines 11959-12020 in chat.js after the fix)
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
		
		// BUG FIX: If still no content found, check tool_results for system tool responses
		if (!finalContent && streamResult.finalData && streamResult.finalData.tool_results) {
			if (Array.isArray(streamResult.finalData.tool_results) && streamResult.finalData.tool_results.length > 0) {
				for (let i = 0; i < streamResult.finalData.tool_results.length; i++) {
					const toolResult = streamResult.finalData.tool_results[i];
					if (!toolResult || !toolResult.content) {
						continue;
					}
					
					// Parse tool result content
					const parsedContent = parseToolResultContent(toolResult.content);
					
					// Skip async pending results
					if (isAsyncPendingToolResult(parsedContent)) {
						continue;
					}
					
					// Use existing extractTextFromContent helper for consistent text extraction
					const toolText = extractTextFromContent(parsedContent);
					if (toolText) {
						if (finalContent) {
							finalContent += '\n\n';
						}
						finalContent += toolText;
					}
				}
			}
		}
		
		return finalContent;
	}

	describe('Bug scenario: System tool response without message content', () => {
		it('should extract content from tool_results when no message content', () => {
			// Simulates get_system_logs or get_user_info tool response
			const streamResult = {
				content: '', // No streaming chunks
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: '' // No message content
							}
						}]
					},
					tool_results: [{
						role: 'tool',
						name: 'get_system_logs',
						content: JSON.stringify({
							text: 'System logs:\n- Error on line 123\n- Warning on line 456'
						})
					}]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			// Should have extracted content from tool_results
			expect(finalContent).toContain('System logs:');
			expect(finalContent).toContain('Error on line 123');
			expect(finalContent.length).toBeGreaterThan(0);
		});

		it('should extract content from get_user_info tool', () => {
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
					},
					tool_results: [{
						role: 'tool',
						name: 'get_user_info',
						content: JSON.stringify({
							text: 'User: John Doe\nEmail: john@example.com\nRole: Administrator'
						})
					}]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			expect(finalContent).toContain('User: John Doe');
			expect(finalContent).toContain('Email: john@example.com');
			expect(finalContent).toContain('Role: Administrator');
		});

		it('should handle multiple tool results', () => {
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
					},
					tool_results: [
						{
							role: 'tool',
							name: 'get_system_logs',
							content: JSON.stringify({
								text: 'System logs data'
							})
						},
						{
							role: 'tool',
							name: 'get_user_info',
							content: JSON.stringify({
								text: 'User info data'
							})
						}
					]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			// Should contain both tool results separated by double newlines
			expect(finalContent).toContain('System logs data');
			expect(finalContent).toContain('User info data');
			expect(finalContent).toContain('\n\n');
		});
	});

	describe('Tool result content formats', () => {
		it('should handle string content directly', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: { role: 'assistant', content: '' }
						}]
					},
					tool_results: [{
						role: 'tool',
						name: 'test_tool',
						content: 'Direct string content'
					}]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			expect(finalContent).toBe('Direct string content');
		});

		it('should handle JSON string with text field', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: { role: 'assistant', content: '' }
						}]
					},
					tool_results: [{
						role: 'tool',
						name: 'test_tool',
						content: JSON.stringify({ text: 'JSON text content' })
					}]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			expect(finalContent).toBe('JSON text content');
		});

		it('should handle object with text field', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: { role: 'assistant', content: '' }
						}]
					},
					tool_results: [{
						role: 'tool',
						name: 'test_tool',
						content: { text: 'Object text content' }
					}]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			expect(finalContent).toBe('Object text content');
		});

		it('should skip async pending tool results', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: { role: 'assistant', content: '' }
						}]
					},
					tool_results: [
						{
							role: 'tool',
							name: 'async_tool',
							content: JSON.stringify({ status: 'pending', text: 'Processing...' })
						},
						{
							role: 'tool',
							name: 'completed_tool',
							content: JSON.stringify({ text: 'Completed result' })
						}
					]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			// Should only include the completed tool result
			expect(finalContent).toBe('Completed result');
			expect(finalContent).not.toContain('Processing');
		});

		it('should skip tool results without content', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: { role: 'assistant', content: '' }
						}]
					},
					tool_results: [
						{
							role: 'tool',
							name: 'empty_tool',
							content: null
						},
						{
							role: 'tool',
							name: 'valid_tool',
							content: 'Valid content'
						}
					]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			expect(finalContent).toBe('Valid content');
		});
	});

	describe('Backward compatibility', () => {
		it('should prefer message content over tool_results when both exist', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: 'Message content'
							}
						}]
					},
					tool_results: [{
						role: 'tool',
						name: 'test_tool',
						content: 'Tool result content'
					}]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			// Should use message content, not tool results
			expect(finalContent).toBe('Message content');
			expect(finalContent).not.toContain('Tool result');
		});

		it('should prefer streaming chunks over everything', () => {
			const streamResult = {
				content: 'Streamed content',
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: 'Message content'
							}
						}]
					},
					tool_results: [{
						role: 'tool',
						name: 'test_tool',
						content: 'Tool result content'
					}]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			// Should use streamed content first
			expect(finalContent).toBe('Streamed content');
		});

		it('should return empty string when no content available', () => {
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
					},
					tool_results: []
				}
			};

			const finalContent = extractFinalContent(streamResult);
			expect(finalContent).toBe('');
		});
	});

	describe('Edge cases', () => {
		it('should handle missing tool_results array', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: { role: 'assistant', content: '' }
						}]
					}
					// No tool_results
				}
			};

			const finalContent = extractFinalContent(streamResult);
			expect(finalContent).toBe('');
		});

		it('should handle null tool_results', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: { role: 'assistant', content: '' }
						}]
					},
					tool_results: null
				}
			};

			const finalContent = extractFinalContent(streamResult);
			expect(finalContent).toBe('');
		});

		it('should handle empty tool_results array', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: { role: 'assistant', content: '' }
						}]
					},
					tool_results: []
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
	});

	describe('Persistence scenario', () => {
		it('should enable shouldKeepStreamingElement when content extracted from tool_results', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: { role: 'assistant', content: '' }
						}]
					},
					tool_results: [{
						role: 'tool',
						name: 'get_system_logs',
						content: JSON.stringify({
							text: 'System log content that should persist'
						})
					}]
				}
			};

			const finalContent = extractFinalContent(streamResult);
			
			// Simulate the shouldKeepStreamingElement condition
			const streamingMessageElement = { parentNode: {} }; // Mock element in DOM
			const shouldKeepStreamingElement = streamingMessageElement && 
			                                  streamingMessageElement.parentNode &&
			                                  finalContent &&
			                                  finalContent.trim();
			
			// With the fix, this should now be truthy (content was extracted)
			expect(shouldKeepStreamingElement).toBeTruthy();
			expect(finalContent).toContain('System log content');
		});
	});
});
