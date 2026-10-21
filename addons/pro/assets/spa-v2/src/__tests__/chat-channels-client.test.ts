/**
 * ChatChannelsClient contract tests (fetch mocked) — Phase 4 W14.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { ChatChannelsClient } from '../api/chat-channels';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai-pro/v1/chat-channels';

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

describe( 'ChatChannelsClient', () => {
	it( 'maps the conversations envelope and applies filters', async () => {
		const calls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			calls.push( String( input ) );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve( {
						items: [ { id: 9, channel: 'whatsapp', display_name: 'Amina', crm_status: 'new' } ],
						total: 1,
						page: 1,
						per_page: 50,
					} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new ChatChannelsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const envelope = await client.conversations( { channel: 'whatsapp', search: 'amina' } );
		expect( calls[ 0 ] ).toContain( '/chat-channels/conversations' );
		expect( calls[ 0 ] ).toContain( 'channel=whatsapp' );
		expect( calls[ 0 ] ).toContain( 'search=amina' );
		expect( envelope.items ).toHaveLength( 1 );
		expect( envelope.items[ 0 ] ).toMatchObject( { id: 9, channel: 'whatsapp' } );
	} );

	it( 'defaults a missing envelope to empty pages', async () => {
		mockFetch( {} );
		const client = new ChatChannelsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const envelope = await client.conversations();
		expect( envelope.items ).toEqual( [] );
		expect( envelope.total ).toBe( 0 );
	} );

	it( 'fetches messages for a contact', async () => {
		mockFetch( { items: [ { id: 4, message: 'Hello', direction: 'inbound' } ] } );
		const client = new ChatChannelsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const messages = await client.messages( 9 );
		expect( messages ).toHaveLength( 1 );
		expect( messages[ 0 ].message ).toBe( 'Hello' );
	} );

	it( 'POSTs a reply with the contact and connection ids', async () => {
		const calls: { url: string; body: Record< string, unknown > }[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			calls.push( { url: String( input ), body: JSON.parse( String( init?.body ?? '{}' ) ) } );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( {} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new ChatChannelsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.reply( 9, 'Sure, thanks!', 'conn_42' );
		expect( calls[ 0 ].url ).toContain( '/chat-channels/reply' );
		expect( calls[ 0 ].body ).toMatchObject( {
			contact_id: 9,
			message: 'Sure, thanks!',
			connection_id: 'conn_42',
		} );
	} );

	it( 'throws the server message on a failed request', async () => {
		mockFetch( { code: 'rest_forbidden', message: 'Sorry, you are not allowed.' }, false );
		const client = new ChatChannelsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await expect( client.conversations() ).rejects.toThrow( 'Sorry, you are not allowed.' );
	} );
} );
