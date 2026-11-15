/**
 * OrgTeamsClient contract tests (fetch mocked).
 *
 * The controller answers in three different shapes — a list envelope, a
 * `{success, data}` envelope, and the bare record on create — so the client
 * has to normalise all three without callers caring.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { OrgTeamsClient, ORG_TEAM_ROLES } from '../api/org-teams';

const ENDPOINT = 'https://example.com/api/v1/org/teams';

interface MockInit {
	status?: number;
	body?: unknown;
}

function mockFetch( init: MockInit ): ReturnType< typeof vi.fn > {
	const fn = vi.fn( () =>
		Promise.resolve( {
			ok: ( init.status ?? 200 ) < 400,
			status: init.status ?? 200,
			json: () => Promise.resolve( init.body ),
		} as Response )
	);

	global.fetch = fn as unknown as typeof fetch;

	return fn;
}

function client(): OrgTeamsClient {
	return new OrgTeamsClient( { endpoint: `${ ENDPOINT }/`, nonce: 'test-nonce' } );
}

afterEach( () => {
	vi.restoreAllMocks();
} );

describe( 'OrgTeamsClient', () => {
	it( 'exposes the constrained role set', () => {
		expect( [ ...ORG_TEAM_ROLES ] ).toEqual( [ 'owner', 'admin', 'manager', 'member', 'viewer' ] );
	} );

	it( 'sends the nonce and strips a trailing slash from the endpoint', async () => {
		const fetchMock = mockFetch( { body: { teams: [] } } );

		await client().list();

		const [ url, options ] = fetchMock.mock.calls[ 0 ] as [ string, RequestInit ];
		expect( url ).toBe( ENDPOINT );
		expect( ( options.headers as Record< string, string > )[ 'X-WP-Nonce' ] ).toBe( 'test-nonce' );
		expect( options.credentials ).toBe( 'same-origin' );
	} );

	it( 'maps the list envelope', async () => {
		mockFetch( {
			body: {
				teams: [ { id: 1, name: 'Studio', slug: 'studio', role: 'owner', member_count: 2 } ],
				total: 1,
			},
		} );

		await expect( client().list() ).resolves.toEqual( [
			{ id: 1, name: 'Studio', slug: 'studio', role: 'owner', member_count: 2 },
		] );
	} );

	it( 'returns an empty list when the envelope is malformed', async () => {
		mockFetch( { body: {} } );

		await expect( client().list() ).resolves.toEqual( [] );
	} );

	it( 'unwraps the detail envelope', async () => {
		mockFetch( {
			body: {
				success: true,
				data: {
					id: 7,
					name: 'Studio',
					slug: 'studio',
					description: null,
					member_count: 1,
					members: [
						{
							id: 3,
							team_id: 7,
							user_id: 11,
							role: 'owner',
							profession_slug: 'ux_designer',
							invited_by: null,
							joined_at: null,
						},
					],
				},
			},
		} );

		const team = await client().get( 7 );

		expect( team.id ).toBe( 7 );
		expect( team.members?.[ 0 ].profession_slug ).toBe( 'ux_designer' );
	} );

	it( 'throws a readable error when the detail envelope has no data', async () => {
		mockFetch( { body: { success: false } } );

		await expect( client().get( 7 ) ).rejects.toThrow( 'Team not found.' );
	} );

	it( 'creates with a JSON body and returns the bare record', async () => {
		const fetchMock = mockFetch( {
			status: 201,
			body: { id: 9, name: 'New', slug: 'new', description: 'Hi', member_count: 1 },
		} );

		const team = await client().create( { name: 'New', description: 'Hi' } );

		expect( team.id ).toBe( 9 );

		const [ , options ] = fetchMock.mock.calls[ 0 ] as [ string, RequestInit ];
		expect( options.method ).toBe( 'POST' );
		expect( options.body ).toBe( JSON.stringify( { name: 'New', description: 'Hi' } ) );
		expect( ( options.headers as Record< string, string > )[ 'Content-Type' ] ).toBe(
			'application/json'
		);
	} );

	it( 'accepts a bare member record from a fresh add', async () => {
		mockFetch( {
			status: 201,
			body: {
				id: 4,
				team_id: 7,
				user_id: 12,
				role: 'member',
				profession_slug: null,
				invited_by: 1,
				joined_at: null,
			},
		} );

		const member = await client().addMember( 7, { user_id: 12 } );

		expect( member.user_id ).toBe( 12 );
	} );

	it( 'accepts the envelope returned when re-adding an existing member', async () => {
		mockFetch( {
			status: 200,
			body: {
				success: true,
				message: 'User is already a member.',
				data: {
					id: 4,
					team_id: 7,
					user_id: 12,
					role: 'admin',
					profession_slug: null,
					invited_by: 1,
					joined_at: null,
				},
			},
		} );

		const member = await client().addMember( 7, { user_id: 12, role: 'admin' } );

		expect( member.role ).toBe( 'admin' );
	} );

	it( 'treats a 204 delete as success', async () => {
		mockFetch( { status: 204, body: undefined } );

		await expect( client().remove( 7 ) ).resolves.toBeUndefined();
		await expect( client().removeMember( 7, 12 ) ).resolves.toBeUndefined();
	} );

	it( 'surfaces the server message on a rejected write', async () => {
		mockFetch( { status: 422, body: { message: 'A team must keep at least one owner.' } } );

		await expect( client().changeRole( 7, 12, 'member' ) ).rejects.toThrow(
			'A team must keep at least one owner.'
		);
	} );
} );
