/**
 * Tests for embedded LLM client HTML entity decoding
 *
 * This test verifies that the embedded LLM client properly decodes HTML entities
 * in the system prompt that may have been introduced by WordPress sanitization.
 *
 * @package WP_MCP_AI
 */

describe( 'Embedded LLM Client - HTML Entity Decoding', () => {
	// Note: The decodeHtmlEntities function is internal to embedded-llm-client.js (IIFE)
	// and not exported. We duplicate the implementation here to test the behavior.
	// This ensures the logic works correctly and documents the expected behavior.
	// Any changes to the actual implementation should be reflected here.
	function decodeHtmlEntities( text ) {
		if ( ! text || typeof text !== 'string' ) {
			return text;
		}

		const textarea = document.createElement( 'textarea' );
		textarea.innerHTML = text;
		return textarea.value;
	}

	describe( 'decodeHtmlEntities function', () => {
		it( 'should decode &amp; to &', () => {
			const input = 'Value &amp; Wealth';
			const expected = 'Value & Wealth';
			const result = decodeHtmlEntities( input );
			expect( result ).toBe( expected );
		} );

		it( 'should decode &lt; to <', () => {
			const input = '&lt;div&gt;';
			const expected = '<div>';
			const result = decodeHtmlEntities( input );
			expect( result ).toBe( expected );
		} );

		it( 'should decode &gt; to >', () => {
			const input = 'a &gt; b';
			const expected = 'a > b';
			const result = decodeHtmlEntities( input );
			expect( result ).toBe( expected );
		} );

		it( 'should decode &quot; to "', () => {
			const input = '&quot;Hello&quot;';
			const expected = '"Hello"';
			const result = decodeHtmlEntities( input );
			expect( result ).toBe( expected );
		} );

		it( 'should decode &#39; to \'', () => {
			const input = 'It&#39;s great';
			const expected = "It's great";
			const result = decodeHtmlEntities( input );
			expect( result ).toBe( expected );
		} );

		it( 'should decode multiple entities in one string', () => {
			const input = 'You &amp; your partner need to have mutual respect &amp; patience';
			const expected = 'You & your partner need to have mutual respect & patience';
			const result = decodeHtmlEntities( input );
			expect( result ).toBe( expected );
		} );

		it( 'should handle text without entities', () => {
			const input = 'This is plain text without any entities';
			const expected = 'This is plain text without any entities';
			const result = decodeHtmlEntities( input );
			expect( result ).toBe( expected );
		} );

		it( 'should handle null input', () => {
			const result = decodeHtmlEntities( null );
			expect( result ).toBeNull();
		} );

		it( 'should handle undefined input', () => {
			const result = decodeHtmlEntities( undefined );
			expect( result ).toBeUndefined();
		} );

		it( 'should handle empty string', () => {
			const input = '';
			const expected = '';
			const result = decodeHtmlEntities( input );
			expect( result ).toBe( expected );
		} );

		it( 'should handle non-string input', () => {
			const result = decodeHtmlEntities( 123 );
			expect( result ).toBe( 123 );
		} );

		it( 'should decode real-world system prompt example', () => {
			// This is the actual issue from the problem statement
			const input = '🔧 JV\'s Core Personality &amp; Role - NV Digital Solutions Services/Programs Helper:\r\nSoftware engineer helping format and improve code, assist with email services, CMS management, payment gateways, and hosting (Cloudways, SiteGround, DigitalOcean).';
			const result = decodeHtmlEntities( input );
			
			// The key assertion is that &amp; is decoded to &
			expect( result ).toContain( '&' );
			expect( result ).not.toContain( '&amp;' );
			expect( result ).toContain( 'Personality & Role' );
		} );

		it( 'should handle complex system prompt with multiple encoded entities', () => {
			const input = 'Your thought processes:\r\n- Value vs Wealth, You feel this is what everyone must decide early on in their lives because based on this you will be able to set out path for your education &amp; work experience needed to accomplish this.\r\n- Your Friends &amp; Family are the only things that matters in life, everything else will fall into place is you have those 2 things as support.';
			const result = decodeHtmlEntities( input );
			
			// The key assertions are that &amp; entities are decoded to &
			expect( result ).toContain( 'education & work experience' );
			expect( result ).toContain( 'Friends & Family' );
			expect( result ).not.toContain( '&amp;' );
		} );
	} );

	describe( 'System prompt sanitization flow', () => {
		it( 'should simulate WordPress wp_kses_post() behavior', () => {
			// wp_kses_post() converts & to &amp;
			const originalPrompt = 'Value & Wealth';
			const sanitizedPrompt = originalPrompt.replace( /&/g, '&amp;' );
			
			expect( sanitizedPrompt ).toBe( 'Value &amp; Wealth' );
			
			// Our decoding should reverse this
			const decodedPrompt = decodeHtmlEntities( sanitizedPrompt );
			expect( decodedPrompt ).toBe( originalPrompt );
		} );

		it( 'should handle round-trip encoding/decoding', () => {
			const original = 'Software & Services: <setup> "test" \'example\'';
			
			// Simulate WordPress sanitization
			const textarea = document.createElement( 'textarea' );
			textarea.textContent = original;
			const htmlEncoded = textarea.innerHTML;
			
			// Decode back
			const decoded = decodeHtmlEntities( htmlEncoded );
			
			expect( decoded ).toBe( original );
		} );
	} );

	describe( 'Integration with embedded client configuration', () => {
		it( 'should decode system prompt from config object', () => {
			// Simulate config object from wp_json_encode() with HTML entities
			const config = {
				systemPrompt: 'You are a helpful assistant. You help with coding &amp; debugging.',
				tools: [],
				memoryFiles: [],
				vectorStoreId: null,
			};

			// Simulate what the embedded client constructor does
			const systemPrompt = config.systemPrompt ? decodeHtmlEntities( config.systemPrompt ) : null;

			expect( systemPrompt ).toBe( 'You are a helpful assistant. You help with coding & debugging.' );
		} );

		it( 'should handle null system prompt in config', () => {
			const config = {
				systemPrompt: null,
				tools: [],
				memoryFiles: [],
				vectorStoreId: null,
			};

			const systemPrompt = config.systemPrompt ? decodeHtmlEntities( config.systemPrompt ) : null;

			expect( systemPrompt ).toBeNull();
		} );

		it( 'should handle missing system prompt in config', () => {
			const config = {
				tools: [],
				memoryFiles: [],
				vectorStoreId: null,
			};

			const systemPrompt = config.systemPrompt ? decodeHtmlEntities( config.systemPrompt ) : null;

			expect( systemPrompt ).toBeNull();
		} );
	} );
} );
