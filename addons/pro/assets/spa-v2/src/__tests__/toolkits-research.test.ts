/**
 * ToolkitsClient.research contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { ToolkitsApiError, ToolkitsClient } from '../api/toolkits';

const ENDPOINT = 'https://example.com/api/v1/pro/toolkits';

function mockFetch( payload: unknown, ok = true, status = 200 ): ReturnType< typeof vi.fn > {
	const fn = vi.fn( () =>
		Promise.resolve( {
			ok,
			status,
			json: () => Promise.resolve( payload ),
		} as Response )
	);

	global.fetch = fn as unknown as typeof fetch;

	return fn;
}

function client(): ToolkitsClient {
	return new ToolkitsClient( { endpoint: `${ ENDPOINT }/`, nonce: 'test-nonce' } );
}

afterEach( () => {
	vi.restoreAllMocks();
} );

describe( 'ToolkitsClient.research', () => {
	it( 'posts the entity and query and maps the draft envelope', async () => {
		const fetchMock = mockFetch( {
			success: true,
			data: {
				entity: 'product',
				draft: { sku: 'A-100', name: 'Widget' },
				provider: 'gpt-test',
			},
		} );

		const result = await client().research( 'commerce', 'product', 'Add the A-100 widget' );

		expect( result ).toEqual( {
			entity: 'product',
			draft: { sku: 'A-100', name: 'Widget' },
			provider: 'gpt-test',
		} );

		const [ url, init ] = fetchMock.mock.calls[ 0 ] as [ string, RequestInit ];

		expect( url ).toBe( `${ ENDPOINT }/commerce/research` );
		expect( init.method ).toBe( 'POST' );
		expect( JSON.parse( String( init.body ) ) ).toEqual( {
			entity: 'product',
			query: 'Add the A-100 widget',
		} );
	} );

	it( 'falls back to the requested entity and an empty draft', async () => {
		mockFetch( { success: true, data: {} } );

		const result = await client().research( 'commerce', 'product', 'anything' );

		expect( result ).toEqual( { entity: 'product', draft: {}, provider: '' } );
	} );

	it( 'surfaces the typed ai_unavailable error', async () => {
		mockFetch(
			{
				success: false,
				error: { code: 'ai_unavailable', message: 'No AI provider is configured.' },
			},
			false,
			503
		);

		const failure = client().research( 'commerce', 'product', 'anything' );

		await expect( failure ).rejects.toBeInstanceOf( ToolkitsApiError );
		await expect( failure ).rejects.toMatchObject( {
			code: 'ai_unavailable',
			status: 503,
			message: 'No AI provider is configured.',
		} );
	} );
} );
