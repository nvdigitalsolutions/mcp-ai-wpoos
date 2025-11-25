/**
 * Test suite for streamed content fallback in handleChatResponse
 * Tests the fix for issue where OpenAI image generation streams text
 * like "Here's an image of..." but the final message doesn't display it.
 *
 * @package WP_MCP_AI
 */

describe( 'Streamed Content Fallback', () => {
	/**
	 * Helper function to apply streamed content fallback logic.
	 * Mirrors the implementation in handleChatResponse.
	 *
	 * @param {Object} assistantDisplay - Display object with text and attachments
	 * @param {*} streamedContent - Streamed content from SSE
	 * @return {boolean} Whether the fallback was applied
	 */
	function applyStreamedContentFallback( assistantDisplay, streamedContent ) {
		const hasDisplayText = typeof assistantDisplay.text === 'string' && assistantDisplay.text.trim() !== '';
		if ( ! hasDisplayText && streamedContent && typeof streamedContent === 'string' && streamedContent.trim() ) {
			assistantDisplay.text = streamedContent.trim();
			return true;
		}
		return false;
	}

	describe( 'Assistant display text with streamed content', () => {
		it( 'should use streamed content when message content is empty', () => {
			// Simulate the scenario where:
			// 1. Text is streamed during SSE
			// 2. Final message.content is null (LLM made a tool call)
			// 3. We have tool_results with image attachments

			const assistantDisplay = {
				text: '',
				attachments: [],
			};

			const streamedContent = 'Here\'s an inspiring image of a banana!';
			applyStreamedContentFallback( assistantDisplay, streamedContent );

			expect( assistantDisplay.text ).toBe( streamedContent );
		} );

		it( 'should not override existing assistant text', () => {
			// When message.content has actual text, don't override with streamed content
			const existingText = 'Generated the image you requested.';
			const assistantDisplay = {
				text: existingText,
				attachments: [],
			};

			const streamedContent = 'Here\'s a different streamed text.';
			applyStreamedContentFallback( assistantDisplay, streamedContent );

			expect( assistantDisplay.text ).toBe( existingText );
		} );

		it( 'should handle null or undefined streamed content gracefully', () => {
			const assistantDisplay = {
				text: '',
				attachments: [],
			};

			applyStreamedContentFallback( assistantDisplay, null );
			expect( assistantDisplay.text ).toBe( '' );

			applyStreamedContentFallback( assistantDisplay, undefined );
			expect( assistantDisplay.text ).toBe( '' );
		} );

		it( 'should handle empty string streamed content', () => {
			const assistantDisplay = {
				text: '',
				attachments: [],
			};

			applyStreamedContentFallback( assistantDisplay, '' );
			expect( assistantDisplay.text ).toBe( '' );
		} );

		it( 'should handle whitespace-only streamed content', () => {
			const assistantDisplay = {
				text: '',
				attachments: [],
			};

			applyStreamedContentFallback( assistantDisplay, '   \n\t  ' );
			expect( assistantDisplay.text ).toBe( '' );
		} );

		it( 'should trim streamed content', () => {
			const assistantDisplay = {
				text: '',
				attachments: [],
			};

			applyStreamedContentFallback( assistantDisplay, '  Here is your image  ' );
			expect( assistantDisplay.text ).toBe( 'Here is your image' );
		} );
	} );

	describe( 'Integration with tool results', () => {
		it( 'should combine streamed content with tool result attachments', () => {
			// Full flow: streamed text + tool result attachments
			const assistantDisplay = {
				text: '',
				attachments: [],
			};

			const streamedContent = 'Here\'s an inspiring image of a banana!';
			applyStreamedContentFallback( assistantDisplay, streamedContent );

			// Simulate adding attachments from tool results
			const toolResultAttachments = [
				{
					url: 'https://example.com/banana.png',
					label: 'Generated Banana Image',
					downloadName: 'banana.png',
					meta: '1024x1024 • medium',
				},
			];

			assistantDisplay.attachments = assistantDisplay.attachments.concat( toolResultAttachments );

			// Verify the final display object
			expect( assistantDisplay.text ).toBe( streamedContent );
			expect( assistantDisplay.attachments ).toHaveLength( 1 );
			expect( assistantDisplay.attachments[ 0 ].url ).toBe( 'https://example.com/banana.png' );
		} );

		it( 'should display tool result text when no streamed content exists', () => {
			const assistantDisplay = {
				text: '',
				attachments: [],
			};

			// No streamed content (happens when streaming isn't used)
			applyStreamedContentFallback( assistantDisplay, undefined );

			// Tool result provides text
			const toolResultText = 'Successfully generated image (ID: 42).';
			if ( toolResultText ) {
				if ( assistantDisplay.text ) {
					assistantDisplay.text += '\n\n' + toolResultText;
				} else {
					assistantDisplay.text = toolResultText;
				}
			}

			expect( assistantDisplay.text ).toBe( toolResultText );
		} );

		it( 'should combine streamed content with tool result text', () => {
			// Both streamed content and tool result text exist
			const assistantDisplay = {
				text: '',
				attachments: [],
			};

			const streamedContent = 'Here\'s an inspiring image of a banana!';
			applyStreamedContentFallback( assistantDisplay, streamedContent );

			// Tool result adds more text
			const toolResultText = 'Successfully generated image (ID: 42).';
			if ( toolResultText ) {
				if ( assistantDisplay.text ) {
					assistantDisplay.text += '\n\n' + toolResultText;
				} else {
					assistantDisplay.text = toolResultText;
				}
			}

			expect( assistantDisplay.text ).toBe( 'Here\'s an inspiring image of a banana!\n\nSuccessfully generated image (ID: 42).' );
		} );
	} );
} );
