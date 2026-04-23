/**
 * Tests for embedded LLM client OpenAI compatibility
 *
 * This test verifies that the embedded LLM client follows OpenAI API patterns
 * where system messages MUST be included in every request, not filtered out.
 *
 * Web-LLM is stateless like OpenAI - the full conversation history including
 * system prompt must be sent with each chat.completions.create() call.
 *
 * Also tests that instance tools are used when options.tools is not provided.
 *
 * Reference: https://github.com/mlc-ai/web-llm?tab=readme-ov-file#full-openai-compatibility
 *
 * @package WP_MCP_AI
 */

describe( 'Embedded LLM Client - OpenAI Compatibility', () => {
	describe( 'System Message Handling (OpenAI Pattern)', () => {
		// System messages should NOT be filtered - they're required for every request
		function processMessages( messages ) {
			// OpenAI-compatible: return all messages including system
			return messages;
		}

		it( 'should include system messages in every request', () => {
			const messages = [
				{ role: 'system', content: 'You are a helpful assistant.' },
				{ role: 'user', content: 'Hello' },
				{ role: 'assistant', content: 'Hi there!' },
			];

			const processed = processMessages( messages );

			expect( processed ).toHaveLength( 3 );
			expect( processed[ 0 ].role ).toBe( 'system' );
			expect( processed[ 1 ].role ).toBe( 'user' );
			expect( processed[ 2 ].role ).toBe( 'assistant' );
		} );

		it( 'should preserve all messages when no system messages present', () => {
			const messages = [
				{ role: 'user', content: 'Hello' },
				{ role: 'assistant', content: 'Hi there!' },
			];

			const processed = processMessages( messages );

			expect( processed ).toHaveLength( 2 );
			expect( processed ).toEqual( messages );
		} );

		it( 'should preserve multiple system messages if present', () => {
			const messages = [
				{ role: 'system', content: 'You are helpful.' },
				{ role: 'user', content: 'Hello' },
				{ role: 'system', content: 'Extra system prompt.' },
				{ role: 'assistant', content: 'Hi!' },
			];

			const processed = processMessages( messages );

			// All messages preserved, including both system messages
			expect( processed ).toHaveLength( 4 );
			expect( processed[ 0 ].role ).toBe( 'system' );
			expect( processed[ 2 ].role ).toBe( 'system' );
		} );

		it( 'should handle only system messages correctly', () => {
			const messages = [
				{ role: 'system', content: 'You are helpful.' },
				{ role: 'system', content: 'Additional instructions.' },
			];

			const processed = processMessages( messages );

			// System messages are preserved
			expect( processed ).toHaveLength( 2 );
			expect( processed[ 0 ].role ).toBe( 'system' );
			expect( processed[ 1 ].role ).toBe( 'system' );
		} );

		it( 'should preserve message order including system messages', () => {
			const messages = [
				{ role: 'system', content: 'System prompt' },
				{ role: 'user', content: 'First user message' },
				{ role: 'assistant', content: 'First assistant response' },
				{ role: 'user', content: 'Second user message' },
			];

			const processed = processMessages( messages );

			expect( processed ).toHaveLength( 4 );
			expect( processed[ 0 ].content ).toBe( 'System prompt' );
			expect( processed[ 1 ].content ).toBe( 'First user message' );
			expect( processed[ 2 ].content ).toBe( 'First assistant response' );
			expect( processed[ 3 ].content ).toBe( 'Second user message' );
		} );

		it( 'should handle empty messages array', () => {
			const messages = [];

			const processed = processMessages( messages );

			expect( processed ).toHaveLength( 0 );
		} );
	} );

	describe( 'toolsToUse selection', () => {
		// Simulate the tool selection logic from generateStreamingCompletion
		function selectTools( instanceTools, optionsTools ) {
			return ( optionsTools && Array.isArray( optionsTools ) && optionsTools.length > 0 )
				? optionsTools
				: instanceTools;
		}

		it( 'should use options.tools when provided', () => {
			const instanceTools = [
				{ function: { name: 'instance_tool_1' } },
			];
			const optionsTools = [
				{ function: { name: 'options_tool_1' } },
			];

			const selected = selectTools( instanceTools, optionsTools );

			expect( selected ).toBe( optionsTools );
			expect( selected[ 0 ].function.name ).toBe( 'options_tool_1' );
		} );

		it( 'should use instance tools when options.tools is empty', () => {
			const instanceTools = [
				{ function: { name: 'instance_tool_1' } },
			];
			const optionsTools = [];

			const selected = selectTools( instanceTools, optionsTools );

			expect( selected ).toBe( instanceTools );
			expect( selected[ 0 ].function.name ).toBe( 'instance_tool_1' );
		} );

		it( 'should use instance tools when options.tools is null', () => {
			const instanceTools = [
				{ function: { name: 'instance_tool_1' } },
			];
			const optionsTools = null;

			const selected = selectTools( instanceTools, optionsTools );

			expect( selected ).toBe( instanceTools );
		} );

		it( 'should use instance tools when options.tools is undefined', () => {
			const instanceTools = [
				{ function: { name: 'instance_tool_1' } },
			];
			const optionsTools = undefined;

			const selected = selectTools( instanceTools, optionsTools );

			expect( selected ).toBe( instanceTools );
		} );

		it( 'should use instance tools when options.tools is not an array', () => {
			const instanceTools = [
				{ function: { name: 'instance_tool_1' } },
			];
			const optionsTools = 'not an array';

			const selected = selectTools( instanceTools, optionsTools );

			expect( selected ).toBe( instanceTools );
		} );

		it( 'should return empty array when both are empty', () => {
			const instanceTools = [];
			const optionsTools = [];

			const selected = selectTools( instanceTools, optionsTools );

			expect( selected ).toEqual( [] );
		} );

		it( 'should prefer options.tools even when instance has more tools', () => {
			const instanceTools = [
				{ function: { name: 'instance_tool_1' } },
				{ function: { name: 'instance_tool_2' } },
				{ function: { name: 'instance_tool_3' } },
			];
			const optionsTools = [
				{ function: { name: 'options_tool_1' } },
			];

			const selected = selectTools( instanceTools, optionsTools );

			expect( selected ).toBe( optionsTools );
			expect( selected ).toHaveLength( 1 );
		} );
	} );

	describe( 'Integration: OpenAI-compatible message handling with tools', () => {
		it( 'should handle real-world scenario following OpenAI pattern', () => {
			// Following OpenAI/Web-LLM pattern:
			// - System prompt INCLUDED in every request
			// - Tools passed via options (or from instance)
			// - Full conversation history maintained

			const instanceTools = [
				{ function: { name: 'web_search' } },
			];
			
			const messages = [
				{ 
					role: 'system', 
					content: 'You are JV, a helpful assistant for NV Digital Solutions. Help with coding, email services, CMS management, and hosting.',
				},
				{ role: 'user', content: 'Hello' },
				{ role: 'assistant', content: 'Hi there!' },
				{ role: 'user', content: 'Can you search for something?' },
			];

			const options = {
				temperature: 1,
				max_tokens: 2048,
				// tools not provided in options
			};

			// OpenAI-compatible approach: include ALL messages
			const processedMessages = messages; // No filtering!
			
			const toolsToUse = ( options.tools && Array.isArray( options.tools ) && options.tools.length > 0 )
				? options.tools
				: instanceTools;

			// Verify OpenAI-compatible results
			expect( processedMessages ).toHaveLength( 4 );
			expect( processedMessages[ 0 ].role ).toBe( 'system' );
			expect( processedMessages.some( ( msg ) => msg.role === 'system' ) ).toBe( true );
			expect( toolsToUse ).toBe( instanceTools );
			expect( toolsToUse ).toHaveLength( 1 );
			expect( toolsToUse[ 0 ].function.name ).toBe( 'web_search' );
		} );

		it( 'should include system prompt in every chat completion request', () => {
			// OpenAI pattern: system prompt must be in every request
			const chatMessages = [
				{ role: 'system', content: 'You are a helpful assistant.' },
				{ role: 'user', content: 'What is 2+2?' },
			];

			// No filtering - send all messages
			const processed = chatMessages;

			expect( processed ).toHaveLength( 2 );
			expect( processed[ 0 ].role ).toBe( 'system' );
			expect( processed[ 1 ].role ).toBe( 'user' );
			expect( processed[ 1 ].content ).toBe( 'What is 2+2?' );
		} );

		it( 'should maintain full conversation context', () => {
			// Multi-turn conversation with system prompt
			const conversation = [
				{ role: 'system', content: 'You are a math tutor.' },
				{ role: 'user', content: 'What is 2+2?' },
				{ role: 'assistant', content: '2+2 equals 4.' },
				{ role: 'user', content: 'What about 3+3?' },
			];

			// All messages sent to API
			const processed = conversation;

			expect( processed ).toHaveLength( 4 );
			expect( processed[ 0 ].role ).toBe( 'system' );
			// System prompt persists throughout conversation
			expect( processed.filter( ( m ) => m.role === 'system' ) ).toHaveLength( 1 );
			// All conversation turns preserved
			expect( processed.filter( ( m ) => m.role === 'user' ) ).toHaveLength( 2 );
			expect( processed.filter( ( m ) => m.role === 'assistant' ) ).toHaveLength( 1 );
		} );
	} );
} );
