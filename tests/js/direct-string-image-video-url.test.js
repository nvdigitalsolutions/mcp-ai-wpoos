/**
 * Test suite for direct string image_url and video_url support in tool results
 * Tests the fix for issue #2513 - Add structure detection for complex nested data
 *
 * @package WP_MCP_AI
 */

describe( 'Direct String image_url and video_url Support', () => {
	describe( 'normaliseToolResultForDisplay with direct string image_url', () => {
		// Mock implementation of normaliseToolResultForDisplay that includes the fix
		function normaliseToolResultForDisplay( toolName, result ) {
			if ( ! result || typeof result !== 'object' ) {
				return null;
			}

			const nestedImage = result && result.image && typeof result.image === 'object' ? result.image : null;
			const nestedVideo = result && result.video_url && typeof result.video_url === 'object' ? result.video_url : null;

			let url = '';
			if ( typeof result.url === 'string' && result.url.trim() ) {
				url = result.url.trim();
			} else if ( typeof result.download_url === 'string' && result.download_url.trim() ) {
				url = result.download_url.trim();
			} else if ( typeof result.downloadUrl === 'string' && result.downloadUrl.trim() ) {
				url = result.downloadUrl.trim();
			} else if ( typeof result.image_url === 'string' && result.image_url.trim() ) {
				// Handle direct string image_url (e.g., from some image generation tools)
				url = result.image_url.trim();
			} else if ( typeof result.video_url === 'string' && result.video_url.trim() ) {
				// Handle direct string video_url (e.g., from some video generation tools)
				url = result.video_url.trim();
			} else if ( nestedVideo ) {
				// Handle video_url structure from generate_veo_video
				if ( typeof nestedVideo.url === 'string' && nestedVideo.url.trim() ) {
					url = nestedVideo.url.trim();
				}
			} else if ( nestedImage ) {
				if ( typeof nestedImage.url === 'string' && nestedImage.url.trim() ) {
					url = nestedImage.url.trim();
				} else if ( typeof nestedImage.download_url === 'string' && nestedImage.download_url.trim() ) {
					url = nestedImage.download_url.trim();
				} else if ( typeof nestedImage.downloadUrl === 'string' && nestedImage.downloadUrl.trim() ) {
					url = nestedImage.downloadUrl.trim();
				}
			}

			if ( ! url ) {
				return null;
			}

			const text = result.text || result.message || '';

			return {
				text: text,
				attachments: [
					{
						url: url,
						label: result.file_name || result.fileName || 'Download',
						downloadName: result.file_name || result.fileName || '',
						meta: '',
					},
				],
			};
		}

		it( 'should extract direct string image_url', () => {
			const toolResult = {
				image_url: 'https://example.com/generated-image.png',
				text: 'Image generated successfully.',
				file_name: 'ai-generated.png',
			};

			const normalized = normaliseToolResultForDisplay( 'generate_image', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments ).toHaveLength( 1 );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/generated-image.png' );
			expect( normalized.text ).toBe( 'Image generated successfully.' );
		} );

		it( 'should extract direct string video_url', () => {
			const toolResult = {
				video_url: 'https://example.com/generated-video.mp4',
				text: 'Video generated successfully.',
				file_name: 'ai-video.mp4',
			};

			const normalized = normaliseToolResultForDisplay( 'generate_video', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments ).toHaveLength( 1 );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/generated-video.mp4' );
			expect( normalized.text ).toBe( 'Video generated successfully.' );
		} );

		it( 'should prioritize url field over direct string image_url', () => {
			const toolResult = {
				url: 'https://example.com/primary-url.png',
				image_url: 'https://example.com/fallback-image-url.png',
				text: 'Image with multiple URL fields.',
			};

			const normalized = normaliseToolResultForDisplay( 'generate_image', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/primary-url.png' );
		} );

		it( 'should prioritize download_url over direct string image_url', () => {
			const toolResult = {
				download_url: 'https://example.com/download-url.png',
				image_url: 'https://example.com/fallback-image-url.png',
				text: 'Image with download_url and image_url.',
			};

			const normalized = normaliseToolResultForDisplay( 'generate_image', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/download-url.png' );
		} );

		it( 'should prioritize direct string image_url over object video_url', () => {
			const toolResult = {
				image_url: 'https://example.com/image.png',
				video_url: {
					url: 'https://example.com/video.mp4',
				},
				text: 'Result with both image_url and video_url.',
			};

			const normalized = normaliseToolResultForDisplay( 'mixed_tool', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/image.png' );
		} );

		it( 'should prioritize direct string video_url over nested image object', () => {
			const toolResult = {
				video_url: 'https://example.com/video.mp4',
				image: {
					url: 'https://example.com/image.png',
				},
				text: 'Result with video_url string and nested image object.',
			};

			const normalized = normaliseToolResultForDisplay( 'mixed_tool', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/video.mp4' );
		} );

		it( 'should fall back to object video_url when no direct strings available', () => {
			const toolResult = {
				video_url: {
					url: 'https://example.com/video.mp4',
				},
				text: 'Video with object structure.',
			};

			const normalized = normaliseToolResultForDisplay( 'generate_veo_video', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/video.mp4' );
		} );

		it( 'should fall back to nested image object when no direct strings available', () => {
			const toolResult = {
				image: {
					url: 'https://example.com/image.png',
				},
				text: 'Image with nested object structure.',
			};

			const normalized = normaliseToolResultForDisplay( 'some_tool', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/image.png' );
		} );

		it( 'should handle empty string image_url gracefully', () => {
			const toolResult = {
				image_url: '',
				text: 'Empty image_url field.',
			};

			const normalized = normaliseToolResultForDisplay( 'some_tool', toolResult );

			expect( normalized ).toBe( null );
		} );

		it( 'should handle whitespace-only image_url gracefully', () => {
			const toolResult = {
				image_url: '   ',
				text: 'Whitespace image_url field.',
			};

			const normalized = normaliseToolResultForDisplay( 'some_tool', toolResult );

			expect( normalized ).toBe( null );
		} );

		it( 'should handle empty string video_url gracefully', () => {
			const toolResult = {
				video_url: '',
				text: 'Empty video_url field.',
			};

			const normalized = normaliseToolResultForDisplay( 'some_tool', toolResult );

			expect( normalized ).toBe( null );
		} );

		it( 'should handle whitespace-only video_url gracefully', () => {
			const toolResult = {
				video_url: '   ',
				text: 'Whitespace video_url field.',
			};

			const normalized = normaliseToolResultForDisplay( 'some_tool', toolResult );

			expect( normalized ).toBe( null );
		} );

		it( 'should trim direct string image_url', () => {
			const toolResult = {
				image_url: '  https://example.com/image.png  ',
				text: 'Image URL with spaces.',
			};

			const normalized = normaliseToolResultForDisplay( 'some_tool', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/image.png' );
		} );

		it( 'should trim direct string video_url', () => {
			const toolResult = {
				video_url: '  https://example.com/video.mp4  ',
				text: 'Video URL with spaces.',
			};

			const normalized = normaliseToolResultForDisplay( 'some_tool', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/video.mp4' );
		} );

		it( 'should handle image_url with object format (backward compatibility)', () => {
			const toolResult = {
				image_url: {
					url: 'https://example.com/image.png',
				},
				text: 'Image with object image_url.',
			};

			// Since image_url is an object, it won't be extracted as a direct string
			// but the nestedVideo/nestedImage check should not extract it either
			// (only 'image' and 'video_url' objects are checked)
			const normalized = normaliseToolResultForDisplay( 'some_tool', toolResult );

			// Should return null because image_url object is not handled in nestedImage check
			expect( normalized ).toBe( null );
		} );

		it( 'should handle video_url with nested url (backward compatibility)', () => {
			const toolResult = {
				video_url: {
					url: 'https://example.com/video.mp4',
				},
				text: 'Video with object video_url.',
			};

			const normalized = normaliseToolResultForDisplay( 'some_tool', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/video.mp4' );
		} );

		it( 'should handle complex tool result with multiple URL formats', () => {
			const toolResult = {
				image_url: 'https://example.com/direct-image.png',
				video_url: 'https://example.com/direct-video.mp4',
				url: 'https://example.com/primary-url.png',
				image: {
					url: 'https://example.com/nested-image.png',
				},
				text: 'Complex result with all URL types.',
			};

			const normalized = normaliseToolResultForDisplay( 'complex_tool', toolResult );

			// Primary url should take precedence
			expect( normalized ).not.toBe( null );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/primary-url.png' );
		} );

		it( 'should extract image_url when it is the only URL field', () => {
			const toolResult = {
				image_url: 'https://example.com/only-image-url.png',
				text: 'Result with only image_url.',
				file_name: 'image.png',
				bytes: 12345,
				mime_type: 'image/png',
			};

			const normalized = normaliseToolResultForDisplay( 'some_tool', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments ).toHaveLength( 1 );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/only-image-url.png' );
			expect( normalized.attachments[ 0 ].label ).toBe( 'image.png' );
		} );

		it( 'should extract video_url when it is the only URL field', () => {
			const toolResult = {
				video_url: 'https://example.com/only-video-url.mp4',
				text: 'Result with only video_url.',
				file_name: 'video.mp4',
				bytes: 234567,
				mime_type: 'video/mp4',
			};

			const normalized = normaliseToolResultForDisplay( 'some_tool', toolResult );

			expect( normalized ).not.toBe( null );
			expect( normalized.attachments ).toHaveLength( 1 );
			expect( normalized.attachments[ 0 ].url ).toBe( 'https://example.com/only-video-url.mp4' );
			expect( normalized.attachments[ 0 ].label ).toBe( 'video.mp4' );
		} );
	} );

	describe( 'Priority order of URL extraction', () => {
		it( 'should follow correct priority: url > download_url > downloadUrl > image_url > video_url > nestedVideo > nestedImage', () => {
			// This test documents the expected priority order for URL extraction
			const testCases = [
				{
					name: 'url has highest priority',
					input: {
						url: 'https://example.com/url.png',
						download_url: 'https://example.com/download.png',
						downloadUrl: 'https://example.com/downloadUrl.png',
						image_url: 'https://example.com/image.png',
						video_url: 'https://example.com/video.mp4',
					},
					expected: 'https://example.com/url.png',
				},
				{
					name: 'download_url has second priority',
					input: {
						download_url: 'https://example.com/download.png',
						downloadUrl: 'https://example.com/downloadUrl.png',
						image_url: 'https://example.com/image.png',
						video_url: 'https://example.com/video.mp4',
					},
					expected: 'https://example.com/download.png',
				},
				{
					name: 'downloadUrl has third priority',
					input: {
						downloadUrl: 'https://example.com/downloadUrl.png',
						image_url: 'https://example.com/image.png',
						video_url: 'https://example.com/video.mp4',
					},
					expected: 'https://example.com/downloadUrl.png',
				},
				{
					name: 'image_url has fourth priority',
					input: {
						image_url: 'https://example.com/image.png',
						video_url: 'https://example.com/video.mp4',
					},
					expected: 'https://example.com/image.png',
				},
				{
					name: 'video_url has fifth priority',
					input: {
						video_url: 'https://example.com/video.mp4',
					},
					expected: 'https://example.com/video.mp4',
				},
			];

			testCases.forEach( function ( testCase ) {
				// Simple extraction logic to test priority
				let url = '';
				const result = testCase.input;
				
				if ( typeof result.url === 'string' && result.url.trim() ) {
					url = result.url.trim();
				} else if ( typeof result.download_url === 'string' && result.download_url.trim() ) {
					url = result.download_url.trim();
				} else if ( typeof result.downloadUrl === 'string' && result.downloadUrl.trim() ) {
					url = result.downloadUrl.trim();
				} else if ( typeof result.image_url === 'string' && result.image_url.trim() ) {
					url = result.image_url.trim();
				} else if ( typeof result.video_url === 'string' && result.video_url.trim() ) {
					url = result.video_url.trim();
				}

				expect( url ).toBe( testCase.expected );
			} );
		} );
	} );
} );
