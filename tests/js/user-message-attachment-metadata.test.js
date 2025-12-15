/**
 * Test user message attachment metadata display
 * Verifies that attachments in user messages display complete metadata
 * matching the format used by tool results (buildAttachmentMeta)
 *
 * @package WP_MCP_AI
 */

describe('User Message Attachment Metadata Display', () => {
	// Mock formatBytes function
	function formatBytes(bytes) {
		if (bytes === 0) return '0 Bytes';
		const k = 1024;
		const sizes = ['Bytes', 'KB', 'MB', 'GB'];
		const i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
	}

	// Simulate the buildAttachmentMeta function
	function buildAttachmentMeta(record) {
		if (!record) {
			return '';
		}

		const parts = [];
		let size = null;

		if (typeof record.size === 'number') {
			size = record.size;
		} else if (typeof record.bytes === 'number') {
			size = record.bytes;
		}

		if (size && size > 0) {
			parts.push(formatBytes(size));
		}

		const mime = record.mime || record.mime_type || record.type;
		if (mime) {
			parts.push(mime);
		}

		// Include attachment_id if available (matching tool result format)
		const attachmentId = record.id || record.attachment_id;
		if (typeof attachmentId === 'number' || (typeof attachmentId === 'string' && attachmentId)) {
			parts.push('ID: ' + attachmentId);
		}

		return parts.join(' • ');
	}

	// Simulate building display payload from message content (the fix)
	function buildDisplayPayloadFromContent(content) {
		const displayPayload = { text: '', attachments: [] };

		if (typeof content === 'string') {
			displayPayload.text = content;
		} else if (Array.isArray(content)) {
			const textParts = [];
			content.forEach(function (segment) {
				if (segment && segment.type === 'text' && segment.text) {
					textParts.push(segment.text);
				} else if (segment && (segment.type === 'input_image' || segment.type === 'image_url')) {
					// Build attachment link for image
					let imageUrl = segment.url || '';
					if (!imageUrl && segment.image_url) {
						if (typeof segment.image_url === 'string') {
							imageUrl = segment.image_url;
						} else if (segment.image_url.url) {
							imageUrl = segment.image_url.url;
						}
					}

					if (imageUrl) {
						// Build metadata record from segment for display (matching tool result format)
						const metaRecord = {
							bytes: segment.bytes || null,
							mime_type: segment.mime_type || '',
							attachment_id: segment.attachment_id || null,
						};

						displayPayload.attachments.push({
							url: imageUrl,
							label: segment.caption || segment.name || segment.file_name || 'Image attachment',
							downloadName: segment.name || segment.file_name || '',
							meta: buildAttachmentMeta(metaRecord),
						});
					} else {
						textParts.push('[Image attachment]');
					}
				} else if (segment && segment.type === 'input_file') {
					// Build attachment link for file
					if (segment.url) {
						// Build metadata record from segment for display (matching tool result format)
						const metaRecord = {
							bytes: segment.bytes || null,
							mime_type: segment.mime_type || '',
							attachment_id: segment.attachment_id || null,
						};

						displayPayload.attachments.push({
							url: segment.url,
							label: segment.display_name || segment.name || segment.file_name || 'File attachment',
							downloadName: segment.display_name || segment.name || segment.file_name || '',
							meta: buildAttachmentMeta(metaRecord),
						});
					} else {
						textParts.push('[File attachment]');
					}
				}
			});
			displayPayload.text = textParts.join('\n');
		}

		return displayPayload;
	}

	describe('Image attachment metadata display', () => {
		it('should display complete metadata for attached image', () => {
			const userMessage = {
				role: 'user',
				content: [
					{
						type: 'text',
						text: 'edit this image to remove the background',
					},
					{
						type: 'input_image',
						attachment_id: 2360,
						url: 'https://bots.nvdigital.solutions/wp-content/uploads/2025/12/test-image.jpg',
						file_name: 'test-image.jpg',
						mime_type: 'image/jpeg',
						bytes: 194332,
					},
				],
			};

			const displayPayload = buildDisplayPayloadFromContent(userMessage.content);

			expect(displayPayload.text).toBe('edit this image to remove the background');
			expect(displayPayload.attachments.length).toBe(1);
			expect(displayPayload.attachments[0].url).toBe('https://bots.nvdigital.solutions/wp-content/uploads/2025/12/test-image.jpg');
			expect(displayPayload.attachments[0].label).toBe('test-image.jpg');
			expect(displayPayload.attachments[0].meta).toBe('189.8 KB • image/jpeg • ID: 2360');
		});

		it('should handle image with caption', () => {
			const content = [
				{
					type: 'text',
					text: 'Process this',
				},
				{
					type: 'input_image',
					attachment_id: 123,
					url: 'https://example.com/photo.jpg',
					caption: 'Product photo',
					mime_type: 'image/jpeg',
					bytes: 204800,
				},
			];

			const displayPayload = buildDisplayPayloadFromContent(content);

			expect(displayPayload.attachments[0].label).toBe('Product photo');
			expect(displayPayload.attachments[0].meta).toContain('200 KB');
			expect(displayPayload.attachments[0].meta).toContain('image/jpeg');
			expect(displayPayload.attachments[0].meta).toContain('ID: 123');
		});

		it('should handle PNG image', () => {
			const content = [
				{
					type: 'input_image',
					attachment_id: 456,
					url: 'https://example.com/screenshot.png',
					name: 'screenshot.png',
					mime_type: 'image/png',
					bytes: 512000,
				},
			];

			const displayPayload = buildDisplayPayloadFromContent(content);

			expect(displayPayload.attachments[0].meta).toBe('500 KB • image/png • ID: 456');
		});
	});

	describe('File attachment metadata display', () => {
		it('should display complete metadata for PDF file', () => {
			const content = [
				{
					type: 'text',
					text: 'Please review this document',
				},
				{
					type: 'input_file',
					attachment_id: 789,
					url: 'https://example.com/report.pdf',
					name: 'Monthly Report.pdf',
					display_name: 'Monthly Report.pdf',
					mime_type: 'application/pdf',
					bytes: 1048576,
				},
			];

			const displayPayload = buildDisplayPayloadFromContent(content);

			expect(displayPayload.attachments.length).toBe(1);
			expect(displayPayload.attachments[0].label).toBe('Monthly Report.pdf');
			expect(displayPayload.attachments[0].meta).toBe('1 MB • application/pdf • ID: 789');
		});

		it('should handle video file', () => {
			const content = [
				{
					type: 'input_file',
					attachment_id: 999,
					url: 'https://example.com/demo.mp4',
					name: 'product-demo.mp4',
					mime_type: 'video/mp4',
					bytes: 5242880,
				},
			];

			const displayPayload = buildDisplayPayloadFromContent(content);

			expect(displayPayload.attachments[0].meta).toBe('5 MB • video/mp4 • ID: 999');
		});
	});

	describe('Multiple attachments', () => {
		it('should display metadata for multiple images', () => {
			const content = [
				{
					type: 'text',
					text: 'Compare these images',
				},
				{
					type: 'input_image',
					attachment_id: 100,
					url: 'https://example.com/before.jpg',
					name: 'before.jpg',
					mime_type: 'image/jpeg',
					bytes: 200000,
				},
				{
					type: 'input_image',
					attachment_id: 101,
					url: 'https://example.com/after.jpg',
					name: 'after.jpg',
					mime_type: 'image/jpeg',
					bytes: 180000,
				},
			];

			const displayPayload = buildDisplayPayloadFromContent(content);

			expect(displayPayload.attachments.length).toBe(2);
			expect(displayPayload.attachments[0].meta).toContain('ID: 100');
			expect(displayPayload.attachments[1].meta).toContain('ID: 101');
		});
	});

	describe('Format consistency with tool results', () => {
		it('should match edit_gemini_image tool result format', () => {
			// User attachment
			const userContent = [
				{
					type: 'input_image',
					attachment_id: 555,
					url: 'https://example.com/image.jpg',
					name: 'image.jpg',
					mime_type: 'image/jpeg',
					bytes: 189800,
				},
			];

			// Simulated tool result
			const toolResult = {
				attachment_id: 556,
				bytes: 189800,
				mime_type: 'image/png',
			};

			const userDisplay = buildDisplayPayloadFromContent(userContent);
			const toolMeta = buildAttachmentMeta(toolResult);

			// Both should follow the same format pattern: size • mime • ID: X
			expect(userDisplay.attachments[0].meta).toMatch(/\d+(\.\d+)?\s+(KB|MB)\s+•\s+[\w/]+\s+•\s+ID:\s+\d+/);
			expect(toolMeta).toMatch(/\d+(\.\d+)?\s+(KB|MB)\s+•\s+[\w/]+\s+•\s+ID:\s+\d+/);
		});
	});

	describe('Edge cases', () => {
		it('should handle missing metadata fields gracefully', () => {
			const content = [
				{
					type: 'input_image',
					attachment_id: 777,
					url: 'https://example.com/no-meta.jpg',
					// Missing mime_type and bytes
				},
			];

			const displayPayload = buildDisplayPayloadFromContent(content);

			// Should still show attachment_id even without size and mime
			expect(displayPayload.attachments[0].meta).toBe('ID: 777');
		});

		it('should handle attachment without attachment_id', () => {
			const content = [
				{
					type: 'input_image',
					url: 'https://example.com/external.jpg',
					mime_type: 'image/jpeg',
					bytes: 204800,
					// No attachment_id (external image)
				},
			];

			const displayPayload = buildDisplayPayloadFromContent(content);

			// Should show size and mime but no ID
			expect(displayPayload.attachments[0].meta).toBe('200 KB • image/jpeg');
		});

		it('should handle missing URL gracefully', () => {
			const content = [
				{
					type: 'input_image',
					attachment_id: 888,
					// Missing url
				},
			];

			const displayPayload = buildDisplayPayloadFromContent(content);

			// Should not create attachment entry without URL
			expect(displayPayload.attachments.length).toBe(0);
			expect(displayPayload.text).toBe('[Image attachment]');
		});
	});

	describe('Real-world scenario from issue #2125', () => {
		it('should display metadata for image used in edit_gemini_image workflow', () => {
			// This is the exact scenario from the issue where edit_gemini_image failed
			// because the LLM couldn't see the URL/attachment_id
			const userMessage = {
				role: 'user',
				content: [
					{
						type: 'text',
						text: 'edit Gemini image to remove background',
					},
					{
						type: 'input_image',
						image_url: {
							url: 'https://bots.nvdigital.solutions/wp-content/uploads/2025/12/81pgwTzeHL._SL1500_-6.jpg',
						},
						file_name: '81pgwTzeHL._SL1500_-6',
						mime_type: 'image/jpeg',
						bytes: 194332,
						url: 'https://bots.nvdigital.solutions/wp-content/uploads/2025/12/81pgwTzeHL._SL1500_-6.jpg',
						attachment_id: 2360,
					},
				],
			};

			const displayPayload = buildDisplayPayloadFromContent(userMessage.content);

			// After the fix, the LLM should see:
			// - The URL in the display
			// - The attachment_id in the metadata
			// - Complete file information
			expect(displayPayload.attachments.length).toBe(1);
			expect(displayPayload.attachments[0].url).toBe('https://bots.nvdigital.solutions/wp-content/uploads/2025/12/81pgwTzeHL._SL1500_-6.jpg');
			expect(displayPayload.attachments[0].meta).toBe('189.8 KB • image/jpeg • ID: 2360');

			// This metadata is now visible in the chat, helping the LLM extract
			// the URL and pass it to edit_gemini_image tool
		});
	});
});
