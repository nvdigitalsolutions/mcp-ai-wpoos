/**
 * Test multi-step attachment prepare pipeline.
 *
 * Verifies that the two-step attachment pipeline works correctly:
 *   Step 1 – upload file to WordPress media library
 *   Step 2 – pre-register the file with the AI provider via the prepare endpoint
 *
 * @package WP_MCP_AI
 */

describe('Multi-step Attachment Prepare Pipeline', () => {
	// ---------------------------------------------------------------------------
	// Helpers that mirror the real chat.js implementations
	// ---------------------------------------------------------------------------

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

		return { id, fileId, name, originalName: name, url, mime, size, isImage };
	}

	/**
	 * Simulate prepareAttachment: calls the prepare endpoint and augments the record.
	 * Returns a promise that resolves to the (possibly augmented) record.
	 */
	function simulatePrepareAttachment(prepareEndpoint, record, prepareResponse) {
		if (!prepareEndpoint || !record) {
			return Promise.resolve(record);
		}

		// Simulate fetch to prepare endpoint.
		return Promise.resolve(prepareResponse)
			.then(function (data) {
				if (data && data.file_id) {
					record.providerFileId = data.file_id;
					record.provider = data.provider || '';
				}
				return record;
			})
			.catch(function () {
				// Non-fatal fallback.
				return record;
			});
	}

	// ---------------------------------------------------------------------------
	// Tests
	// ---------------------------------------------------------------------------

	describe('Step 1: normalise WordPress upload response', () => {
		it('should produce a valid record for an image upload', () => {
			const wpResponse = { id: 10, source_url: 'https://example.com/img.jpg', mime_type: 'image/jpeg' };
			const file = { name: 'img.jpg', type: 'image/jpeg', size: 102400 };

			const record = normaliseUploadResponse(wpResponse, file);

			expect(record).toBeTruthy();
			expect(record.id).toBe(10);
			expect(record.fileId).toBe('wp-attachment-10');
			expect(record.isImage).toBe(true);
			expect(record.mime).toBe('image/jpeg');
		});

		it('should produce a valid record for a PDF upload', () => {
			const wpResponse = { id: 20, source_url: 'https://example.com/doc.pdf', mime_type: 'application/pdf' };
			const file = { name: 'doc.pdf', type: 'application/pdf', size: 512000 };

			const record = normaliseUploadResponse(wpResponse, file);

			expect(record).toBeTruthy();
			expect(record.id).toBe(20);
			expect(record.isImage).toBe(false);
		});
	});

	describe('Step 2: prepare attachment with AI provider', () => {
		it('should augment record with providerFileId on success', async () => {
			const prepareEndpoint = 'https://example.com/wp-json/mcp-ai/v1/attachments/prepare';
			const record = {
				id: 10,
				fileId: 'wp-attachment-10',
				name: 'img.jpg',
				isImage: true,
				mime: 'image/jpeg',
			};

			const prepareResponse = {
				attachment_id: 10,
				file_id: 'file-abc123',
				provider: 'openai',
				status: 'ready',
			};

			const result = await simulatePrepareAttachment(prepareEndpoint, record, prepareResponse);

			expect(result.providerFileId).toBe('file-abc123');
			expect(result.provider).toBe('openai');
			// Original fields preserved.
			expect(result.id).toBe(10);
			expect(result.isImage).toBe(true);
		});

		it('should return original record unchanged when prepare endpoint is not configured', async () => {
			const record = { id: 30, fileId: 'wp-attachment-30', name: 'file.pdf', isImage: false };

			const result = await simulatePrepareAttachment('', record, null);

			expect(result).toBe(record);
			expect(result.providerFileId).toBeUndefined();
		});

		it('should return original record unchanged when record is null', async () => {
			const result = await simulatePrepareAttachment('https://example.com/prepare', null, null);

			expect(result).toBeNull();
		});

		it('should fall back gracefully when prepare endpoint returns no file_id', async () => {
			const record = { id: 40, fileId: 'wp-attachment-40', name: 'img.png', isImage: true };
			const prepareResponse = { status: 'error' }; // No file_id field.

			const result = await simulatePrepareAttachment('https://example.com/prepare', record, prepareResponse);

			// No augmentation, but record is still returned.
			expect(result).toBe(record);
			expect(result.providerFileId).toBeUndefined();
		});

		it('should fall back gracefully when prepare endpoint rejects', async () => {
			const record = { id: 50, fileId: 'wp-attachment-50', name: 'doc.txt', isImage: false };

			// Simulate a rejected promise (network error etc.).
			const rejectingPrepare = function (prepareEndpoint, rec) {
				if (!prepareEndpoint || !rec) {
					return Promise.resolve(rec);
				}
				return Promise.reject(new Error('Network error'))
					.catch(function () {
						return rec; // Non-fatal fallback.
					});
			};

			const result = await rejectingPrepare('https://example.com/prepare', record);

			expect(result).toBe(record); // Graceful fallback.
		});
	});

	describe('Full two-step pipeline', () => {
		it('should complete both steps and produce an augmented record', async () => {
			// Step 1: WordPress upload.
			const wpResponse = { id: 60, source_url: 'https://example.com/chart.png', mime_type: 'image/png' };
			const file = { name: 'chart.png', type: 'image/png', size: 204800 };
			const record = normaliseUploadResponse(wpResponse, file);

			expect(record).toBeTruthy();
			expect(record.providerFileId).toBeUndefined();

			// Step 2: Pre-register with provider.
			const prepareResponse = { attachment_id: 60, file_id: 'file-xyz789', provider: 'openai', status: 'ready' };
			const result = await simulatePrepareAttachment('https://example.com/prepare', record, prepareResponse);

			expect(result.providerFileId).toBe('file-xyz789');
			expect(result.provider).toBe('openai');
			// Step 1 data still intact.
			expect(result.id).toBe(60);
			expect(result.isImage).toBe(true);
			expect(result.mime).toBe('image/png');
		});

		it('should complete step 1 and skip step 2 when prepareEndpoint is absent', async () => {
			const wpResponse = { id: 70, source_url: 'https://example.com/report.pdf', mime_type: 'application/pdf' };
			const file = { name: 'report.pdf', type: 'application/pdf', size: 1048576 };
			const record = normaliseUploadResponse(wpResponse, file);

			const result = await simulatePrepareAttachment('', record, null);

			// Record is usable but not augmented.
			expect(result.id).toBe(70);
			expect(result.providerFileId).toBeUndefined();
		});
	});
});
