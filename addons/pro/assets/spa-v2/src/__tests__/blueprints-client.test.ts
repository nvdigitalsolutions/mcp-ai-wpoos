/**
 * BlueprintsClient contract tests (fetch mocked, zero network).
 *
 * Covers: the catalogue envelope mapping, the detail payload, the install
 * POST body (overwrite flag), and error passthrough with the server's
 * message (including the duplicate-detection heuristic the page relies on).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { BlueprintsClient } from '../api/blueprints';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai-pro/v1/blueprints';

function mockFetch( payload: unknown, ok = true ): void {
	global.fetch = vi.fn( () =>
		Promise.resolve( {
			ok,
			status: ok ? 200 : 400,
			json: () => Promise.resolve( payload ),
		} as Response )
	) as unknown as typeof fetch;
}

afterEach( () => {
	vi.restoreAllMocks();
} );

describe( 'BlueprintsClient', () => {
	it( 'maps the grouped catalogue envelope', async () => {
		mockFetch( {
			success: true,
			data: {
				total: 2,
				toolkits: [
					{
						slug: 'crm',
						label: 'CRM & Email Marketing',
						blueprints: [
							{
								slug: 'agency-account-manager',
								name: 'Agency Account Manager',
								description: 'Retainer-aware account manager.',
								tool_count: 20,
								installed: true,
								assistant_id: 42,
							},
						],
					},
				],
			},
		} );

		const client = new BlueprintsClient( { endpoint: ENDPOINT, nonce: 'abc' } );
		const catalogue = await client.list();

		expect( catalogue.total ).toBe( 2 );
		expect( catalogue.toolkits[ 0 ].blueprints[ 0 ].assistant_id ).toBe( 42 );
	} );

	it( 'maps the detail payload and URL-encodes the path segments', async () => {
		mockFetch( {
			success: true,
			data: {
				toolkit: 'crm',
				slug: 'agency-account-manager',
				name: 'Agency Account Manager',
				description: '…',
				instructions: 'Be an account manager.',
				tools: [ 'create_deal', 'list_deals' ],
				defaults: { profession: 'account_executive' },
			},
		} );

		const client = new BlueprintsClient( { endpoint: ENDPOINT, nonce: 'abc' } );
		const details = await client.details( 'crm', 'agency-account-manager' );

		expect( details.tools ).toHaveLength( 2 );
		expect( details.defaults ).toEqual( { profession: 'account_executive' } );
		const calledUrl = String(
			( global.fetch as ReturnType< typeof vi.fn > ).mock.calls[ 0 ][ 0 ]
		);
		expect( calledUrl ).toContain( '/crm/agency-account-manager' );
	} );

	it( 'POSTs the install with the overwrite flag in the body', async () => {
		mockFetch( {
			success: true,
			data: { toolkit: 'crm', slug: 'agency-account-manager', assistant_id: 7 },
		} );

		const client = new BlueprintsClient( { endpoint: ENDPOINT, nonce: 'abc' } );
		const result = await client.install( 'crm', 'agency-account-manager', true );

		expect( result.assistant_id ).toBe( 7 );
		const init = ( global.fetch as ReturnType< typeof vi.fn > ).mock
			.calls[ 0 ][ 1 ] as RequestInit;
		expect( init.method ).toBe( 'POST' );
		expect( JSON.parse( String( init.body ) ) ).toEqual( { overwrite: true } );
	} );

	it( 'surfaces the server message (the page keys its duplicate flow on it)', async () => {
		mockFetch( { message: 'A blueprint with this name already exists.' }, false );

		const client = new BlueprintsClient( { endpoint: ENDPOINT, nonce: 'abc' } );
		await expect( client.install( 'crm', 'agency-account-manager' ) ).rejects.toThrow(
			/already exists/
		);
	} );
} );
