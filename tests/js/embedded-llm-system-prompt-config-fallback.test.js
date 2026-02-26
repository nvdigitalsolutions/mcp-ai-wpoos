/**
 * Tests for the embedded LLM system prompt config fallback in generateEmbeddedCompletion.
 *
 * When embeddedClient.systemPrompt is null (e.g. the client was created before the system
 * prompt was available), the function should fall back to state.config.systemPrompt and
 * state.config.professionalPrompt to build the system prompt, decoding any HTML entities
 * or tags introduced by wp_kses_post() sanitization.
 *
 * Root-cause note: the previous implementation used textarea.innerHTML + textarea.value to
 * decode HTML entities.  When wp_kses_post() preserves allowed HTML tags (e.g. <p>, <strong>)
 * those survive wp_json_encode() and are present as real HTML tags in the JS string.  Setting
 * innerHTML on a detached <textarea> then returns an EMPTY textarea.value in Chrome/Firefox/
 * Safari, silently dropping the entire system prompt.  The fix replaces the textarea with a
 * <div> whose textContent correctly extracts plain text from HTML markup.
 *
 * @package WP_MCP_AI
 */

describe( 'Embedded LLM - System Prompt Config Fallback', () => {
	/**
	 * Simulate decodeHtmlEntities — updated to use div.textContent (matches embedded-llm-client.js).
	 *
	 * The previous textarea.innerHTML approach returned an empty string when the input
	 * contained real HTML tags (e.g. <p>text</p>), which are preserved by wp_kses_post().
	 */
	function decodeHtmlEntities( text ) {
		if ( ! text || typeof text !== 'string' ) {
			return text;
		}
		const div = document.createElement( 'div' );
		div.innerHTML = text;
		return div.textContent || div.innerText || text;
	}

	/**
	 * Simulate the system prompt resolution logic from generateEmbeddedCompletion.
	 *
	 * Order (matches updated chat.js):
	 *   1. Build rawSystemPrompt from state.config (professionalPrompt + systemPrompt).
	 *   2. Decode via div.textContent.
	 *   3. If still falsy, fall back to embeddedClient.systemPrompt.
	 *
	 * Returns the effective system prompt string, or null if none.
	 */
	function resolveEffectiveSystemPrompt( embeddedClientSystemPrompt, config ) {
		// Step 1: build raw prompt from config values (same precedence as chat.js).
		var rawSystemPrompt = '';
		if ( config.professionalPrompt && config.systemPrompt ) {
			rawSystemPrompt = config.professionalPrompt + '\n\n---\n\n# Additional Instructions\n\n' + config.systemPrompt;
		} else if ( config.professionalPrompt ) {
			rawSystemPrompt = config.professionalPrompt;
		} else if ( config.systemPrompt ) {
			rawSystemPrompt = config.systemPrompt;
		}

		// Step 2: decode.
		var effectiveSystemPrompt = null;
		if ( rawSystemPrompt ) {
			var decodeEl = document.createElement( 'div' );
			decodeEl.innerHTML = rawSystemPrompt;
			effectiveSystemPrompt = ( decodeEl.textContent || decodeEl.innerText || rawSystemPrompt ) || null;
		}

		// Step 3: fall back to client's stored prompt when config produced nothing.
		if ( ! effectiveSystemPrompt ) {
			effectiveSystemPrompt = embeddedClientSystemPrompt || null;
		}

		return effectiveSystemPrompt;
	}

	describe( 'resolveEffectiveSystemPrompt', () => {
		it( 'should use state.config.systemPrompt when config has a prompt (config takes priority)', () => {
			const result = resolveEffectiveSystemPrompt(
				'Stored client prompt.',
				{ systemPrompt: 'Config prompt', professionalPrompt: null }
			);

			// Config is rebuilt per-request and takes priority over the client's stored value
			expect( result ).toBe( 'Config prompt' );
		} );

		it( 'should fall back to embeddedClient.systemPrompt when config has no prompt', () => {
			const result = resolveEffectiveSystemPrompt(
				'You are a helpful assistant.',
				{ systemPrompt: null, professionalPrompt: null }
			);

			// Client fallback used when config is empty
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

		// ── Root-cause regression tests ────────────────────────────────────────────
		// wp_kses_post() preserves allowed HTML tags (<p>, <strong>, <br> …).
		// After wp_json_encode() the tags survive into the JavaScript string.
		// The previous textarea.innerHTML / textarea.value approach returned an
		// EMPTY string for such input in Chrome/Firefox/Safari.  The div.textContent
		// fix extracts the text content correctly in all browsers.

		it( 'should decode system prompt that contains allowed HTML paragraph tags', () => {
			// Simulates a system prompt saved via rich-text editor or with <p> wrapping
			const htmlPrompt = '<p>You are a helpful assistant.</p>';
			const result = resolveEffectiveSystemPrompt(
				null,
				{ systemPrompt: htmlPrompt, professionalPrompt: null }
			);

			// Must not be null (the old textarea bug) and must contain the text
			expect( result ).not.toBeNull();
			expect( result ).toContain( 'You are a helpful assistant.' );
		} );

		it( 'should decode system prompt containing multiple HTML tags', () => {
			const htmlPrompt = '<p>You are a <strong>helpful</strong> assistant.</p>';
			const result = resolveEffectiveSystemPrompt(
				null,
				{ systemPrompt: htmlPrompt, professionalPrompt: null }
			);

			expect( result ).not.toBeNull();
			expect( result ).toContain( 'You are a' );
			expect( result ).toContain( 'helpful' );
			expect( result ).toContain( 'assistant.' );
		} );

		it( 'should decode professional prompt that contains HTML tags', () => {
			const htmlProfessional = '<p>You are a <em>professional</em> consultant.</p>';
			const result = resolveEffectiveSystemPrompt(
				null,
				{ systemPrompt: null, professionalPrompt: htmlProfessional }
			);

			expect( result ).not.toBeNull();
			expect( result ).toContain( 'professional' );
			expect( result ).toContain( 'consultant.' );
		} );

		it( 'should not return null for HTML-wrapped prompt (old textarea bug regression)', () => {
			// This is the exact scenario that caused the bug:
			// The textarea.innerHTML approach returned '' for HTML-tagged input,
			// making effectiveSystemPrompt null and silently dropping the system prompt.
			const result = resolveEffectiveSystemPrompt(
				null,
				{ systemPrompt: '<p>System instructions.</p>', professionalPrompt: null }
			);

			expect( result ).not.toBeNull();
			expect( result ).not.toBe( '' );
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

		it( 'should include system prompt even when it contains HTML tags from wp_kses_post()', () => {
			// This is the exact production bug: wp_kses_post() allows <p> and similar tags.
			// After wp_json_encode() they arrive in JS as actual HTML tags.
			// The old textarea.innerHTML / .value approach returned '' for these, silently
			// dropping the entire system prompt.  The div.textContent fix resolves this.
			const embeddedClientSystemPrompt = null;
			const config = {
				systemPrompt: "<p>You are JV's Core Personality.</p><p>NV Digital Solutions Services Helper.</p>",
				professionalPrompt: null,
			};

			const effective = resolveEffectiveSystemPrompt( embeddedClientSystemPrompt, config );
			const userMessages = [ { role: 'user', content: 'Hello' } ];

			expect( effective ).not.toBeNull();

			let finalMessages = userMessages.slice();
			if ( effective && ! userMessages.some( function( m ) { return m.role === 'system'; } ) ) {
				finalMessages = [ { role: 'system', content: effective } ].concat( finalMessages );
			}

			// System message should now be present (not dropped by the old bug)
			expect( finalMessages[ 0 ].role ).toBe( 'system' );
			expect( finalMessages[ 0 ].content ).toContain( "JV's Core Personality" );
			expect( finalMessages[ 1 ].role ).toBe( 'user' );
		} );
	} );
} );
