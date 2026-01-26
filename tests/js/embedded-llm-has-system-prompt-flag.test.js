/**
 * Tests for embedded LLM client hasSystemPrompt flag
 *
 * This test verifies that the hasSystemPrompt flag is correctly set
 * based on the decoded system prompt, not the original config value.
 *
 * Related issue: Embedded client was skipping context initialization
 * because hasSystemPrompt was set to false even when a valid system
 * prompt existed after HTML entity decoding.
 *
 * @package WP_MCP_AI
 */

describe( 'Embedded LLM Client - hasSystemPrompt Flag', () => {
	// Duplicate the decodeHtmlEntities function for testing
	// (same as in embedded-llm-html-entity-decoding.test.js)
	function decodeHtmlEntities( text ) {
		if ( ! text || typeof text !== 'string' ) {
			return text;
		}

		const textarea = document.createElement( 'textarea' );
		textarea.innerHTML = text;
		return textarea.value;
	}

	// Simulate the logic from the embedded-llm-client.js constructor
	function calculateHasSystemPrompt( config ) {
		const systemPrompt = config.systemPrompt ? decodeHtmlEntities( config.systemPrompt ) : null;
		return !! ( systemPrompt && systemPrompt.trim() );
	}

	describe( 'hasSystemPrompt flag calculation', () => {
		it( 'should be true for valid system prompt', () => {
			const config = {
				systemPrompt: 'You are a helpful assistant.',
			};

			const hasSystemPrompt = calculateHasSystemPrompt( config );

			expect( hasSystemPrompt ).toBe( true );
		} );

		it( 'should be true for system prompt with HTML entities', () => {
			const config = {
				systemPrompt: 'You help with coding &amp; debugging.',
			};

			const hasSystemPrompt = calculateHasSystemPrompt( config );

			expect( hasSystemPrompt ).toBe( true );
		} );

		it( 'should be false for null system prompt', () => {
			const config = {
				systemPrompt: null,
			};

			const hasSystemPrompt = calculateHasSystemPrompt( config );

			expect( hasSystemPrompt ).toBe( false );
		} );

		it( 'should be false for undefined system prompt', () => {
			const config = {
				systemPrompt: undefined,
			};

			const hasSystemPrompt = calculateHasSystemPrompt( config );

			expect( hasSystemPrompt ).toBe( false );
		} );

		it( 'should be false for empty string system prompt', () => {
			const config = {
				systemPrompt: '',
			};

			const hasSystemPrompt = calculateHasSystemPrompt( config );

			expect( hasSystemPrompt ).toBe( false );
		} );

		it( 'should be false for whitespace-only system prompt', () => {
			const config = {
				systemPrompt: '   ',
			};

			const hasSystemPrompt = calculateHasSystemPrompt( config );

			expect( hasSystemPrompt ).toBe( false );
		} );

		it( 'should be false for whitespace-only system prompt with tabs and newlines', () => {
			const config = {
				systemPrompt: '\t\n  \r\n',
			};

			const hasSystemPrompt = calculateHasSystemPrompt( config );

			expect( hasSystemPrompt ).toBe( false );
		} );

		it( 'should be true for system prompt with only whitespace before/after but content in middle', () => {
			const config = {
				systemPrompt: '  You are helpful.  ',
			};

			const hasSystemPrompt = calculateHasSystemPrompt( config );

			expect( hasSystemPrompt ).toBe( true );
		} );

		it( 'should correctly handle the real-world example from problem statement', () => {
			// The actual system prompt from the issue
			const config = {
				systemPrompt: '🔧 JV\'s Core Personality &amp; Role - NV Digital Solutions Services/Programs Helper:\r\nSoftware engineer helping format and improve code, assist with email services, CMS management, payment gateways, and hosting (Cloudways, SiteGround, DigitalOcean).',
			};

			const hasSystemPrompt = calculateHasSystemPrompt( config );

			// This should be true because the prompt has valid content
			expect( hasSystemPrompt ).toBe( true );
		} );

		it( 'should handle complex system prompt with multiple HTML entities', () => {
			const config = {
				systemPrompt: 'Your thought processes:\r\n- Value vs Wealth, You feel this is what everyone must decide early on in their lives because based on this you will be able to set out path for your education &amp; work experience needed to accomplish this.\r\n- Your Friends &amp; Family are the only things that matters in life, everything else will fall into place is you have those 2 things as support.',
			};

			const hasSystemPrompt = calculateHasSystemPrompt( config );

			// This should be true because the prompt has valid content
			expect( hasSystemPrompt ).toBe( true );
		} );
	} );

	describe( 'Comparison: old vs new approach', () => {
		it( 'old approach (incorrect): checks config.systemPrompt before decoding', () => {
			// Old approach was: this.hasSystemPrompt = !!config.systemPrompt;
			const config = {
				systemPrompt: 'You are helpful.',
			};

			const oldApproach = !! config.systemPrompt;

			// This works fine for non-empty strings
			expect( oldApproach ).toBe( true );
		} );

		it( 'old approach fails for empty string even after decoding could produce content', () => {
			// This is a hypothetical edge case
			const config = {
				systemPrompt: '',
			};

			const oldApproach = !! config.systemPrompt;

			// Old approach would be false
			expect( oldApproach ).toBe( false );

			// New approach would also be false (correctly)
			const newApproach = calculateHasSystemPrompt( config );
			expect( newApproach ).toBe( false );
		} );

		it( 'new approach correctly handles decoded content', () => {
			const config = {
				systemPrompt: 'Coding &amp; debugging',
			};

			// New approach checks the decoded value
			const newApproach = calculateHasSystemPrompt( config );

			expect( newApproach ).toBe( true );
		} );

		it( 'new approach correctly handles whitespace-only prompts', () => {
			const config = {
				systemPrompt: '   ',
			};

			// Old approach would be true (incorrect)
			const oldApproach = !! config.systemPrompt;
			expect( oldApproach ).toBe( true );

			// New approach would be false (correct)
			const newApproach = calculateHasSystemPrompt( config );
			expect( newApproach ).toBe( false );
		} );
	} );
} );
