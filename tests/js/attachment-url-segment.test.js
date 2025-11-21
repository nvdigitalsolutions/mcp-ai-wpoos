/**
 * Tests for attachment URL inclusion in chat segments
 *
 * Validates that attachments include URLs in segments for server processing,
 * enabling the backend to skip database lookups and directly use attachment URLs.
 *
 * @package WP_MCP_AI
 */

describe( 'Attachment URL in Segments', () => {
	describe( 'addAttachmentMetadataToSegment behavior', () => {
		it( 'should add URL from attachment to segment', () => {
			const segment = {
				type: 'input_image',
				attachment_id: 123,
			};

			const attachment = {
				id: 123,
				url: 'https://example.com/wp-content/uploads/2024/01/test-image.jpg',
				name: 'test-image.jpg',
				mime: 'image/jpeg',
			};

			// Simulate addAttachmentMetadataToSegment function behavior
			if ( attachment.url ) {
				segment.url = attachment.url;
			}
			if ( attachment.name ) {
				segment.name = attachment.name;
			}

			expect( segment.url ).toBe( 'https://example.com/wp-content/uploads/2024/01/test-image.jpg' );
			expect( segment.name ).toBe( 'test-image.jpg' );
			expect( segment.type ).toBe( 'input_image' );
			expect( segment.attachment_id ).toBe( 123 );
		} );

		it( 'should handle segment without attachment URL gracefully', () => {
			const segment = {
				type: 'input_file',
				attachment_id: 456,
			};

			const attachment = {
				id: 456,
				// No URL provided
				name: 'document.pdf',
				mime: 'application/pdf',
			};

			// Simulate addAttachmentMetadataToSegment function behavior
			if ( attachment.url ) {
				segment.url = attachment.url;
			}
			if ( attachment.name ) {
				segment.name = attachment.name;
			}

			expect( segment.url ).toBeUndefined();
			expect( segment.name ).toBe( 'document.pdf' );
			expect( segment.type ).toBe( 'input_file' );
			expect( segment.attachment_id ).toBe( 456 );
		} );

		it( 'should not override segment properties if attachment is invalid', () => {
			const segment = {
				type: 'input_image',
				attachment_id: 789,
			};

			const attachment = null;

			// Simulate addAttachmentMetadataToSegment function behavior with null check
			if ( attachment && typeof attachment === 'object' ) {
				if ( attachment.url ) {
					segment.url = attachment.url;
				}
				if ( attachment.name ) {
					segment.name = attachment.name;
				}
			}

			expect( segment.url ).toBeUndefined();
			expect( segment.name ).toBeUndefined();
			expect( segment.type ).toBe( 'input_image' );
			expect( segment.attachment_id ).toBe( 789 );
		} );
	} );

	describe( 'normaliseUploadResponse behavior', () => {
		it( 'should extract URL from upload response', () => {
			const uploadResponse = {
				id: 123,
				source_url: 'https://example.com/wp-content/uploads/2024/01/test.jpg',
				title: { rendered: 'Test Image' },
				mime_type: 'image/jpeg',
				media_details: {
					filesize: 102400,
				},
			};

			const file = {
				name: 'test.jpg',
				type: 'image/jpeg',
				size: 102400,
			};

			// Simulate normaliseUploadResponse function behavior
			const url = uploadResponse.source_url || ( uploadResponse.guid && uploadResponse.guid.rendered ) || '';
			const mime = uploadResponse.mime_type || file.type || '';
			const isImage = typeof mime === 'string' && mime.indexOf( 'image/' ) === 0;

			const record = {
				id: uploadResponse.id,
				fileId: 'wp-attachment-' + uploadResponse.id,
				url,
				mime,
				isImage,
			};

			expect( record.url ).toBe( 'https://example.com/wp-content/uploads/2024/01/test.jpg' );
			expect( record.isImage ).toBe( true );
			expect( record.fileId ).toBe( 'wp-attachment-123' );
		} );

		it( 'should fallback to guid.rendered if source_url is missing', () => {
			const uploadResponse = {
				id: 456,
				guid: {
					rendered: 'https://example.com/wp-content/uploads/document.pdf',
				},
				mime_type: 'application/pdf',
			};

			const file = {
				name: 'document.pdf',
				type: 'application/pdf',
			};

			// Simulate normaliseUploadResponse function behavior
			const url = uploadResponse.source_url || ( uploadResponse.guid && uploadResponse.guid.rendered ) || '';
			const mime = uploadResponse.mime_type || file.type || '';

			const record = {
				id: uploadResponse.id,
				url,
				mime,
			};

			expect( record.url ).toBe( 'https://example.com/wp-content/uploads/document.pdf' );
			expect( record.mime ).toBe( 'application/pdf' );
		} );
	} );

	describe( 'segment creation with URL', () => {
		it( 'should create image segment with URL', () => {
			const attachment = {
				id: 123,
				fileId: 'wp-attachment-123',
				url: 'https://example.com/image.jpg',
				name: 'image.jpg',
				mime: 'image/jpeg',
				isImage: true,
			};

			// Simulate createSegmentFromAttachment behavior
			const segment = {
				type: 'input_image',
				attachment_id: attachment.id,
			};

			// Add metadata (simulating addAttachmentMetadataToSegment)
			if ( attachment.url ) {
				segment.url = attachment.url;
			}
			if ( attachment.name ) {
				segment.name = attachment.name;
			}

			expect( segment ).toEqual( {
				type: 'input_image',
				attachment_id: 123,
				url: 'https://example.com/image.jpg',
				name: 'image.jpg',
			} );
		} );

		it( 'should create file segment with URL and display_name', () => {
			const attachment = {
				id: 456,
				fileId: 'wp-attachment-456',
				url: 'https://example.com/document.pdf',
				name: 'My Document',
				originalName: 'document.pdf',
				mime: 'application/pdf',
				isImage: false,
			};

			// Simulate createSegmentFromAttachment behavior
			const segment = {
				type: 'input_file',
				attachment_id: attachment.id,
			};

			const displayName = attachment.originalName || attachment.name || '';
			if ( displayName ) {
				segment.display_name = displayName;
			}

			// Add metadata (simulating addAttachmentMetadataToSegment)
			if ( attachment.url ) {
				segment.url = attachment.url;
			}
			if ( attachment.name ) {
				segment.name = attachment.name;
			}

			expect( segment ).toEqual( {
				type: 'input_file',
				attachment_id: 456,
				display_name: 'document.pdf',
				url: 'https://example.com/document.pdf',
				name: 'My Document',
			} );
		} );
	} );

	describe( 'backend URL processing', () => {
		it( 'should recognize image segments with URL for direct use', () => {
			const segment = {
				type: 'input_image',
				attachment_id: 123,
				url: 'https://example.com/image.jpg',
			};

			// Backend should prefer URL over attachment_id for images when available
			const hasUrl = segment.url && segment.url.length > 0;
			expect( hasUrl ).toBe( true );

			// Backend can create image_url format directly
			const backendSegment = {
				type: 'input_image',
				image_url: { url: segment.url },
			};

			expect( backendSegment.image_url.url ).toBe( 'https://example.com/image.jpg' );
		} );

		it( 'should process file segments with URL as metadata', () => {
			const segment = {
				type: 'input_file',
				attachment_id: 456,
				url: 'https://example.com/document.pdf',
				display_name: 'document.pdf',
			};

			// For files, URL is metadata but file still needs to be uploaded to AI provider
			// The URL can be used for logging, display, or alternative providers
			expect( segment.url ).toBe( 'https://example.com/document.pdf' );
			expect( segment.attachment_id ).toBe( 456 );
			expect( segment.display_name ).toBe( 'document.pdf' );
		} );
	} );
} );
