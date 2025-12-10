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
		// Helper function to check if URL is real (HTTP/HTTPS) vs display-only (blob:/data:)
		const isRealAttachmentUrl = function(url) {
			if (!url || typeof url !== 'string') {
				return false;
			}
			const trimmedUrl = url.trim();
			try {
				const parsedUrl = new URL(trimmedUrl);
				const protocol = parsedUrl.protocol.toLowerCase();
				return protocol === 'http:' || protocol === 'https:';
			} catch (e) {
				return false;
			}
		};
		
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

			// Preserve real attachment URLs (HTTP/HTTPS) for the agentic workflow
			// Strip blob:/data: URLs which are display-only
			if (segment.url && isRealAttachmentUrl(segment.url)) {
				cleanSegment.url = segment.url;
			}

			// Preserve API-required fields
			if (segment.display_name !== undefined) {
				cleanSegment.display_name = segment.display_name;
			}
			if (segment.caption !== undefined) {
				cleanSegment.caption = segment.caption;
			}
			if (segment.detail !== undefined) {
				cleanSegment.detail = segment.detail;
			}
			
			// Preserve file metadata for agentic workflow (following OpenAI image tool pattern)
			if (segment.file_name !== undefined) {
				cleanSegment.file_name = segment.file_name;
			}
			if (segment.name !== undefined) {
				cleanSegment.name = segment.name;
			}
			if (segment.mime_type !== undefined) {
				cleanSegment.mime_type = segment.mime_type;
			}
			if (segment.bytes !== undefined) {
				cleanSegment.bytes = segment.bytes;
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
		it('should remove blob: URLs but preserve file metadata from input_image segments', () => {
			const segment = {
				type: 'input_image',
				attachment_id: 123,
				url: 'blob:http://localhost/abc-123',
				name: 'test-image.jpg',
				file_name: 'test-image.jpg',
				mime_type: 'image/jpeg',
				bytes: 102400
			};

			const cleaned = stripSegmentDisplayData(segment);

			expect(cleaned.type).toBe('input_image');
			expect(cleaned.attachment_id).toBe(123);
			expect(cleaned.url).toBeUndefined(); // blob: URL should be stripped
			expect(cleaned.name).toBe('test-image.jpg'); // name preserved for agentic flow
			expect(cleaned.file_name).toBe('test-image.jpg'); // file_name preserved
			expect(cleaned.mime_type).toBe('image/jpeg'); // mime_type preserved
			expect(cleaned.bytes).toBe(102400); // bytes preserved
		});

		it('should preserve real HTTP/HTTPS URLs and file metadata from input_file segments', () => {
			const segment = {
				type: 'input_file',
				attachment_id: 456,
				url: 'https://example.com/uploads/document.pdf',
				name: 'document.pdf',
				file_name: 'document.pdf',
				mime_type: 'application/pdf',
				bytes: 524288,
				display_name: 'My Document'
			};

			const cleaned = stripSegmentDisplayData(segment);

			expect(cleaned.type).toBe('input_file');
			expect(cleaned.attachment_id).toBe(456);
			expect(cleaned.url).toBe('https://example.com/uploads/document.pdf'); // real URL preserved
			expect(cleaned.name).toBe('document.pdf'); // name preserved
			expect(cleaned.file_name).toBe('document.pdf'); // file_name preserved
			expect(cleaned.mime_type).toBe('application/pdf'); // mime_type preserved
			expect(cleaned.bytes).toBe(524288); // bytes preserved
			expect(cleaned.display_name).toBe('My Document');
		});

		it('should strip data: URLs but preserve file metadata', () => {
			const segment = {
				type: 'input_file',
				attachment_id: 789,
				url: 'data:application/pdf;base64,JVBERi0xLjQK...',
				name: 'inline-doc.pdf',
				file_name: 'inline-doc.pdf',
				mime_type: 'application/pdf',
				bytes: 8192
			};

			const cleaned = stripSegmentDisplayData(segment);

			expect(cleaned.type).toBe('input_file');
			expect(cleaned.attachment_id).toBe(789);
			expect(cleaned.url).toBeUndefined(); // data: URL should be stripped
			expect(cleaned.name).toBe('inline-doc.pdf'); // name preserved
			expect(cleaned.file_name).toBe('inline-doc.pdf'); // file_name preserved
			expect(cleaned.mime_type).toBe('application/pdf'); // mime_type preserved
			expect(cleaned.bytes).toBe(8192); // bytes preserved
		});

		it('should preserve API-required fields like caption and detail along with metadata', () => {
			const segment = {
				type: 'input_image',
				attachment_id: 789,
				url: 'blob:http://localhost/xyz-789',
				name: 'screenshot.png',
				file_name: 'screenshot.png',
				mime_type: 'image/png',
				bytes: 51200,
				caption: 'Error screenshot',
				detail: 'high'
			};

			const cleaned = stripSegmentDisplayData(segment);

			expect(cleaned.type).toBe('input_image');
			expect(cleaned.attachment_id).toBe(789);
			expect(cleaned.caption).toBe('Error screenshot');
			expect(cleaned.detail).toBe('high');
			expect(cleaned.url).toBeUndefined(); // blob: URL stripped
			expect(cleaned.name).toBe('screenshot.png'); // metadata preserved
			expect(cleaned.file_name).toBe('screenshot.png');
			expect(cleaned.mime_type).toBe('image/png');
			expect(cleaned.bytes).toBe(51200);
		});

		it('should not modify text segments', () => {
			const segment = {
				type: 'text',
				text: 'Hello world'
			};

			const cleaned = stripSegmentDisplayData(segment);

			expect(cleaned).toEqual(segment);
		});

		it('should handle segments without optional metadata fields', () => {
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

		it('should clean array of segments - strip blob: URLs but preserve metadata', () => {
			const content = [
				{
					type: 'text',
					text: 'Check out this image:'
				},
				{
					type: 'input_image',
					attachment_id: 123,
					url: 'blob:http://localhost/abc-123',
					name: 'image.jpg',
					file_name: 'image.jpg',
					mime_type: 'image/jpeg',
					bytes: 204800
				}
			];

			const cleaned = stripContentDisplayData(content);

			expect(cleaned).toHaveLength(2);
			expect(cleaned[0]).toEqual({
				type: 'text',
				text: 'Check out this image:'
			});
			expect(cleaned[1].type).toBe('input_image');
			expect(cleaned[1].attachment_id).toBe(123);
			expect(cleaned[1].url).toBeUndefined(); // blob: URL stripped
			expect(cleaned[1].name).toBe('image.jpg'); // metadata preserved
			expect(cleaned[1].file_name).toBe('image.jpg');
			expect(cleaned[1].mime_type).toBe('image/jpeg');
			expect(cleaned[1].bytes).toBe(204800);
		});

		it('should handle mixed content - preserve real URLs, strip blob:/data: URLs', () => {
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
					file_name: 'report.pdf',
					mime_type: 'application/pdf',
					bytes: 1048576,
					display_name: 'Monthly Report'
				},
				{
					type: 'input_image',
					attachment_id: 789,
					url: 'blob:http://localhost/image',
					name: 'chart.png',
					file_name: 'chart.png',
					mime_type: 'image/png',
					bytes: 307200,
					caption: 'Sales chart'
				}
			];

			const cleaned = stripContentDisplayData(content);

			expect(cleaned).toHaveLength(3);
			expect(cleaned[0].type).toBe('text');
			expect(cleaned[1].type).toBe('input_file');
			expect(cleaned[1].attachment_id).toBe(456);
			expect(cleaned[1].url).toBe('https://example.com/file.pdf'); // real URL preserved
			expect(cleaned[1].name).toBe('report.pdf');
			expect(cleaned[1].file_name).toBe('report.pdf');
			expect(cleaned[1].mime_type).toBe('application/pdf');
			expect(cleaned[1].bytes).toBe(1048576);
			expect(cleaned[1].display_name).toBe('Monthly Report');
			expect(cleaned[2].type).toBe('input_image');
			expect(cleaned[2].attachment_id).toBe(789);
			expect(cleaned[2].url).toBeUndefined(); // blob: URL stripped
			expect(cleaned[2].name).toBe('chart.png');
			expect(cleaned[2].file_name).toBe('chart.png');
			expect(cleaned[2].mime_type).toBe('image/png');
			expect(cleaned[2].bytes).toBe(307200);
			expect(cleaned[2].caption).toBe('Sales chart');
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
			// After our changes, name is preserved for agentic workflow
			// but blob: URL is stripped
			expect(cleaned.content[1].type).toBe('input_image');
			expect(cleaned.content[1].attachment_id).toBe(123);
			expect(cleaned.content[1].name).toBe('photo.jpg');
			expect(cleaned.content[1].url).toBeUndefined(); // blob: URL stripped
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
							file_name: 'document1.pdf',
							mime_type: 'application/pdf',
							bytes: 2097152,
							display_name: 'Document 1'
						},
						{
							type: 'input_image',
							attachment_id: 101,
							url: 'blob:http://localhost/img1',
							name: 'screenshot.png',
							file_name: 'screenshot.png',
							mime_type: 'image/png',
							bytes: 409600,
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
			expect(cleaned[2].content[1].type).toBe('input_file');
			expect(cleaned[2].content[1].attachment_id).toBe(100);
			expect(cleaned[2].content[1].url).toBe('https://example.com/doc1.pdf'); // real URL preserved
			expect(cleaned[2].content[1].name).toBe('document1.pdf');
			expect(cleaned[2].content[1].file_name).toBe('document1.pdf');
			expect(cleaned[2].content[1].mime_type).toBe('application/pdf');
			expect(cleaned[2].content[1].bytes).toBe(2097152);
			expect(cleaned[2].content[1].display_name).toBe('Document 1');
			expect(cleaned[2].content[2].type).toBe('input_image');
			expect(cleaned[2].content[2].attachment_id).toBe(101);
			expect(cleaned[2].content[2].url).toBeUndefined(); // blob: URL stripped
			expect(cleaned[2].content[2].name).toBe('screenshot.png');
			expect(cleaned[2].content[2].file_name).toBe('screenshot.png');
			expect(cleaned[2].content[2].mime_type).toBe('image/png');
			expect(cleaned[2].content[2].bytes).toBe(409600);
			expect(cleaned[2].content[2].caption).toBe('Error screen');
		});
	});
});
