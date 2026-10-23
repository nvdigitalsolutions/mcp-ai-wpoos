/**
 * AssistantsClient contract tests (fetch mocked) — the console edit flow.
 *
 * The server contract (mcp-ai/v1/assistants):
 *   GET   /assistants       → { assistants: [...summaries], total }
 *   GET   /assistants/{id}  → { success, data: { ...summary, system_prompt } }
 *   POST  /assistants       → the created summary directly (201)
 *   PUT   /assistants/{id}  → { success, data: summary }
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { AssistantsClient } from '../api/assistants';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/assistants';

const SUMMARY = {
	id: 7,
	title: 'Writer',
	provider: 'openai',
	model: 'gpt-4o',
	temperature: 0.7,
	tools: [ 'get_site_health', 'web_search' ],
};

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

describe( 'AssistantsClient', () => {
	it( 'maps the directory envelope to a list', async () => {
		mockFetch( { assistants: [ SUMMARY ], total: 1 } );
		const client = new AssistantsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const result = await client.list();
		expect( result.assistants ).toHaveLength( 1 );
		expect( result.assistants[ 0 ].tools ).toEqual( [ 'get_site_health', 'web_search' ] );
	} );

	it( 'unwraps the { success, data } envelope for a single assistant', async () => {
		mockFetch( { success: true, data: { ...SUMMARY, system_prompt: 'You are a writer.' } } );
		const client = new AssistantsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const record = await client.get( 7 );
		expect( record.system_prompt ).toBe( 'You are a writer.' );
		expect( record.id ).toBe( 7 );
	} );

	it( 'PUTs changes and unwraps the refreshed summary', async () => {
		const calls: { url: string; method?: string; body?: string }[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			calls.push( {
				url: String( input ),
				method: init?.method,
				body: String( init?.body ?? '' ),
			} );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true, data: { ...SUMMARY, title: 'Renamed' } } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new AssistantsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const updated = await client.update( 7, { title: 'Renamed', system_prompt: '', tools: [] } );
		expect( calls[ 0 ].method ).toBe( 'PUT' );
		expect( calls[ 0 ].url ).toBe( `${ ENDPOINT }/7` );
		expect( JSON.parse( calls[ 0 ].body ?? '{}' ) ).toEqual( {
			title: 'Renamed',
			system_prompt: '',
			tools: [],
		} );
		expect( updated.title ).toBe( 'Renamed' );
	} );

	it( 'treats the create response as the summary directly', async () => {
		mockFetch( SUMMARY );
		const client = new AssistantsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const created = await client.create( { title: 'Writer' } );
		expect( created.id ).toBe( 7 );
		expect( created.tools ).toHaveLength( 2 );
	} );

	it( 'throws on a refused request', async () => {
		mockFetch( { code: 'rest_forbidden', message: 'Administrator access required.' }, false );
		const client = new AssistantsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await expect( client.get( 7 ) ).rejects.toThrow( 'Assistants request failed.' );
	} );
} );
