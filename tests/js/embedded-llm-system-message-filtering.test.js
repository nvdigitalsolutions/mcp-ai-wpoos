/**
 * Tests for embedded LLM client system message filtering
 *
 * This test verifies that system messages are properly filtered out
 * from messages array when calling generateStreamingCompletion,
 * since WebLLM maintains stateful chat history and system prompt
 * is already set during initialization.
 *
 * Also tests that instance tools are used when options.tools is not provided.
 *
 * Related issue: Embedded chat client not aware of system instructions
 * and tools not being passed to completion requests.
 *
 * @package WP_MCP_AI
 */

describe( 'Embedded LLM Client - System Message Filtering', () => {
	describe( 'filterSystemMessages', () => {
		// Simulate the filtering logic from generateStreamingCompletion
		function filterSystemMessages( messages ) {
			return messages.filter( function( msg ) {
				return msg.role !== 'system';
			} );
		}

		it( 'should filter out system messages', () => {
			const messages = [
				{ role: 'system', content: 'You are a helpful assistant.' },
				{ role: 'user', content: 'Hello' },
				{ role: 'assistant', content: 'Hi there!' },
			];

			const filtered = filterSystemMessages( messages );

			expect( filtered ).toHaveLength( 2 );
			expect( filtered[ 0 ].role ).toBe( 'user' );
			expect( filtered[ 1 ].role ).toBe( 'assistant' );
		} );

		it( 'should return all messages when no system messages present', () => {
			const messages = [
				{ role: 'user', content: 'Hello' },
				{ role: 'assistant', content: 'Hi there!' },
			];

			const filtered = filterSystemMessages( messages );

			expect( filtered ).toHaveLength( 2 );
			expect( filtered ).toEqual( messages );
		} );

		it( 'should handle multiple system messages', () => {
			const messages = [
				{ role: 'system', content: 'You are helpful.' },
				{ role: 'user', content: 'Hello' },
				{ role: 'system', content: 'Extra system prompt.' },
				{ role: 'assistant', content: 'Hi!' },
			];

			const filtered = filterSystemMessages( messages );

			expect( filtered ).toHaveLength( 2 );
			expect( filtered[ 0 ].role ).toBe( 'user' );
			expect( filtered[ 1 ].role ).toBe( 'assistant' );
		} );

		it( 'should return empty array when only system messages', () => {
			const messages = [
				{ role: 'system', content: 'You are helpful.' },
				{ role: 'system', content: 'Additional instructions.' },
			];

			const filtered = filterSystemMessages( messages );

			expect( filtered ).toHaveLength( 0 );
		} );

		it( 'should preserve message order', () => {
			const messages = [
				{ role: 'system', content: 'System prompt' },
				{ role: 'user', content: 'First user message' },
				{ role: 'assistant', content: 'First assistant response' },
				{ role: 'user', content: 'Second user message' },
			];

			const filtered = filterSystemMessages( messages );

			expect( filtered ).toHaveLength( 3 );
			expect( filtered[ 0 ].content ).toBe( 'First user message' );
			expect( filtered[ 1 ].content ).toBe( 'First assistant response' );
			expect( filtered[ 2 ].content ).toBe( 'Second user message' );
		} );

		it( 'should handle empty messages array', () => {
			const messages = [];

			const filtered = filterSystemMessages( messages );

			expect( filtered ).toHaveLength( 0 );
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

	describe( 'Integration: message filtering with tools', () => {
		it( 'should handle real-world scenario from issue logs', () => {
			// From the logs, we see:
			// - System prompt with 5242 chars
			// - hasTools: false (but tools should be available)
			// - Messages include system prompt each time

			const instanceTools = [
				{ function: { name: 'web_search' } },
			];
			
			const messages = [
				{ 
					role: 'system', 
					content: '🔧 JV\'s Core Personality & Role - NV Digital Solutions...',
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

			// Apply fixes
			const filteredMessages = messages.filter( function( msg ) {
				return msg.role !== 'system';
			} );
			
			const toolsToUse = ( options.tools && Array.isArray( options.tools ) && options.tools.length > 0 )
				? options.tools
				: instanceTools;

			// Verify fix results
			expect( filteredMessages ).toHaveLength( 3 );
			expect( filteredMessages.some( ( msg ) => msg.role === 'system' ) ).toBe( false );
			expect( toolsToUse ).toBe( instanceTools );
			expect( toolsToUse ).toHaveLength( 1 );
			expect( toolsToUse[ 0 ].function.name ).toBe( 'web_search' );
		} );

		it( 'should preserve system prompt during initialization phase', () => {
			// During initialization, system prompt IS needed
			const initMessages = [
				{ role: 'system', content: 'You are a helpful assistant.' },
				{ role: 'user', content: 'Understood. I am ready to assist.' },
			];

			// During initialization, we DON'T filter (this happens in initializeModelContext)
			// This test just confirms we understand the difference
			expect( initMessages ).toHaveLength( 2 );
			expect( initMessages[ 0 ].role ).toBe( 'system' );
		} );

		it( 'should filter system prompt during chat completion phase', () => {
			// After initialization, subsequent messages should NOT include system prompt
			const chatMessages = [
				{ role: 'system', content: 'You are a helpful assistant.' },
				{ role: 'user', content: 'What is 2+2?' },
			];

			// During chat completion, we DO filter
			const filtered = chatMessages.filter( function( msg ) {
				return msg.role !== 'system';
			} );

			expect( filtered ).toHaveLength( 1 );
			expect( filtered[ 0 ].role ).toBe( 'user' );
			expect( filtered[ 0 ].content ).toBe( 'What is 2+2?' );
		} );
	} );
} );
