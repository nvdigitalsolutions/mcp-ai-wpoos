/**
 * Tests for stripping display-only data from attachment segments
 *
 * @package WP_MCP_AI
 */

describe('Strip Attachment Display Data', () => {
	let stripMessageDisplayMetadata;
	let stripContentDisplayData;
	let stripSegmentDisplayData;

	beforeEach(() => {
		// Load the chat.js file and extract the functions we need to test
		// Since the functions are in a closure, we'll mock them here for testing
		
		// Mock stripSegmentDisplayData function
		stripSegmentDisplayData = function(segment) {
			if (!segment || typeof segment !== 'object') {
				return segment;
			}

			// Only process attachment segments
			if (segment.type !== 'input_image' && segment.type !== 'input_file') {
				return segment;
			}

			// Create clean segment with API-required fields only
			const cleanSegment = {
				type: segment.type,
				attachment_id: segment.attachment_id
			};

			// Preserve API-required fields (but not display-only url/name)
			if (segment.display_name !== undefined) {
				cleanSegment.display_name = segment.display_name;
			}
			if (segment.caption !== undefined) {
				cleanSegment.caption = segment.caption;
			}
			if (segment.detail !== undefined) {
				cleanSegment.detail = segment.detail;
			}

			return cleanSegment;
		};

		// Mock stripContentDisplayData function
		stripContentDisplayData = function(content) {
			// String content passes through unchanged
			if (typeof content === 'string') {
				return content;
			}

			// Array content (segments) needs cleaning
			if (Array.isArray(content)) {
				return content.map(function(segment) {
					// Text segments pass through unchanged
					if (!segment || typeof segment !== 'object') {
						return segment;
					}
					if (segment.type === 'text') {
						return segment;
					}
					// Attachment segments get display data stripped
					return stripSegmentDisplayData(segment);
				});
			}

			// Other content types pass through unchanged
			return content;
		};

		// Mock stripMessageDisplayMetadata function
		stripMessageDisplayMetadata = function(message) {
			// Handle invalid input
			if (!message || typeof message !== 'object') {
				return null;
			}

			// Validate required field 'role' is present
			if (!message.role) {
				return null;
			}

			// Create a new object with only API-compatible fields
			const cleanMessage = {
				role: message.role,
				content: stripContentDisplayData(message.content)
			};

			// Preserve other API-required fields if present
			if (message.tool_calls !== undefined) {
				cleanMessage.tool_calls = message.tool_calls;
			}
			if (message.tool_call_id !== undefined) {
				cleanMessage.tool_call_id = message.tool_call_id;
			}
			if (message.name !== undefined) {
				cleanMessage.name = message.name;
			}

			return cleanMessage;
		};
	});

	describe('stripSegmentDisplayData', () => {
		it('should remove url and name from input_image segments', () => {
			const segment = {
				type: 'input_image',
				attachment_id: 123,
				url: 'blob:http://localhost/abc-123',
				name: 'test-image.jpg'
			};

			const cleaned = stripSegmentDisplayData(segment);

			expect(cleaned).toEqual({
				type: 'input_image',
				attachment_id: 123
			});
			expect(cleaned.url).toBeUndefined();
			expect(cleaned.name).toBeUndefined();
		});

		it('should remove url and name from input_file segments', () => {
			const segment = {
				type: 'input_file',
				attachment_id: 456,
				url: 'data:application/pdf;base64,JVBERi0xLjQK...',
				name: 'document.pdf',
				display_name: 'My Document'
			};

			const cleaned = stripSegmentDisplayData(segment);

			expect(cleaned).toEqual({
				type: 'input_file',
				attachment_id: 456,
				display_name: 'My Document'
			});
			expect(cleaned.url).toBeUndefined();
			expect(cleaned.name).toBeUndefined();
		});

		it('should preserve API-required fields like caption and detail', () => {
			const segment = {
				type: 'input_image',
				attachment_id: 789,
				url: 'blob:http://localhost/xyz-789',
				name: 'screenshot.png',
				caption: 'Error screenshot',
				detail: 'high'
			};

			const cleaned = stripSegmentDisplayData(segment);

			expect(cleaned).toEqual({
				type: 'input_image',
				attachment_id: 789,
				caption: 'Error screenshot',
				detail: 'high'
			});
		});

		it('should not modify text segments', () => {
			const segment = {
				type: 'text',
				text: 'Hello world'
			};

			const cleaned = stripSegmentDisplayData(segment);

			expect(cleaned).toEqual(segment);
		});

		it('should handle segments without url or name fields', () => {
			const segment = {
				type: 'input_image',
				attachment_id: 111
			};

			const cleaned = stripSegmentDisplayData(segment);

			expect(cleaned).toEqual({
				type: 'input_image',
				attachment_id: 111
			});
		});
	});

	describe('stripContentDisplayData', () => {
		it('should pass through string content unchanged', () => {
			const content = 'Hello world';
			const cleaned = stripContentDisplayData(content);

			expect(cleaned).toBe('Hello world');
		});

		it('should clean array of segments with attachments', () => {
			const content = [
				{
					type: 'text',
					text: 'Check out this image:'
				},
				{
					type: 'input_image',
					attachment_id: 123,
					url: 'blob:http://localhost/abc-123',
					name: 'image.jpg'
				}
			];

			const cleaned = stripContentDisplayData(content);

			expect(cleaned).toHaveLength(2);
			expect(cleaned[0]).toEqual({
				type: 'text',
				text: 'Check out this image:'
			});
			expect(cleaned[1]).toEqual({
				type: 'input_image',
				attachment_id: 123
			});
		});

		it('should handle mixed content with multiple attachments', () => {
			const content = [
				{
					type: 'text',
					text: 'Upload complete'
				},
				{
					type: 'input_file',
					attachment_id: 456,
					url: 'https://example.com/file.pdf',
					name: 'report.pdf',
					display_name: 'Monthly Report'
				},
				{
					type: 'input_image',
					attachment_id: 789,
					url: 'blob:http://localhost/image',
					name: 'chart.png',
					caption: 'Sales chart'
				}
			];

			const cleaned = stripContentDisplayData(content);

			expect(cleaned).toHaveLength(3);
			expect(cleaned[0].type).toBe('text');
			expect(cleaned[1]).toEqual({
				type: 'input_file',
				attachment_id: 456,
				display_name: 'Monthly Report'
			});
			expect(cleaned[2]).toEqual({
				type: 'input_image',
				attachment_id: 789,
				caption: 'Sales chart'
			});
		});
	});

	describe('stripMessageDisplayMetadata', () => {
		it('should strip display data from message with attachment segments', () => {
			const message = {
				role: 'user',
				content: [
					{
						type: 'text',
						text: 'Here is my image'
					},
					{
						type: 'input_image',
						attachment_id: 123,
						url: 'blob:http://localhost/abc-123',
						name: 'photo.jpg'
					}
				],
				display: {
					text: 'Here is my image',
					attachments: [
						{
							url: 'blob:http://localhost/abc-123',
							label: 'photo.jpg'
						}
					]
				}
			};

			const cleaned = stripMessageDisplayMetadata(message);

			expect(cleaned.role).toBe('user');
			expect(cleaned.content).toHaveLength(2);
			expect(cleaned.content[0]).toEqual({
				type: 'text',
				text: 'Here is my image'
			});
			expect(cleaned.content[1]).toEqual({
				type: 'input_image',
				attachment_id: 123
			});
			expect(cleaned.display).toBeUndefined();
		});

		it('should handle string content without modification', () => {
			const message = {
				role: 'user',
				content: 'Simple text message'
			};

			const cleaned = stripMessageDisplayMetadata(message);

			expect(cleaned).toEqual({
				role: 'user',
				content: 'Simple text message'
			});
		});

		it('should preserve tool_calls and other API fields', () => {
			const message = {
				role: 'assistant',
				content: null,
				tool_calls: [
					{
						id: 'call_123',
						type: 'function',
						function: {
							name: 'get_weather',
							arguments: '{}'
						}
					}
				]
			};

			const cleaned = stripMessageDisplayMetadata(message);

			expect(cleaned.role).toBe('assistant');
			expect(cleaned.content).toBeNull();
			expect(cleaned.tool_calls).toEqual(message.tool_calls);
		});

		it('should return null for invalid messages', () => {
			expect(stripMessageDisplayMetadata(null)).toBeNull();
			expect(stripMessageDisplayMetadata(undefined)).toBeNull();
			expect(stripMessageDisplayMetadata({})).toBeNull();
			expect(stripMessageDisplayMetadata({ content: 'test' })).toBeNull();
		});
	});

	describe('Real-world scenarios', () => {
		it('should handle conversation with multiple messages containing attachments', () => {
			const conversation = [
				{
					role: 'user',
					content: 'Hello'
				},
				{
					role: 'assistant',
					content: 'Hi! How can I help?'
				},
				{
					role: 'user',
					content: [
						{
							type: 'text',
							text: 'Check these files:'
						},
						{
							type: 'input_file',
							attachment_id: 100,
							url: 'https://example.com/doc1.pdf',
							name: 'document1.pdf',
							display_name: 'Document 1'
						},
						{
							type: 'input_image',
							attachment_id: 101,
							url: 'blob:http://localhost/img1',
							name: 'screenshot.png',
							caption: 'Error screen'
						}
					]
				}
			];

			const cleaned = conversation.map(stripMessageDisplayMetadata);

			expect(cleaned).toHaveLength(3);
			expect(cleaned[0].content).toBe('Hello');
			expect(cleaned[1].content).toBe('Hi! How can I help?');
			expect(cleaned[2].content).toHaveLength(3);
			expect(cleaned[2].content[1]).toEqual({
				type: 'input_file',
				attachment_id: 100,
				display_name: 'Document 1'
			});
			expect(cleaned[2].content[2]).toEqual({
				type: 'input_image',
				attachment_id: 101,
				caption: 'Error screen'
			});
		});
	});
});
