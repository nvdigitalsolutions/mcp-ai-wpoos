/**
 * ToolsClient.list() contract tests.
 *
 * The REST route serves a top-level envelope — { tools: [ { name,
 * description, inputSchema, slug, required_capability } ], total } — that
 * must map onto the page's ToolDefinition shape. Older installs may still
 * serve the legacy { success, data: { tools } } envelope.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { ToolsClient } from '../api/tools';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/tools';

function mockFetch( payload: unknown ): void {
	global.fetch = vi.fn( () =>
		Promise.resolve( {
			ok: true,
			status: 200,
			json: () => Promise.resolve( payload ),
		} as Response )
	) as unknown as typeof fetch;
}

afterEach( () => {
	vi.restoreAllMocks();
} );

describe( 'ToolsClient.list', () => {
	it( 'maps the top-level { tools, total } envelope onto ToolDefinition', async () => {
		mockFetch( {
			tools: [
				{
					name: 'create_deal',
					slug: 'create_deal',
					description: 'Create a CRM deal.',
					required_capability: 'edit_posts',
					inputSchema: { type: 'object', properties: {} },
				},
			],
			total: 1,
		} );

		const client = new ToolsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.list();

		expect( result.total ).toBe( 1 );
		expect( result.tools ).toHaveLength( 1 );
		expect( result.tools[ 0 ] ).toEqual( {
			slug: 'create_deal',
			name: 'create_deal',
			description: 'Create a CRM deal.',
			category: '',
			required_capability: 'edit_posts',
			parameters: { type: 'object', properties: {} },
		} );
	} );

	it( 'derives the slug from name when the server omits it', async () => {
		mockFetch( {
			tools: [
				{
					name: 'create_deal',
					description: 'Create a CRM deal.',
					inputSchema: { type: 'object' },
				},
			],
			total: 1,
		} );

		const client = new ToolsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.list();

		expect( result.tools[ 0 ].slug ).toBe( 'create_deal' );
		expect( result.tools[ 0 ].required_capability ).toBe( '' );
	} );

	it( 'accepts the legacy { success, data: { tools } } envelope', async () => {
		mockFetch( {
			success: true,
			data: {
				tools: [
					{
						slug: 'create_deal',
						name: 'Create Deal',
						description: 'Create a CRM deal.',
						category: 'crm',
						required_capability: 'edit_posts',
						parameters: {},
					},
				],
				total: 1,
			},
		} );

		const client = new ToolsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.list();

		expect( result.tools ).toHaveLength( 1 );
		expect( result.tools[ 0 ].category ).toBe( 'crm' );
		expect( result.total ).toBe( 1 );
	} );

	it( 'returns an empty list when the payload has no tools key', async () => {
		mockFetch( {} );

		const client = new ToolsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.list();

		expect( result.tools ).toEqual( [] );
		expect( result.total ).toBe( 0 );
	} );
} );
