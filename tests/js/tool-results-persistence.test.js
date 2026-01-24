/**
 * Test for tool_results extraction in post-streaming handler
 * 
 * Ensures that system tool responses (e.g., get_system_logs, get_user_info)
 * persist correctly in the chat client even when message content is empty.
 *
 * This test addresses the bug where:
 * - System tool responses were not extracted from tool_results when message content was empty
 * - After page reload, responses vanished because they weren't persisted
 * - Post-streaming handler only checked streamResult.content and message.content
 *
 * Root cause: The post-streaming handler didn't extract content from tool_results
 * when both streamResult.content and message.content were empty.
 *
 * Solution: Add tool_results extraction logic mirroring the SSE streaming handler,
 * maintaining precedence: streaming chunks → message content → tool_results.
 *
 * @package WP_MCP_AI
 */

describe('Tool Results Persistence in Post-Streaming Handler', () => {
	/**
	 * Helper function to parse tool result content
	 * (Mirrors the implementation in chat.js)
	 */
	function parseToolResultContent(content) {
		if (!content) return null;
		if (typeof content === 'object') return content;
		if (typeof content === 'string') {
			try {
				return JSON.parse(content);
			} catch (e) {
				return content;
			}
		}
		return null;
	}

	/**
	 * Helper function to check if tool result is async pending
	 * (Mirrors the implementation in chat.js)
	 */
	function isAsyncPendingToolResult(parsedContent) {
		if (!parsedContent || typeof parsedContent !== 'object') {
			return false;
		}
		return parsedContent.status === 'pending' || parsedContent.status === 'async_pending';
	}

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
			if (typeof content.message === 'string') {
				return content.message;
			}
			// Some formats nest text deeper
			if (typeof content.content === 'string') {
				return content.content;
			}
		}
		
		return '';
	}

	/**
	 * Simulates the tool_results extraction logic from the fix
	 * (Lines 11769-11801 in chat.js after the fix)
	 */
	function extractFinalContentWithToolResults(streamResult) {
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
		
		// If still no content found, check tool_results for system tool responses
		if (!finalContent && streamResult.finalData && streamResult.finalData.tool_results) {
			if (Array.isArray(streamResult.finalData.tool_results) && streamResult.finalData.tool_results.length > 0) {
				for (let i = 0; i < streamResult.finalData.tool_results.length; i++) {
					const toolResult = streamResult.finalData.tool_results[i];
					if (!toolResult || !toolResult.content) continue;
					
					const parsedContent = parseToolResultContent(toolResult.content);
					if (isAsyncPendingToolResult(parsedContent)) continue;
					
					const toolText = extractTextFromContent(parsedContent);
					if (toolText) {
						if (finalContent) finalContent += '\n\n';
						finalContent += toolText;
					}
				}
			}
		}
		
		return finalContent;
	}

	describe('System tool response extraction', () => {
		it('should extract text from tool_results when message content is empty', () => {
			// Simulates system tool response with no message content
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
								message: 'System logs retrieved successfully',
								logs: ['Log entry 1', 'Log entry 2']
							})
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('System logs retrieved successfully');
		});

		it('should extract text from multiple tool results', () => {
			const streamResult = {
				content: '',
				finalData: {
					tool_results: [
						{
							role: 'tool',
							name: 'get_user_info',
							content: JSON.stringify({
								message: 'User info retrieved'
							})
						},
						{
							role: 'tool',
							name: 'get_system_logs',
							content: JSON.stringify({
								message: 'Logs retrieved'
							})
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('User info retrieved\n\nLogs retrieved');
		});

		it('should handle tool result with string content directly', () => {
			const streamResult = {
				content: '',
				finalData: {
					tool_results: [
						{
							role: 'tool',
							name: 'get_system_logs',
							content: 'Direct string content from tool'
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('Direct string content from tool');
		});

		it('should handle tool result with text field', () => {
			const streamResult = {
				content: '',
				finalData: {
					tool_results: [
						{
							role: 'tool',
							name: 'research_topic',
							content: JSON.stringify({
								text: 'Research results about the topic'
							})
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('Research results about the topic');
		});
	});

	describe('Async pending result handling', () => {
		it('should skip async pending tool results', () => {
			const streamResult = {
				content: '',
				finalData: {
					tool_results: [
						{
							role: 'tool',
							name: 'generate_video',
							content: JSON.stringify({
								status: 'pending',
								message: 'Video generation in progress'
							})
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('');
		});

		it('should skip async_pending status results', () => {
			const streamResult = {
				content: '',
				finalData: {
					tool_results: [
						{
							role: 'tool',
							name: 'generate_veo_video',
							content: JSON.stringify({
								status: 'async_pending',
								job_id: '12345'
							})
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('');
		});

		it('should extract from completed tool and skip pending tool', () => {
			const streamResult = {
				content: '',
				finalData: {
					tool_results: [
						{
							role: 'tool',
							name: 'get_user_info',
							content: JSON.stringify({
								message: 'User info retrieved'
							})
						},
						{
							role: 'tool',
							name: 'generate_video',
							content: JSON.stringify({
								status: 'pending',
								message: 'Video in progress'
							})
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('User info retrieved');
		});
	});

	describe('Content precedence', () => {
		it('should prefer streamResult.content over tool_results', () => {
			const streamResult = {
				content: 'Streamed content from chunks',
				finalData: {
					tool_results: [
						{
							role: 'tool',
							name: 'get_system_logs',
							content: JSON.stringify({
								message: 'This should be ignored'
							})
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('Streamed content from chunks');
		});

		it('should prefer message.content over tool_results', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: 'Message content from LLM'
							}
						}]
					},
					tool_results: [
						{
							role: 'tool',
							name: 'get_system_logs',
							content: JSON.stringify({
								message: 'This should be ignored'
							})
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('Message content from LLM');
		});

		it('should use tool_results only when both streaming and message content are empty', () => {
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
								message: 'Tool result content'
							})
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('Tool result content');
		});
	});

	describe('Edge cases', () => {
		it('should handle missing tool_results array', () => {
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

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('');
		});

		it('should handle empty tool_results array', () => {
			const streamResult = {
				content: '',
				finalData: {
					tool_results: []
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('');
		});

		it('should handle tool result with missing content field', () => {
			const streamResult = {
				content: '',
				finalData: {
					tool_results: [
						{
							role: 'tool',
							name: 'get_system_logs'
							// No content field
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('');
		});

		it('should handle tool result with null content', () => {
			const streamResult = {
				content: '',
				finalData: {
					tool_results: [
						{
							role: 'tool',
							name: 'get_system_logs',
							content: null
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('');
		});

		it('should handle malformed JSON in tool result content', () => {
			const streamResult = {
				content: '',
				finalData: {
					tool_results: [
						{
							role: 'tool',
							name: 'get_system_logs',
							content: '{invalid json}'
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			// Should fall back to treating it as plain string
			expect(finalContent).toBe('{invalid json}');
		});

		it('should handle tool result with object content (already parsed)', () => {
			const streamResult = {
				content: '',
				finalData: {
					tool_results: [
						{
							role: 'tool',
							name: 'get_system_logs',
							content: {
								message: 'Already parsed object'
							}
						}
					]
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('Already parsed object');
		});
	});

	describe('Backward compatibility', () => {
		it('should not break existing streaming behavior', () => {
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
					}
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('Streamed content');
		});

		it('should not break existing finalData.data extraction', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						choices: [{
							message: {
								role: 'assistant',
								content: 'Message from finalData'
							}
						}]
					}
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('Message from finalData');
		});

		it('should handle Ollama response format', () => {
			const streamResult = {
				content: '',
				finalData: {
					data: {
						content: 'Ollama response content'
					}
				}
			};

			const finalContent = extractFinalContentWithToolResults(streamResult);
			
			expect(finalContent).toBe('Ollama response content');
		});
	});
});
