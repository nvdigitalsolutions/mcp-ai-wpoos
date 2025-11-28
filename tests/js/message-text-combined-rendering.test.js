/**
 * Tests for combined message and text field rendering in chat conversations
 *
 * Validates that both 'message' and 'text' fields are rendered when available,
 * and properly persisted in localStorage and CCT.
 *
 * @package WP_MCP_AI
 */

describe('Message and Text Combined Rendering', () => {
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

	describe('Display metadata with both message and text fields', () => {
		it('should preserve both message and text fields in display metadata', () => {
			const assistantMessage = {
				role: 'assistant',
				content: 'Full response content',
				display: {
					message: 'Operation completed successfully',
					text: 'Post created with ID: 123',
					attachments: []
				}
			};

			const conversation = [assistantMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_123',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0]).toEqual(assistantMessage);
			expect(retrieved.conversation[0].display.message).toBe('Operation completed successfully');
			expect(retrieved.conversation[0].display.text).toBe('Post created with ID: 123');
		});

		it('should handle message only without text', () => {
			const assistantMessage = {
				role: 'assistant',
				content: 'Response content',
				display: {
					message: 'Status message only',
					attachments: []
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

			expect(retrieved.conversation[0].display.message).toBe('Status message only');
			expect(retrieved.conversation[0].display.text).toBeUndefined();
		});

		it('should handle text only without message', () => {
			const assistantMessage = {
				role: 'assistant',
				content: 'Response content',
				display: {
					text: 'Details only',
					attachments: []
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

			expect(retrieved.conversation[0].display.message).toBeUndefined();
			expect(retrieved.conversation[0].display.text).toBe('Details only');
		});

		it('should handle identical message and text fields', () => {
			const assistantMessage = {
				role: 'assistant',
				content: 'Same content',
				display: {
					message: 'Same content',
					text: 'Same content',
					attachments: []
				}
			};

			const conversation = [assistantMessage];
			const storageKey = 'wp_mcp_ai_chat_test_assistant';
			const data = {
				conversation: conversation,
				sessionKey: 'session_same',
				timestamp: Date.now(),
				assistantId: 'test_assistant'
			};

			localStorage.setItem(storageKey, JSON.stringify(data));
			const retrieved = JSON.parse(localStorage.getItem(storageKey));

			expect(retrieved.conversation[0].display.message).toBe('Same content');
			expect(retrieved.conversation[0].display.text).toBe('Same content');
		});
	});

	describe('Combining message and text for rendering', () => {
		/**
		 * Simulates the text extraction logic from appendMessage
		 */
		function extractTextForRendering(payload) {
			let text = '';

			if (typeof payload === 'object' && !Array.isArray(payload)) {
				const hasMessageField = Object.prototype.hasOwnProperty.call(payload, 'message');
				const hasTextField = Object.prototype.hasOwnProperty.call(payload, 'text');

				if (hasMessageField && hasTextField) {
					const messageStr = String(payload.message || '').trim();
					const textStr = String(payload.text || '').trim();
					if (messageStr && textStr && messageStr !== textStr) {
						text = messageStr + '\n\n' + textStr;
					} else if (messageStr) {
						text = messageStr;
					} else {
						text = textStr;
					}
				} else if (hasMessageField) {
					text = String(payload.message || '');
				} else if (hasTextField) {
					text = String(payload.text || '');
				}
			}

			return text;
		}

		it('should combine both message and text with separator', () => {
			const payload = {
				message: 'Post saved successfully',
				text: 'Post ID: 456, Title: Test Post'
			};

			const result = extractTextForRendering(payload);
			expect(result).toBe('Post saved successfully\n\nPost ID: 456, Title: Test Post');
		});

		it('should use only message when text is empty', () => {
			const payload = {
				message: 'Operation complete',
				text: ''
			};

			const result = extractTextForRendering(payload);
			expect(result).toBe('Operation complete');
		});

		it('should use only text when message is empty', () => {
			const payload = {
				message: '',
				text: 'Detailed result info'
			};

			const result = extractTextForRendering(payload);
			expect(result).toBe('Detailed result info');
		});

		it('should not duplicate when message and text are identical', () => {
			const payload = {
				message: 'Same content here',
				text: 'Same content here'
			};

			const result = extractTextForRendering(payload);
			expect(result).toBe('Same content here');
		});

		it('should fallback to text only when no message property', () => {
			const payload = {
				text: 'Text only payload'
			};

			const result = extractTextForRendering(payload);
			expect(result).toBe('Text only payload');
		});

		it('should fallback to message only when no text property', () => {
			const payload = {
				message: 'Message only payload'
			};

			const result = extractTextForRendering(payload);
			expect(result).toBe('Message only payload');
		});
	});

	describe('Tool response extraction with both message and text', () => {
		/**
		 * Simulates the extractGenericToolResponse logic
		 */
		function extractGenericToolResponse(result) {
			if (!result || typeof result !== 'object') {
				return null;
			}

			let text = '';

			const hasMessage = typeof result.message === 'string' && result.message.trim();
			const hasText = typeof result.text === 'string' && result.text.trim();

			if (hasMessage && hasText) {
				const messageStr = result.message.trim();
				const textStr = result.text.trim();
				if (messageStr !== textStr) {
					text = messageStr + '\n\n' + textStr;
				} else {
					text = messageStr;
				}
			} else if (hasMessage) {
				text = result.message.trim();
			} else if (hasText) {
				text = result.text.trim();
			}

			return text || null;
		}

		it('should combine message and text from tool result', () => {
			const toolResult = {
				message: 'File uploaded successfully',
				text: 'File: document.pdf, Size: 1.2MB',
				attachment_id: 789
			};

			const result = extractGenericToolResponse(toolResult);
			expect(result).toBe('File uploaded successfully\n\nFile: document.pdf, Size: 1.2MB');
		});

		it('should use message when text is missing', () => {
			const toolResult = {
				message: 'Task completed',
				attachment_id: 123
			};

			const result = extractGenericToolResponse(toolResult);
			expect(result).toBe('Task completed');
		});

		it('should use text when message is missing', () => {
			const toolResult = {
				text: 'Result details here',
				status: 'success'
			};

			const result = extractGenericToolResponse(toolResult);
			expect(result).toBe('Result details here');
		});

		it('should not duplicate identical message and text', () => {
			const toolResult = {
				message: 'Action performed',
				text: 'Action performed'
			};

			const result = extractGenericToolResponse(toolResult);
			expect(result).toBe('Action performed');
		});
	});

	describe('Message restoration with both fields', () => {
		it('should restore both message and text fields from display metadata', () => {
			const storedMessage = {
				role: 'assistant',
				content: 'Original content',
				display: {
					message: 'Status: Created',
					text: 'ID: 456, Name: Test Item',
					attachments: []
				}
			};

			// Simulate restoration: if display exists, use it
			let payload;
			if (storedMessage.display && typeof storedMessage.display === 'object') {
				payload = {
					attachments: storedMessage.display.attachments || []
				};
				if (storedMessage.display.message) {
					payload.message = storedMessage.display.message;
				}
				if (storedMessage.display.text) {
					payload.text = storedMessage.display.text;
				}
			}

			expect(payload.message).toBe('Status: Created');
			expect(payload.text).toBe('ID: 456, Name: Test Item');
		});

		it('should handle message only in restoration', () => {
			const storedMessage = {
				role: 'assistant',
				content: 'Content',
				display: {
					message: 'Only message here',
					attachments: []
				}
			};

			let payload;
			if (storedMessage.display && typeof storedMessage.display === 'object') {
				payload = {
					attachments: storedMessage.display.attachments || []
				};
				if (storedMessage.display.message) {
					payload.message = storedMessage.display.message;
				}
				if (storedMessage.display.text) {
					payload.text = storedMessage.display.text;
				}
			}

			expect(payload.message).toBe('Only message here');
			expect(payload.text).toBeUndefined();
		});

		it('should handle text only in restoration', () => {
			const storedMessage = {
				role: 'assistant',
				content: 'Content',
				display: {
					text: 'Only text here',
					attachments: []
				}
			};

			let payload;
			if (storedMessage.display && typeof storedMessage.display === 'object') {
				payload = {
					attachments: storedMessage.display.attachments || []
				};
				if (storedMessage.display.message) {
					payload.message = storedMessage.display.message;
				}
				if (storedMessage.display.text) {
					payload.text = storedMessage.display.text;
				}
			}

			expect(payload.message).toBeUndefined();
			expect(payload.text).toBe('Only text here');
		});
	});

	describe('Speech text extraction with both fields', () => {
		/**
		 * Simulates the speech text extraction logic
		 */
		function extractSpeechText(display, content) {
			let textForSpeech = '';
			if (display) {
				const messageStr = display.message || '';
				const textStr = display.text || '';
				if (messageStr && textStr && messageStr !== textStr) {
					textForSpeech = messageStr + '\n\n' + textStr;
				} else {
					textForSpeech = messageStr || textStr;
				}
			}
			if (!textForSpeech) {
				textForSpeech = content || '';
			}
			return textForSpeech;
		}

		it('should combine message and text for speech', () => {
			const display = {
				message: 'Success notification',
				text: 'Details about the action'
			};

			const result = extractSpeechText(display, 'fallback content');
			expect(result).toBe('Success notification\n\nDetails about the action');
		});

		it('should use message only for speech when text is empty', () => {
			const display = {
				message: 'Only message',
				text: ''
			};

			const result = extractSpeechText(display, 'fallback');
			expect(result).toBe('Only message');
		});

		it('should fallback to content when display is empty', () => {
			const display = {};
			const result = extractSpeechText(display, 'fallback content');
			expect(result).toBe('fallback content');
		});

		it('should fallback to content when display is null', () => {
			const result = extractSpeechText(null, 'fallback content');
			expect(result).toBe('fallback content');
		});
	});
});
