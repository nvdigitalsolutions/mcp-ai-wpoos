/**
 * Tests for WebLLM Function Calling Client (webllm-function-calling-client.js)
 *
 * Tests focus on the pure helper logic (bufferToolCalls) and the dependency-
 * waiting mechanism (waitForDependencies) that can be exercised without a live
 * WebLLM engine or DOM window globals.
 *
 * All functions under test are extracted or replicated from
 * assets/js/webllm-function-calling-client.js.
 *
 * @package WP_MCP_AI
 */

// ---------------------------------------------------------------------------
// Helper extracted from assets/js/webllm-function-calling-client.js
// ---------------------------------------------------------------------------

/**
 * Buffer streaming tool-call deltas into a complete tool-calls array.
 * Mirrors WebLLMFunctionCallingClient.bufferToolCalls().
 *
 * @param {Array} buffer        Accumulator – mutated in place.
 * @param {Array} toolCallDeltas Streaming delta fragments from WebLLM.
 */
function bufferToolCalls( buffer, toolCallDeltas ) {
	toolCallDeltas.forEach( function( delta ) {
		var index = delta.index || 0;

		if ( ! buffer[ index ] ) {
			buffer[ index ] = {
				id: delta.id || 'call_' + Date.now() + '_' + index,
				type: 'function',
				function: {
					name: ( delta.function && delta.function.name ) || '',
					arguments: ( delta.function && delta.function.arguments ) || '',
				},
			};
		} else {
			if ( delta.function && delta.function.name ) {
				buffer[ index ].function.name += delta.function.name;
			}
			if ( delta.function && delta.function.arguments ) {
				buffer[ index ].function.arguments += delta.function.arguments;
			}
		}
	} );
}

/**
 * Replicate the dependency-ready detection from waitForDependencies().
 * Returns true when both required globals are present.
 *
 * @param {Object} win Window-like object to check globals on.
 */
function areDependenciesReady( win ) {
	return !! ( win.WP_MCP_AI_EmbeddedLLM && win.WP_MCP_AI_ToolAdapter );
}

// ---------------------------------------------------------------------------
// Tests – bufferToolCalls
// ---------------------------------------------------------------------------

