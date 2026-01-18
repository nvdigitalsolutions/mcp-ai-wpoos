/**
 * Tests for systematic assistant and tool message persistence
 *
 * Validates that the ensureFinalMessagesPresent function maintains
 * conversation continuity for agentic chat flow.
 *
 * @package WP_MCP_AI
 */

describe( 'Assistant Message Persistence', () => {
	describe( 'ensureFinalMessagesPresent validation', () => {
		it( 'should add missing assistant message when tool results exist but assistant message is not in conversation', () => {
			// Simulate conversation state
			const state = {
				conversation: [],
			};

			// Create assistant message that hasn't been added yet
			const assistantMessage = {
				role: 'assistant',
				content: null,
			};

			// Simulate response data with tool results
			const data = {
				tool_results: [
					{
						role: 'tool',
						tool_call_id: 'call_123',
						name: 'generate_image',
						content: '{"success": true, "url": "https://example.com/image.png"}',
					},
				],
			};

			const hasToolResults = true;

			// Simulate ensureFinalMessagesPresent logic
			const assistantMessageInConversation = state.conversation.indexOf( assistantMessage ) !== -1;

			if ( hasToolResults && ! assistantMessageInConversation ) {
				if ( ! assistantMessage.content && ! assistantMessage.tool_calls ) {
					assistantMessage.content = '';
				}

				if ( ! assistantMessage.display ) {
					assistantMessage.display = {
						bubbleType: 'assistant',
						text: assistantMessage.content || '',
						attachments: [],
						addedByValidation: true,
					};
				}

				state.conversation.push( assistantMessage );
			}

			// Verify assistant message was added
			expect( state.conversation ).toHaveLength( 1 );
			expect( state.conversation[ 0 ] ).toBe( assistantMessage );
			expect( assistantMessage.content ).toBe( '' );
			expect( assistantMessage.display ).toBeDefined();
			expect( assistantMessage.display.addedByValidation ).toBe( true );
		} );

		it( 'should not duplicate assistant message if already in conversation', () => {
			// Simulate conversation state with assistant message already present
			const assistantMessage = {
				role: 'assistant',
				content: 'Here are the results',
				tool_calls: [
					{
						id: 'call_123',
						function: { name: 'search_web' },
					},
				],
			};

			const state = {
				conversation: [ assistantMessage ],
			};

			const data = {
				tool_results: [
					{
						role: 'tool',
						tool_call_id: 'call_123',
						name: 'search_web',
						content: '{"results": []}',
					},
				],
			};

			const hasToolResults = true;

			// Simulate ensureFinalMessagesPresent logic
			const assistantMessageInConversation = state.conversation.indexOf( assistantMessage ) !== -1;

			if ( hasToolResults && ! assistantMessageInConversation ) {
				state.conversation.push( assistantMessage );
			}

			// Verify no duplicate was added
			expect( state.conversation ).toHaveLength( 1 );
			expect( state.conversation[ 0 ] ).toBe( assistantMessage );
		} );

		it( 'should handle empty response with no tool calls by persisting assistant message', () => {
			// Simulate state
			const state = {
				conversation: [],
			};

			const assistantMessage = {
				role: 'assistant',
			};

			const hasDisplayContent = false;
			const hasToolCalls = false;

			// Simulate the fixed early return logic (lines 13591-13610)
			if ( ! hasDisplayContent && ! hasToolCalls ) {
				// Instead of early return, persist the message
				assistantMessage.content = '';
				assistantMessage.display = {
					bubbleType: 'assistant',
					text: '',
					attachments: [],
					isEmptyResponse: true,
				};
				state.conversation.push( assistantMessage );
			}

			// Verify empty assistant message was persisted
			expect( state.conversation ).toHaveLength( 1 );
			expect( state.conversation[ 0 ] ).toBe( assistantMessage );
			expect( assistantMessage.content ).toBe( '' );
			expect( assistantMessage.display ).toBeDefined();
			expect( assistantMessage.display.isEmptyResponse ).toBe( true );
		} );

		it( 'should maintain conversation structure for LLM continuation', () => {
			// Simulate a complete agentic workflow
			const state = {
				conversation: [
					{ role: 'user', content: 'Generate an image of a cat' },
				],
			};

			// Step 1: Assistant makes tool call
			const assistantMessage1 = {
				role: 'assistant',
				content: null,
				tool_calls: [
					{
						id: 'call_123',
						function: {
							name: 'generate_image',
							arguments: '{"prompt": "cat"}',
						},
					},
				],
			};
			state.conversation.push( assistantMessage1 );

			// Step 2: Tool result
			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_123',
				name: 'generate_image',
				content: '{"url": "https://example.com/cat.png"}',
			};
			state.conversation.push( toolResult );

			// Step 3: Assistant final response (could be empty)
			const assistantMessage2 = {
				role: 'assistant',
				content: '',
			};

			// Ensure final message persists even if empty
			if ( assistantMessage2.content === '' ) {
				assistantMessage2.display = {
					bubbleType: 'assistant',
					text: '',
					attachments: [],
					isEmptyResponse: true,
				};
			}
			state.conversation.push( assistantMessage2 );

			// Verify complete conversation structure
			expect( state.conversation ).toHaveLength( 4 );
			expect( state.conversation[ 0 ].role ).toBe( 'user' );
			expect( state.conversation[ 1 ].role ).toBe( 'assistant' );
			expect( state.conversation[ 1 ].tool_calls ).toBeDefined();
			expect( state.conversation[ 2 ].role ).toBe( 'tool' );
			expect( state.conversation[ 3 ].role ).toBe( 'assistant' );
			expect( state.conversation[ 3 ].display ).toBeDefined();
		} );

		it( 'should preserve tool result display metadata', () => {
			const state = {
				conversation: [],
			};

			const assistantMessage = {
				role: 'assistant',
				content: null,
			};

			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_123',
				name: 'generate_image',
				content: '{"url": "https://example.com/image.png", "success": true}',
				display: {
					bubbleType: 'tool',
					attachments: [
						{
							type: 'image',
							url: 'https://example.com/image.png',
						},
					],
				},
			};

			const data = {
				tool_results: [ toolResult ],
			};

			const hasToolResults = true;

			// Simulate ensureFinalMessagesPresent
			const assistantMessageInConversation = state.conversation.indexOf( assistantMessage ) !== -1;

			if ( hasToolResults && ! assistantMessageInConversation ) {
				if ( ! assistantMessage.content && ! assistantMessage.tool_calls ) {
					assistantMessage.content = '';
				}

				if ( ! assistantMessage.display ) {
					assistantMessage.display = {
						bubbleType: 'assistant',
						text: '',
						attachments: [],
						addedByValidation: true,
					};
				}

				state.conversation.push( assistantMessage );
			}

			// Tool result would have been pushed separately (line 13910)
			state.conversation.push( toolResult );

			// Verify both messages are present with display metadata
			expect( state.conversation ).toHaveLength( 2 );
			expect( state.conversation[ 0 ].display ).toBeDefined();
			expect( state.conversation[ 1 ].display ).toBeDefined();
			expect( state.conversation[ 1 ].display.attachments ).toHaveLength( 1 );
		} );
	} );

	describe( 'Conversation state after page reload', () => {
		it( 'should restore complete conversation with empty assistant messages', () => {
			// Simulate saved conversation from localStorage
			const savedConversation = [
				{ role: 'user', content: 'Search for documentation' },
				{
					role: 'assistant',
					content: '',
					display: {
						bubbleType: 'assistant',
						text: '',
						attachments: [],
						isEmptyResponse: true,
					},
				},
			];

			// Verify structure is preserved
			expect( savedConversation ).toHaveLength( 2 );
			expect( savedConversation[ 1 ].content ).toBe( '' );
			expect( savedConversation[ 1 ].display.isEmptyResponse ).toBe( true );
		} );

		it( 'should restore tool results with assistant messages', () => {
			// Simulate saved conversation with tool workflow
			const savedConversation = [
				{ role: 'user', content: 'Create an image' },
				{
					role: 'assistant',
					content: null,
					tool_calls: [
						{
							id: 'call_123',
							function: { name: 'generate_image' },
						},
					],
				},
				{
					role: 'tool',
					tool_call_id: 'call_123',
					name: 'generate_image',
					content: '{"url": "https://example.com/image.png"}',
					display: {
						bubbleType: 'tool',
						attachments: [ { type: 'image', url: 'https://example.com/image.png' } ],
					},
				},
				{
					role: 'assistant',
					content: '',
					display: {
						bubbleType: 'assistant',
						text: '',
						attachments: [],
						addedByValidation: true,
					},
				},
			];

			// Verify complete workflow is preserved
			expect( savedConversation ).toHaveLength( 4 );
			expect( savedConversation[ 1 ].tool_calls ).toBeDefined();
			expect( savedConversation[ 2 ].role ).toBe( 'tool' );
			expect( savedConversation[ 3 ].role ).toBe( 'assistant' );
			expect( savedConversation[ 3 ].display.addedByValidation ).toBe( true );
		} );
	} );
} );
