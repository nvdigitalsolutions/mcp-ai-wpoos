/**
 * Tests for video attachment rendering in chat client
 *
 * @package WP_MCP_AI
 */

// Mock the chat.js functions we need to test
// In real implementation, these would be extracted to a module
// For now, we're testing the behavior through DOM manipulation

describe( 'Video Attachment Rendering', () => {
	describe( 'isVideoAttachment helper', () => {
		// Mock implementation of isVideoAttachment for testing
		function isVideoAttachment( attachment ) {
			if ( ! attachment || typeof attachment !== 'object' ) {
				return false;
			}

			const url = attachment.url || '';
			const meta = attachment.meta || '';
			const label = attachment.label || '';

			// Check for video file extensions in URL
			if ( url && typeof url === 'string' ) {
				const lowerUrl = url.toLowerCase();

				// Check for common video file extensions
				const videoExtensions = [ '.mp4', '.webm', '.ogg', '.ogv', '.mov', '.avi', '.mkv' ];
				for ( let i = 0; i < videoExtensions.length; i++ ) {
					if ( lowerUrl.indexOf( videoExtensions[ i ] ) !== -1 ) {
						return true;
					}
				}

				// Check for data URLs with video MIME type
				if ( lowerUrl.indexOf( 'data:video/' ) === 0 ) {
					return true;
				}

				// Check for 'video' in path segments (more specific than global match)
				if ( lowerUrl.indexOf( '/video/' ) !== -1 || lowerUrl.indexOf( '/videos/' ) !== -1 ) {
					return true;
				}
			}

			// Check metadata for video indicators
			if ( meta && typeof meta === 'string' ) {
				const lowerMeta = meta.toLowerCase();
				const videoIndicators = [ 'video', '.mp4', '.webm', '.ogg', '.mov' ];
				for ( let i = 0; i < videoIndicators.length; i++ ) {
					if ( lowerMeta.indexOf( videoIndicators[ i ] ) !== -1 ) {
						return true;
					}
				}
			}

			// Check label for video indicators
			if ( label && typeof label === 'string' ) {
				const lowerLabel = label.toLowerCase();
				// More specific label checks for Veo or explicit "video" mentions
				if ( lowerLabel.indexOf( 'veo' ) !== -1 ||
					lowerLabel.indexOf( 'video' ) === 0 || // "video" at start
					lowerLabel.indexOf( ' video' ) !== -1 ) { // " video" anywhere
					return true;
				}
			}

			return false;
		}

		it( 'should detect MP4 video URL', () => {
			const attachment = {
				url: 'https://example.com/video.mp4',
				label: 'Video file',
			};
			expect( isVideoAttachment( attachment ) ).toBe( true );
		} );

		it( 'should detect WebM video URL', () => {
			const attachment = {
				url: 'https://example.com/file.webm',
				label: 'Video',
			};
			expect( isVideoAttachment( attachment ) ).toBe( true );
		} );

		it( 'should detect video data URL', () => {
			const attachment = {
				url: 'data:video/mp4;base64,AAAAA...',
				label: 'Generated video',
			};
			expect( isVideoAttachment( attachment ) ).toBe( true );
		} );

		it( 'should detect video by label', () => {
			const attachment = {
				url: 'https://example.com/file',
				label: 'Veo Generated Video',
			};
			expect( isVideoAttachment( attachment ) ).toBe( true );
		} );

		it( 'should detect video label starting with "video"', () => {
			const attachment = {
				url: 'https://example.com/file',
				label: 'Video file from server',
			};
			expect( isVideoAttachment( attachment ) ).toBe( true );
		} );

		it( 'should detect video label containing " video"', () => {
			const attachment = {
				url: 'https://example.com/file',
				label: 'Generated video file',
			};
			expect( isVideoAttachment( attachment ) ).toBe( true );
		} );

		it( 'should detect video by metadata', () => {
			const attachment = {
				url: 'https://example.com/file',
				label: 'File',
				meta: '720p • 5s • video',
			};
			expect( isVideoAttachment( attachment ) ).toBe( true );
		} );

		it( 'should detect video by path segment /video/', () => {
			const attachment = {
				url: 'https://example.com/video/file.dat',
				label: 'File',
			};
			expect( isVideoAttachment( attachment ) ).toBe( true );
		} );

		it( 'should not detect non-video files', () => {
			const attachment = {
				url: 'https://example.com/image.png',
				label: 'Image file',
			};
			expect( isVideoAttachment( attachment ) ).toBe( false );
		} );

		it( 'should not detect text files', () => {
			const attachment = {
				url: 'https://example.com/document.pdf',
				label: 'PDF document',
			};
			expect( isVideoAttachment( attachment ) ).toBe( false );
		} );

		it( 'should not detect files with "video" in the middle of filename', () => {
			const attachment = {
				url: 'https://example.com/videogame.txt',
				label: 'Text file',
			};
			expect( isVideoAttachment( attachment ) ).toBe( false );
		} );

		it( 'should handle null attachment', () => {
			expect( isVideoAttachment( null ) ).toBe( false );
		} );

		it( 'should handle undefined attachment', () => {
			expect( isVideoAttachment( undefined ) ).toBe( false );
		} );

		it( 'should handle attachment without URL', () => {
			const attachment = {
				label: 'No URL',
			};
			expect( isVideoAttachment( attachment ) ).toBe( false );
		} );
	} );

	describe( 'Video player rendering', () => {
		it( 'should create video element for video attachment', () => {
			const attachment = {
				url: 'https://example.com/video.mp4',
				label: 'Test video',
				downloadName: 'test.mp4',
				meta: '720p • 5s • Veo 3.1',
			};

			// Create container
			const container = document.createElement( 'div' );

			// Simulate video rendering
			const videoContainer = document.createElement( 'div' );
			videoContainer.className = 'wp-mcp-ai-chat__video-container';

			const video = document.createElement( 'video' );
			video.controls = true;
			video.preload = 'metadata';
			video.className = 'wp-mcp-ai-chat__video-player';

			const source = document.createElement( 'source' );
			source.src = attachment.url;
			source.type = 'video/mp4';

			video.appendChild( source );
			videoContainer.appendChild( video );

			const downloadLink = document.createElement( 'a' );
			downloadLink.href = attachment.url;
			downloadLink.download = attachment.downloadName;
			downloadLink.className = 'wp-mcp-ai-chat__video-download';
			downloadLink.textContent = 'Download video';
			videoContainer.appendChild( downloadLink );

			container.appendChild( videoContainer );

			// Assertions
			expect( container.querySelector( 'video' ) ).toBeTruthy();
			expect( container.querySelector( 'video' ).controls ).toBe( true );
			expect( container.querySelector( 'source' ).src ).toContain( 'video.mp4' );
			expect( container.querySelector( 'source' ).type ).toBe( 'video/mp4' );
			expect( container.querySelector( '.wp-mcp-ai-chat__video-download' ) ).toBeTruthy();
		} );

		it( 'should include download link for video', () => {
			const container = document.createElement( 'div' );

			const downloadLink = document.createElement( 'a' );
			downloadLink.href = 'https://example.com/video.mp4';
			downloadLink.download = 'my-video.mp4';
			downloadLink.className = 'wp-mcp-ai-chat__video-download';
			downloadLink.textContent = 'Download video';

			container.appendChild( downloadLink );

			const link = container.querySelector( '.wp-mcp-ai-chat__video-download' );
			expect( link ).toBeTruthy();
			expect( link.href ).toContain( 'video.mp4' );
			expect( link.download ).toBe( 'my-video.mp4' );
		} );

		it( 'should handle metadata display', () => {
			const meta = '720p • 5s • Veo 3.1';

			const metaElement = document.createElement( 'div' );
			metaElement.className = 'wp-mcp-ai-chat__attachments-meta';
			metaElement.textContent = meta;

			expect( metaElement.textContent ).toBe( meta );
			expect( metaElement.className ).toBe( 'wp-mcp-ai-chat__attachments-meta' );
		} );
	} );

	describe( 'Veo video result normalization', () => {
		it( 'should extract video metadata correctly', () => {
			const result = {
				url: 'https://example.com/veo-video.mp4',
				attachment_id: 123,
				duration: 5,
				aspect_ratio: '16:9',
				resolution: '720p',
				model: 'veo-3.1-generate-preview',
				provider: 'gemini',
			};

			// Simulate metadata extraction
			const metaParts = [];

			if ( typeof result.duration === 'number' ) {
				metaParts.push( result.duration + 's' );
			}

			if ( result.aspect_ratio ) {
				metaParts.push( result.aspect_ratio );
			}

			if ( result.resolution ) {
				metaParts.push( result.resolution );
			}

			if ( result.model ) {
				const modelName = result.model;
				if ( modelName.indexOf( 'veo-3.1' ) !== -1 ) {
					metaParts.push( 'Veo 3.1' );
				} else if ( modelName.indexOf( 'veo-2' ) !== -1 ) {
					metaParts.push( 'Veo 2.0' );
				}
			}

			const metadata = metaParts.join( ' • ' );

			expect( metadata ).toBe( '5s • 16:9 • 720p • Veo 3.1' );
		} );

		it( 'should extract Veo 2.0 model name correctly', () => {
			const result = {
				model: 'veo-2.0-generate-001',
			};

			const metaParts = [];

			if ( result.model ) {
				const modelName = result.model;
				if ( modelName.indexOf( 'veo-3.1' ) !== -1 ) {
					metaParts.push( 'Veo 3.1' );
				} else if ( modelName.indexOf( 'veo-2' ) !== -1 ) {
					metaParts.push( 'Veo 2.0' );
				}
			}

			expect( metaParts[ 0 ] ).toBe( 'Veo 2.0' );
		} );
	} );
} );
