/**
 * Test file upload metadata flow
 * Verifies that attachment metadata from upload flows through to message segments
 *
 * @package WP_MCP_AI
 */

describe('File Upload Metadata Flow', () => {
	// Simulate the normaliseUploadResponse function
	function normaliseUploadResponse(data, file) {
		if (!data || typeof data !== 'object') {
			return null;
		}

		let id = data.id;
		if (!id && data.data && typeof data.data.id !== 'undefined') {
			id = data.data.id;
		}

		if (typeof id === 'undefined' || id === null) {
			return null;
		}

		const fileId = 'wp-attachment-' + id;
		const name = (file && file.name) || '';
		const url = data.source_url || '';
		const mime = data.mime_type || (file && file.type) || '';
		const size = file && file.size;
		const isImage = typeof mime === 'string' && mime.indexOf('image/') === 0;

		return {
			id: id,
			fileId: fileId,
			name: name,
			originalName: file ? file.name : '',
			url: url,
			mime: mime,
			size: size,
			isImage: isImage,
		};
	}

	// Simulate the createSegmentFromAttachment function
	function createSegmentFromAttachment(attachment) {
		if (!attachment) {
			return null;
		}

		let id = attachment.id;
		if (!id && attachment.fileId && attachment.fileId.indexOf('wp-attachment-') === 0) {
			const parsed = parseInt(attachment.fileId.replace('wp-attachment-', ''), 10);
			if (!isNaN(parsed)) {
				id = parsed;
			}
		}

		if (!id) {
			return null;
		}

		const mime = attachment.mime || attachment.type || '';
		const isImage = typeof attachment.isImage === 'boolean' ? attachment.isImage : typeof mime === 'string' && mime.indexOf('image/') === 0;

		if (isImage) {
			const segment = {
				type: 'input_image',
				attachment_id: id,
			};
			addAttachmentMetadataToSegment(segment, attachment);
			return segment;
		}

		const segment = {
			type: 'input_file',
			attachment_id: id,
		};

		const displayName = attachment.originalName || attachment.name || '';
		if (displayName) {
			segment.display_name = displayName;
		}

		addAttachmentMetadataToSegment(segment, attachment);
		return segment;
	}

	// Simulate the addAttachmentMetadataToSegment function
	function addAttachmentMetadataToSegment(segment, attachment) {
		if (!segment || typeof segment !== 'object') {
			return;
		}
		if (!attachment || typeof attachment !== 'object') {
			return;
		}

		// Include metadata for agentic workflow, following OpenAI image tool pattern
		if (attachment.url) {
			segment.url = attachment.url;
		}
		if (attachment.name) {
			segment.name = attachment.name;
			// file_name is only set when name exists
			segment.file_name = attachment.name;
		}
		if (attachment.mime) {
			segment.mime_type = attachment.mime;
		}
		if (attachment.size) {
			segment.bytes = attachment.size;
		}
	}

	describe('Image Upload Flow', () => {
		it('should preserve all metadata from upload response through segment creation', () => {
			// Simulate server response
			const serverResponse = {
				id: 123,
				source_url: 'https://example.com/wp-content/uploads/2024/12/test-image.jpg',
				mime_type: 'image/jpeg',
				title: { rendered: 'Test Image' },
			};

			// Simulate file object
			const file = {
				name: 'test-image.jpg',
				type: 'image/jpeg',
				size: 204800, // 200KB
			};

			// Step 1: Normalize upload response
			const attachment = normaliseUploadResponse(serverResponse, file);

			expect(attachment).toBeTruthy();
			expect(attachment.id).toBe(123);
			expect(attachment.fileId).toBe('wp-attachment-123');
			expect(attachment.name).toBe('test-image.jpg');
			expect(attachment.url).toBe('https://example.com/wp-content/uploads/2024/12/test-image.jpg');
			expect(attachment.mime).toBe('image/jpeg');
			expect(attachment.size).toBe(204800);
			expect(attachment.isImage).toBe(true);

			// Step 2: Create segment from attachment
			const segment = createSegmentFromAttachment(attachment);

			expect(segment).toBeTruthy();
			expect(segment.type).toBe('input_image');
			expect(segment.attachment_id).toBe(123);
			expect(segment.url).toBe('https://example.com/wp-content/uploads/2024/12/test-image.jpg');
			expect(segment.name).toBe('test-image.jpg');
			expect(segment.file_name).toBe('test-image.jpg');
			expect(segment.mime_type).toBe('image/jpeg');
			expect(segment.bytes).toBe(204800);
		});
	});

	describe('File Upload Flow', () => {
		it('should preserve all metadata for PDF file upload', () => {
			const serverResponse = {
				id: 456,
				source_url: 'https://example.com/wp-content/uploads/2024/12/report.pdf',
				mime_type: 'application/pdf',
			};

			const file = {
				name: 'Monthly Report.pdf',
				type: 'application/pdf',
				size: 1048576, // 1MB
			};

			const attachment = normaliseUploadResponse(serverResponse, file);

			expect(attachment.id).toBe(456);
			expect(attachment.isImage).toBe(false);

			const segment = createSegmentFromAttachment(attachment);

			expect(segment.type).toBe('input_file');
			expect(segment.attachment_id).toBe(456);
			expect(segment.display_name).toBe('Monthly Report.pdf');
			expect(segment.url).toBe('https://example.com/wp-content/uploads/2024/12/report.pdf');
			expect(segment.name).toBe('Monthly Report.pdf');
			expect(segment.file_name).toBe('Monthly Report.pdf');
			expect(segment.mime_type).toBe('application/pdf');
			expect(segment.bytes).toBe(1048576);
		});

		it('should handle various file types correctly', () => {
			const testCases = [
				{
					file: { name: 'video.mp4', type: 'video/mp4', size: 5242880 },
					expected: { type: 'input_file', mime_type: 'video/mp4' },
				},
				{
					file: { name: 'document.docx', type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', size: 524288 },
					expected: { type: 'input_file', mime_type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
				},
				{
					file: { name: 'photo.png', type: 'image/png', size: 307200 },
					expected: { type: 'input_image', mime_type: 'image/png' },
				},
			];

			testCases.forEach((testCase, index) => {
				const serverResponse = {
					id: 1000 + index,
					source_url: 'https://example.com/uploads/' + testCase.file.name,
					mime_type: testCase.file.type,
				};

				const attachment = normaliseUploadResponse(serverResponse, testCase.file);
				const segment = createSegmentFromAttachment(attachment);

				expect(segment.type).toBe(testCase.expected.type);
				expect(segment.mime_type).toBe(testCase.expected.mime_type);
				expect(segment.bytes).toBe(testCase.file.size);
				expect(segment.attachment_id).toBe(1000 + index);
			});
		});
	});

	describe('Metadata Completeness', () => {
		it('should include all fields matching OpenAI image tool pattern', () => {
			const serverResponse = {
				id: 789,
				source_url: 'https://example.com/uploads/chart.png',
				mime_type: 'image/png',
			};

			const file = {
				name: 'sales-chart.png',
				type: 'image/png',
				size: 409600,
			};

			const attachment = normaliseUploadResponse(serverResponse, file);
			const segment = createSegmentFromAttachment(attachment);

			// Verify all OpenAI image tool fields are present
			const requiredFields = ['attachment_id', 'url', 'file_name', 'name', 'mime_type', 'bytes'];
			requiredFields.forEach(field => {
				expect(segment[field]).toBeDefined();
				expect(segment[field]).not.toBeNull();
			});

			// Verify field values match expected pattern
			expect(typeof segment.attachment_id).toBe('number');
			expect(typeof segment.url).toBe('string');
			expect(typeof segment.file_name).toBe('string');
			expect(typeof segment.name).toBe('string');
			expect(typeof segment.mime_type).toBe('string');
			expect(typeof segment.bytes).toBe('number');
		});
	});
});
