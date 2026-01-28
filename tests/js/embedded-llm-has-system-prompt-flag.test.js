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

describe( 'Embedded LLM Client - Configuration Flags', () => {
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

	describe( 'hasTools flag calculation', () => {
		// Simulate the logic from the embedded-llm-client.js constructor
		function calculateHasTools( config ) {
			const tools = config.tools || [];
			return !! ( tools && Array.isArray( tools ) && tools.length > 0 );
		}

		it( 'should be true for valid tools array', () => {
			const config = {
				tools: [ { name: 'search' }, { name: 'calculator' } ],
			};

			const hasTools = calculateHasTools( config );

			expect( hasTools ).toBe( true );
		} );

		it( 'should be true for single tool', () => {
			const config = {
				tools: [ { name: 'search' } ],
			};

			const hasTools = calculateHasTools( config );

			expect( hasTools ).toBe( true );
		} );

		it( 'should be false for empty tools array', () => {
			const config = {
				tools: [],
			};

			const hasTools = calculateHasTools( config );

			expect( hasTools ).toBe( false );
		} );

		it( 'should be false for null tools', () => {
			const config = {
				tools: null,
			};

			const hasTools = calculateHasTools( config );

			expect( hasTools ).toBe( false );
		} );

		it( 'should be false for undefined tools', () => {
			const config = {
				tools: undefined,
			};

			const hasTools = calculateHasTools( config );

			expect( hasTools ).toBe( false );
		} );

		it( 'should be false for non-array tools', () => {
			const config = {
				tools: 'not an array',
			};

			const hasTools = calculateHasTools( config );

			expect( hasTools ).toBe( false );
		} );

		it( 'should use stored value after normalization', () => {
			// When config.tools is undefined, it becomes [] after normalization
			const config = {
				tools: undefined,
			};

			// Simulate stored value normalization
			const storedTools = config.tools || [];
			const hasToolsOld = !! ( config.tools && Array.isArray( config.tools ) && config.tools.length > 0 );
			const hasToolsNew = !! ( storedTools && Array.isArray( storedTools ) && storedTools.length > 0 );

			// Both should be false (empty array)
			expect( hasToolsOld ).toBe( false );
			expect( hasToolsNew ).toBe( false );
		} );
	} );

	describe( 'hasKnowledge flag calculation', () => {
		// Simulate the logic from the embedded-llm-client.js constructor
		function calculateHasKnowledge( config ) {
			const memoryFiles = config.memoryFiles || [];
			const vectorStoreId = config.vectorStoreId || null;
			const hasMemoryFiles = !! ( memoryFiles && Array.isArray( memoryFiles ) && memoryFiles.length > 0 );
			return hasMemoryFiles || !! vectorStoreId;
		}

		it( 'should be true for valid memory files', () => {
			const config = {
				memoryFiles: [ { id: 1, name: 'doc1.pdf' } ],
				vectorStoreId: null,
			};

			const hasKnowledge = calculateHasKnowledge( config );

			expect( hasKnowledge ).toBe( true );
		} );

		it( 'should be true for vector store ID', () => {
			const config = {
				memoryFiles: [],
				vectorStoreId: 'vs_123',
			};

			const hasKnowledge = calculateHasKnowledge( config );

			expect( hasKnowledge ).toBe( true );
		} );

		it( 'should be true for both memory files and vector store', () => {
			const config = {
				memoryFiles: [ { id: 1, name: 'doc1.pdf' } ],
				vectorStoreId: 'vs_123',
			};

			const hasKnowledge = calculateHasKnowledge( config );

			expect( hasKnowledge ).toBe( true );
		} );

		it( 'should be false for empty memory files and no vector store', () => {
			const config = {
				memoryFiles: [],
				vectorStoreId: null,
			};

			const hasKnowledge = calculateHasKnowledge( config );

			expect( hasKnowledge ).toBe( false );
		} );

		it( 'should be false for null/undefined memory files and vector store', () => {
			const config = {
				memoryFiles: null,
				vectorStoreId: undefined,
			};

			const hasKnowledge = calculateHasKnowledge( config );

			expect( hasKnowledge ).toBe( false );
		} );

		it( 'should use stored values after normalization', () => {
			// When config values are undefined, they become normalized
			const config = {
				memoryFiles: undefined,
				vectorStoreId: undefined,
			};

			// Simulate stored value normalization
			const storedMemoryFiles = config.memoryFiles || [];
			const storedVectorStoreId = config.vectorStoreId || null;
			
			const hasKnowledgeOld = !! ( ( config.memoryFiles && Array.isArray( config.memoryFiles ) && config.memoryFiles.length > 0 ) || config.vectorStoreId );
			const hasKnowledgeNew = !! ( ( storedMemoryFiles && Array.isArray( storedMemoryFiles ) && storedMemoryFiles.length > 0 ) || storedVectorStoreId );

			// Both should be false
			expect( hasKnowledgeOld ).toBe( false );
			expect( hasKnowledgeNew ).toBe( false );
		} );
	} );
} );
