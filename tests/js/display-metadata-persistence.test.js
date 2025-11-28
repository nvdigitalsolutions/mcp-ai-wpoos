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
						bubbleType: 'user',
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
					display: {
						text: 'Let me analyze the data.',
						bubbleType: 'assistant'
					}
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
			expect(retrieved.conversation[0].display.bubbleType).toBe('user');
			expect(retrieved.conversation[1].display).toBeDefined();
			expect(retrieved.conversation[1].display.bubbleType).toBe('assistant');
			expect(retrieved.conversation[2].display.bubbleType).toBe('json');
		});

		it('should preserve standard assistant message with display metadata (industry standard)', () => {
			// Industry standard: All message types should have display metadata
			// for consistent persistence and restoration, similar to JSON bubbles
			const assistantMessage = {
				role: 'assistant',
				content: 'Hello! How can I help you today?',
				display: {
					text: 'Hello! How can I help you today?',
					bubbleType: 'assistant'
				}
			};

			const conversation = [assistantMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_standard',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0]).toEqual(assistantMessage);
			expect(retrieved.conversation[0].display).toBeDefined();
			expect(retrieved.conversation[0].display.text).toBe('Hello! How can I help you today?');
			expect(retrieved.conversation[0].display.bubbleType).toBe('assistant');
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

	describe('Badge persistence', () => {
		it('should preserve usage data in display metadata', () => {
			const assistantMessage = {
				role: 'assistant',
				content: 'Here is the analysis.',
				display: {
					text: 'Here is the analysis.',
					bubbleType: 'assistant',
					usage: {
						prompt_tokens: 100,
						completion_tokens: 50,
						total_tokens: 150
					}
				}
			};

			const conversation = [assistantMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_usage',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0].display).toBeDefined();
			expect(retrieved.conversation[0].display.usage).toBeDefined();
			expect(retrieved.conversation[0].display.usage.prompt_tokens).toBe(100);
			expect(retrieved.conversation[0].display.usage.completion_tokens).toBe(50);
			expect(retrieved.conversation[0].display.usage.total_tokens).toBe(150);
		});

		it('should preserve cost data in display metadata', () => {
			const assistantMessage = {
				role: 'assistant',
				content: 'Here is the analysis.',
				display: {
					text: 'Here is the analysis.',
					bubbleType: 'assistant',
					cost: {
						total: 0.0025,
						currency: 'USD'
					}
				}
			};

			const conversation = [assistantMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_cost',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0].display).toBeDefined();
			expect(retrieved.conversation[0].display.cost).toBeDefined();
			expect(retrieved.conversation[0].display.cost.total).toBe(0.0025);
			expect(retrieved.conversation[0].display.cost.currency).toBe('USD');
		});

		it('should preserve capability flags in display metadata', () => {
			const assistantMessage = {
				role: 'assistant',
				content: 'Here is the analysis.',
				display: {
					text: 'Here is the analysis.',
					bubbleType: 'assistant',
					capabilityFlags: ['vision', 'code_interpreter']
				}
			};

			const conversation = [assistantMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_flags',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0].display).toBeDefined();
			expect(retrieved.conversation[0].display.capabilityFlags).toBeDefined();
			expect(retrieved.conversation[0].display.capabilityFlags).toHaveLength(2);
			expect(retrieved.conversation[0].display.capabilityFlags).toContain('vision');
			expect(retrieved.conversation[0].display.capabilityFlags).toContain('code_interpreter');
		});

		it('should preserve all badge data together', () => {
			const assistantMessage = {
				role: 'assistant',
				content: 'Complete response with all badges.',
				display: {
					text: 'Complete response with all badges.',
					bubbleType: 'assistant',
					usage: {
						prompt_tokens: 200,
						completion_tokens: 100,
						total_tokens: 300
					},
					cost: {
						total: 0.005,
						currency: 'USD'
					},
					capabilityFlags: ['vision']
				}
			};

			const conversation = [assistantMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_all_badges',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0].display.usage.total_tokens).toBe(300);
			expect(retrieved.conversation[0].display.cost.total).toBe(0.005);
			expect(retrieved.conversation[0].display.capabilityFlags).toContain('vision');
		});
	});
});
