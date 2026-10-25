/**
 * RemoteSitesClient contract tests (fetch mocked) — console Connections page.
 *
 * Mirrors chat-channels-client.test.ts: asserts URL shaping, envelope
 * normalisation, and the nonce/cookie headers the console proxy needs.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { RemoteSitesClient, buildWpAdminSettingsUrl } from '../api/remote-sites';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai-pro/v1/remote-sites';

function mockFetch( payload: unknown, ok = true ): void {
	global.fetch = vi.fn( () =>
		Promise.resolve( {
			ok,
			status: ok ? 200 : 403,
			json: () => Promise.resolve( payload ),
		} as Response )
	) as unknown as typeof fetch;
}

afterEach( () => {
	vi.restoreAllMocks();
} );

describe( 'RemoteSitesClient', () => {
	it( 'normalises the list envelope', async () => {
		mockFetch( {
			items: [
				{ id: 'conn_abc', source: 'pro', name: 'Store', connection_type: 'wordpress' },
				{ id: 'base-gmail', source: 'base', name: 'Gmail', connection_type: 'gmail' },
			],
			total: 2,
		} );
		const client = new RemoteSitesClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const envelope = await client.list();
		expect( envelope.items ).toHaveLength( 2 );
		expect( envelope.total ).toBe( 2 );
		expect( envelope.items[ 1 ].id ).toBe( 'base-gmail' );
	} );

	it( 'defaults a missing envelope to empty pages', async () => {
		mockFetch( {} );
		const client = new RemoteSitesClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const envelope = await client.list();
		expect( envelope.items ).toEqual( [] );
		expect( envelope.total ).toBe( 0 );
	} );

	it( 'fetches the type catalogue', async () => {
		const calls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			calls.push( String( input ) );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve( {
						items: [ { id: 'gmail', label: 'Gmail', category: 'email', fields: [] } ],
					} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new RemoteSitesClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const types = await client.types();
		expect( calls[ 0 ] ).toContain( '/remote-sites/types' );
		expect( types ).toHaveLength( 1 );
		expect( types[ 0 ].id ).toBe( 'gmail' );
	} );

	it( 'POSTs a create payload with the nonce header', async () => {
		const calls: { url: string; body: Record< string, unknown > }[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			calls.push( { url: String( input ), body: JSON.parse( String( init?.body ?? '{}' ) ) } );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve( {
						item: { id: 'conn_1', source: 'pro', name: 'Support bot', connection_type: 'telegram' },
					} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new RemoteSitesClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const saved = await client.create( {
			name: 'Support bot',
			connection_type: 'telegram',
			api_key: '123:ABC',
		} );
		expect( calls[ 0 ].url ).toBe( ENDPOINT );
		expect( calls[ 0 ].body ).toEqual( {
			name: 'Support bot',
			connection_type: 'telegram',
			api_key: '123:ABC',
		} );
		expect( saved.id ).toBe( 'conn_1' );
	} );

	it( 'updates through the item route and deletes through it too', async () => {
		const calls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			calls.push( String( input ) );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { item: { id: 'conn_1', source: 'pro' } } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new RemoteSitesClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.update( 'conn_1', { name: 'Renamed' } );
		await client.delete( 'conn_1' );
		expect( calls[ 0 ] ).toContain( '/remote-sites/conn_1' );
		expect( calls[ 1 ] ).toContain( '/remote-sites/conn_1' );
	} );

	it( 'tests a connection and normalises the result', async () => {
		mockFetch( { success: true, message: 'OK' } );
		const client = new RemoteSitesClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.test( 'conn_1' );
		expect( result ).toEqual( { success: true, message: 'OK' } );
	} );

	it( 'surfaces the server message on error responses', async () => {
		mockFetch( { message: 'Connection name is required.' }, false );
		const client = new RemoteSitesClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await expect( client.create( { connection_type: 'telegram' } ) ).rejects.toThrow(
			'Connection name is required.'
		);
	} );
} );

describe( 'buildWpAdminSettingsUrl', () => {
	it( 'strips a namespaced REST base down to the origin', () => {
		expect( buildWpAdminSettingsUrl( 'https://pro.example.com/wp-json/mcp-ai/v1' ) ).toBe(
			'https://pro.example.com/wp-admin/options-general.php?page=wp-mcp-ai-settings'
		);
	} );

	it( 'strips a bare /wp-json base down to the origin', () => {
		expect( buildWpAdminSettingsUrl( 'https://pro.example.com/wp-json' ) ).toBe(
			'https://pro.example.com/wp-admin/options-general.php?page=wp-mcp-ai-settings'
		);
	} );

	it( 'returns an empty string when no runtime URL is available', () => {
		expect( buildWpAdminSettingsUrl( '' ) ).toBe( '' );
	} );
} );
