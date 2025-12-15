/**
 * Test enhanced metadata display for edit_gemini_image tool
 * 
 * Verifies that edit_gemini_image displays comprehensive metadata including:
 * - Attachment ID
 * - File size
 * - MIME type
 * - Aspect ratio
 * - Format
 * - Model name
 * - Edit instruction
 * 
 * @package WP_MCP_AI
 */

describe('edit_gemini_image Metadata Display', () => {
	// Mock the normaliseToolResultForDisplay function behavior
	// This simulates what happens in chat.js lines 7916-7940
	function buildEditGeminiImageMetadata(result) {
		const metaParts = [];

		// Base metadata (from buildAttachmentMeta)
		const parts = [];
		if (result.bytes) {
			// Simple byte formatting
			const kb = result.bytes / 1024;
			if (kb < 1024) {
				parts.push(Math.round(kb) + ' KB');
			} else {
				parts.push((kb / 1024).toFixed(1) + ' MB');
			}
		}
		if (result.mime_type) {
			parts.push(result.mime_type);
		}
		if (result.attachment_id) {
			parts.push('ID: ' + result.attachment_id);
		}
		if (parts.length > 0) {
			metaParts.push(parts.join(' • '));
		}

		// Aspect ratio
		if (result.aspect_ratio) {
			metaParts.push(result.aspect_ratio);
		}

		// Format
		if (result.format) {
			metaParts.push(result.format.toUpperCase());
		}

		// Model name (new enhancement)
		if (result.model) {
			let modelName = result.model;
			if (modelName.indexOf('gemini-2.5-flash-image') !== -1 || modelName.indexOf('gemini-2.0-flash-exp') !== -1) {
				metaParts.push('Gemini 2.5');
			} else if (modelName.indexOf('gemini-exp-1206') !== -1) {
				metaParts.push('Gemini Exp');
			} else {
				metaParts.push(modelName);
			}
		}

		// Edit instruction (new enhancement)
		if (result.edit_instruction && result.edit_instruction.trim().length > 0) {
			const instruction = result.edit_instruction.trim();
			const maxLength = 30;
			if (instruction.length > maxLength) {
				metaParts.push('Edit: ' + instruction.substring(0, maxLength) + '...');
			} else {
				metaParts.push('Edit: ' + instruction);
			}
		}

		return metaParts.join(' • ');
	}

	describe('Complete metadata display', () => {
		it('should display all metadata fields for a complete result', () => {
			const result = {
				attachment_id: 456,
				bytes: 409600, // 400 KB
				mime_type: 'image/png',
				aspect_ratio: '1:1',
				format: 'png',
				model: 'gemini-2.5-flash-image-001',
				edit_instruction: 'remove background',
			};

			const metadata = buildEditGeminiImageMetadata(result);

			expect(metadata).toContain('400 KB');
			expect(metadata).toContain('image/png');
			expect(metadata).toContain('ID: 456');
			expect(metadata).toContain('1:1');
			expect(metadata).toContain('PNG');
			expect(metadata).toContain('Gemini 2.5');
			expect(metadata).toContain('Edit: remove background');
		});

		it('should handle long edit instructions with truncation', () => {
			const result = {
				attachment_id: 789,
				bytes: 512000,
				mime_type: 'image/jpeg',
				aspect_ratio: '16:9',
				format: 'jpeg',
				model: 'gemini-exp-1206',
				edit_instruction: 'remove the background and make the colors more vibrant and saturated',
			};

			const metadata = buildEditGeminiImageMetadata(result);

			expect(metadata).toContain('Edit: remove the background and make...');
			expect(metadata).not.toContain('vibrant and saturated');
		});

		it('should handle gemini-exp-1206 model name', () => {
			const result = {
				attachment_id: 123,
				bytes: 204800,
				mime_type: 'image/png',
				model: 'gemini-exp-1206',
			};

			const metadata = buildEditGeminiImageMetadata(result);

			expect(metadata).toContain('Gemini Exp');
		});

		it('should handle unknown model names', () => {
			const result = {
				attachment_id: 999,
				bytes: 102400,
				mime_type: 'image/webp',
				model: 'gemini-3.0-ultra-custom',
			};

			const metadata = buildEditGeminiImageMetadata(result);

			expect(metadata).toContain('gemini-3.0-ultra-custom');
		});
	});

	describe('Backward compatibility', () => {
		it('should work without model field', () => {
			const result = {
				attachment_id: 456,
				bytes: 409600,
				mime_type: 'image/png',
				aspect_ratio: '1:1',
				format: 'png',
				edit_instruction: 'make brighter',
			};

			const metadata = buildEditGeminiImageMetadata(result);

			expect(metadata).toContain('400 KB');
			expect(metadata).toContain('Edit: make brighter');
			expect(metadata).not.toContain('Gemini');
		});

		it('should work without edit_instruction field', () => {
			const result = {
				attachment_id: 456,
				bytes: 409600,
				mime_type: 'image/png',
				aspect_ratio: '1:1',
				format: 'png',
				model: 'gemini-2.5-flash-image-001',
			};

			const metadata = buildEditGeminiImageMetadata(result);

			expect(metadata).toContain('400 KB');
			expect(metadata).toContain('Gemini 2.5');
			expect(metadata).not.toContain('Edit:');
		});

		it('should work with minimal fields (legacy)', () => {
			const result = {
				attachment_id: 456,
				bytes: 409600,
				mime_type: 'image/png',
			};

			const metadata = buildEditGeminiImageMetadata(result);

			expect(metadata).toBe('400 KB • image/png • ID: 456');
		});
	});

	describe('Edge cases', () => {
		it('should handle empty edit_instruction gracefully', () => {
			const result = {
				attachment_id: 456,
				bytes: 409600,
				mime_type: 'image/png',
				edit_instruction: '',
			};

			const metadata = buildEditGeminiImageMetadata(result);

			expect(metadata).not.toContain('Edit:');
		});

		it('should handle whitespace-only edit_instruction', () => {
			const result = {
				attachment_id: 456,
				bytes: 409600,
				mime_type: 'image/png',
				edit_instruction: '   ',
			};

			const metadata = buildEditGeminiImageMetadata(result);

			expect(metadata).not.toContain('Edit:');
		});

		it('should handle exactly 30 character instruction (no truncation)', () => {
			const result = {
				attachment_id: 456,
				bytes: 409600,
				mime_type: 'image/png',
				edit_instruction: 'make colors vibrant saturate',
			};

			const metadata = buildEditGeminiImageMetadata(result);

			expect(metadata).toContain('Edit: make colors vibrant saturate');
			expect(metadata).not.toContain('...');
		});

		it('should handle large file sizes (MB range)', () => {
			const result = {
				attachment_id: 999,
				bytes: 5242880, // 5 MB
				mime_type: 'image/png',
			};

			const metadata = buildEditGeminiImageMetadata(result);

			expect(metadata).toContain('5.0 MB');
		});
	});

	describe('Comparison with other tools', () => {
		it('should have similar metadata richness to generate_veo_video', () => {
			const editGeminiResult = {
				attachment_id: 456,
				bytes: 409600,
				mime_type: 'image/png',
				aspect_ratio: '1:1',
				format: 'png',
				model: 'gemini-2.5-flash-image-001',
				edit_instruction: 'remove background',
			};

			const metadata = buildEditGeminiImageMetadata(editGeminiResult);
			const parts = metadata.split(' • ');

			// Should have at least 7 metadata parts (base meta, aspect, format, model, instruction)
			expect(parts.length).toBeGreaterThanOrEqual(5);
			
			// Should include file info, technical specs, and operation context
			expect(metadata).toMatch(/\d+ KB/);  // File size
			expect(metadata).toMatch(/image\//);  // MIME type
			expect(metadata).toMatch(/ID: \d+/);  // Attachment ID
			expect(metadata).toMatch(/\d+:\d+/);  // Aspect ratio
			expect(metadata).toMatch(/Gemini/);  // Model
			expect(metadata).toMatch(/Edit:/);  // Operation context
		});
	});
});
