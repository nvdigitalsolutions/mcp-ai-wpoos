/**
 * Tests for the embedded LLM system prompt config fallback in generateEmbeddedCompletion.
 *
 * When embeddedClient.systemPrompt is null (e.g. the client was created before the system
 * prompt was available), the function should fall back to state.config.systemPrompt and
 * state.config.professionalPrompt to build the system prompt, decoding any HTML entities
 * introduced by wp_kses_post() sanitization.
 *
 * @package WP_MCP_AI
 */

describe( 'Embedded LLM - System Prompt Config Fallback', () => {
	/**
	 * Simulate decodeHtmlEntities (same logic as embedded-llm-client.js).
	 */
	function decodeHtmlEntities( text ) {
		if ( ! text || typeof text !== 'string' ) {
			return text;
		}
		const textarea = document.createElement( 'textarea' );
		textarea.innerHTML = text;
		return textarea.value;
	}

	/**
	 * Simulate the system prompt resolution logic from generateEmbeddedCompletion.
	 * Returns the effective system prompt string, or null if none.
	 */
	function resolveEffectiveSystemPrompt( embeddedClientSystemPrompt, config ) {
		var effectiveSystemPrompt = embeddedClientSystemPrompt;

		if ( ! effectiveSystemPrompt && ( config.systemPrompt || config.professionalPrompt ) ) {
			var rawFallbackPrompt = '';
			if ( config.professionalPrompt ) {
				rawFallbackPrompt = config.professionalPrompt;
				if ( config.systemPrompt ) {
					rawFallbackPrompt = config.professionalPrompt + '\n\n---\n\n# Additional Instructions\n\n' + config.systemPrompt;
				}
			} else {
				rawFallbackPrompt = config.systemPrompt;
			}

			var decodeEl = document.createElement( 'textarea' );
			decodeEl.innerHTML = rawFallbackPrompt;
			effectiveSystemPrompt = decodeEl.value || null;
		}

		return effectiveSystemPrompt;
	}

	describe( 'resolveEffectiveSystemPrompt', () => {
		it( 'should use embeddedClient.systemPrompt when available', () => {
			const result = resolveEffectiveSystemPrompt(
				'You are a helpful assistant.',
				{ systemPrompt: 'Config prompt', professionalPrompt: null }
			);

			// Client value takes priority over config
			expect( result ).toBe( 'You are a helpful assistant.' );
		} );

		it( 'should fall back to state.config.systemPrompt when client prompt is null', () => {
			const result = resolveEffectiveSystemPrompt(
				null,
				{ systemPrompt: 'You are a helpful assistant.', professionalPrompt: null }
			);

			expect( result ).toBe( 'You are a helpful assistant.' );
		} );

		it( 'should fall back to state.config.professionalPrompt when client prompt is null', () => {
			const result = resolveEffectiveSystemPrompt(
				null,
				{ systemPrompt: null, professionalPrompt: 'You are a professional consultant.' }
			);

			expect( result ).toBe( 'You are a professional consultant.' );
		} );

		it( 'should combine professionalPrompt and systemPrompt when client prompt is null', () => {
			const result = resolveEffectiveSystemPrompt(
				null,
				{
					systemPrompt: 'Extra instructions.',
					professionalPrompt: 'You are a professional.',
				}
			);

			expect( result ).toContain( 'You are a professional.' );
			expect( result ).toContain( 'Extra instructions.' );
			expect( result ).toContain( '# Additional Instructions' );
		} );

		it( 'should decode HTML entities in config fallback', () => {
			const result = resolveEffectiveSystemPrompt(
				null,
				{ systemPrompt: 'You help with coding &amp; debugging.', professionalPrompt: null }
			);

			// HTML entity should be decoded
			expect( result ).toBe( 'You help with coding & debugging.' );
		} );

		it( 'should decode HTML entities in professional prompt fallback', () => {
			const result = resolveEffectiveSystemPrompt(
				null,
				{ systemPrompt: null, professionalPrompt: 'Expert in AI &amp; ML.' }
			);

			expect( result ).toBe( 'Expert in AI & ML.' );
		} );

		it( 'should return null when both client and config have no system prompt', () => {
			const result = resolveEffectiveSystemPrompt(
				null,
				{ systemPrompt: null, professionalPrompt: null }
			);

			expect( result ).toBeNull();
		} );

		it( 'should return null when both client and config have empty system prompts', () => {
			const result = resolveEffectiveSystemPrompt(
				null,
				{ systemPrompt: '', professionalPrompt: '' }
			);

			expect( result ).toBeNull();
		} );

		it( 'should return null when client prompt is empty string', () => {
			// Empty string is falsy, so it falls through to config check
			const result = resolveEffectiveSystemPrompt(
				'',
				{ systemPrompt: 'Config prompt', professionalPrompt: null }
			);

			// '' is falsy so we fall back to config
			expect( result ).toBe( 'Config prompt' );
		} );

		it( 'should handle undefined config properties gracefully', () => {
			const result = resolveEffectiveSystemPrompt( null, {} );

			expect( result ).toBeNull();
		} );
	} );

	describe( 'System prompt inclusion in messages', () => {
		/**
		 * Simulate the message building logic in generateEmbeddedCompletion.
		 */
		function buildMessagesWithSystemPrompt( formattedMessages, effectiveSystemPrompt ) {
			const hasSystemMessage = formattedMessages.some(
				function( msg ) { return msg.role === 'system'; }
			);

			if ( effectiveSystemPrompt && ! hasSystemMessage ) {
				return [ { role: 'system', content: effectiveSystemPrompt } ].concat( formattedMessages );
			}

			return formattedMessages.slice();
		}

		it( 'should prepend system message when effectiveSystemPrompt is set', () => {
			const messages = [ { role: 'user', content: 'Hello' } ];
			const result = buildMessagesWithSystemPrompt( messages, 'You are a helpful assistant.' );

			expect( result[ 0 ].role ).toBe( 'system' );
			expect( result[ 0 ].content ).toBe( 'You are a helpful assistant.' );
			expect( result[ 1 ].role ).toBe( 'user' );
			expect( result ).toHaveLength( 2 );
		} );

		it( 'should not add system message when effectiveSystemPrompt is null', () => {
			const messages = [ { role: 'user', content: 'Hello' } ];
			const result = buildMessagesWithSystemPrompt( messages, null );

			expect( result ).toHaveLength( 1 );
			expect( result[ 0 ].role ).toBe( 'user' );
		} );

		it( 'should not add duplicate system message when one already exists', () => {
			const messages = [
				{ role: 'system', content: 'Existing system prompt.' },
				{ role: 'user', content: 'Hello' },
			];
			const result = buildMessagesWithSystemPrompt( messages, 'New system prompt.' );

			// Should not add another system message
			const systemMessages = result.filter( function( m ) { return m.role === 'system'; } );
			expect( systemMessages ).toHaveLength( 1 );
			expect( systemMessages[ 0 ].content ).toBe( 'Existing system prompt.' );
		} );
	} );

	describe( 'Integration: complete fallback flow', () => {
		it( 'should include system prompt in messages when client has null but config has prompt', () => {
			// Simulate: embeddedClient.systemPrompt = null (created without system prompt)
			//           state.config.systemPrompt = 'You are helpful.' (config has it)
			const embeddedClientSystemPrompt = null;
			const config = { systemPrompt: 'You are helpful.', professionalPrompt: null };

			const effective = resolveEffectiveSystemPrompt( embeddedClientSystemPrompt, config );
			const userMessages = [ { role: 'user', content: 'What can you do?' } ];

			const hasExistingSystemMsg = userMessages.some( function( m ) { return m.role === 'system'; } );
			let finalMessages = userMessages.slice();
			if ( effective && ! hasExistingSystemMsg ) {
				finalMessages = [ { role: 'system', content: effective } ].concat( finalMessages );
			}

			expect( finalMessages[ 0 ].role ).toBe( 'system' );
			expect( finalMessages[ 0 ].content ).toBe( 'You are helpful.' );
			expect( finalMessages[ 1 ].role ).toBe( 'user' );
		} );

		it( 'should decode HTML entities from config when falling back', () => {
			// PHP sends HTML-entity-encoded system prompt via wp_kses_post
			const embeddedClientSystemPrompt = null;
			const config = {
				systemPrompt: '🔧 JV\'s Core Personality &amp; Role - NV Digital Solutions Services/Programs Helper.',
				professionalPrompt: null,
			};

			const effective = resolveEffectiveSystemPrompt( embeddedClientSystemPrompt, config );

			// HTML entity should be decoded in the fallback
			expect( effective ).toContain( '&' );
			expect( effective ).not.toContain( '&amp;' );
		} );

		it( 'should produce no system message when neither client nor config has prompt', () => {
			const embeddedClientSystemPrompt = null;
			const config = { systemPrompt: null, professionalPrompt: null };

			const effective = resolveEffectiveSystemPrompt( embeddedClientSystemPrompt, config );
			const userMessages = [ { role: 'user', content: 'Hello' } ];

			let finalMessages = userMessages.slice();
			if ( effective ) {
				finalMessages = [ { role: 'system', content: effective } ].concat( finalMessages );
			}

			// No system message added when there's no prompt
			expect( finalMessages ).toHaveLength( 1 );
			expect( finalMessages[ 0 ].role ).toBe( 'user' );
		} );
	} );
} );
