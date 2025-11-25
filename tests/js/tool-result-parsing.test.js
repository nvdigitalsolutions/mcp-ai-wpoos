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

		function extractSiteHealthSummary( result ) {
			if ( ! result || typeof result !== 'object' ) {
				return null;
			}

			const summary = result.summary;
			const tests = result.tests;

			if ( ! summary || typeof summary !== 'object' || ! tests ) {
				return null;
			}

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
} );