describe( 'WebLLM Function Calling Client – bufferToolCalls', () => {
	describe( 'initialising a new tool call', () => {
		it( 'creates a new entry at the correct index', () => {
			const buffer = [];
			const deltas = [
				{
					index: 0,
					id: 'call_abc',
					function: { name: 'get_weather', arguments: '' },
				},
			];

			bufferToolCalls( buffer, deltas );

			expect( buffer[ 0 ] ).toBeDefined();
			expect( buffer[ 0 ].id ).toBe( 'call_abc' );
			expect( buffer[ 0 ].type ).toBe( 'function' );
			expect( buffer[ 0 ].function.name ).toBe( 'get_weather' );
			expect( buffer[ 0 ].function.arguments ).toBe( '' );
		} );

		it( 'defaults id to a generated string when delta has no id', () => {
			const buffer = [];

			bufferToolCalls( buffer, [ { index: 0, function: { name: 'tool_x', arguments: '' } } ] );

			expect( typeof buffer[ 0 ].id ).toBe( 'string' );
			expect( buffer[ 0 ].id ).toMatch( /^call_/ );
		} );

		it( 'defaults index to 0 when delta has no index field', () => {
			const buffer = [];

			bufferToolCalls( buffer, [ { id: 'c1', function: { name: 'tool_y', arguments: '' } } ] );

			expect( buffer[ 0 ] ).toBeDefined();
			expect( buffer[ 0 ].id ).toBe( 'c1' );
		} );

		it( 'initialises arguments to empty string when not supplied', () => {
			const buffer = [];

			bufferToolCalls( buffer, [ { index: 0, id: 'c2', function: { name: 'tool_z' } } ] );

			expect( buffer[ 0 ].function.arguments ).toBe( '' );
		} );

		it( 'initialises name to empty string when function.name is absent', () => {
			const buffer = [];

			bufferToolCalls( buffer, [ { index: 0, id: 'c3', function: { arguments: '{}' } } ] );

			expect( buffer[ 0 ].function.name ).toBe( '' );
		} );

		it( 'handles a delta with no function property at all', () => {
			const buffer = [];

			bufferToolCalls( buffer, [ { index: 0, id: 'c4' } ] );

			expect( buffer[ 0 ].function.name ).toBe( '' );
			expect( buffer[ 0 ].function.arguments ).toBe( '' );
		} );
	} );

	describe( 'appending to an existing tool call (streaming)', () => {
		it( 'appends argument fragments across multiple deltas', () => {
			const buffer = [];

			// First delta – initialises the entry
			bufferToolCalls( buffer, [
				{ index: 0, id: 'call_1', function: { name: 'get_weather', arguments: '{"ci' } },
			] );

			// Subsequent deltas – argument continuation
			bufferToolCalls( buffer, [
				{ index: 0, function: { arguments: 'ty":' } },
			] );
			bufferToolCalls( buffer, [
				{ index: 0, function: { arguments: '"Paris"}' } },
			] );

			expect( buffer[ 0 ].function.arguments ).toBe( '{"city":"Paris"}' );
		} );

		it( 'appends name fragments when streamed in pieces', () => {
			const buffer = [];

			bufferToolCalls( buffer, [
				{ index: 0, id: 'call_2', function: { name: 'get_', arguments: '' } },
			] );
			bufferToolCalls( buffer, [
				{ index: 0, function: { name: 'weather' } },
			] );

			expect( buffer[ 0 ].function.name ).toBe( 'get_weather' );
		} );

		it( 'does not overwrite existing id on subsequent deltas', () => {
			const buffer = [];

			bufferToolCalls( buffer, [
				{ index: 0, id: 'original_id', function: { name: 'tool', arguments: '' } },
			] );
			bufferToolCalls( buffer, [
				{ index: 0, id: 'new_id', function: { arguments: '{}' } },
			] );

			// The id must remain unchanged after the first initialisation
			expect( buffer[ 0 ].id ).toBe( 'original_id' );
		} );

		it( 'ignores undefined name/arguments on subsequent deltas', () => {
			const buffer = [];

			bufferToolCalls( buffer, [
				{ index: 0, id: 'c5', function: { name: 'stable_tool', arguments: '{"a":1' } },
			] );
			// Delta with no function changes
			bufferToolCalls( buffer, [ { index: 0 } ] );

			expect( buffer[ 0 ].function.name ).toBe( 'stable_tool' );
			expect( buffer[ 0 ].function.arguments ).toBe( '{"a":1' );
		} );
	} );

	describe( 'multiple parallel tool calls', () => {
		it( 'handles two tool calls at different indices simultaneously', () => {
			const buffer = [];

			bufferToolCalls( buffer, [
				{ index: 0, id: 'c_a', function: { name: 'tool_a', arguments: '{"x":' } },
				{ index: 1, id: 'c_b', function: { name: 'tool_b', arguments: '{"y":' } },
			] );
			bufferToolCalls( buffer, [
				{ index: 0, function: { arguments: '1}' } },
				{ index: 1, function: { arguments: '2}' } },
			] );

			expect( buffer[ 0 ].function.name ).toBe( 'tool_a' );
			expect( buffer[ 0 ].function.arguments ).toBe( '{"x":1}' );
			expect( buffer[ 1 ].function.name ).toBe( 'tool_b' );
			expect( buffer[ 1 ].function.arguments ).toBe( '{"y":2}' );
		} );

		it( 'does not cross-contaminate arguments between indices', () => {
			const buffer = [];

			bufferToolCalls( buffer, [
				{ index: 0, id: 'c0', function: { name: 'a', arguments: 'AAA' } },
				{ index: 2, id: 'c2', function: { name: 'b', arguments: 'BBB' } },
			] );

			expect( buffer[ 0 ].function.arguments ).toBe( 'AAA' );
			expect( buffer[ 2 ].function.arguments ).toBe( 'BBB' );
			// Index 1 should not exist
			expect( buffer[ 1 ] ).toBeUndefined();
		} );
	} );

	describe( 'edge cases', () => {
		it( 'does nothing when toolCallDeltas is empty', () => {
			const buffer = [];
			bufferToolCalls( buffer, [] );
			expect( buffer ).toHaveLength( 0 );
		} );

		it( 'processes a single-chunk complete tool call', () => {
			const buffer = [];

			bufferToolCalls( buffer, [
				{
					index: 0,
					id: 'call_single',
					function: { name: 'full_tool', arguments: '{"complete":true}' },
				},
			] );

			expect( buffer[ 0 ].function.arguments ).toBe( '{"complete":true}' );
			expect( JSON.parse( buffer[ 0 ].function.arguments ).complete ).toBe( true );
		} );

		it( 'mutates the supplied buffer in place', () => {
			const buffer = [];
			const ref = buffer;

			bufferToolCalls( buffer, [ { index: 0, id: 'x', function: { name: 'n', arguments: '' } } ] );

			expect( buffer ).toBe( ref );
			expect( buffer ).toHaveLength( 1 );
		} );
	} );
} );

// ---------------------------------------------------------------------------
// Tests – dependency-readiness detection
// ---------------------------------------------------------------------------

describe( 'WebLLM Function Calling Client – dependency readiness', () => {
	it( 'returns false when both globals are absent', () => {
		expect( areDependenciesReady( {} ) ).toBe( false );
	} );

	it( 'returns false when only WP_MCP_AI_EmbeddedLLM is present', () => {
		expect( areDependenciesReady( { WP_MCP_AI_EmbeddedLLM: function() {} } ) ).toBe( false );
	} );

	it( 'returns false when only WP_MCP_AI_ToolAdapter is present', () => {
		expect( areDependenciesReady( { WP_MCP_AI_ToolAdapter: {} } ) ).toBe( false );
	} );

	it( 'returns true when both globals are present', () => {
		expect(
			areDependenciesReady( {
				WP_MCP_AI_EmbeddedLLM: function() {},
				WP_MCP_AI_ToolAdapter: {},
			} )
		).toBe( true );
	} );

	it( 'returns false when either global is falsy (null)', () => {
		expect(
			areDependenciesReady( {
				WP_MCP_AI_EmbeddedLLM: null,
				WP_MCP_AI_ToolAdapter: {},
			} )
		).toBe( false );

		expect(
			areDependenciesReady( {
				WP_MCP_AI_EmbeddedLLM: function() {},
				WP_MCP_AI_ToolAdapter: null,
			} )
		).toBe( false );
	} );
} );
