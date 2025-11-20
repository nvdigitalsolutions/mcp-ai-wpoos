/**
 * Tests for display metadata persistence in chat conversations
 *
 * @package WP_MCP_AI
 */

describe('Display Metadata Persistence', () => {
	let localStorage;

	beforeEach(() => {
		// Create a mock localStorage
		localStorage = {
			data: {},
			setItem(key, value) {
				this.data[key] = value;
			},
			getItem(key) {
				return this.data[key] || null;
			},
			removeItem(key) {
				delete this.data[key];
			},
			clear() {
				this.data = {};
			}
		};
	});

	describe('Message structure with display metadata', () => {
		it('should preserve user message with attachments', () => {
			const userMessage = {
				role: 'user',
				content: 'Check out this image',
				display: {
					text: 'Check out this image',
					attachments: [
						{
							url: 'https://example.com/image.jpg',
							label: 'Screenshot',
							meta: '1.2 MB'
						}
					]
				}
			};

			const conversation = [userMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_123',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0]).toEqual(userMessage);
			expect(retrieved.conversation[0].display).toBeDefined();
			expect(retrieved.conversation[0].display.attachments).toHaveLength(1);
		});

		it('should preserve assistant message with JSON bubble type', () => {
			const assistantMessage = {
				role: 'assistant',
				content: '{"result": "success", "count": 42}',
				display: {
					text: '{"result": "success", "count": 42}',
					bubbleType: 'json'
				}
			};

			const conversation = [assistantMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_456',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0]).toEqual(assistantMessage);
			expect(retrieved.conversation[0].display.bubbleType).toBe('json');
		});

		it('should preserve assistant message with truncated bubble type', () => {
			const longText = 'Very long response... [... Result truncated by orchestration layer to fit within budget constraints ...]';
			const assistantMessage = {
				role: 'assistant',
				content: longText,
				display: {
					text: longText,
					bubbleType: 'truncated'
				}
			};

			const conversation = [assistantMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_789',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0].display.bubbleType).toBe('truncated');
		});

		it('should preserve assistant message with tool results and attachments', () => {
			const assistantMessage = {
				role: 'assistant',
				content: null,
				tool_calls: [
					{
						id: 'call_abc123',
						function: {
							name: 'generate_image',
							arguments: '{"prompt": "A beautiful sunset"}'
						}
					}
				],
				display: {
					text: 'I generated the image for you.',
					attachments: [
						{
							url: 'https://example.com/generated-image.png',
							label: 'Generated Image',
							meta: '1024x1024'
						}
					]
				}
			};

			const conversation = [assistantMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_xyz',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0].tool_calls).toBeDefined();
			expect(retrieved.conversation[0].display).toBeDefined();
			expect(retrieved.conversation[0].display.attachments).toHaveLength(1);
		});

		it('should handle messages without display metadata (backward compatibility)', () => {
			const simpleMessage = {
				role: 'user',
				content: 'Simple text message'
			};

			const conversation = [simpleMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_simple',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0].role).toBe('user');
			expect(retrieved.conversation[0].content).toBe('Simple text message');
			expect(retrieved.conversation[0].display).toBeUndefined();
		});

		it('should preserve full conversation with mixed message types', () => {
			const conversation = [
				{
					role: 'user',
					content: 'Can you analyze this data?',
					display: {
						text: 'Can you analyze this data?',
						attachments: [
							{
								url: 'https://example.com/data.csv',
								label: 'data.csv',
								meta: '15 KB'
							}
						]
					}
				},
				{
					role: 'assistant',
					content: 'Let me analyze the data.',
				},
				{
					role: 'assistant',
					content: '{"total": 1000, "average": 45.2}',
					display: {
						text: '{"total": 1000, "average": 45.2}',
						bubbleType: 'json'
					}
				}
			];

			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_mixed',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation).toHaveLength(3);
			expect(retrieved.conversation[0].display.attachments).toHaveLength(1);
			expect(retrieved.conversation[1].display).toBeUndefined();
			expect(retrieved.conversation[2].display.bubbleType).toBe('json');
		});
	});

	describe('Message restoration', () => {
		it('should use display metadata when available', () => {
			const storedMessage = {
				role: 'assistant',
				content: '{"status": "ok"}',
				display: {
					text: '{"status": "ok"}',
					bubbleType: 'json'
				}
			};

			// Simulate reload: if display exists, use it
			let payload;
			if (storedMessage.display && typeof storedMessage.display === 'object') {
				payload = storedMessage.display;
			} else {
				payload = { text: storedMessage.content };
			}

			expect(payload.bubbleType).toBe('json');
			expect(payload.text).toBe('{"status": "ok"}');
		});

		it('should fallback to content when no display metadata', () => {
			const storedMessage = {
				role: 'user',
				content: 'Hello world'
			};

			// Simulate reload: if display exists, use it
			let payload;
			if (storedMessage.display && typeof storedMessage.display === 'object') {
				payload = storedMessage.display;
			} else {
				payload = { text: storedMessage.content };
			}

			expect(payload.text).toBe('Hello world');
			expect(payload.bubbleType).toBeUndefined();
		});
	});
});
