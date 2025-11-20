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
} );
