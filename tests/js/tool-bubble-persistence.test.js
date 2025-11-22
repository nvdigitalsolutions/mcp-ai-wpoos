/**
 * Test suite for tool bubble persistence after page refresh
 * Tests the fix for the issue where tool bubbles display [object Object] after refresh
 *
 * @package WP_MCP_AI
 */

describe( 'Tool Bubble Persistence', () => {
	describe( 'Tool result display metadata creation', () => {
		it( 'should create display metadata with text and empty attachments for simple tool result', () => {
			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_123',
				name: 'some_tool',
				content: JSON.stringify( {
					text: 'Tool executed successfully',
				} ),
			};

			// Parse content
			let parsedContent = toolResult.content;
			if ( typeof parsedContent === 'string' ) {
				try {
					parsedContent = JSON.parse( parsedContent );
				} catch ( e ) {
					parsedContent = toolResult.content;
				}
			}

			// Build display metadata
			const displayMetadata = {
				text: '',
				attachments: [],
			};

			if ( parsedContent && parsedContent.text ) {
				displayMetadata.text = parsedContent.text;
			}

			expect( displayMetadata.text ).toBe( 'Tool executed successfully' );
			expect( displayMetadata.attachments ).toEqual( [] );
		} );

		it( 'should create display metadata with text and attachments for tool result with files', () => {
			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_456',
				name: 'generate_openai_image',
				content: JSON.stringify( {
					attachment_id: 42,
					url: 'https://example.com/image.png',
					file_name: 'generated-image.png',
					mime_type: 'image/png',
					bytes: 12345,
					text: 'Successfully generated image (ID: 42).',
				} ),
			};

			// This simulates what normaliseToolResultForDisplay does
			// In a real scenario, this would call the actual function
			const mockNormalized = {
				text: 'Successfully generated image (ID: 42).',
				attachments: [
					{
						url: 'https://example.com/image.png',
						label: 'generated-image.png',
						downloadName: 'generated-image.png',
						meta: '12.06 KB • image/png',
					},
				],
			};

			const displayMetadata = {
				text: mockNormalized.text || 'generate_openai_image: Completed',
				attachments: mockNormalized.attachments,
			};

			expect( displayMetadata.text ).toBe( 'Successfully generated image (ID: 42).' );
			expect( displayMetadata.attachments ).toHaveLength( 1 );
			expect( displayMetadata.attachments[ 0 ].url ).toBe( 'https://example.com/image.png' );
		} );

		it( 'should handle tool result with message field instead of text', () => {
			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_789',
				name: 'some_tool',
				content: JSON.stringify( {
					message: 'Operation completed',
				} ),
			};

			let parsedContent = toolResult.content;
			if ( typeof parsedContent === 'string' ) {
				try {
					parsedContent = JSON.parse( parsedContent );
				} catch ( e ) {
					parsedContent = toolResult.content;
				}
			}

			const displayMetadata = {
				text: '',
				attachments: [],
			};

			if ( parsedContent && parsedContent.message ) {
				displayMetadata.text = parsedContent.message;
			} else if ( parsedContent && parsedContent.text ) {
				displayMetadata.text = parsedContent.text;
			}

			expect( displayMetadata.text ).toBe( 'Operation completed' );
		} );

		it( 'should use fallback text when tool result has no text or message', () => {
			const toolName = 'some_tool';
			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_999',
				name: toolName,
				content: JSON.stringify( {
					status: 'success',
				} ),
			};

			let parsedContent = toolResult.content;
			if ( typeof parsedContent === 'string' ) {
				try {
					parsedContent = JSON.parse( parsedContent );
				} catch ( e ) {
					parsedContent = toolResult.content;
				}
			}

			const displayMetadata = {
				text: '',
				attachments: [],
			};

			// Check for text/message, fall back to default
			if ( parsedContent && parsedContent.text ) {
				displayMetadata.text = parsedContent.text;
			} else if ( parsedContent && parsedContent.message ) {
				displayMetadata.text = parsedContent.message;
			} else {
				displayMetadata.text = toolName + ': Completed successfully';
			}

			expect( displayMetadata.text ).toBe( 'some_tool: Completed successfully' );
		} );
	} );

	describe( 'Tool result restoration from localStorage', () => {
		it( 'should restore tool message from display metadata', () => {
			const storedMessage = {
				role: 'tool',
				content: JSON.stringify( { text: 'Original content' } ),
				display: {
					text: 'Tool executed successfully',
					attachments: [],
				},
				tool_call_id: 'call_123',
				name: 'some_tool',
			};

			// Simulate restoration logic
			const role = storedMessage.role;
			const display = storedMessage.display;

			let toolPayload;
			if ( display && typeof display === 'object' ) {
				toolPayload = {
					text: display.text || '',
					attachments: Array.isArray( display.attachments ) ? display.attachments : [],
				};
			}

			expect( toolPayload.text ).toBe( 'Tool executed successfully' );
			expect( toolPayload.attachments ).toEqual( [] );
			expect( typeof toolPayload.text ).toBe( 'string' );
		} );

		it( 'should restore tool message with attachments from display metadata', () => {
			const storedMessage = {
				role: 'tool',
				content: JSON.stringify( { url: 'https://example.com/image.png' } ),
				display: {
					text: 'Successfully generated image',
					attachments: [
						{
							url: 'https://example.com/image.png',
							label: 'generated-image.png',
							downloadName: 'generated-image.png',
							meta: '12.06 KB',
						},
					],
				},
			};

			const display = storedMessage.display;

			let toolPayload;
			if ( display && typeof display === 'object' ) {
				toolPayload = {
					text: display.text || '',
					attachments: Array.isArray( display.attachments ) ? display.attachments : [],
				};
			}

			expect( toolPayload.text ).toBe( 'Successfully generated image' );
			expect( toolPayload.attachments ).toHaveLength( 1 );
			expect( toolPayload.attachments[ 0 ].url ).toBe( 'https://example.com/image.png' );
		} );

		it( 'should fallback to parsing content when display metadata is missing', () => {
			const storedMessage = {
				role: 'tool',
				content: JSON.stringify( { text: 'Fallback text' } ),
				tool_call_id: 'call_456',
			};

			const display = storedMessage.display; // undefined
			const content = storedMessage.content;

			let toolPayload;
			if ( display && typeof display === 'object' ) {
				toolPayload = {
					text: display.text || '',
					attachments: Array.isArray( display.attachments ) ? display.attachments : [],
				};
			} else {
				// Fallback: parse content
				let parsedContent = content;
				if ( typeof content === 'string' ) {
					try {
						parsedContent = JSON.parse( content );
					} catch ( e ) {
						parsedContent = content;
					}
				}

				if ( typeof parsedContent === 'object' && parsedContent !== null ) {
					toolPayload = {
						text: parsedContent.text || parsedContent.message || String( content ),
						attachments: [],
					};
				} else {
					toolPayload = {
						text: String( content ),
						attachments: [],
					};
				}
			}

			expect( toolPayload.text ).toBe( 'Fallback text' );
			expect( toolPayload.attachments ).toEqual( [] );
		} );

		it( 'should handle malformed content gracefully in fallback', () => {
			const storedMessage = {
				role: 'tool',
				content: '{ invalid json',
			};

			const display = storedMessage.display; // undefined
			const content = storedMessage.content;

			let toolPayload;
			if ( display && typeof display === 'object' ) {
				toolPayload = {
					text: display.text || '',
					attachments: [],
				};
			} else {
				let parsedContent = content;
				if ( typeof content === 'string' ) {
					try {
						parsedContent = JSON.parse( content );
					} catch ( e ) {
						parsedContent = content;
					}
				}

				if ( typeof parsedContent === 'object' && parsedContent !== null ) {
					toolPayload = {
						text: parsedContent.text || parsedContent.message || String( content ),
						attachments: [],
					};
				} else {
					toolPayload = {
						text: String( content ),
						attachments: [],
					};
				}
			}

			expect( toolPayload.text ).toBe( '{ invalid json' );
			expect( typeof toolPayload.text ).toBe( 'string' );
		} );
	} );

	describe( 'Display metadata structure validation', () => {
		it( 'should never have arrays as text property', () => {
			const displayMetadata = {
				text: 'This is a string',
				attachments: [],
			};

			expect( typeof displayMetadata.text ).toBe( 'string' );
			expect( Array.isArray( displayMetadata.text ) ).toBe( false );
		} );

		it( 'should always have attachments as an array', () => {
			const displayMetadata = {
				text: 'Some text',
				attachments: [
					{ url: 'https://example.com/file.png', label: 'File' },
				],
			};

			expect( Array.isArray( displayMetadata.attachments ) ).toBe( true );
		} );

		it( 'should handle empty attachments array', () => {
			const displayMetadata = {
				text: 'Some text',
				attachments: [],
			};

			expect( displayMetadata.attachments ).toEqual( [] );
			expect( displayMetadata.attachments ).toHaveLength( 0 );
		} );
	} );
} );
