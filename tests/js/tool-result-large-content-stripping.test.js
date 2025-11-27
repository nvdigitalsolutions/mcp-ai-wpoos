/**
 * Tests for stripping large file content from tool result messages
 *
 * These tests verify that:
 * 1. Tool result messages are preserved in API/CCT requests
 * 2. Large file content (base64 data, data URLs) is stripped to keep payloads lean
 * 3. Essential fields like attachment_id, url, and metadata are preserved
 *
 * @package WP_MCP_AI
 */

describe('Tool Result Large Content Stripping', () => {
	let stripToolResultLargeContent;
	let stripLargeContentFromObject;

	beforeEach(() => {
		/**
		 * Mock of stripLargeContentFromObject - recursively strips large content
		 */
		stripLargeContentFromObject = function(obj, depth) {
			if (depth === undefined) {
				depth = 0;
			}
			
			if (depth > 10) {
				return obj;
			}

			if (obj === null || obj === undefined) {
				return obj;
			}

			if (Array.isArray(obj)) {
				return obj.map(function(item) {
					return stripLargeContentFromObject(item, depth + 1);
				});
			}

			if (typeof obj !== 'object') {
				if (typeof obj === 'string') {
					if (obj.indexOf('data:') === 0 && obj.length > 1000) {
						return '[data URL stripped]';
					}
					// Check length first to avoid expensive regex on small strings
					if (obj.length > 5000) {
						// Sample first 100 chars to check if it looks like base64
						var sample = obj.substring(0, 100);
						if (/^[A-Za-z0-9+/=]+$/.test(sample)) {
							return '[base64 data stripped]';
						}
					}
				}
				return obj;
			}

			const cleaned = {};
			
			for (var key in obj) {
				if (!Object.prototype.hasOwnProperty.call(obj, key)) {
					continue;
				}

				var value = obj[key];

				if (key === 'data' || key === 'base64' || key === 'data_url' || key === 'raw_data' || key === 'binary') {
					if (typeof value === 'string' && value.length > 1000) {
						cleaned[key] = '[' + key + ' stripped - ' + value.length + ' chars]';
						continue;
					}
				}

				if (key === 'content' && typeof value === 'object' && value !== null) {
					if (value.encoding === 'base64' && value.data) {
						cleaned[key] = {
							encoding: value.encoding,
							mime_type: value.mime_type,
							stripped: true,
							original_size: typeof value.data === 'string' ? value.data.length : 0
						};
						continue;
					}
				}

				cleaned[key] = stripLargeContentFromObject(value, depth + 1);
			}

			return cleaned;
		};

		/**
		 * Mock of stripToolResultLargeContent from chat.js
		 */
		stripToolResultLargeContent = function(content) {
			if (typeof content !== 'string' || !content.trim()) {
				return content;
			}

			let parsed;
			try {
				parsed = JSON.parse(content);
			} catch (e) {
				return content;
			}

			if (!parsed || typeof parsed !== 'object') {
				return content;
			}

			const cleaned = stripLargeContentFromObject(parsed);

			try {
				return JSON.stringify(cleaned);
			} catch (e) {
				return content;
			}
		};
	});

	describe('stripToolResultLargeContent', () => {
		it('should preserve essential fields like attachment_id and url', () => {
			const content = JSON.stringify({
				attachment_id: 123,
				url: 'https://example.com/image.png',
				download_url: 'https://example.com/download/image.png',
				file_name: 'image.png',
				mime_type: 'image/png',
				text: 'Image generated successfully'
			});

			const cleaned = stripToolResultLargeContent(content);
			const parsed = JSON.parse(cleaned);

			expect(parsed.attachment_id).toBe(123);
			expect(parsed.url).toBe('https://example.com/image.png');
			expect(parsed.download_url).toBe('https://example.com/download/image.png');
			expect(parsed.file_name).toBe('image.png');
			expect(parsed.mime_type).toBe('image/png');
			expect(parsed.text).toBe('Image generated successfully');
		});

		it('should strip large base64 data field', () => {
			const largeBase64 = 'A'.repeat(10000);
			const content = JSON.stringify({
				attachment_id: 456,
				url: 'https://example.com/file.pdf',
				data: largeBase64
			});

			const cleaned = stripToolResultLargeContent(content);
			const parsed = JSON.parse(cleaned);

			expect(parsed.attachment_id).toBe(456);
			expect(parsed.url).toBe('https://example.com/file.pdf');
			expect(parsed.data).toContain('stripped');
			expect(parsed.data).toContain('10000');
		});

		it('should strip data_url field with large content', () => {
			const largeDataUrl = 'data:image/png;base64,' + 'A'.repeat(5000);
			const content = JSON.stringify({
				attachment_id: 789,
				url: 'https://example.com/image.png',
				data_url: largeDataUrl
			});

			const cleaned = stripToolResultLargeContent(content);
			const parsed = JSON.parse(cleaned);

			expect(parsed.attachment_id).toBe(789);
			expect(parsed.url).toBe('https://example.com/image.png');
			expect(parsed.data_url).toContain('stripped');
		});

		it('should handle nested content object with base64 encoding', () => {
			const content = JSON.stringify({
				attachment_id: 101,
				url: 'https://example.com/image.png',
				content: {
					encoding: 'base64',
					data: 'A'.repeat(10000),
					mime_type: 'image/png'
				}
			});

			const cleaned = stripToolResultLargeContent(content);
			const parsed = JSON.parse(cleaned);

			expect(parsed.attachment_id).toBe(101);
			expect(parsed.url).toBe('https://example.com/image.png');
			expect(parsed.content.encoding).toBe('base64');
			expect(parsed.content.mime_type).toBe('image/png');
			expect(parsed.content.stripped).toBe(true);
			expect(parsed.content.original_size).toBe(10000);
			expect(parsed.content.data).toBeUndefined();
		});

		it('should pass through small content unchanged', () => {
			const content = JSON.stringify({
				success: true,
				message: 'Operation completed',
				user_id: 42
			});

			const cleaned = stripToolResultLargeContent(content);
			const parsed = JSON.parse(cleaned);

			expect(parsed.success).toBe(true);
			expect(parsed.message).toBe('Operation completed');
			expect(parsed.user_id).toBe(42);
		});

		it('should return non-JSON content as-is', () => {
			const content = 'Simple string result';
			const cleaned = stripToolResultLargeContent(content);
			expect(cleaned).toBe('Simple string result');
		});

		it('should handle empty content', () => {
			expect(stripToolResultLargeContent('')).toBe('');
			expect(stripToolResultLargeContent(null)).toBe(null);
			expect(stripToolResultLargeContent(undefined)).toBe(undefined);
		});

		it('should handle arrays in tool results', () => {
			const content = JSON.stringify({
				files: [
					{
						attachment_id: 1,
						url: 'https://example.com/file1.pdf',
						data: 'A'.repeat(5000)
					},
					{
						attachment_id: 2,
						url: 'https://example.com/file2.pdf',
						data: 'B'.repeat(5000)
					}
				]
			});

			const cleaned = stripToolResultLargeContent(content);
			const parsed = JSON.parse(cleaned);

			expect(parsed.files).toHaveLength(2);
			expect(parsed.files[0].attachment_id).toBe(1);
			expect(parsed.files[0].url).toBe('https://example.com/file1.pdf');
			expect(parsed.files[0].data).toContain('stripped');
			expect(parsed.files[1].attachment_id).toBe(2);
			expect(parsed.files[1].url).toBe('https://example.com/file2.pdf');
			expect(parsed.files[1].data).toContain('stripped');
		});
	});

	describe('Real-world tool result scenarios', () => {
		it('should handle generate_gemini_image result', () => {
			const content = JSON.stringify({
				attachment_id: 123,
				url: 'https://example.com/generated-image.png',
				download_url: 'https://example.com/download/generated-image.png',
				file_name: 'generated-image.png',
				mime_type: 'image/png',
				bytes: 50000,
				text: 'Successfully generated image "Test Image" (ID: 123).',
				content: {
					encoding: 'base64',
					data: 'iVBORw0KGgo' + 'A'.repeat(20000),
					mime_type: 'image/png',
					data_url: 'data:image/png;base64,' + 'A'.repeat(20000)
				}
			});

			const cleaned = stripToolResultLargeContent(content);
			const parsed = JSON.parse(cleaned);

			// Essential fields preserved
			expect(parsed.attachment_id).toBe(123);
			expect(parsed.url).toBe('https://example.com/generated-image.png');
			expect(parsed.download_url).toBe('https://example.com/download/generated-image.png');
			expect(parsed.file_name).toBe('generated-image.png');
			expect(parsed.text).toContain('Successfully generated image');

			// Large content stripped
			expect(parsed.content.stripped).toBe(true);
			expect(parsed.content.data).toBeUndefined();
		});

		it('should handle get_user_info result (no large content)', () => {
			const content = JSON.stringify({
				success: true,
				user: {
					id: 42,
					name: 'John Doe',
					email: 'john@example.com',
					roles: ['administrator']
				},
				text: 'Retrieved user: John Doe (john@example.com)'
			});

			const cleaned = stripToolResultLargeContent(content);
			const parsed = JSON.parse(cleaned);

			// All fields should be preserved as-is
			expect(parsed.success).toBe(true);
			expect(parsed.user.id).toBe(42);
			expect(parsed.user.name).toBe('John Doe');
			expect(parsed.user.email).toBe('john@example.com');
			expect(parsed.user.roles).toEqual(['administrator']);
			expect(parsed.text).toBe('Retrieved user: John Doe (john@example.com)');
		});

		it('should handle crawl4ai result with large HTML content', () => {
			const largeHtml = '<html>' + 'content'.repeat(5000) + '</html>';
			const content = JSON.stringify({
				url: 'https://example.com',
				title: 'Example Page',
				raw_data: largeHtml,
				summary: 'Page scraped successfully',
				text: 'Crawled example.com'
			});

			const cleaned = stripToolResultLargeContent(content);
			const parsed = JSON.parse(cleaned);

			expect(parsed.url).toBe('https://example.com');
			expect(parsed.title).toBe('Example Page');
			expect(parsed.summary).toBe('Page scraped successfully');
			expect(parsed.text).toBe('Crawled example.com');
			expect(parsed.raw_data).toContain('stripped');
		});
	});

	describe('Tool result restoration from localStorage', () => {
		let buildToolPayloadFromMessage;

		beforeEach(() => {
			/**
			 * Mock of the tool result restoration logic from restoreConversationFromStorage
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
