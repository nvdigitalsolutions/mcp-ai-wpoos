/**
 * TokenManagerClient contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { TokenManagerClient } from '../api/token-manager';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/token-manager';

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

describe( 'TokenManagerClient', () => {
	it( 'maps the overview envelope to the typed payload', async () => {
		mockFetch( {
			success: true,
			data: {
				stats: { total: 1, active: 1, revoked: 0, assistants: 1 },
				credentials: [
					{
						id: 'cred_abc',
						status: 'active',
						assistant_id: 7,
						assistant_title: 'Support Bot',
						created_at: '2026-01-01 10:00:00',
						created_by: 1,
						created_by_name: 'admin',
						revoked_at: '',
						revoked_by: 0,
						expires_at: '',
					},
				],
				restrictions: [
					{
						user_id: 3,
						user_login: 'blocked',
						display_name: 'Blocked User',
						type: 'manual',
						type_label: 'Manual block',
						scope: 'site',
						reason: 'test',
						triggered_at: 1700000000,
					},
				],
			},
		} );
		const client = new TokenManagerClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const overview = await client.overview();
		expect( overview!.stats ).toMatchObject( { total: 1, active: 1 } );
		expect( overview!.credentials[ 0 ] ).toMatchObject( {
			id: 'cred_abc',
			status: 'active',
			assistant_title: 'Support Bot',
		} );
		expect( overview!.restrictions ).toHaveLength( 1 );
	} );

	it( 'falls back to an empty overview when the payload is absent', async () => {
		mockFetch( { success: true } );
		const client = new TokenManagerClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const overview = await client.overview();
		expect( overview!.credentials ).toEqual( [] );
		expect( overview!.stats.total ).toBe( 0 );
	} );

	it( 'POSTs revoke and delete to the credential routes', async () => {
		const urls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			urls.push( String( input ) );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true, data: {} } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new TokenManagerClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.revokeCredential( 7, 'cred_abc' );
		await client.deleteCredential( 7, 'cred_abc' );
		expect( urls[ 0 ] ).toContain( '/token-manager/credentials/7/cred_abc/revoke' );
		expect( urls[ 1 ] ).toContain( '/token-manager/credentials/7/cred_abc/delete' );
	} );

	it( 'POSTs the lift route with the restriction type body', async () => {
		let requestedUrl = '';
		let requestedBody = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			requestedUrl = String( input );
			requestedBody = String( init?.body ?? '' );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true, data: { lifted: true } } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new TokenManagerClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.liftRestriction( 3, 'rate_limit' );
		expect( requestedUrl ).toContain( '/token-manager/restrictions/3/lift' );
		expect( JSON.parse( requestedBody ) ).toEqual( { type: 'rate_limit' } );
	} );

	it( 'throws the server message on a refused request', async () => {
		mockFetch( { message: 'Administrator access required.' }, false );
		const client = new TokenManagerClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await expect( client.revokeCredential( 7, 'cred_abc' ) ).rejects.toThrow(
			'Administrator access required.'
		);
	} );
} );
