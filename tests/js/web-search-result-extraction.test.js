/**
 * Test suite for web_search tool result extraction and display
 * Tests the fix for web_search results not being properly displayed in chat client
 *
 * @package WP_MCP_AI
 */

describe( 'Web Search Tool Result Extraction', () => {
	// Mock implementation of the functions from chat.js
	function isWebSearchStructure( result ) {
		return result && 
			typeof result === 'object' &&
			typeof result.query === 'string' &&
			Array.isArray( result.results ) &&
			typeof result.provider === 'string';
	}

	function extractWebSearchSummary( result ) {
		if ( ! isWebSearchStructure( result ) ) {
			return null;
		}

		const query = result.query || '';
		const results = result.results || [];
		const provider = result.provider || 'search engine';
		const resultCount = results.length;

		let text = '';
		const links = [];

		// Build summary text
		if ( resultCount === 0 ) {
			if ( result.note && typeof result.note === 'string' ) {
				text = result.note;
			} else {
				text = 'No web search results found for "' + query + '"';
			}
		} else {
			// Format provider name nicely
			let providerName = provider;
			if ( provider === 'duckduckgo' ) {
				providerName = 'DuckDuckGo';
			} else if ( provider === 'brave' ) {
				providerName = 'Brave Search';
			}
			
			text = 'Found ' + resultCount + ' result' + ( resultCount !== 1 ? 's' : '' ) + 
				' for "' + query + '" (via ' + providerName + ')';

			// Add top results as links
			results.forEach( function ( item, index ) {
				if ( item && typeof item.url === 'string' && item.url.trim() ) {
					const linkLabel = item.title && typeof item.title === 'string' && item.title.trim() 
						? item.title.trim() 
						: 'Result ' + ( index + 1 );
					
					links.push( {
						url: item.url.trim(),
						label: linkLabel,
						snippet: item.snippet && typeof item.snippet === 'string' ? item.snippet.trim() : '',
					} );
				}
			} );
		}

		return {
			text: text,
			links: links,
		};
	}

	describe( 'Structure detection', () => {
		it( 'should identify valid DuckDuckGo search result structure', () => {
			const result = {
				query: 'test query',
				results: [],
				result_count: 0,
				provider: 'duckduckgo',
				timestamp: 1234567890,
			};

			expect( isWebSearchStructure( result ) ).toBe( true );
		} );

		it( 'should identify valid Brave search result structure', () => {
			const result = {
				query: 'test query',
				results: [
					{
						title: 'Example',
						url: 'https://example.com',
						snippet: 'Example snippet',
					},
				],
				result_count: 1,
				provider: 'brave',
			};

			expect( isWebSearchStructure( result ) ).toBe( true );
		} );

		it( 'should reject invalid structure missing query', () => {
			const result = {
				results: [],
				provider: 'duckduckgo',
			};

			expect( isWebSearchStructure( result ) ).toBe( false );
		} );

		it( 'should reject invalid structure missing results array', () => {
			const result = {
				query: 'test',
				provider: 'duckduckgo',
			};

			expect( isWebSearchStructure( result ) ).toBe( false );
		} );

		it( 'should reject invalid structure missing provider', () => {
			const result = {
				query: 'test',
				results: [],
			};

			expect( isWebSearchStructure( result ) ).toBe( false );
		} );

		it( 'should reject non-object input', () => {
			expect( isWebSearchStructure( null ) ).toBeFalsy();
			expect( isWebSearchStructure( undefined ) ).toBeFalsy();
			expect( isWebSearchStructure( 'string' ) ).toBeFalsy();
			expect( isWebSearchStructure( 123 ) ).toBeFalsy();
		} );
	} );

	describe( 'Summary extraction for empty results', () => {
		it( 'should handle empty results from DuckDuckGo', () => {
			const result = {
				query: 'nonexistent query',
				results: [],
				note: 'No web search results were found for this query.',
				cached: false,
				provider: 'duckduckgo',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary ).not.toBe( null );
			expect( summary.text ).toBe( 'No web search results were found for this query.' );
			expect( summary.links ).toEqual( [] );
		} );

		it( 'should handle empty results from Brave', () => {
			const result = {
				query: 'another nonexistent query',
				results: [],
				note: 'No web search results were found for this query.',
				cached: false,
				provider: 'brave',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.text ).toBe( 'No web search results were found for this query.' );
			expect( summary.links ).toEqual( [] );
		} );

		it( 'should use fallback text when note is missing', () => {
			const result = {
				query: 'test query',
				results: [],
				provider: 'duckduckgo',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.text ).toBe( 'No web search results found for "test query"' );
		} );
	} );

	describe( 'Summary extraction with results', () => {
		it( 'should format DuckDuckGo results correctly', () => {
			const result = {
				query: 'WordPress plugins',
				results: [
					{
						title: 'WordPress Plugin Directory',
						url: 'https://wordpress.org/plugins/',
						snippet: 'Browse thousands of free WordPress plugins',
						source: 'duckduckgo',
						type: 'abstract',
					},
					{
						title: 'Best WordPress Plugins',
						url: 'https://example.com/best-plugins',
						snippet: 'Top plugins for WordPress',
						source: 'duckduckgo',
						type: 'result',
					},
				],
				result_count: 2,
				cached: false,
				provider: 'duckduckgo',
				timestamp: 1234567890,
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.text ).toBe( 'Found 2 results for "WordPress plugins" (via DuckDuckGo)' );
			expect( summary.links ).toHaveLength( 2 );
			expect( summary.links[ 0 ].url ).toBe( 'https://wordpress.org/plugins/' );
			expect( summary.links[ 0 ].label ).toBe( 'WordPress Plugin Directory' );
			expect( summary.links[ 0 ].snippet ).toBe( 'Browse thousands of free WordPress plugins' );
		} );

		it( 'should format Brave Search results correctly', () => {
			const result = {
				query: 'artificial intelligence',
				results: [
					{
						title: 'OpenAI',
						url: 'https://openai.com',
						snippet: 'Creating safe AGI',
						source: 'brave',
						type: 'result',
					},
				],
				result_count: 1,
				cached: false,
				provider: 'brave',
				timestamp: 1234567890,
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.text ).toBe( 'Found 1 result for "artificial intelligence" (via Brave Search)' );
			expect( summary.links ).toHaveLength( 1 );
			expect( summary.links[ 0 ].url ).toBe( 'https://openai.com' );
			expect( summary.links[ 0 ].label ).toBe( 'OpenAI' );
		} );

		it( 'should handle singular vs plural results correctly', () => {
			const singleResult = {
				query: 'test',
				results: [
					{ title: 'Test', url: 'https://test.com', snippet: 'Test' },
				],
				provider: 'duckduckgo',
			};

			const multiResult = {
				query: 'test',
				results: [
					{ title: 'Test 1', url: 'https://test1.com', snippet: 'Test' },
					{ title: 'Test 2', url: 'https://test2.com', snippet: 'Test' },
					{ title: 'Test 3', url: 'https://test3.com', snippet: 'Test' },
				],
				provider: 'duckduckgo',
			};

			const singleSummary = extractWebSearchSummary( singleResult );
			expect( singleSummary.text ).toContain( '1 result' );
			expect( singleSummary.text ).not.toContain( 'results' );

			const multiSummary = extractWebSearchSummary( multiResult );
			expect( multiSummary.text ).toContain( '3 results' );
		} );

		it( 'should use fallback label when title is missing', () => {
			const result = {
				query: 'test',
				results: [
					{ url: 'https://example.com' }, // No title
					{ title: '', url: 'https://example2.com' }, // Empty title
				],
				provider: 'duckduckgo',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.links[ 0 ].label ).toBe( 'Result 1' );
			expect( summary.links[ 1 ].label ).toBe( 'Result 2' );
		} );

		it( 'should skip results without URLs', () => {
			const result = {
				query: 'test',
				results: [
					{ title: 'Valid Result', url: 'https://example.com', snippet: 'Valid' },
					{ title: 'Invalid Result' }, // No URL
					{ title: 'Empty URL', url: '' }, // Empty URL
					{ title: 'Another Valid', url: 'https://example2.com', snippet: 'Valid' },
				],
				provider: 'duckduckgo',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.links ).toHaveLength( 2 );
			expect( summary.links[ 0 ].label ).toBe( 'Valid Result' );
			expect( summary.links[ 1 ].label ).toBe( 'Another Valid' );
		} );

		it( 'should handle results without snippets', () => {
			const result = {
				query: 'test',
				results: [
					{ title: 'Test', url: 'https://test.com' }, // No snippet
				],
				provider: 'duckduckgo',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.links[ 0 ].snippet ).toBe( '' );
		} );

		it( 'should trim whitespace from URLs, titles, and snippets', () => {
			const result = {
				query: 'test',
				results: [
					{
						title: '  Padded Title  ',
						url: '  https://example.com  ',
						snippet: '  Padded snippet  ',
					},
				],
				provider: 'duckduckgo',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.links[ 0 ].url ).toBe( 'https://example.com' );
			expect( summary.links[ 0 ].label ).toBe( 'Padded Title' );
			expect( summary.links[ 0 ].snippet ).toBe( 'Padded snippet' );
		} );
	} );

	describe( 'Provider name formatting', () => {
		it( 'should format "duckduckgo" as "DuckDuckGo"', () => {
			const result = {
				query: 'test',
				results: [ { title: 'Test', url: 'https://test.com' } ],
				provider: 'duckduckgo',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.text ).toContain( 'via DuckDuckGo' );
		} );

		it( 'should format "brave" as "Brave Search"', () => {
			const result = {
				query: 'test',
				results: [ { title: 'Test', url: 'https://test.com' } ],
				provider: 'brave',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.text ).toContain( 'via Brave Search' );
		} );

		it( 'should use provider name as-is for unknown providers', () => {
			const result = {
				query: 'test',
				results: [ { title: 'Test', url: 'https://test.com' } ],
				provider: 'google',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.text ).toContain( 'via google' );
		} );
	} );

	describe( 'Edge cases', () => {
		it( 'should return null for invalid structure', () => {
			const invalid = {
				something: 'else',
			};

			expect( extractWebSearchSummary( invalid ) ).toBe( null );
		} );

		it( 'should handle result with all results filtered out', () => {
			const result = {
				query: 'test',
				results: [
					{ title: 'No URL 1' }, // No URL - will be filtered
					{ url: '' }, // Empty URL - will be filtered
				],
				provider: 'duckduckgo',
			};

			const summary = extractWebSearchSummary( result );
			// Should still generate text, but no links
			expect( summary.text ).toContain( 'Found 2 results' );
			expect( summary.links ).toEqual( [] );
		} );

		it( 'should not display [object Object] in summary text', () => {
			const result = {
				query: 'test query',
				results: [
					{
						title: 'Test Result',
						url: 'https://example.com',
						snippet: 'Test snippet',
					},
				],
				provider: 'duckduckgo',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.text ).not.toContain( '[object Object]' );
			expect( typeof summary.text ).toBe( 'string' );
		} );

		it( 'should handle cached results', () => {
			const result = {
				query: 'cached query',
				results: [
					{ title: 'Cached Result', url: 'https://cached.com' },
				],
				cached: true,
				provider: 'duckduckgo',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.text ).toBe( 'Found 1 result for "cached query" (via DuckDuckGo)' );
			// Cached flag doesn't affect the summary - it's handled elsewhere
		} );

		it( 'should handle maximum number of results', () => {
			const results = [];
			for ( let i = 1; i <= 10; i++ ) {
				results.push( {
					title: 'Result ' + i,
					url: 'https://example' + i + '.com',
					snippet: 'Snippet ' + i,
				} );
			}

			const result = {
				query: 'many results',
				results: results,
				result_count: 10,
				provider: 'brave',
			};

			const summary = extractWebSearchSummary( result );
			expect( summary.text ).toBe( 'Found 10 results for "many results" (via Brave Search)' );
			expect( summary.links ).toHaveLength( 10 );
		} );
	} );
} );
