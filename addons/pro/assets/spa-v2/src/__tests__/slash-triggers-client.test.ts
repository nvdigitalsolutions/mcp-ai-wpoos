/**
 * SlashCommandsClient + TriggersClient contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { SlashCommandsClient } from '../api/slash-commands';
import { TriggersClient } from '../api/triggers';

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

describe( 'SlashCommandsClient', () => {
	const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/slash-command';

	it( 'maps a { commands } envelope to summaries', async () => {
		mockFetch( {
			commands: [ { name: 'research', description: 'Research a topic', usage: '/research', capability: 'edit_posts' } ],
		} );
		const client = new SlashCommandsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.list();
		expect( result ).toHaveLength( 1 );
		expect( result[ 0 ] ).toMatchObject( { name: 'research', usage: '/research' } );
	} );

	it( 'POSTs the command string to the execute route', async () => {
		let requestedBody = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			requestedBody = String( init?.body ?? '' );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true, message: 'ok' } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new SlashCommandsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.execute( '/research cars' );
		expect( result.success ).toBe( true );
		expect( JSON.parse( requestedBody ) ).toEqual( { command: '/research cars' } );
	} );
} );

describe( 'TriggersClient', () => {
	const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/orchestration/triggers';

	it( 'maps a raw array of triggers', async () => {
		mockFetch( [
			{ id: 3, name: 'Daily digest', type: 'cron', config: {}, workflow_id: 9, enabled: true, last_fired_at: 0, webhook_url: '' },
		] );
		const client = new TriggersClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.list();
		expect( result ).toHaveLength( 1 );
		expect( result[ 0 ] ).toMatchObject( { id: 3, workflow_id: 9, enabled: true } );
	} );

	it( 'PUTs the enabled toggle to the item route', async () => {
		let requestedUrl = '';
		let requestedBody = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			requestedUrl = String( input );
			requestedBody = String( init?.body ?? '' );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { id: 3, enabled: false } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new TriggersClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.setEnabled( 3, false );
		expect( requestedUrl ).toContain( '/triggers/3' );
		expect( JSON.parse( requestedBody ) ).toEqual( { enabled: false } );
	} );

	it( 'DELETEs the item route', async () => {
		let requestedUrl = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			requestedUrl = String( input );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { deleted: true, id: 3 } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new TriggersClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.remove( 3 );
		expect( requestedUrl ).toContain( '/triggers/3' );
	} );
} );
