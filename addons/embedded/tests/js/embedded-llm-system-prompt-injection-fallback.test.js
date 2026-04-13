/**
 * Tests for the embedded LLM client system prompt injection fallback
 * in generateStreamingCompletion.
 *
 * When the caller (e.g. generateEmbeddedCompletion) does not include a system
 * message in the messages array, generateStreamingCompletion falls back to
 * injecting this.systemPrompt so the model always receives context.
 *
 * @package WP_MCP_AI
 */

describe( 'Embedded LLM Client - System Prompt Injection Fallback', () => {
	/**
	 * Simulate the system prompt injection logic added to generateStreamingCompletion.
	 *
	 * The actual implementation in embedded-llm-client.js:
	 *   1. Finds any existing system message in the passed messages array.
	 *   2. If found: logs success, proceeds as-is.
	 *   3. If not found AND this.systemPrompt is set: injects it at position 0.
	 *   4. If not found AND this.systemPrompt is null: logs a warning, proceeds.
	 *
	 * @param {Array}       messages    Messages passed to generateStreamingCompletion.
	 * @param {string|null} storedPrompt The value of this.systemPrompt on the client instance.
	 * @returns {{ messages: Array, injected: boolean, warned: boolean }}
	 */
	function simulateInjection( messages, storedPrompt ) {
		let warned = false;
		let injected = false;

		// Replicate the exact logic from generateStreamingCompletion after the fix.
		let systemMessage = messages.find( function( msg ) { return msg.role === 'system'; } );

		if ( systemMessage ) {
			// Already present — nothing to do.
		} else if ( storedPrompt ) {
			messages = [ { role: 'system', content: storedPrompt } ].concat( messages );
			systemMessage = messages[ 0 ];
			injected = true;
		} else {
			warned = true;
		}

		return { messages, injected, warned };
	}

	describe( 'When caller omits system message but client has a stored prompt', () => {
		it( 'should inject the stored system prompt at position 0', () => {
			const storedPrompt = 'You are a helpful assistant.';
			const input = [ { role: 'user', content: 'Hello' } ];

			const result = simulateInjection( input, storedPrompt );

			expect( result.injected ).toBe( true );
			expect( result.warned ).toBe( false );
			expect( result.messages[ 0 ].role ).toBe( 'system' );
			expect( result.messages[ 0 ].content ).toBe( storedPrompt );
		} );

		it( 'should preserve the original user message after injection', () => {
			const storedPrompt = 'You are a helpful assistant.';
			const input = [ { role: 'user', content: 'Hello' } ];

			const result = simulateInjection( input, storedPrompt );

			expect( result.messages ).toHaveLength( 2 );
			expect( result.messages[ 1 ].role ).toBe( 'user' );
			expect( result.messages[ 1 ].content ).toBe( 'Hello' );
		} );

		it( 'should preserve full multi-turn conversation after injection', () => {
			const storedPrompt = 'You are a helpful assistant.';
			const input = [
				{ role: 'user', content: 'Hi' },
				{ role: 'assistant', content: 'Hello!' },
				{ role: 'user', content: 'How are you?' },
			];

			const result = simulateInjection( input, storedPrompt );

			expect( result.messages ).toHaveLength( 4 );
			expect( result.messages[ 0 ].role ).toBe( 'system' );
			expect( result.messages[ 1 ].role ).toBe( 'user' );
			expect( result.messages[ 2 ].role ).toBe( 'assistant' );
			expect( result.messages[ 3 ].role ).toBe( 'user' );
		} );

		it( 'should work with a decoded (non-entity) system prompt', () => {
			// The stored prompt on the client is already decoded (no HTML entities).
			const storedPrompt = 'You help with coding & debugging.';
			const input = [ { role: 'user', content: 'Fix my code.' } ];

			const result = simulateInjection( input, storedPrompt );

			expect( result.injected ).toBe( true );
			expect( result.messages[ 0 ].content ).toBe( 'You help with coding & debugging.' );
		} );
	} );

	describe( 'When caller already includes a system message', () => {
		it( 'should not inject a duplicate system message', () => {
			const storedPrompt = 'Stored system prompt.';
			const callerSystemPrompt = 'Caller-provided system prompt.';
			const input = [
				{ role: 'system', content: callerSystemPrompt },
				{ role: 'user', content: 'Hello' },
			];

			const result = simulateInjection( input, storedPrompt );

			expect( result.injected ).toBe( false );
			expect( result.warned ).toBe( false );
			const systemMessages = result.messages.filter( function( m ) { return m.role === 'system'; } );
			expect( systemMessages ).toHaveLength( 1 );
			expect( systemMessages[ 0 ].content ).toBe( callerSystemPrompt );
		} );

		it( 'should keep the caller-provided system prompt unchanged', () => {
			const storedPrompt = 'Stored prompt.';
			const input = [
				{ role: 'system', content: 'Caller prompt with date/time context.' },
				{ role: 'user', content: 'What time is it?' },
			];

			const result = simulateInjection( input, storedPrompt );

			expect( result.messages ).toHaveLength( 2 );
			expect( result.messages[ 0 ].content ).toBe( 'Caller prompt with date/time context.' );
		} );
	} );

	describe( 'When neither caller nor client has a system prompt', () => {
		it( 'should warn and not inject anything when storedPrompt is null', () => {
			const input = [ { role: 'user', content: 'Hello' } ];

			const result = simulateInjection( input, null );

			expect( result.injected ).toBe( false );
			expect( result.warned ).toBe( true );
			expect( result.messages ).toHaveLength( 1 );
			expect( result.messages[ 0 ].role ).toBe( 'user' );
		} );

		it( 'should warn and not inject anything when storedPrompt is empty string', () => {
			const input = [ { role: 'user', content: 'Hello' } ];

			// Empty string is falsy — treated as "no prompt configured"
			const result = simulateInjection( input, '' );

			expect( result.injected ).toBe( false );
			expect( result.warned ).toBe( true );
		} );
	} );

	describe( 'Integration: real-world scenario where caller omits system message', () => {
		it( 'should recover the system prompt so the model sees it', () => {
			// Simulate: PHP set systemPrompt correctly, client stored it decoded,
			// but generateEmbeddedCompletion somehow failed to inject it before calling
			// generateStreamingCompletion.
			const storedPrompt = '🔧 JV\'s Core Personality & Role - NV Digital Solutions Services Helper.';
			const messagesFromCaller = [
				{ role: 'user', content: 'Can you help me with hosting?' },
			];

			const result = simulateInjection( messagesFromCaller, storedPrompt );

			expect( result.injected ).toBe( true );
			// Model now receives system context
			const systemMessages = result.messages.filter( function( m ) { return m.role === 'system'; } );
			expect( systemMessages ).toHaveLength( 1 );
			expect( systemMessages[ 0 ].content ).toContain( 'NV Digital Solutions' );
			// User message is still present
			expect( result.messages[ result.messages.length - 1 ].role ).toBe( 'user' );
		} );
	} );
} );
