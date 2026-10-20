/**
 * Teams/Portability/SecurityCenter client contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { TeamsClient } from '../api/teams';
import { PortabilityClient } from '../api/portability';
import { SecurityCenterClient } from '../api/security';

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

describe( 'TeamsClient', () => {
	const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/teams';

	it( 'maps the { teams } catalogue envelope', async () => {
		mockFetch( {
			success: true,
			teams: [ { id: 2, title: 'Ops Crew', member_count: 3 } ],
		} );
		const client = new TeamsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.list();
		expect( result ).toEqual( [ { id: 2, title: 'Ops Crew', member_count: 3 } ] );
	} );

	it( 'fetches members for one team', async () => {
		mockFetch( { success: true, members: [ { id: 5, title: 'Writer' } ] } );
		const client = new TeamsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.members( 2 );
		expect( result.members ).toHaveLength( 1 );
	} );
} );

describe( 'PortabilityClient', () => {
	const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/assistants';

	it( 'exports with ids when a selection is given', async () => {
		let requestedBody = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			requestedBody = String( init?.body ?? '' );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true, data: { format: 'nvoos-assistant' } } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new PortabilityClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.exportAssistants( [ 4, 9 ] );
		expect( result.data ).toMatchObject( { format: 'nvoos-assistant' } );
		expect( JSON.parse( requestedBody ) ).toMatchObject( { ids: [ 4, 9 ], format: 'json' } );
	} );

	it( 'imports with the conflict-mode payload', async () => {
		let requestedBody = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			requestedBody = String( init?.body ?? '' );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true, data: { imported: 1 } } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new PortabilityClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.importAssistants( { json: '{"format":"nvoos-assistant"}', mode: 'overwrite', dry_run: true } );
		expect( JSON.parse( requestedBody ) ).toMatchObject( { mode: 'overwrite', dry_run: true } );
	} );
} );

describe( 'SecurityCenterClient', () => {
	const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/security';

	it( 'reads posture, headers and snapshots', async () => {
		mockFetch( { score: 80 } );
		const client = new SecurityCenterClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const posture = await client.posture();
		expect( posture ).toMatchObject( { score: 80 } );
	} );

	it( 'POSTs the restore payload', async () => {
		let requestedBody = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			requestedBody = String( init?.body ?? '' );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new SecurityCenterClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.restoreSnapshot( 'snap-1' );
		expect( JSON.parse( requestedBody ) ).toEqual( { snapshot_id: 'snap-1' } );
	} );
} );
