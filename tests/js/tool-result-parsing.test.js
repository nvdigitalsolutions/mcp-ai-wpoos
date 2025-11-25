/**
 * Test suite for tool result parsing and display
 * Tests the fix for issue #1429 where images weren't surfacing in chat
 *
 * @package WP_MCP_AI
 */

describe( 'Tool Result Parsing for Chat Display', () => {
	describe( 'JSON string parsing from tool results', () => {
		it( 'should parse JSON string tool result content', () => {
			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_123',
				name: 'generate_openai_image',
				content: JSON.stringify( {
					attachment_id: 42,
					url: 'https://example.com/image.png',
					file_name: 'generated-image.png',
					mime_type: 'image/png',
					bytes: 12345,
					text: 'Successfully generated image (ID: 42).',
					size: '1024x1024',
					quality: 'medium',
				} ),
			};

			// Parse the content string
			let parsedContent = toolResult.content;
			if ( typeof parsedContent === 'string' ) {
				try {
					parsedContent = JSON.parse( parsedContent );
				} catch ( e ) {
					// If parsing fails, use the string as-is
					parsedContent = toolResult.content;
				}
			}

			expect( typeof parsedContent ).toBe( 'object' );
			expect( parsedContent.attachment_id ).toBe( 42 );
			expect( parsedContent.url ).toBe( 'https://example.com/image.png' );
			expect( parsedContent.text ).toBe( 'Successfully generated image (ID: 42).' );
		} );

		it( 'should handle already-parsed object content', () => {
			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_456',
				name: 'generate_gemini_image',
				content: {
					attachment_id: 99,
					url: 'https://example.com/gemini.png',
					text: 'Gemini image saved.',
				},
			};

			// This simulates the parsing logic - if it's already an object, no parsing needed
			let parsedContent = toolResult.content;
			if ( typeof parsedContent === 'string' ) {
				try {
					parsedContent = JSON.parse( parsedContent );
				} catch ( e ) {
					parsedContent = toolResult.content;
				}
			}

			expect( typeof parsedContent ).toBe( 'object' );
			expect( parsedContent.attachment_id ).toBe( 99 );
			expect( parsedContent.text ).toBe( 'Gemini image saved.' );
		} );

		it( 'should handle malformed JSON gracefully', () => {
			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_789',
				name: 'some_tool',
				content: '{ invalid json',
			};

			// Parse with error handling
			let parsedContent = toolResult.content;
			if ( typeof parsedContent === 'string' ) {
				try {
					parsedContent = JSON.parse( parsedContent );
				} catch ( e ) {
					// If parsing fails, use the string as-is
					parsedContent = toolResult.content;
				}
			}

			// Should fall back to the original string
			expect( parsedContent ).toBe( '{ invalid json' );
		} );

		it( 'should handle empty or null content', () => {
			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_000',
				name: 'some_tool',
				content: null,
			};

			let parsedContent = toolResult.content;
			if ( typeof parsedContent === 'string' ) {
				try {
					parsedContent = JSON.parse( parsedContent );
				} catch ( e ) {
					parsedContent = toolResult.content;
				}
			}

			expect( parsedContent ).toBe( null );
		} );
	} );

	describe( 'Tool result text extraction', () => {
		it( 'should extract text field from parsed tool result', () => {
			const parsedResult = {
				attachment_id: 42,
				url: 'https://example.com/image.png',
				text: 'Successfully generated image (ID: 42).',
			};

			// Simulate the text extraction logic
			const extractedText = parsedResult.text && typeof parsedResult.text === 'string' ? parsedResult.text : '';

			expect( extractedText ).toBe( 'Successfully generated image (ID: 42).' );
		} );

		it( 'should handle missing text field', () => {
			const parsedResult = {
				attachment_id: 42,
				url: 'https://example.com/image.png',
			};

			const extractedText = parsedResult.text && typeof parsedResult.text === 'string' ? parsedResult.text : '';

			expect( extractedText ).toBe( '' );
		} );

		it( 'should combine multiple tool result texts', () => {
			let assistantText = 'Initial response from assistant.';
			const toolResultText = 'Successfully generated image (ID: 42).';

			// Simulate the combining logic from the fix
			if ( toolResultText ) {
				if ( assistantText ) {
					assistantText += '\n\n' + toolResultText;
				} else {
					assistantText = toolResultText;
				}
			}

			expect( assistantText ).toBe( 'Initial response from assistant.\n\nSuccessfully generated image (ID: 42).' );
		} );

		it( 'should set text when assistant text is empty', () => {
			let assistantText = '';
			const toolResultText = 'Successfully generated image (ID: 42).';

			if ( toolResultText ) {
				if ( assistantText ) {
					assistantText += '\n\n' + toolResultText;
				} else {
					assistantText = toolResultText;
				}
			}

			expect( assistantText ).toBe( 'Successfully generated image (ID: 42).' );
		} );
	} );

	describe( 'Tool result attachment extraction', () => {
		it( 'should extract attachment data from parsed result', () => {
			const parsedResult = {
				attachment_id: 42,
				url: 'https://example.com/image.png',
				file_name: 'generated-image.png',
				mime_type: 'image/png',
				bytes: 12345,
				text: 'Successfully generated image.',
			};

			// Simulate extracting attachment fields
			const hasAttachmentData = Boolean( parsedResult.url || parsedResult.attachment_id );
			expect( hasAttachmentData ).toBe( true );

			const attachmentId = typeof parsedResult.attachment_id === 'number' ? parsedResult.attachment_id : null;
			expect( attachmentId ).toBe( 42 );

			const url = typeof parsedResult.url === 'string' ? parsedResult.url.trim() : '';
			expect( url ).toBe( 'https://example.com/image.png' );
		} );
	} );

	describe( 'Site Health tool result extraction', () => {
		// Mock implementation of the functions from chat.js
		function extractSiteHealthBadges( tests ) {
			if ( ! tests || typeof tests !== 'object' ) {
				return [];
			}

			const badgeInfo = [];

			// Check for badges in critical tests
			if ( Array.isArray( tests.critical ) ) {
				tests.critical.forEach( function ( test ) {
					if ( test && test.badge && typeof test.badge.label === 'string' && test.badge.label.trim() ) {
						badgeInfo.push( test.badge.label.trim() );
					}
				} );
			}

			// Check for badges in warning tests
			if ( Array.isArray( tests.warning ) ) {
				tests.warning.forEach( function ( test ) {
					if ( test && test.badge && typeof test.badge.label === 'string' && test.badge.label.trim() ) {
						badgeInfo.push( test.badge.label.trim() );
					}
				} );
			}

			// Return unique badge labels
			const uniqueBadges = [];
			badgeInfo.forEach( function ( badge ) {
				if ( uniqueBadges.indexOf( badge ) === -1 ) {
					uniqueBadges.push( badge );
				}
			} );

			return uniqueBadges;
		}

		function isSiteHealthStructure( result ) {
			return result && 
				typeof result === 'object' &&
				typeof result.summary === 'object' && 
				result.summary !== null &&
				typeof result.tests === 'object' && 
				result.tests !== null;
		}

		function extractSiteHealthSummary( result ) {
			if ( ! isSiteHealthStructure( result ) ) {
				return null;
			}

			const summary = result.summary;
			const tests = result.tests;
			const parts = [];

			// Build summary count string
			if ( typeof summary.critical === 'number' ) {
				parts.push( summary.critical + ' critical' );
			}
			if ( typeof summary.warning === 'number' ) {
				parts.push( summary.warning + ' warning' + ( summary.warning !== 1 ? 's' : '' ) );
			}
			if ( typeof summary.pass === 'number' ) {
				parts.push( summary.pass + ' passing' );
			}

			if ( parts.length === 0 ) {
				return null;
			}

			let text = 'Site Health: ' + parts.join( ', ' );

			// Extract badge information from tests
			const badgeLabels = extractSiteHealthBadges( tests );
			if ( badgeLabels.length > 0 ) {
				text += ' [' + badgeLabels.join( ', ' ) + ']';
			}

			return text;
		}

		it( 'should extract summary with all counts', () => {
			const result = {
				summary: {
					critical: 0,
					warning: 1,
					pass: 18,
				},
				tests: {
					critical: [],
					warning: [],
					pass: [],
				},
			};

			const summary = extractSiteHealthSummary( result );
			expect( summary ).toBe( 'Site Health: 0 critical, 1 warning, 18 passing' );
		} );

		it( 'should handle plural warnings correctly', () => {
			const result = {
				summary: {
					critical: 0,
					warning: 2,
					pass: 17,
				},
				tests: {
					critical: [],
					warning: [],
					pass: [],
				},
			};

			const summary = extractSiteHealthSummary( result );
			expect( summary ).toBe( 'Site Health: 0 critical, 2 warnings, 17 passing' );
		} );

		it( 'should handle singular warning correctly', () => {
			const result = {
				summary: {
					critical: 0,
					warning: 1,
					pass: 18,
				},
				tests: {
					critical: [],
					warning: [],
					pass: [],
				},
			};

			const summary = extractSiteHealthSummary( result );
			expect( summary ).toBe( 'Site Health: 0 critical, 1 warning, 18 passing' );
		} );

		it( 'should extract badges from critical tests', () => {
			const result = {
				summary: {
					critical: 1,
					warning: 0,
					pass: 18,
				},
				tests: {
					critical: [
						{
							test: 'auto_updates',
							status: 'critical',
							label: 'Automatic updates disabled',
							badge: {
								label: 'Security',
								color: 'red',
							},
						},
					],
					warning: [],
					pass: [],
				},
			};

			const summary = extractSiteHealthSummary( result );
			expect( summary ).toBe( 'Site Health: 1 critical, 0 warnings, 18 passing [Security]' );
		} );

		it( 'should extract badges from warning tests', () => {
			const result = {
				summary: {
					critical: 0,
					warning: 1,
					pass: 18,
				},
				tests: {
					critical: [],
					warning: [
						{
							test: 'persistent_object_cache',
							status: 'recommended',
							label: 'Persistent object cache',
							badge: {
								label: 'Performance',
								color: 'blue',
							},
						},
					],
					pass: [],
				},
			};

			const summary = extractSiteHealthSummary( result );
			expect( summary ).toBe( 'Site Health: 0 critical, 1 warning, 18 passing [Performance]' );
		} );

		it( 'should extract and deduplicate multiple badges', () => {
			const result = {
				summary: {
					critical: 1,
					warning: 2,
					pass: 16,
				},
				tests: {
					critical: [
						{
							badge: {
								label: 'Security',
								color: 'red',
							},
						},
					],
					warning: [
						{
							badge: {
								label: 'Performance',
								color: 'blue',
							},
						},
						{
							badge: {
								label: 'Security',
								color: 'orange',
							},
						},
					],
					pass: [],
				},
			};

			const summary = extractSiteHealthSummary( result );
			// Should have unique badges only (Security appears twice but should only be listed once)
			expect( summary ).toBe( 'Site Health: 1 critical, 2 warnings, 16 passing [Security, Performance]' );
		} );

		it( 'should handle tests without badges', () => {
			const result = {
				summary: {
					critical: 0,
					warning: 1,
					pass: 18,
				},
				tests: {
					critical: [],
					warning: [
						{
							test: 'some_test',
							status: 'recommended',
							label: 'Some test',
							// No badge property
						},
					],
					pass: [],
				},
			};

			const summary = extractSiteHealthSummary( result );
			expect( summary ).toBe( 'Site Health: 0 critical, 1 warning, 18 passing' );
		} );

		it( 'should return null for invalid structure', () => {
			const result = {
				// Missing summary or tests
				something: 'else',
			};

			const summary = extractSiteHealthSummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should return null when summary is not an object', () => {
			const result = {
				summary: 'some string',
				tests: {},
			};

			const summary = extractSiteHealthSummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should return null when tests is missing', () => {
			const result = {
				summary: {
					critical: 0,
					warning: 1,
					pass: 18,
				},
				// Missing tests property
			};

			const summary = extractSiteHealthSummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should handle empty badge labels gracefully', () => {
			const result = {
				summary: {
					critical: 0,
					warning: 1,
					pass: 18,
				},
				tests: {
					critical: [],
					warning: [
						{
							badge: {
								label: '',
								color: 'blue',
							},
						},
					],
					pass: [],
				},
			};

			const summary = extractSiteHealthSummary( result );
			// Empty badge labels should be ignored
			expect( summary ).toBe( 'Site Health: 0 critical, 1 warning, 18 passing' );
		} );

		it( 'should not display [object Object] for site health results', () => {
			const result = {
				summary: {
					critical: 0,
					warning: 1,
					pass: 18,
				},
				tests: {
					critical: [],
					warning: [],
					pass: [],
				},
			};

			const summary = extractSiteHealthSummary( result );
			
			// This is the main fix: should NOT contain [object Object]
			expect( summary ).not.toContain( '[object Object]' );
			expect( summary ).toContain( 'Site Health' );
			expect( typeof summary ).toBe( 'string' );
		} );
	} );

	describe( 'Site Security tool result extraction', () => {
		// Mock implementation of the functions from chat.js
		function isSiteSecurityStructure( result ) {
			return result && 
				typeof result === 'object' &&
				typeof result.summary === 'object' && 
				result.summary !== null &&
				typeof result.checks === 'object' && 
				result.checks !== null;
		}

		function extractSiteSecuritySummary( result ) {
			if ( ! isSiteSecurityStructure( result ) ) {
				return null;
			}

			const summary = result.summary;
			const parts = [];

			// Build summary count string
			if ( typeof summary.critical === 'number' ) {
				parts.push( summary.critical + ' critical' );
			}
			if ( typeof summary.warning === 'number' ) {
				parts.push( summary.warning + ' warning' + ( summary.warning !== 1 ? 's' : '' ) );
			}
			if ( typeof summary.pass === 'number' ) {
				parts.push( summary.pass + ' passing' );
			}

			if ( parts.length === 0 ) {
				return null;
			}

			// Add risk level if available
			let text = 'Security';
			if ( typeof result.risk_level === 'string' && result.risk_level.trim() ) {
				text += ': ' + result.risk_level.toUpperCase() + ' risk';
			}
			text += ' - ' + parts.join( ', ' );

			return text;
		}

		it( 'should extract summary with risk level', () => {
			const result = {
				risk_level: 'low',
				is_safe_to_use: true,
				recommendation: 'Site security looks good.',
				summary: {
					critical: 0,
					warning: 1,
					pass: 7,
					total: 8,
				},
				checks: {
					https: { name: 'HTTPS', status: 'pass', severity: 'pass' },
				},
			};

			const summary = extractSiteSecuritySummary( result );
			expect( summary ).toBe( 'Security: LOW risk - 0 critical, 1 warning, 7 passing' );
		} );

		it( 'should handle high risk security issues', () => {
			const result = {
				risk_level: 'high',
				is_safe_to_use: false,
				summary: {
					critical: 2,
					warning: 1,
					pass: 5,
					total: 8,
				},
				checks: {},
			};

			const summary = extractSiteSecuritySummary( result );
			expect( summary ).toBe( 'Security: HIGH risk - 2 critical, 1 warning, 5 passing' );
		} );

		it( 'should handle plural warnings correctly', () => {
			const result = {
				risk_level: 'medium',
				summary: {
					critical: 1,
					warning: 3,
					pass: 4,
				},
				checks: {},
			};

			const summary = extractSiteSecuritySummary( result );
			expect( summary ).toBe( 'Security: MEDIUM risk - 1 critical, 3 warnings, 4 passing' );
		} );

		it( 'should handle singular warning correctly', () => {
			const result = {
				risk_level: 'low',
				summary: {
					critical: 0,
					warning: 1,
					pass: 7,
				},
				checks: {},
			};

			const summary = extractSiteSecuritySummary( result );
			expect( summary ).toBe( 'Security: LOW risk - 0 critical, 1 warning, 7 passing' );
		} );

		it( 'should work without risk_level', () => {
			const result = {
				summary: {
					critical: 0,
					warning: 0,
					pass: 8,
				},
				checks: {},
			};

			const summary = extractSiteSecuritySummary( result );
			expect( summary ).toBe( 'Security - 0 critical, 0 warnings, 8 passing' );
		} );

		it( 'should return null for invalid structure', () => {
			const result = {
				// Missing summary or checks
				something: 'else',
			};

			const summary = extractSiteSecuritySummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should return null when summary is not an object', () => {
			const result = {
				summary: 'some string',
				checks: {},
			};

			const summary = extractSiteSecuritySummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should return null when checks is missing', () => {
			const result = {
				summary: {
					critical: 0,
					warning: 1,
					pass: 7,
				},
				// Missing checks property
			};

			const summary = extractSiteSecuritySummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should not display [object Object] for security results', () => {
			const result = {
				risk_level: 'safe',
				summary: {
					critical: 0,
					warning: 0,
					pass: 8,
				},
				checks: {},
			};

			const summary = extractSiteSecuritySummary( result );
			
			// This is the main fix: should NOT contain [object Object]
			expect( summary ).not.toContain( '[object Object]' );
			expect( summary ).toContain( 'Security' );
			expect( typeof summary ).toBe( 'string' );
		} );
	} );

	describe( 'Update Status tool result extraction', () => {
		// Mock implementation of the functions from chat.js
		function isUpdateStatusStructure( result ) {
			return result && 
				typeof result === 'object' &&
				typeof result.summary === 'object' && 
				result.summary !== null &&
				typeof result.components === 'object' && 
				result.components !== null;
		}

		function extractUpdateStatusSummary( result ) {
			if ( ! isUpdateStatusStructure( result ) ) {
				return null;
			}

			const summary = result.summary;
			
			// Check if there are any updates
			const total = typeof summary.total === 'number' ? summary.total : 0;
			
			if ( total === 0 ) {
				return 'Updates: No updates available';
			}

			const parts = [];

			// Build component breakdown
			if ( typeof summary.core === 'number' && summary.core > 0 ) {
				parts.push( summary.core + ' core' );
			}
			if ( typeof summary.plugins === 'number' && summary.plugins > 0 ) {
				parts.push( summary.plugins + ' plugin' + ( summary.plugins !== 1 ? 's' : '' ) );
			}
			if ( typeof summary.themes === 'number' && summary.themes > 0 ) {
				parts.push( summary.themes + ' theme' + ( summary.themes !== 1 ? 's' : '' ) );
			}

			let text = 'Updates: ' + total + ' total';
			if ( parts.length > 0 ) {
				text += ' (' + parts.join( ', ' ) + ')';
			}

			return text;
		}

		it( 'should extract summary with all update types', () => {
			const result = {
				summary: {
					total: 5,
					core: 1,
					plugins: 3,
					themes: 1,
				},
				components: {
					core: [],
					plugins: [],
					themes: [],
				},
			};

			const summary = extractUpdateStatusSummary( result );
			expect( summary ).toBe( 'Updates: 5 total (1 core, 3 plugins, 1 theme)' );
		} );

		it( 'should handle no updates available', () => {
			const result = {
				summary: {
					total: 0,
					core: 0,
					plugins: 0,
					themes: 0,
				},
				components: {
					core: [],
					plugins: [],
					themes: [],
				},
			};

			const summary = extractUpdateStatusSummary( result );
			expect( summary ).toBe( 'Updates: No updates available' );
		} );

		it( 'should handle only plugin updates', () => {
			const result = {
				summary: {
					total: 2,
					core: 0,
					plugins: 2,
					themes: 0,
				},
				components: {
					plugins: [],
				},
			};

			const summary = extractUpdateStatusSummary( result );
			expect( summary ).toBe( 'Updates: 2 total (2 plugins)' );
		} );

		it( 'should handle singular plugin correctly', () => {
			const result = {
				summary: {
					total: 1,
					core: 0,
					plugins: 1,
					themes: 0,
				},
				components: {
					plugins: [],
				},
			};

			const summary = extractUpdateStatusSummary( result );
			expect( summary ).toBe( 'Updates: 1 total (1 plugin)' );
		} );

		it( 'should handle singular theme correctly', () => {
			const result = {
				summary: {
					total: 1,
					core: 0,
					plugins: 0,
					themes: 1,
				},
				components: {
					themes: [],
				},
			};

			const summary = extractUpdateStatusSummary( result );
			expect( summary ).toBe( 'Updates: 1 total (1 theme)' );
		} );

		it( 'should handle only core update', () => {
			const result = {
				summary: {
					total: 1,
					core: 1,
					plugins: 0,
					themes: 0,
				},
				components: {
					core: [],
				},
			};

			const summary = extractUpdateStatusSummary( result );
			expect( summary ).toBe( 'Updates: 1 total (1 core)' );
		} );

		it( 'should return null for invalid structure', () => {
			const result = {
				// Missing summary or components
				something: 'else',
			};

			const summary = extractUpdateStatusSummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should return null when summary is not an object', () => {
			const result = {
				summary: 'some string',
				components: {},
			};

			const summary = extractUpdateStatusSummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should return null when components is missing', () => {
			const result = {
				summary: {
					total: 5,
					core: 1,
					plugins: 3,
					themes: 1,
				},
				// Missing components property
			};

			const summary = extractUpdateStatusSummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should not display [object Object] for update results', () => {
			const result = {
				summary: {
					total: 3,
					core: 1,
					plugins: 2,
					themes: 0,
				},
				components: {
					core: [],
					plugins: [],
				},
			};

			const summary = extractUpdateStatusSummary( result );
			
			// This is the main fix: should NOT contain [object Object]
			expect( summary ).not.toContain( '[object Object]' );
			expect( summary ).toContain( 'Updates' );
			expect( typeof summary ).toBe( 'string' );
		} );
	} );

	describe( 'Video Metadata tool result extraction', () => {
		// Mock implementation of the functions from chat.js
		function isVideoMetadataStructure( result ) {
			return result && 
				typeof result === 'object' &&
				typeof result.format === 'object' && 
				result.format !== null;
		}

		function extractVideoMetadataSummary( result ) {
			if ( ! isVideoMetadataStructure( result ) ) {
				return null;
			}

			const format = result.format;
			const parts = [];

			// Extract dimensions from video streams if available
			if ( Array.isArray( result.video_streams ) && result.video_streams.length > 0 ) {
				const firstVideo = result.video_streams[ 0 ];
				if ( typeof firstVideo.width === 'number' && typeof firstVideo.height === 'number' ) {
					parts.push( firstVideo.width + 'x' + firstVideo.height );
				}
			}

			// Add duration if available
			if ( typeof format.duration_formatted === 'string' && format.duration_formatted.trim() ) {
				parts.push( format.duration_formatted );
			} else if ( typeof format.duration === 'number' && format.duration > 0 ) {
				parts.push( Math.floor( format.duration ) + 's' );
			}

			// Add file size if available
			if ( typeof format.size_formatted === 'string' && format.size_formatted.trim() ) {
				parts.push( format.size_formatted );
			}

			// Add format name if available
			if ( typeof format.format_name === 'string' && format.format_name.trim() ) {
				parts.push( format.format_name );
			}

			if ( parts.length === 0 ) {
				return 'Video metadata retrieved';
			}

			return 'Video: ' + parts.join( ', ' );
		}

		it( 'should extract complete video metadata summary', () => {
			const result = {
				format: {
					filename: 'test.mp4',
					format_name: 'mov,mp4,m4a,3gp,3g2,mj2',
					duration: 150.5,
					duration_formatted: '00:02:30',
					size: 15500000,
					size_formatted: '14.78 MB',
					bit_rate: 826666,
				},
				video_streams: [
					{
						codec_name: 'h264',
						width: 1920,
						height: 1080,
						frame_rate: '30 fps',
					},
				],
				audio_streams: [],
				success: true,
			};

			const summary = extractVideoMetadataSummary( result );
			expect( summary ).toBe( 'Video: 1920x1080, 00:02:30, 14.78 MB, mov,mp4,m4a,3gp,3g2,mj2' );
		} );

		it( 'should handle video without formatted duration', () => {
			const result = {
				format: {
					duration: 45,
					format_name: 'mp4',
				},
				video_streams: [],
			};

			const summary = extractVideoMetadataSummary( result );
			expect( summary ).toContain( '45s' );
			expect( summary ).toContain( 'mp4' );
		} );

		it( 'should handle minimal video metadata', () => {
			const result = {
				format: {
					format_name: 'webm',
				},
			};

			const summary = extractVideoMetadataSummary( result );
			expect( summary ).toBe( 'Video: webm' );
		} );

		it( 'should handle video with no extractable fields', () => {
			const result = {
				format: {},
			};

			const summary = extractVideoMetadataSummary( result );
			expect( summary ).toBe( 'Video metadata retrieved' );
		} );

		it( 'should return null for invalid structure', () => {
			const result = {
				// Missing format object
				something: 'else',
			};

			const summary = extractVideoMetadataSummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should return null when format is not an object', () => {
			const result = {
				format: 'some string',
			};

			const summary = extractVideoMetadataSummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should not display [object Object] for video metadata results', () => {
			const result = {
				format: {
					duration: 120,
					format_name: 'mp4',
				},
				video_streams: [
					{
						width: 1280,
						height: 720,
					},
				],
			};

			const summary = extractVideoMetadataSummary( result );
			
			// This is the main fix: should NOT contain [object Object]
			expect( summary ).not.toContain( '[object Object]' );
			expect( summary ).toContain( 'Video' );
			expect( typeof summary ).toBe( 'string' );
		} );
	} );

	describe( 'Environment Status tool result extraction', () => {
		// Mock implementation of the functions from chat.js
		function isEnvironmentStatusStructure( result ) {
			return result && 
				typeof result === 'object' &&
				typeof result.environment === 'object' && 
				result.environment !== null &&
				typeof result.plugin === 'object' && 
				result.plugin !== null;
		}

		function extractEnvironmentStatusSummary( result ) {
			if ( ! isEnvironmentStatusStructure( result ) ) {
				return null;
			}

			const env = result.environment;
			const plugin = result.plugin;
			const parts = [];

			// Add WordPress version
			if ( typeof env.wordpress_version === 'string' && env.wordpress_version.trim() ) {
				parts.push( 'WordPress ' + env.wordpress_version );
			}

			// Add PHP version
			if ( typeof env.php_version === 'string' && env.php_version.trim() ) {
				parts.push( 'PHP ' + env.php_version );
			}

			// Add plugin version
			if ( typeof plugin.version === 'string' && plugin.version.trim() ) {
				parts.push( 'WP oOS ' + plugin.version );
			}

			// Add assistant count
			if ( result.assistants && typeof result.assistants.total_assistants === 'number' ) {
				parts.push( result.assistants.total_assistants + ' assistant' + ( result.assistants.total_assistants !== 1 ? 's' : '' ) );
			}

			// Add warnings count if any
			if ( Array.isArray( result.warnings ) && result.warnings.length > 0 ) {
				parts.push( result.warnings.length + ' warning' + ( result.warnings.length !== 1 ? 's' : '' ) );
			}

			if ( parts.length === 0 ) {
				return 'Environment status retrieved';
			}

			return 'Environment: ' + parts.join( ', ' );
		}

		it( 'should extract complete environment status', () => {
			const result = {
				checked_at: '2025-11-25T12:00:00Z',
				environment: {
					wordpress_version: '6.4.2',
					php_version: '8.1.0',
					environment_type: 'production',
				},
				plugin: {
					version: '1.0.0',
					default_provider: 'openai',
				},
				assistants: {
					total_assistants: 3,
					default_assistant_id: 1,
				},
				warnings: [
					'No API key configured',
				],
			};

			const summary = extractEnvironmentStatusSummary( result );
			expect( summary ).toBe( 'Environment: WordPress 6.4.2, PHP 8.1.0, WP oOS 1.0.0, 3 assistants, 1 warning' );
		} );

		it( 'should handle environment without warnings', () => {
			const result = {
				environment: {
					wordpress_version: '6.4',
					php_version: '8.2',
				},
				plugin: {
					version: '2.0.0',
				},
				assistants: {
					total_assistants: 1,
				},
				warnings: [],
			};

			const summary = extractEnvironmentStatusSummary( result );
			expect( summary ).toBe( 'Environment: WordPress 6.4, PHP 8.2, WP oOS 2.0.0, 1 assistant' );
		} );

		it( 'should handle singular assistant correctly', () => {
			const result = {
				environment: {
					wordpress_version: '6.4',
				},
				plugin: {
					version: '1.0',
				},
				assistants: {
					total_assistants: 1,
				},
			};

			const summary = extractEnvironmentStatusSummary( result );
			expect( summary ).toContain( '1 assistant' );
			expect( summary ).not.toContain( 'assistants' );
		} );

		it( 'should handle plural assistants correctly', () => {
			const result = {
				environment: {},
				plugin: {},
				assistants: {
					total_assistants: 5,
				},
			};

			const summary = extractEnvironmentStatusSummary( result );
			expect( summary ).toContain( '5 assistants' );
		} );

		it( 'should handle plural warnings correctly', () => {
			const result = {
				environment: {},
				plugin: {},
				warnings: [ 'Warning 1', 'Warning 2', 'Warning 3' ],
			};

			const summary = extractEnvironmentStatusSummary( result );
			expect( summary ).toContain( '3 warnings' );
		} );

		it( 'should handle minimal environment data', () => {
			const result = {
				environment: {},
				plugin: {},
			};

			const summary = extractEnvironmentStatusSummary( result );
			expect( summary ).toBe( 'Environment status retrieved' );
		} );

		it( 'should return null for invalid structure', () => {
			const result = {
				// Missing environment or plugin
				something: 'else',
			};

			const summary = extractEnvironmentStatusSummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should return null when environment is not an object', () => {
			const result = {
				environment: 'some string',
				plugin: {},
			};

			const summary = extractEnvironmentStatusSummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should return null when plugin is missing', () => {
			const result = {
				environment: {
					wordpress_version: '6.4',
				},
				// Missing plugin property
			};

			const summary = extractEnvironmentStatusSummary( result );
			expect( summary ).toBe( null );
		} );

		it( 'should not display [object Object] for environment results', () => {
			const result = {
				environment: {
					wordpress_version: '6.4',
					php_version: '8.1',
				},
				plugin: {
					version: '1.0.0',
				},
			};

			const summary = extractEnvironmentStatusSummary( result );
			
			// This is the main fix: should NOT contain [object Object]
			expect( summary ).not.toContain( '[object Object]' );
			expect( summary ).toContain( 'Environment' );
			expect( typeof summary ).toBe( 'string' );
		} );
	} );
} );
