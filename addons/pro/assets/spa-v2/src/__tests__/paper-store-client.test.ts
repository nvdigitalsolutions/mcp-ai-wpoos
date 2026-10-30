/**
 * PaperStoreClient contract tests — record-page pagination envelope.
 *
 * The REST list route counts the collection before slicing, so the client
 * must surface that total (not the page length) for the console pager.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { PaperStoreClient } from '../api/paper-store';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/paper-store';

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

describe( 'PaperStoreClient.records', () => {
	it( 'maps the records/total envelope onto a page', async () => {
		mockFetch( {
			collection: 'knowledge',
			total: 312,
			count: 1,
			records: [ { id: 'refunds', title: 'Refund policy' } ],
			offset: 0,
			limit: 25,
		} );

		const client = new PaperStoreClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const page = await client.records( 'knowledge' );

		expect( page.items ).toHaveLength( 1 );
		expect( page.items[ 0 ] ).toMatchObject( { id: 'refunds' } );
		expect( page ).toMatchObject( { total: 312, page: 1, perPage: 25 } );
	} );

	it( 'requests a window with limit/offset', async () => {
		let requestedUrl = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			requestedUrl = String( input );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { records: [], total: 0 } ),
			} as Response );
		} ) as unknown as typeof fetch;

		const client = new PaperStoreClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.records( 'knowledge', { page: 3, perPage: 50 } );

		expect( requestedUrl ).toContain( '/paper-store/knowledge' );
		expect( requestedUrl ).toContain( 'limit=50' );
		// Page 3 at 50 per page skips 100.
		expect( requestedUrl ).toContain( 'offset=100' );
	} );

	it( 'falls back to the page length when the server omits total', async () => {
		mockFetch( { records: [ { id: 'a', title: 'A' }, { id: 'b', title: 'B' } ] } );

		const client = new PaperStoreClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const page = await client.records( 'knowledge' );

		expect( page.total ).toBe( 2 );
	} );

	it( 'clamps the page size to the API maximum', async () => {
		let requestedUrl = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			requestedUrl = String( input );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { records: [], total: 0 } ),
			} as Response );
		} ) as unknown as typeof fetch;

		const client = new PaperStoreClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.records( 'knowledge', { perPage: 5000 } );

		expect( requestedUrl ).toContain( 'limit=200' );
	} );
} );
