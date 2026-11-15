/**
 * ProfessionsClient contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { ProfessionsClient } from '../api/professions';

const ENDPOINT = 'https://example.com/api/v1/professions';

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

function client(): ProfessionsClient {
	return new ProfessionsClient( { endpoint: `${ ENDPOINT }/`, nonce: 'test-nonce' } );
}

afterEach( () => {
	vi.restoreAllMocks();
} );

describe( 'ProfessionsClient', () => {
	it( 'maps the catalogue envelope', async () => {
		mockFetch( {
			professions: [
				{
					slug: 'ux_designer',
					title: 'UX Designer',
					category: 'Creative Services',
					category_slug: 'creative',
					description: 'Designs interfaces.',
					expertise: [ 'User research' ],
					tools_count: 3,
				},
			],
			total: 1,
			catalogue_version: '1.0.0',
		} );

		const result = await client().list();

		expect( result.total ).toBe( 1 );
		expect( result.catalogue_version ).toBe( '1.0.0' );
		expect( result.professions[ 0 ].slug ).toBe( 'ux_designer' );
	} );

	it( 'tolerates a malformed envelope', async () => {
		mockFetch( {} );

		await expect( client().list() ).resolves.toEqual( {
			professions: [],
			total: 0,
			catalogue_version: null,
		} );
	} );

	it( 'strips a trailing slash and sends the nonce', async () => {
		const fetchMock = mockFetch( { professions: [], total: 0 } );

		await client().list();

		const [ url, options ] = fetchMock.mock.calls[ 0 ] as [ string, RequestInit ];
		expect( url ).toBe( ENDPOINT );
		expect( ( options.headers as Record< string, string > )[ 'X-WP-Nonce' ] ).toBe( 'test-nonce' );
		expect( options.credentials ).toBe( 'same-origin' );
	} );

	it( 'unwraps the detail envelope and encodes the slug', async () => {
		const fetchMock = mockFetch( {
			success: true,
			data: {
				slug: 'ux_designer',
				title: 'UX Designer',
				category: 'Creative Services',
				category_slug: 'creative',
				description: 'Designs interfaces.',
				expertise: [],
				tools_count: 3,
				role_description: 'You ground design decisions in evidence.',
				warnings: [],
				knowledge_base: '### Design',
				recommended_tools: [ 'web_search' ],
			},
		} );

		const detail = await client().get( 'ux_designer' );

		expect( detail.recommended_tools ).toEqual( [ 'web_search' ] );

		const [ url ] = fetchMock.mock.calls[ 0 ] as [ string ];
		expect( url ).toBe( `${ ENDPOINT }/ux_designer` );
	} );

	it( 'throws when the detail envelope has no data', async () => {
		mockFetch( { success: false } );

		await expect( client().get( 'nope' ) ).rejects.toThrow( 'Profession not found.' );
	} );

	it( 'surfaces the server message on a failed request', async () => {
		mockFetch( { message: 'Profession not found.' }, false, 404 );

		await expect( client().get( 'nope' ) ).rejects.toThrow( 'Profession not found.' );
	} );
} );
