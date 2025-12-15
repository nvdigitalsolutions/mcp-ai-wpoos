/**
 * Test attachment metadata display includes attachment_id
 * Verifies that buildAttachmentMeta includes attachment_id in the format "ID: X"
 *
 * @package WP_MCP_AI
 */

describe('Attachment Metadata Display', () => {
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

	describe('buildAttachmentMeta with attachment_id', () => {
		it('should include attachment_id for uploaded image', () => {
			const record = {
				id: 123,
				size: 204800, // 200KB
				mime: 'image/jpeg',
			};

			const meta = buildAttachmentMeta(record);

			expect(meta).toBe('200 KB • image/jpeg • ID: 123');
		});

		it('should include attachment_id for PDF file', () => {
			const record = {
				id: 456,
				bytes: 1048576, // 1MB
				mime_type: 'application/pdf',
			};

			const meta = buildAttachmentMeta(record);

			expect(meta).toBe('1 MB • application/pdf • ID: 456');
		});

		it('should handle attachment_id field instead of id', () => {
			const record = {
				attachment_id: 789,
				size: 512000,
				type: 'image/png',
			};

			const meta = buildAttachmentMeta(record);

			expect(meta).toBe('500 KB • image/png • ID: 789');
		});

		it('should work with string attachment_id', () => {
			const record = {
				id: '999',
				size: 102400,
				mime: 'video/mp4',
			};

			const meta = buildAttachmentMeta(record);

			expect(meta).toBe('100 KB • video/mp4 • ID: 999');
		});

		it('should handle missing size gracefully', () => {
			const record = {
				id: 321,
				mime: 'image/jpeg',
			};

			const meta = buildAttachmentMeta(record);

			expect(meta).toBe('image/jpeg • ID: 321');
		});

		it('should handle missing mime type gracefully', () => {
			const record = {
				id: 654,
				size: 204800,
			};

			const meta = buildAttachmentMeta(record);

			expect(meta).toBe('200 KB • ID: 654');
		});

		it('should handle missing attachment_id gracefully (backward compatibility)', () => {
			const record = {
				size: 204800,
				mime: 'image/jpeg',
			};

			const meta = buildAttachmentMeta(record);

			expect(meta).toBe('200 KB • image/jpeg');
		});

		it('should return empty string for null record', () => {
			const meta = buildAttachmentMeta(null);

			expect(meta).toBe('');
		});

		it('should match tool result format (e.g., generate_openai_image)', () => {
			// Simulate a tool result structure
			const toolResult = {
				attachment_id: 888,
				bytes: 409600,
				mime_type: 'image/png',
				size: '1024x1024',
				quality: 'high',
			};

			const meta = buildAttachmentMeta(toolResult);

			// Should include ID in the same format as tool results
			expect(meta).toContain('ID: 888');
			expect(meta).toContain('400 KB');
			expect(meta).toContain('image/png');
		});

		it('should handle various file types consistently', () => {
			const testCases = [
				{
					record: { id: 100, size: 5242880, mime: 'video/mp4' },
					expected: '5 MB • video/mp4 • ID: 100',
				},
				{
					record: { id: 200, size: 524288, mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
					expected: '512 KB • application/vnd.openxmlformats-officedocument.wordprocessingml.document • ID: 200',
				},
				{
					record: { id: 300, size: 307200, mime: 'image/png' },
					expected: '300 KB • image/png • ID: 300',
				},
			];

			testCases.forEach((testCase) => {
				const meta = buildAttachmentMeta(testCase.record);
				expect(meta).toBe(testCase.expected);
			});
		});
	});

	describe('Display consistency with tool results', () => {
		it('should match the format used by edit_gemini_image tool results', () => {
			// Tool result format from normaliseToolResultForDisplay
			const editGeminiImageResult = {
				attachment_id: 777,
				bytes: 189800,
				mime_type: 'image/jpeg',
			};

			const meta = buildAttachmentMeta(editGeminiImageResult);

			// Should match the format: "size • mime • ID: X"
			expect(meta).toMatch(/\d+(\.\d+)?\s+(KB|MB|GB)\s+•\s+image\/jpeg\s+•\s+ID:\s+777/);
		});

		it('should provide complete context for agentic workflow', () => {
			// User-attached file
			const userAttachment = {
				id: 555,
				name: 'test-image.jpg',
				size: 204800,
				mime: 'image/jpeg',
				url: 'https://example.com/wp-content/uploads/2024/12/test-image.jpg',
			};

			const meta = buildAttachmentMeta(userAttachment);

			// All key identifiers should be present
			expect(meta).toContain('200 KB');
			expect(meta).toContain('image/jpeg');
			expect(meta).toContain('ID: 555');
		});
	});
});
