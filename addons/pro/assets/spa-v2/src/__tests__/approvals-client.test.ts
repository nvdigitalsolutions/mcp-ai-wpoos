/**
 * ApprovalsClient contract tests — list envelope mapping and the
 * approve/deny POST shapes (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { ApprovalsClient } from '../api/approvals';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/approvals';

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

describe( 'ApprovalsClient.list', () => {
	it( 'maps the { approvals, total } envelope', async () => {
		mockFetch( {
			approvals: [
				{
					id: 7,
					status: 'pending',
					tool: 'delete_post',
					reason: 'Destructive op',
					created_at: 1700000000,
				},
			],
			total: 1,
		} );

		const client = new ApprovalsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.list();

		expect( result.total ).toBe( 1 );
		expect( result.approvals ).toHaveLength( 1 );
		expect( result.approvals[ 0 ] ).toMatchObject( { id: 7, tool: 'delete_post' } );
	} );

	it( 'tolerates a missing approvals key', async () => {
		mockFetch( {} );

		const client = new ApprovalsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.list();

		expect( result.approvals ).toEqual( [] );
		expect( result.total ).toBe( 0 );
	} );
} );

describe( 'ApprovalsClient.resolve', () => {
	it( 'POSTs to the action route with a JSON note body', async () => {
		let requestedUrl = '';
		let requestedBody = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			requestedUrl = String( input );
			requestedBody = String( init?.body ?? '' );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true } ),
			} as Response );
		} ) as unknown as typeof fetch;

		const client = new ApprovalsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.resolve( 7, 'approve', 'looks good' );

		expect( result ).toEqual( { success: true } );
		expect( requestedUrl ).toContain( '/approvals/7/approve' );
		expect( JSON.parse( requestedBody ) ).toEqual( { note: 'looks good' } );
	} );

	it( 'surfaces the server error message', async () => {
		global.fetch = vi.fn( () =>
			Promise.resolve( {
				ok: false,
				status: 403,
				json: () => Promise.resolve( { message: 'Administrator access required.' } ),
			} as Response )
		) as unknown as typeof fetch;

		const client = new ApprovalsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await expect( client.resolve( 7, 'deny' ) ).rejects.toThrow(
			/Administrator access required/,
		);
	} );
} );
