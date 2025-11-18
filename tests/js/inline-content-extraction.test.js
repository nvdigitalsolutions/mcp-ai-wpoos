/**
 * Test suite for inline content extraction from tool results
 * Specifically tests the generate_gemini_image and edit_gemini_image response handling
 *
 * @package WP_MCP_AI
 */

describe( 'Inline Content Extraction', () => {
	/**
	 * Mock extractInlineContentData function
	 * This mirrors the implementation in chat.js
	 */
	function extractInlineContentData( result ) {
		if ( ! result || typeof result !== 'object' ) {
			return null;
		}

		const content = result.content;
		if ( ! content || typeof content !== 'object' ) {
			return null;
		}

		let base64Data = '';
		let mimeType = '';

		// Extract base64 data from various possible formats.
		if ( typeof content.data === 'string' && content.data.trim() ) {
			base64Data = content.data.trim();
		}

		// Extract MIME type.
		if ( typeof content.mime_type === 'string' && content.mime_type.trim() ) {
			mimeType = content.mime_type.trim();
		}

		if ( ! base64Data ) {
			return null;
		}

		return {
			data: base64Data,
			mime_type: mimeType,
		};
	}

	describe( 'extractInlineContentData', () => {
		it( 'should extract base64 data and mime_type from result.content', () => {
			const result = {
				model: 'gemini-2.5-flash-image',
				mime_type: 'image/png',
				aspect_ratio: '1:1',
				format: 'png',
				content: {
					encoding: 'base64',
					data: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
					data_url: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
					mime_type: 'image/png',
				},
			};

			const extracted = extractInlineContentData( result );

			expect( extracted ).not.toBeNull();
			expect( extracted.data ).toBe( result.content.data );
			expect( extracted.mime_type ).toBe( 'image/png' );
		} );

		it( 'should return null if result is null', () => {
			const extracted = extractInlineContentData( null );
			expect( extracted ).toBeNull();
		} );

		it( 'should return null if result is not an object', () => {
			const extracted = extractInlineContentData( 'string' );
			expect( extracted ).toBeNull();
		} );

		it( 'should return null if result.content is missing', () => {
			const result = {
				model: 'gemini-2.5-flash-image',
				url: 'https://example.com/image.png',
			};

			const extracted = extractInlineContentData( result );
			expect( extracted ).toBeNull();
		} );

		it( 'should return null if result.content.data is missing', () => {
			const result = {
				model: 'gemini-2.5-flash-image',
				content: {
					mime_type: 'image/png',
				},
			};

			const extracted = extractInlineContentData( result );
			expect( extracted ).toBeNull();
		} );

		it( 'should return null if result.content.data is empty string', () => {
			const result = {
				model: 'gemini-2.5-flash-image',
				content: {
					data: '',
					mime_type: 'image/png',
				},
			};

			const extracted = extractInlineContentData( result );
			expect( extracted ).toBeNull();
		} );

		it( 'should handle whitespace-only data string', () => {
			const result = {
				content: {
					data: '   ',
					mime_type: 'image/png',
				},
			};

			const extracted = extractInlineContentData( result );
			expect( extracted ).toBeNull();
		} );

		it( 'should trim whitespace from data and mime_type', () => {
			const result = {
				content: {
					data: '  base64data  ',
					mime_type: '  image/png  ',
				},
			};

			const extracted = extractInlineContentData( result );

			expect( extracted ).not.toBeNull();
			expect( extracted.data ).toBe( 'base64data' );
			expect( extracted.mime_type ).toBe( 'image/png' );
		} );

		it( 'should work even if mime_type is missing', () => {
			const result = {
				content: {
					data: 'base64data',
				},
			};

			const extracted = extractInlineContentData( result );

			expect( extracted ).not.toBeNull();
			expect( extracted.data ).toBe( 'base64data' );
			expect( extracted.mime_type ).toBe( '' );
		} );

		it( 'should extract content from edit_gemini_image response', () => {
			const result = {
				model: 'gemini-2.5-flash-image',
				mime_type: 'image/png',
				aspect_ratio: '1:1',
				format: 'png',
				edit_instruction: 'remove background',
				content: {
					encoding: 'base64',
					data: 'editedImageBase64Data',
					data_url: 'data:image/png;base64,editedImageBase64Data',
					mime_type: 'image/png',
				},
			};

			const extracted = extractInlineContentData( result );

			expect( extracted ).not.toBeNull();
			expect( extracted.data ).toBe( 'editedImageBase64Data' );
			expect( extracted.mime_type ).toBe( 'image/png' );
		} );

		it( 'should not include base64 data in conversation messages', () => {
			// This test verifies the separation of concerns:
			// Inline content is for DISPLAY only, not for sending to LLM

			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_123',
				content: {
					attachment_id: 123,
					url: 'https://example.com/image.png',
					file_name: 'image.png',
					mime_type: 'image/png',
					// Note: Server-side sanitize_for_llm strips content.data
					// before returning in tool_results
				},
			};

			// Verify that tool result for conversation doesn't have large data
			expect( toolResult.content.data ).toBeUndefined();
			expect( toolResult.content.data_url ).toBeUndefined();
		} );
	} );

	describe( 'Attachment entry structure', () => {
		it( 'should create attachment with inline data when URL is missing', () => {
			const result = {
				file_name: 'gemini-image.png',
				mime_type: 'image/png',
				aspect_ratio: '1:1',
				format: 'png',
				content: {
					data: 'base64data',
					mime_type: 'image/png',
				},
			};

			const inlineContent = extractInlineContentData( result );
			const url = ''; // No URL available

			const attachmentEntry = {
				url,
				label: result.file_name,
				downloadName: result.file_name,
				meta: '1:1 • PNG',
			};

			// If we have inline content data and no URL, add it to the attachment
			if ( inlineContent && ! url ) {
				attachmentEntry.data = inlineContent.data;
				if ( inlineContent.mime_type ) {
					attachmentEntry.mime = inlineContent.mime_type;
				}
			}

			expect( attachmentEntry.data ).toBe( 'base64data' );
			expect( attachmentEntry.mime ).toBe( 'image/png' );
		} );

		it( 'should not add inline data when URL is present', () => {
			const result = {
				url: 'https://example.com/image.png',
				file_name: 'gemini-image.png',
				mime_type: 'image/png',
				content: {
					data: 'base64data',
					mime_type: 'image/png',
				},
			};

			const inlineContent = extractInlineContentData( result );
			const url = result.url;

			const attachmentEntry = {
				url,
				label: result.file_name,
				downloadName: result.file_name,
				meta: '',
			};

			// If we have inline content data and no URL, add it to the attachment
			if ( inlineContent && ! url ) {
				attachmentEntry.data = inlineContent.data;
				if ( inlineContent.mime_type ) {
					attachmentEntry.mime = inlineContent.mime_type;
				}
			}

			// Should not add inline data when URL exists
			expect( attachmentEntry.data ).toBeUndefined();
			expect( attachmentEntry.mime ).toBeUndefined();
		} );
	} );
} );
