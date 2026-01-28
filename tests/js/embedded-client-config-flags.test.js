/**
 * Tests for embedded client configuration flag checks in chat.js
 *
 * This test verifies that when creating an embedded client instance,
 * the capability flags (hasTools, hasKnowledge, hasSystemPrompt) are
 * correctly evaluated based on the assistantConfig object rather than
 * the original state.config values.
 *
 * Related issue: The chat client was checking state.config values instead
 * of assistantConfig values when deciding whether to use the enhanced
 * client. This caused it to miss the combined system prompt (assistant +
 * professional) and always create the basic client.
 *
 * @package WP_MCP_AI
 */

describe( 'Embedded Client - Configuration Flag Checks', () => {
	/**
	 * Helper function to build complete system prompt from state config
	 * This mirrors the logic in chat.js lines 11482-11496
	 */
	function buildCompleteSystemPrompt( stateConfig ) {
		let completeSystemPrompt = stateConfig.systemPrompt || '';
		if ( stateConfig.professionalPrompt ) {
			if ( completeSystemPrompt ) {
				completeSystemPrompt = completeSystemPrompt + '\n\n' + stateConfig.professionalPrompt;
			} else {
				completeSystemPrompt = stateConfig.professionalPrompt;
			}
		}
		return completeSystemPrompt;
	}

	/**
	 * Simulate the logic from chat.js sendChatEmbedded function
	 * This is the NEW (correct) behavior after the fix
	 */
	function calculateCapabilityFlags( assistantConfig ) {
		// Note: Using !! to ensure boolean return type for test assertions
		const hasTools = !! ( assistantConfig.tools && Array.isArray( assistantConfig.tools ) && assistantConfig.tools.length > 0 );
		const hasKnowledge = !! ( ( assistantConfig.memoryFiles && Array.isArray( assistantConfig.memoryFiles ) && assistantConfig.memoryFiles.length > 0 ) ||
			assistantConfig.vectorStoreId );
		const hasSystemPrompt = !! ( assistantConfig.systemPrompt && assistantConfig.systemPrompt.trim() );

		return { hasTools, hasKnowledge, hasSystemPrompt };
	}

	/**
	 * Simulate the OLD (incorrect) behavior before the fix
	 */
	function calculateCapabilityFlagsOld( stateConfig ) {
		// Note: Using !! to ensure boolean return type for test assertions
		const hasTools = !! ( stateConfig.tools && Array.isArray( stateConfig.tools ) && stateConfig.tools.length > 0 );
		const hasKnowledge = !! ( ( stateConfig.memoryFiles && Array.isArray( stateConfig.memoryFiles ) && stateConfig.memoryFiles.length > 0 ) ||
			stateConfig.vectorStoreId );
		const hasSystemPrompt = !! ( stateConfig.systemPrompt || stateConfig.professionalPrompt );

		return { hasTools, hasKnowledge, hasSystemPrompt };
	}

	describe( 'System prompt combination scenario', () => {
		it( 'should detect system prompt when combined from assistant + professional prompts', () => {
			// Simulate the scenario from the issue:
			// - state.config has separate systemPrompt and professionalPrompt
			// - assistantConfig has combined completeSystemPrompt
			const stateConfig = {
				systemPrompt: '', // Empty or missing
				professionalPrompt: 'You are a professional developer.', // Professional role
				tools: [],
				memoryFiles: [],
				vectorStoreId: null,
			};

			// Build combined system prompt using helper
			const completeSystemPrompt = buildCompleteSystemPrompt( stateConfig );

			const assistantConfig = {
				systemPrompt: completeSystemPrompt,
				tools: stateConfig.tools || [],
				memoryFiles: stateConfig.memoryFiles || [],
				vectorStoreId: stateConfig.vectorStoreId,
			};

			// Old approach: checks state.config (INCORRECT)
			const oldFlags = calculateCapabilityFlagsOld( stateConfig );

			// New approach: checks assistantConfig (CORRECT)
			const newFlags = calculateCapabilityFlags( assistantConfig );

			// Old approach would have hasSystemPrompt = true (checking professionalPrompt)
			// But the check was inconsistent - it wouldn't see the COMBINED prompt
			expect( oldFlags.hasSystemPrompt ).toBe( true ); // Checking OR of two separate values

			// New approach correctly sees the combined prompt
			expect( newFlags.hasSystemPrompt ).toBe( true );
			expect( assistantConfig.systemPrompt ).toBe( 'You are a professional developer.' );
		} );

		it( 'should detect system prompt when both assistant and professional prompts exist', () => {
			const stateConfig = {
				systemPrompt: 'You are an assistant.',
				professionalPrompt: 'You specialize in coding.',
				tools: [],
				memoryFiles: [],
				vectorStoreId: null,
			};

			// Build combined system prompt using helper
			const completeSystemPrompt = buildCompleteSystemPrompt( stateConfig );

			const assistantConfig = {
				systemPrompt: completeSystemPrompt,
				tools: stateConfig.tools || [],
				memoryFiles: stateConfig.memoryFiles || [],
				vectorStoreId: stateConfig.vectorStoreId,
			};

			// Old approach
			const oldFlags = calculateCapabilityFlagsOld( stateConfig );

			// New approach
			const newFlags = calculateCapabilityFlags( assistantConfig );

			// Both should detect system prompt
			expect( oldFlags.hasSystemPrompt ).toBe( true );
			expect( newFlags.hasSystemPrompt ).toBe( true );

			// But new approach sees the COMBINED prompt
			expect( assistantConfig.systemPrompt ).toBe( 'You are an assistant.\n\nYou specialize in coding.' );
		} );

		it( 'should NOT detect system prompt when both are empty', () => {
			const stateConfig = {
				systemPrompt: '',
				professionalPrompt: '',
				tools: [],
				memoryFiles: [],
				vectorStoreId: null,
			};

			// Build combined system prompt using helper
			const completeSystemPrompt = buildCompleteSystemPrompt( stateConfig );

			const assistantConfig = {
				systemPrompt: completeSystemPrompt,
				tools: stateConfig.tools || [],
				memoryFiles: stateConfig.memoryFiles || [],
				vectorStoreId: stateConfig.vectorStoreId,
			};

			// Old approach
			const oldFlags = calculateCapabilityFlagsOld( stateConfig );

			// New approach
			const newFlags = calculateCapabilityFlags( assistantConfig );

			// Both should NOT detect system prompt
			expect( oldFlags.hasSystemPrompt ).toBe( false );
			expect( newFlags.hasSystemPrompt ).toBe( false );
		} );
	} );

	describe( 'Tools configuration', () => {
		it( 'should correctly detect tools in assistantConfig', () => {
			const stateConfig = {
				systemPrompt: '',
				professionalPrompt: '',
				tools: [ { name: 'search' } ],
				memoryFiles: [],
				vectorStoreId: null,
			};

			const assistantConfig = {
				systemPrompt: '',
				tools: stateConfig.tools || [],
				memoryFiles: stateConfig.memoryFiles || [],
				vectorStoreId: stateConfig.vectorStoreId,
			};

			const flags = calculateCapabilityFlags( assistantConfig );

			expect( flags.hasTools ).toBe( true );
			expect( flags.hasKnowledge ).toBe( false );
			expect( flags.hasSystemPrompt ).toBe( false );
		} );

		it( 'should handle empty tools array', () => {
			const stateConfig = {
				systemPrompt: '',
				professionalPrompt: '',
				tools: [],
				memoryFiles: [],
				vectorStoreId: null,
			};

			const assistantConfig = {
				systemPrompt: '',
				tools: stateConfig.tools || [],
				memoryFiles: stateConfig.memoryFiles || [],
				vectorStoreId: stateConfig.vectorStoreId,
			};

			const flags = calculateCapabilityFlags( assistantConfig );

			expect( flags.hasTools ).toBe( false );
		} );
	} );

	describe( 'Knowledge configuration', () => {
		it( 'should correctly detect memory files in assistantConfig', () => {
			const stateConfig = {
				systemPrompt: '',
				professionalPrompt: '',
				tools: [],
				memoryFiles: [ { id: 1, name: 'doc.pdf' } ],
				vectorStoreId: null,
			};

			const assistantConfig = {
				systemPrompt: '',
				tools: stateConfig.tools || [],
				memoryFiles: stateConfig.memoryFiles || [],
				vectorStoreId: stateConfig.vectorStoreId,
			};

			const flags = calculateCapabilityFlags( assistantConfig );

			expect( flags.hasTools ).toBe( false );
			expect( flags.hasKnowledge ).toBe( true );
			expect( flags.hasSystemPrompt ).toBe( false );
		} );

		it( 'should correctly detect vector store in assistantConfig', () => {
			const stateConfig = {
				systemPrompt: '',
				professionalPrompt: '',
				tools: [],
				memoryFiles: [],
				vectorStoreId: 'vs_123',
			};

			const assistantConfig = {
				systemPrompt: '',
				tools: stateConfig.tools || [],
				memoryFiles: stateConfig.memoryFiles || [],
				vectorStoreId: stateConfig.vectorStoreId,
			};

			const flags = calculateCapabilityFlags( assistantConfig );

			expect( flags.hasTools ).toBe( false );
			expect( flags.hasKnowledge ).toBe( true );
			expect( flags.hasSystemPrompt ).toBe( false );
		} );

		it( 'should detect knowledge when both memory files and vector store exist', () => {
			const stateConfig = {
				systemPrompt: '',
				professionalPrompt: '',
				tools: [],
				memoryFiles: [ { id: 1, name: 'doc.pdf' } ],
				vectorStoreId: 'vs_123',
			};

			const assistantConfig = {
				systemPrompt: '',
				tools: stateConfig.tools || [],
				memoryFiles: stateConfig.memoryFiles || [],
				vectorStoreId: stateConfig.vectorStoreId,
			};

			const flags = calculateCapabilityFlags( assistantConfig );

			expect( flags.hasKnowledge ).toBe( true );
		} );
	} );

	describe( 'Client type selection', () => {
		it( 'should use enhanced client when system prompt exists', () => {
			const stateConfig = {
				systemPrompt: '',
				professionalPrompt: 'You are a professional.',
				tools: [],
				memoryFiles: [],
				vectorStoreId: null,
			};

			const completeSystemPrompt = buildCompleteSystemPrompt( stateConfig );

			const assistantConfig = {
				systemPrompt: completeSystemPrompt,
				tools: stateConfig.tools || [],
				memoryFiles: stateConfig.memoryFiles || [],
				vectorStoreId: stateConfig.vectorStoreId,
			};

			const flags = calculateCapabilityFlags( assistantConfig );

			// Should use enhanced client (any of the flags is true)
			const shouldUseEnhanced = flags.hasTools || flags.hasKnowledge || flags.hasSystemPrompt;
			expect( shouldUseEnhanced ).toBe( true );
		} );

		it( 'should use enhanced client when tools exist', () => {
			const assistantConfig = {
				systemPrompt: '',
				tools: [ { name: 'search' } ],
				memoryFiles: [],
				vectorStoreId: null,
			};

			const flags = calculateCapabilityFlags( assistantConfig );

			const shouldUseEnhanced = flags.hasTools || flags.hasKnowledge || flags.hasSystemPrompt;
			expect( shouldUseEnhanced ).toBe( true );
		} );

		it( 'should use enhanced client when knowledge exists', () => {
			const assistantConfig = {
				systemPrompt: '',
				tools: [],
				memoryFiles: [ { id: 1 } ],
				vectorStoreId: null,
			};

			const flags = calculateCapabilityFlags( assistantConfig );

			const shouldUseEnhanced = flags.hasTools || flags.hasKnowledge || flags.hasSystemPrompt;
			expect( shouldUseEnhanced ).toBe( true );
		} );

		it( 'should use basic client when no capabilities exist', () => {
			const assistantConfig = {
				systemPrompt: '',
				tools: [],
				memoryFiles: [],
				vectorStoreId: null,
			};

			const flags = calculateCapabilityFlags( assistantConfig );

			const shouldUseEnhanced = flags.hasTools || flags.hasKnowledge || flags.hasSystemPrompt;
			expect( shouldUseEnhanced ).toBe( false );
		} );
	} );

	describe( 'Edge cases', () => {
		it( 'should handle whitespace-only combined system prompt', () => {
			const stateConfig = {
				systemPrompt: '  ',
				professionalPrompt: '\n\t',
				tools: [],
				memoryFiles: [],
				vectorStoreId: null,
			};

			const completeSystemPrompt = buildCompleteSystemPrompt( stateConfig );

			const assistantConfig = {
				systemPrompt: completeSystemPrompt,
				tools: stateConfig.tools || [],
				memoryFiles: stateConfig.memoryFiles || [],
				vectorStoreId: stateConfig.vectorStoreId,
			};

			const flags = calculateCapabilityFlags( assistantConfig );

			// Should NOT detect system prompt (only whitespace)
			expect( flags.hasSystemPrompt ).toBe( false );
		} );

		it( 'should handle null values gracefully', () => {
			const assistantConfig = {
				systemPrompt: null,
				tools: null,
				memoryFiles: null,
				vectorStoreId: null,
			};

			// This should not throw an error
			expect( () => calculateCapabilityFlags( assistantConfig ) ).not.toThrow();

			const flags = calculateCapabilityFlags( assistantConfig );

			expect( flags.hasTools ).toBe( false );
			expect( flags.hasKnowledge ).toBe( false );
			expect( flags.hasSystemPrompt ).toBe( false );
		} );

		it( 'should handle undefined values gracefully', () => {
			const assistantConfig = {
				systemPrompt: undefined,
				tools: undefined,
				memoryFiles: undefined,
				vectorStoreId: undefined,
			};

			// This should not throw an error
			expect( () => calculateCapabilityFlags( assistantConfig ) ).not.toThrow();

			const flags = calculateCapabilityFlags( assistantConfig );

			expect( flags.hasTools ).toBe( false );
			expect( flags.hasKnowledge ).toBe( false );
			expect( flags.hasSystemPrompt ).toBe( false );
		} );
	} );
} );
