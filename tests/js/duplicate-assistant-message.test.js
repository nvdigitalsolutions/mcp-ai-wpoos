/**
 * Tests for preventing duplicate assistant messages in conversation
 *
 * @package WP_MCP_AI
 */

describe( 'Duplicate Assistant Message Prevention', () => {
	describe( 'Conversation push with tool results', () => {
		it( 'should not add duplicate assistant message after tool results are pushed', () => {
			// Simulate the conversation state
			const state = {
				conversation: [],
			};

			// Create an assistant message object
			const assistantMessage = {
				role: 'assistant',
				content: null,
				tool_calls: [
					{
						id: 'call_123',
						function: {
							name: 'generate_image',
							arguments: '{"prompt": "test"}',
						},
					},
				],
			};

			// First push: simulates line 9348 where assistantMessage is pushed
			if ( assistantMessage.content || assistantMessage.tool_calls ) {
				state.conversation.push( assistantMessage );
			}

			expect( state.conversation ).toHaveLength( 1 );
			expect( state.conversation[ 0 ] ).toBe( assistantMessage );

			// Simulate tool results being pushed (line 9355)
			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_123',
				content:
					'{"success": true, "attachment_url": "https://example.com/image.png"}',
			};
			state.conversation.push( toolResult );

			expect( state.conversation ).toHaveLength( 2 );

			// The buggy check (before fix) would compare last element only:
			// state.conversation[state.conversation.length - 1] !== assistantMessage
			// This would be true because the last element is toolResult, not assistantMessage
			// Leading to a duplicate push

			// The fixed check uses indexOf to search the entire array:
			// state.conversation.indexOf(assistantMessage) === -1
			// This correctly finds assistantMessage in the array and prevents duplicate

			// Simulate the fixed check at line 9496
			if ( state.conversation.indexOf( assistantMessage ) === -1 ) {
				state.conversation.push( assistantMessage );
			}

			// The assistant message should NOT be added again
			expect( state.conversation ).toHaveLength( 2 );
			expect( state.conversation[ 0 ] ).toBe( assistantMessage );
			expect( state.conversation[ 1 ] ).toBe( toolResult );
		} );

		it( 'should allow adding assistant message when conversation is empty', () => {
			const state = {
				conversation: [],
			};

			const assistantMessage = {
				role: 'assistant',
				content: 'Hello!',
			};

			// Check using indexOf (the fixed version)
			if ( state.conversation.indexOf( assistantMessage ) === -1 ) {
				state.conversation.push( assistantMessage );
			}

			expect( state.conversation ).toHaveLength( 1 );
			expect( state.conversation[ 0 ] ).toBe( assistantMessage );
		} );

		it( 'should allow adding different assistant messages', () => {
			const state = {
				conversation: [],
			};

			const firstAssistantMessage = {
				role: 'assistant',
				content: 'First response',
			};

			const secondAssistantMessage = {
				role: 'assistant',
				content: 'Second response',
			};

			// Add first message
			if ( state.conversation.indexOf( firstAssistantMessage ) === -1 ) {
				state.conversation.push( firstAssistantMessage );
			}

			// Add second message (should be allowed since it's a different object)
			if ( state.conversation.indexOf( secondAssistantMessage ) === -1 ) {
				state.conversation.push( secondAssistantMessage );
			}

			expect( state.conversation ).toHaveLength( 2 );
			expect( state.conversation[ 0 ] ).toBe( firstAssistantMessage );
			expect( state.conversation[ 1 ] ).toBe( secondAssistantMessage );
		} );

		it( 'should correctly identify duplicate by object reference', () => {
			const state = {
				conversation: [],
			};

			const assistantMessage = {
				role: 'assistant',
				content: 'Test message',
			};

			// First push
			state.conversation.push( assistantMessage );

			// Create a different object with the same content
			const differentObject = {
				role: 'assistant',
				content: 'Test message',
			};

			// This should be allowed because it's a different object
			if ( state.conversation.indexOf( differentObject ) === -1 ) {
				state.conversation.push( differentObject );
			}

			// Both should be in the array (different objects)
			expect( state.conversation ).toHaveLength( 2 );

			// But the same object should not be added twice
			if ( state.conversation.indexOf( assistantMessage ) === -1 ) {
				state.conversation.push( assistantMessage );
			}

			// Still 2 because assistantMessage is already in the array
			expect( state.conversation ).toHaveLength( 2 );
		} );
	} );

	describe( 'Bug scenario simulation', () => {
		it( 'should not duplicate assistant message when hasDisplayContent becomes true after tool processing', () => {
			// This test simulates the exact bug scenario:
			// 1. handleChatResponse is called with tool_calls but no content
			// 2. assistantMessage.content is set to null (for OpenAI compatibility)
			// 3. assistantMessage is pushed because it has tool_calls
			// 4. toolResult is pushed
			// 5. Tool processing adds attachments to assistantDisplay
			// 6. The else branch (no text but has attachments) is entered
			// 7. The check should prevent duplicate push

			const state = {
				conversation: [],
			};

			const assistantMessage = {
				role: 'assistant',
				content: null,
				tool_calls: [
					{
						id: 'call_abc',
						function: { name: 'generate_dalle_image' },
					},
				],
			};

			// Step 1-3: Initial push (line 9348)
			let hasDisplayContent = false;
			const hasToolCalls = true;

			if (
				assistantMessage.content !== undefined ||
				assistantMessage.tool_calls
			) {
				state.conversation.push( assistantMessage );
			}

			// Step 4: Tool result pushed (line 9355)
			const toolResult = {
				role: 'tool',
				tool_call_id: 'call_abc',
				content: '{"url": "https://example.com/image.png"}',
			};
			state.conversation.push( toolResult );

			// Step 5-6: Tool processing updates assistantDisplay with attachments
			const assistantDisplay = {
				text: '',
				attachments: [
					{
						url: 'https://example.com/image.png',
						label: 'Generated Image',
					},
				],
			};

			// Step 7: Re-render logic (line 9455-9505)
			if ( assistantDisplay.attachments.length > 0 || assistantDisplay.text ) {
				if ( hasDisplayContent ) {
					// Update existing message - would not push duplicate
				} else {
					// No text content but has attachments - this is where the bug occurred
					if ( ! assistantMessage.content ) {
						assistantMessage.content = hasToolCalls ? null : '';
					}

					// FIXED CHECK: Use indexOf instead of checking only last element
					if ( state.conversation.indexOf( assistantMessage ) === -1 ) {
						state.conversation.push( assistantMessage );
					}
					hasDisplayContent = true;
				}
			}

			// Verify no duplicate
			expect( state.conversation ).toHaveLength( 2 );
			expect( state.conversation[ 0 ].role ).toBe( 'assistant' );
			expect( state.conversation[ 1 ].role ).toBe( 'tool' );

			// Count how many times assistantMessage appears
			const assistantCount = state.conversation.filter(
				( msg ) => msg === assistantMessage
			).length;
			expect( assistantCount ).toBe( 1 );
		} );
	} );
} );
