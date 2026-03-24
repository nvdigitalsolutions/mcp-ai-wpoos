/**
 * Tests for WebLLM Tool Adapter (webllm-tool-adapter.js)
 *
 * These tests cover the schema/tool conversion logic of the ToolAdapter class
 * used to bridge WordPress tool definitions and the OpenAI function-calling API.
 *
 * All functions under test are extracted as pure helpers mirroring the
 * implementations in assets/js/webllm-tool-adapter.js.
 *
 * @package WP_MCP_AI
 */

// ---------------------------------------------------------------------------
// Helpers extracted from assets/js/webllm-tool-adapter.js
// ---------------------------------------------------------------------------

/**
 * Convert a WordPress tool parameter schema to the OpenAI JSON-Schema format.
 * Mirrors ToolAdapter.convertSchema().
 *
 * @param {Object|null|undefined} wpSchema WordPress parameter schema.
 * @returns {Object} OpenAI-compatible JSON Schema object.
 */
function convertSchema( wpSchema ) {
	if ( ! wpSchema ) {
		return { type: 'object', properties: {} };
	}

	// Already in correct format (has both type and properties)
	if ( wpSchema.type && wpSchema.properties ) {
		return wpSchema;
	}

	// Convert from WordPress format
	return {
		type: 'object',
		properties: wpSchema.properties || {},
		required: wpSchema.required || [],
	};
}

/**
 * Convert a single WordPress tool definition to an OpenAI function definition.
 * Mirrors ToolAdapter.convertTool().
 *
 * @param {Object} wpTool WordPress tool object with slug/name/description/parameters.
 * @returns {Object} OpenAI function definition `{ type: 'function', function: {...} }`.
 */
function convertTool( wpTool ) {
	return {
		type: 'function',
		function: {
			name: wpTool.slug || wpTool.name,
			description: wpTool.description || '',
			parameters: convertSchema( wpTool.parameters || wpTool.schema ),
		},
	};
}

/**
 * Convert an array of WordPress tool definitions to OpenAI function definitions.
 * Mirrors ToolAdapter.convertTools().
 *
 * @param {Array|*} wpTools Array of WordPress tool objects (non-array returns []).
 * @returns {Array} Array of OpenAI function definitions.
 */
