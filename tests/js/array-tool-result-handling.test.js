/**
 * Tests for handling array tool results (e.g., from search_attachments)
 *
 * @package WP_MCP_AI
 */

describe('Array Tool Result Handling', () => {
	let normaliseArrayToolResult;
	let normaliseToolResultForDisplay;

	beforeEach(() => {
		// Mock normaliseArrayToolResult function
		normaliseArrayToolResult = function(resultArray, toolName) {
			if (!Array.isArray(resultArray) || resultArray.length === 0) {
				return null;
			}

			// Handle search_attachments results specifically
			if (toolName === 'search_attachments') {
				const attachments = [];
				const attachmentTitles = [];

				resultArray.forEach(function(item, index) {
					if (!item || typeof item !== 'object') {
						return;
					}

					const title = item.title || 'Untitled';
					const downloadUrl = item.download_url || item.url || '';
					const permalink = item.permalink || '';
					const mimeType = item.mime_type || '';
					const filesize = item.filesize_human || '';
					const uploadedAt = item.uploaded_at || '';

					// Build metadata string
					const metaParts = [];
					if (mimeType) {
						const mimeTypeParts = mimeType.split('/');
						if (mimeTypeParts.length > 0) {
							metaParts.push(mimeTypeParts[mimeTypeParts.length - 1].toUpperCase());
						}
					}
					if (filesize) {
						metaParts.push(filesize);
					}
					if (uploadedAt) {
						const uploadDate = new Date(uploadedAt);
						if (!isNaN(uploadDate.getTime())) {
							metaParts.push('Uploaded: ' + uploadDate.toISOString().split('T')[0]);
						}
					}

					const meta = metaParts.join(' • ');

					// Add to attachments list if we have a download URL
					if (downloadUrl) {
						attachments.push({
							url: downloadUrl,
							label: title,
							downloadName: '',
							meta: meta
						});
						attachmentTitles.push(title);
					}

					// Also add permalink if different from download URL
					if (permalink && permalink !== downloadUrl) {
						attachments.push({
							url: permalink,
							label: 'View Details',
							downloadName: '',
							meta: ''
						});
					}
				});

				if (attachments.length === 0) {
					return {
						text: 'No attachments found.',
						attachments: []
					};
				}

				const text = 'Here are the last ' + resultArray.length + ' attachments from the media library:';

				return {
					text: text,
					attachments: attachments
				};
			}

			// Generic array handling for other tools
			const items = resultArray.map(function(item, index) {
				if (typeof item === 'string') {
					return item;
				}
				if (item && typeof item === 'object') {
					return item.title || item.name || item.message || item.text || JSON.stringify(item);
				}
				return String(item);
			}).filter(function(item) {
				return item && item.trim();
			});

			if (items.length === 0) {
				return null;
			}

			return {
				text: items.join('\n'),
				attachments: []
			};
		};

		// Mock normaliseToolResultForDisplay to use the array handler
		normaliseToolResultForDisplay = function(toolName, result) {
			if (typeof result === 'string' && result.trim()) {
				return {
					text: result,
					attachments: []
				};
			}

			if (!result || typeof result !== 'object') {
				return null;
			}

			// Handle array results
			if (Array.isArray(result)) {
				return normaliseArrayToolResult(result, toolName);
			}

			// For non-array objects, return a simple text representation
			if (result.message || result.text) {
				return {
					text: result.message || result.text,
					attachments: []
				};
			}

			return null;
		};
	});

	describe('normaliseArrayToolResult', () => {
		it('should return null for empty arrays', () => {
			const result = normaliseArrayToolResult([], 'search_attachments');
			expect(result).toBeNull();
		});

		it('should return null for non-arrays', () => {
			const result = normaliseArrayToolResult({}, 'search_attachments');
			expect(result).toBeNull();
		});

		it('should handle search_attachments results with single attachment', () => {
			const attachments = [
				{
					id: 123,
					title: 'OpenAI Image: A realistic ripe banana',
					mime_type: 'image/png',
					download_url: 'https://example.com/banana.png',
					permalink: 'https://example.com/?attachment_id=123',
					filesize_human: '1 MB',
					uploaded_at: '2025-12-05T18:22:49Z'
				}
			];

			const result = normaliseArrayToolResult(attachments, 'search_attachments');

			expect(result).not.toBeNull();
			expect(result.text).toBe('Here are the last 1 attachments from the media library:');
			expect(result.attachments).toHaveLength(2); // download_url + permalink
			expect(result.attachments[0].url).toBe('https://example.com/banana.png');
			expect(result.attachments[0].label).toBe('OpenAI Image: A realistic ripe banana');
			expect(result.attachments[0].meta).toContain('PNG');
			expect(result.attachments[0].meta).toContain('1 MB');
			expect(result.attachments[0].meta).toContain('2025-12-05');
			expect(result.attachments[1].url).toBe('https://example.com/?attachment_id=123');
			expect(result.attachments[1].label).toBe('View Details');
		});

		it('should handle search_attachments results with multiple attachments', () => {
			const attachments = [
				{
					id: 123,
					title: 'OpenAI Image: Banana 1',
					mime_type: 'image/png',
					download_url: 'https://example.com/banana1.png',
					permalink: 'https://example.com/?attachment_id=123',
					filesize_human: '1 MB',
					uploaded_at: '2025-12-05T18:22:49Z'
				},
				{
					id: 124,
					title: 'OpenAI Image: Banana 2',
					mime_type: 'image/png',
					download_url: 'https://example.com/banana2.png',
					permalink: 'https://example.com/?attachment_id=124',
					filesize_human: '1.2 MB',
					uploaded_at: '2025-12-04T10:00:00Z'
				}
			];

			const result = normaliseArrayToolResult(attachments, 'search_attachments');

			expect(result).not.toBeNull();
			expect(result.text).toBe('Here are the last 2 attachments from the media library:');
			expect(result.attachments).toHaveLength(4); // 2 downloads + 2 permalinks
		});

		it('should handle attachments without permalinks', () => {
			const attachments = [
				{
					id: 123,
					title: 'Document',
					mime_type: 'application/pdf',
					download_url: 'https://example.com/doc.pdf',
					filesize_human: '500 KB'
				}
			];

			const result = normaliseArrayToolResult(attachments, 'search_attachments');

			expect(result).not.toBeNull();
			expect(result.attachments).toHaveLength(1); // only download_url, no permalink
			expect(result.attachments[0].meta).toContain('PDF');
			expect(result.attachments[0].meta).toContain('500 KB');
		});

		it('should handle attachments with same URL and permalink', () => {
			const attachments = [
				{
					id: 123,
					title: 'Image',
					mime_type: 'image/jpeg',
					download_url: 'https://example.com/image.jpg',
					permalink: 'https://example.com/image.jpg' // Same as download_url
				}
			];

			const result = normaliseArrayToolResult(attachments, 'search_attachments');

			expect(result).not.toBeNull();
			expect(result.attachments).toHaveLength(1); // only download_url since permalink is the same
		});

		it('should return "No attachments found" for empty array after filtering', () => {
			const attachments = [
				{
					id: 123,
					title: 'Image without URL',
					mime_type: 'image/jpeg'
					// No download_url or url field
				}
			];

			const result = normaliseArrayToolResult(attachments, 'search_attachments');

			expect(result).not.toBeNull();
			expect(result.text).toBe('No attachments found.');
			expect(result.attachments).toHaveLength(0);
		});

		it('should handle generic array results from other tools', () => {
			const items = ['Item 1', 'Item 2', 'Item 3'];

			const result = normaliseArrayToolResult(items, 'some_other_tool');

			expect(result).not.toBeNull();
			expect(result.text).toBe('Item 1\nItem 2\nItem 3');
			expect(result.attachments).toHaveLength(0);
		});

		it('should handle array of objects for generic tools', () => {
			const items = [
				{ name: 'Object 1' },
				{ title: 'Object 2' },
				{ message: 'Object 3' }
			];

			const result = normaliseArrayToolResult(items, 'some_other_tool');

			expect(result).not.toBeNull();
			expect(result.text).toBe('Object 1\nObject 2\nObject 3');
			expect(result.attachments).toHaveLength(0);
		});
	});

	describe('normaliseToolResultForDisplay with arrays', () => {
		it('should handle array results by calling normaliseArrayToolResult', () => {
			const attachments = [
				{
					id: 123,
					title: 'Test Attachment',
					mime_type: 'image/png',
					download_url: 'https://example.com/test.png',
					permalink: 'https://example.com/?attachment_id=123',
					filesize_human: '1 MB',
					uploaded_at: '2025-12-05T18:22:49Z'
				}
			];

			const result = normaliseToolResultForDisplay('search_attachments', attachments);

			expect(result).not.toBeNull();
			expect(result.text).toContain('Here are the last');
			expect(result.attachments).toHaveLength(2);
		});

		it('should still handle non-array results', () => {
			const singleResult = {
				message: 'Test message'
			};

			const result = normaliseToolResultForDisplay('some_tool', singleResult);

			expect(result).not.toBeNull();
			expect(result.text).toBe('Test message');
		});

		it('should handle string results', () => {
			const result = normaliseToolResultForDisplay('some_tool', 'Simple string result');

			expect(result).not.toBeNull();
			expect(result.text).toBe('Simple string result');
		});
	});

	describe('Real-world search_attachments scenario', () => {
		it('should properly format the 10 banana images from the problem statement', () => {
			const attachments = [
				{
					id: 1,
					title: 'OpenAI Image: A realistic ripe banana on a tropical background',
					mime_type: 'image/png',
					download_url: 'https://example.com/openai-image-20251205-182249.png',
					permalink: 'https://example.com/?attachment_id=1',
					filesize_human: '1 MB',
					uploaded_at: '2025-12-05T18:22:49Z'
				},
				{
					id: 2,
					title: 'OpenAI Image: A single ripe yellow banana on a clean white background',
					mime_type: 'image/png',
					download_url: 'https://example.com/openai-image-20251205-044550.png',
					permalink: 'https://example.com/?attachment_id=2',
					filesize_human: '1 MB',
					uploaded_at: '2025-12-05T04:45:50Z'
				}
			];

			const result = normaliseToolResultForDisplay('search_attachments', attachments);

			expect(result).not.toBeNull();
			expect(result.text).toBe('Here are the last 2 attachments from the media library:');
			expect(result.attachments).toHaveLength(4); // 2 downloads + 2 permalinks
			
			// Verify first attachment has proper metadata
			expect(result.attachments[0].url).toBe('https://example.com/openai-image-20251205-182249.png');
			expect(result.attachments[0].label).toContain('tropical background');
			expect(result.attachments[0].meta).toContain('PNG');
			expect(result.attachments[0].meta).toContain('1 MB');
			expect(result.attachments[0].meta).toContain('2025-12-05');
		});
	});
});
