/**
 * Tests for filtering tool result messages from API requests
 *
 * These tests verify that tool result messages (role: 'tool') are:
 * 1. Persisted in the conversation for localStorage storage
 * 2. Filtered out when sending messages to the API to keep payloads lean
 * 3. Filtered out when saving to CCT (transcripts)
 *
 * @package WP_MCP_AI
 */

describe('Tool Result Message Filtering', () => {
	let filterMessagesForApi;

	beforeEach(() => {
		/**
		 * Mock of the filter logic used in chat.js for sending messages to API and CCT.
		 * This filters out:
		 * - System messages (UI feedback only)
		 * - Tool messages (persisted locally but not sent to API/CCT)
		 * - Assistant messages with tool_calls (intermediate agentic loop messages)
		 */
		filterMessagesForApi = function(conversation) {
			return conversation.filter(function(message) {
				if (!message) {
					return false;
				}
				// Exclude system and tool messages
				if (message.role === 'system' || message.role === 'tool') {
					return false;
				}
				// Exclude assistant messages with tool_calls (intermediate agentic loop messages)
				if (message.role === 'assistant' && message.tool_calls && Array.isArray(message.tool_calls) && message.tool_calls.length > 0) {
					return false;
				}
				return true;
			});
		};
	});

	describe('Basic filtering', () => {
		it('should filter out tool result messages', () => {
			const conversation = [
				{ role: 'user', content: 'What time is it?' },
				{ role: 'assistant', content: 'The current time is 12:00 PM.' },
				{
					role: 'tool',
					tool_call_id: 'call_123',
					name: 'get_current_time',
					content: '{"time": "12:00 PM"}'
				}
			];

			const filtered = filterMessagesForApi(conversation);

			expect(filtered).toHaveLength(2);
			expect(filtered[0].role).toBe('user');
			expect(filtered[1].role).toBe('assistant');
			// Tool message should be filtered out
			expect(filtered.find(m => m.role === 'tool')).toBeUndefined();
		});

		it('should filter out system messages', () => {
			const conversation = [
				{ role: 'user', content: 'Hello' },
				{ role: 'system', content: 'Error: Something went wrong' },
				{ role: 'assistant', content: 'Hi there!' }
			];

			const filtered = filterMessagesForApi(conversation);

			expect(filtered).toHaveLength(2);
			expect(filtered[0].role).toBe('user');
			expect(filtered[1].role).toBe('assistant');
			// System message should be filtered out
			expect(filtered.find(m => m.role === 'system')).toBeUndefined();
		});

		it('should filter out assistant messages with tool_calls', () => {
			const conversation = [
				{ role: 'user', content: 'What is the weather?' },
				{
					role: 'assistant',
					content: 'Let me check the weather for you.',
					tool_calls: [
						{
							id: 'call_weather_001',
							type: 'function',
							function: {
								name: 'get_weather',
								arguments: '{"location": "London"}'
							}
						}
					]
				},
				{
					role: 'tool',
					tool_call_id: 'call_weather_001',
					name: 'get_weather',
					content: '{"temperature": "20C", "condition": "sunny"}'
				},
				{ role: 'assistant', content: 'The weather in London is 20°C and sunny.' }
			];

			const filtered = filterMessagesForApi(conversation);

			expect(filtered).toHaveLength(2);
			expect(filtered[0].role).toBe('user');
			expect(filtered[0].content).toBe('What is the weather?');
			expect(filtered[1].role).toBe('assistant');
			expect(filtered[1].content).toBe('The weather in London is 20°C and sunny.');
			// Intermediate assistant with tool_calls should be filtered out
			expect(filtered.find(m => m.tool_calls)).toBeUndefined();
			// Tool message should be filtered out
			expect(filtered.find(m => m.role === 'tool')).toBeUndefined();
		});
	});

	describe('Edge cases', () => {
		it('should preserve assistant messages without tool_calls', () => {
			const conversation = [
				{ role: 'user', content: 'Hello' },
				{ role: 'assistant', content: 'Hi! How can I help you today?' }
			];

			const filtered = filterMessagesForApi(conversation);

			expect(filtered).toHaveLength(2);
			expect(filtered[1].role).toBe('assistant');
			expect(filtered[1].content).toBe('Hi! How can I help you today?');
		});

		it('should handle empty tool_calls array', () => {
			const conversation = [
				{ role: 'user', content: 'Hello' },
				{
					role: 'assistant',
					content: 'Hi there!',
					tool_calls: [] // Empty array should NOT be filtered
				}
			];

			const filtered = filterMessagesForApi(conversation);

			expect(filtered).toHaveLength(2);
			expect(filtered[1].role).toBe('assistant');
			expect(filtered[1].content).toBe('Hi there!');
		});

		it('should handle null message in conversation', () => {
			const conversation = [
				{ role: 'user', content: 'Hello' },
				null,
				{ role: 'assistant', content: 'Hi!' }
			];

			const filtered = filterMessagesForApi(conversation);

			expect(filtered).toHaveLength(2);
			expect(filtered[0].role).toBe('user');
			expect(filtered[1].role).toBe('assistant');
		});

		it('should handle empty conversation', () => {
			const conversation = [];
			const filtered = filterMessagesForApi(conversation);

			expect(filtered).toHaveLength(0);
		});
	});

	describe('Multi-iteration agentic workflow', () => {
		it('should filter all intermediate messages from multi-tool workflow', () => {
			// Simulates a conversation where the AI calls multiple tools in sequence
			const conversation = [
				{ role: 'user', content: 'What is the weather and time in New York?' },
				// First tool call
				{
					role: 'assistant',
					content: 'Let me check the time first.',
					tool_calls: [
						{
							id: 'call_time_001',
							type: 'function',
							function: {
								name: 'get_current_time',
								arguments: '{"timezone": "America/New_York"}'
							}
						}
					]
				},
				{
					role: 'tool',
					tool_call_id: 'call_time_001',
					name: 'get_current_time',
					content: '{"time": "10:30 AM EDT"}'
				},
				// Second tool call
				{
					role: 'assistant',
					content: 'Now let me check the weather.',
					tool_calls: [
						{
							id: 'call_weather_001',
							type: 'function',
							function: {
								name: 'get_weather',
								arguments: '{"location": "New York"}'
							}
						}
					]
				},
				{
					role: 'tool',
					tool_call_id: 'call_weather_001',
					name: 'get_weather',
					content: '{"temperature": "72F", "condition": "cloudy"}'
				},
				// Final response
				{
					role: 'assistant',
					content: 'In New York, the current time is 10:30 AM EDT and the weather is 72°F and cloudy.'
				}
			];

			const filtered = filterMessagesForApi(conversation);

			expect(filtered).toHaveLength(2);
			expect(filtered[0].role).toBe('user');
			expect(filtered[0].content).toBe('What is the weather and time in New York?');
			expect(filtered[1].role).toBe('assistant');
			expect(filtered[1].content).toContain('10:30 AM EDT');
			expect(filtered[1].content).toContain('72°F');

			// All intermediate messages should be filtered
			expect(filtered.filter(m => m.role === 'tool')).toHaveLength(0);
			expect(filtered.filter(m => m.tool_calls && m.tool_calls.length > 0)).toHaveLength(0);
		});

		it('should preserve multiple user-assistant exchanges without tool calls', () => {
			const conversation = [
				{ role: 'user', content: 'Hello' },
				{ role: 'assistant', content: 'Hi! How can I help?' },
				{ role: 'user', content: 'Tell me a joke' },
				{ role: 'assistant', content: 'Why did the chicken cross the road?' },
				{ role: 'user', content: 'I don\'t know, why?' },
				{ role: 'assistant', content: 'To get to the other side!' }
			];

			const filtered = filterMessagesForApi(conversation);

			expect(filtered).toHaveLength(6);
			expect(filtered.filter(m => m.role === 'user')).toHaveLength(3);
			expect(filtered.filter(m => m.role === 'assistant')).toHaveLength(3);
		});
	});

	describe('Conversation persistence verification', () => {
		it('should not modify the original conversation array', () => {
			const conversation = [
				{ role: 'user', content: 'Hello' },
				{
					role: 'assistant',
					content: 'Let me check something.',
					tool_calls: [{ id: 'call_1', type: 'function', function: { name: 'test', arguments: '{}' } }]
				},
				{ role: 'tool', tool_call_id: 'call_1', name: 'test', content: '{"result": "ok"}' },
				{ role: 'assistant', content: 'Done!' }
			];

			const originalLength = conversation.length;
			const originalMessages = [...conversation];

			// Filter for API
			filterMessagesForApi(conversation);

			// Original conversation should be unchanged (for localStorage persistence)
			expect(conversation).toHaveLength(originalLength);
			expect(conversation[1].tool_calls).toBeDefined();
			expect(conversation[2].role).toBe('tool');
			
			// Verify each message is still intact
			conversation.forEach((msg, idx) => {
				expect(msg).toEqual(originalMessages[idx]);
			});
		});
	});

	describe('Tool result restoration from localStorage', () => {
		let buildToolPayloadFromMessage;

		beforeEach(() => {
			/**
			 * Mock of the tool result restoration logic from restoreConversationFromStorage.
			 * This handles tool messages that may have:
			 * - display metadata (preferred for UI)
			 * - JSON content string (needs parsing)
			 */
			buildToolPayloadFromMessage = function(message) {
				const display = message.display || null;
				const content = message.content;

				if (display && typeof display === 'object') {
					return display;
				}
				
				if (typeof content === 'string' && content.trim()) {
					let parsedContent = content;
					try {
						parsedContent = JSON.parse(content);
					} catch (e) {
						// Not JSON, use as-is
					}
					
					let displayText = '';
					if (typeof parsedContent === 'object' && parsedContent !== null) {
						displayText = parsedContent.text || 
									 parsedContent.message || 
									 parsedContent.result || 
									 parsedContent.summary ||
									 (typeof parsedContent.content === 'string' ? parsedContent.content : '');
						
						if (!displayText && Object.keys(parsedContent).length > 0) {
							displayText = JSON.stringify(parsedContent, null, 2);
						}
					} else {
						displayText = String(parsedContent);
					}
					
					return { text: displayText };
				}
				
				return { text: '[Tool result]' };
			};
		});

		it('should use display metadata when available', () => {
			const message = {
				role: 'tool',
				content: '{"user_id": 1, "name": "John"}',
				display: { text: '✓ User info retrieved: John (ID: 1)' }
			};

			const payload = buildToolPayloadFromMessage(message);
			expect(payload.text).toBe('✓ User info retrieved: John (ID: 1)');
		});

		it('should parse JSON content when display is not available', () => {
			const message = {
				role: 'tool',
				content: '{"text": "Successfully retrieved user info"}',
				name: 'get_user_info'
			};

			const payload = buildToolPayloadFromMessage(message);
			expect(payload.text).toBe('Successfully retrieved user info');
		});

		it('should handle message field in JSON content', () => {
			const message = {
				role: 'tool',
				content: '{"message": "Operation completed successfully"}',
				name: 'get_user_info'
			};

			const payload = buildToolPayloadFromMessage(message);
			expect(payload.text).toBe('Operation completed successfully');
		});

		it('should handle result field in JSON content', () => {
			const message = {
				role: 'tool',
				content: '{"result": "User found"}',
				name: 'get_user_info'
			};

			const payload = buildToolPayloadFromMessage(message);
			expect(payload.text).toBe('User found');
		});

		it('should stringify complex objects without text fields', () => {
			const message = {
				role: 'tool',
				content: '{"user_id": 1, "email": "john@example.com"}',
				name: 'get_user_info'
			};

			const payload = buildToolPayloadFromMessage(message);
			// Should contain stringified JSON
			expect(payload.text).toContain('user_id');
			expect(payload.text).toContain('john@example.com');
		});

		it('should handle plain string content', () => {
			const message = {
				role: 'tool',
				content: 'Simple string result',
				name: 'test_tool'
			};

			const payload = buildToolPayloadFromMessage(message);
			expect(payload.text).toBe('Simple string result');
		});

		it('should provide fallback for empty content', () => {
			const message = {
				role: 'tool',
				content: '',
				name: 'test_tool'
			};

			const payload = buildToolPayloadFromMessage(message);
			expect(payload.text).toBe('[Tool result]');
		});

		it('should handle get_user_info style results', () => {
			const message = {
				role: 'tool',
				content: JSON.stringify({
					success: true,
					user: {
						id: 42,
						name: 'Test User',
						email: 'test@example.com'
					},
					text: 'Retrieved user: Test User (test@example.com)'
				}),
				name: 'get_user_info',
				tool_call_id: 'call_123'
			};

			const payload = buildToolPayloadFromMessage(message);
			expect(payload.text).toBe('Retrieved user: Test User (test@example.com)');
		});
	});
});