function convertTools( wpTools ) {
	if ( ! Array.isArray( wpTools ) ) {
		return [];
	}
	return wpTools.map( ( tool ) => convertTool( tool ) );
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe( 'WebLLM Tool Adapter – convertSchema', () => {
	it( 'returns empty schema when wpSchema is null', () => {
		expect( convertSchema( null ) ).toEqual( { type: 'object', properties: {} } );
	} );

	it( 'returns empty schema when wpSchema is undefined', () => {
		expect( convertSchema( undefined ) ).toEqual( { type: 'object', properties: {} } );
	} );

	it( 'returns empty schema when wpSchema is an empty object', () => {
		expect( convertSchema( {} ) ).toEqual( {
			type: 'object',
			properties: {},
			required: [],
		} );
	} );

	it( 'passes through schema that already has type and properties', () => {
		const schema = {
			type: 'object',
			properties: { city: { type: 'string' } },
			required: [ 'city' ],
		};
		expect( convertSchema( schema ) ).toBe( schema );
	} );

	it( 'promotes a schema that has only properties', () => {
		const schema = { properties: { q: { type: 'string' } } };
		expect( convertSchema( schema ) ).toEqual( {
			type: 'object',
			properties: { q: { type: 'string' } },
			required: [],
		} );
	} );

	it( 'preserves required array when present', () => {
		const schema = {
			properties: { q: { type: 'string' } },
			required: [ 'q' ],
		};
		expect( convertSchema( schema ) ).toEqual( {
			type: 'object',
			properties: { q: { type: 'string' } },
			required: [ 'q' ],
		} );
	} );

	it( 'returns empty required array when required is missing', () => {
		const schema = { properties: { q: { type: 'string' } } };
		const result = convertSchema( schema );
		expect( result.required ).toEqual( [] );
	} );
} );

describe( 'WebLLM Tool Adapter – convertTool', () => {
	it( 'converts a WordPress tool with slug to an OpenAI function definition', () => {
		const wpTool = {
			slug: 'search_posts',
			description: 'Search WordPress posts.',
			parameters: {
				type: 'object',
				properties: { query: { type: 'string', description: 'Search query' } },
				required: [ 'query' ],
			},
		};

		const result = convertTool( wpTool );

		expect( result.type ).toBe( 'function' );
		expect( result.function.name ).toBe( 'search_posts' );
		expect( result.function.description ).toBe( 'Search WordPress posts.' );
		expect( result.function.parameters.properties.query.type ).toBe( 'string' );
		expect( result.function.parameters.required ).toContain( 'query' );
	} );

	it( 'falls back to name field when slug is absent', () => {
		const wpTool = {
			name: 'get_weather',
			description: 'Get current weather.',
			parameters: null,
		};

		const result = convertTool( wpTool );

		expect( result.function.name ).toBe( 'get_weather' );
	} );

	it( 'prefers slug over name when both are present', () => {
		const wpTool = { slug: 'the_slug', name: 'the_name', description: '' };

		expect( convertTool( wpTool ).function.name ).toBe( 'the_slug' );
	} );

	it( 'uses schema field as fallback for parameters', () => {
		const wpTool = {
			slug: 'create_post',
			description: 'Create a post.',
			schema: {
				type: 'object',
				properties: { title: { type: 'string' } },
			},
		};

		const result = convertTool( wpTool );

		expect( result.function.parameters.properties.title ).toBeDefined();
	} );

	it( 'produces an empty parameters schema when no parameters or schema supplied', () => {
		const wpTool = { slug: 'no_params', description: 'No params tool.' };

		const result = convertTool( wpTool );

		expect( result.function.parameters ).toEqual( { type: 'object', properties: {} } );
	} );

	it( 'uses empty string for description when missing', () => {
		const wpTool = { slug: 'no_desc' };

		expect( convertTool( wpTool ).function.description ).toBe( '' );
	} );
} );

describe( 'WebLLM Tool Adapter – convertTools', () => {
	it( 'returns an empty array for a non-array input', () => {
		expect( convertTools( null ) ).toEqual( [] );
		expect( convertTools( undefined ) ).toEqual( [] );
		expect( convertTools( 'string' ) ).toEqual( [] );
		expect( convertTools( 42 ) ).toEqual( [] );
		expect( convertTools( {} ) ).toEqual( [] );
	} );

	it( 'returns an empty array for an empty array input', () => {
		expect( convertTools( [] ) ).toEqual( [] );
	} );

	it( 'converts a single tool', () => {
		const tools = [ { slug: 'search', description: 'Search', parameters: null } ];

		const result = convertTools( tools );

		expect( result ).toHaveLength( 1 );
		expect( result[ 0 ].type ).toBe( 'function' );
		expect( result[ 0 ].function.name ).toBe( 'search' );
	} );

	it( 'converts multiple tools preserving order', () => {
		const tools = [
			{ slug: 'alpha', description: 'A' },
			{ slug: 'beta', description: 'B' },
			{ slug: 'gamma', description: 'C' },
		];

		const result = convertTools( tools );

		expect( result ).toHaveLength( 3 );
		expect( result[ 0 ].function.name ).toBe( 'alpha' );
		expect( result[ 1 ].function.name ).toBe( 'beta' );
		expect( result[ 2 ].function.name ).toBe( 'gamma' );
	} );

	it( 'each converted tool has type "function"', () => {
		const tools = [
			{ slug: 'tool_a', description: 'A' },
			{ slug: 'tool_b', description: 'B' },
		];

		convertTools( tools ).forEach( ( t ) => {
			expect( t.type ).toBe( 'function' );
		} );
	} );

	it( 'does not mutate the original tools array', () => {
		const tools = [ { slug: 'x', description: 'X' } ];
		const copy = JSON.parse( JSON.stringify( tools ) );

		convertTools( tools );

		expect( tools ).toEqual( copy );
	} );
} );
