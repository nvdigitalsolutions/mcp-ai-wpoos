/**
 * RecordsClient contract tests (fetch mocked) — Phase 4 W11.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { RecordsClient, type RecordEntityConfig } from '../api/records';
import { filterEntitiesByGroup } from '../features/records/RecordsPage';

const SCHEMA = 'https://example.com/wp-json/mcp-ai-pro/v1/records/schema';
const WP_BASE = 'https://example.com/wp-json/wp/v2';

const COMPANY: RecordEntityConfig = {
	key: 'company',
	postType: 'mcp_ai_company',
	restBase: 'mcp_ai_company',
	label: 'Companies',
	singular: 'Company',
	icon: '🏢',
	columns: [
		{ key: '_company_industry', label: 'Industry', type: 'string' },
		{ key: '_company_status', label: 'Lead Status', type: 'string', enum: [ 'prospect', 'customer' ] },
	],
};

function mockFetch( payload: unknown, ok = true, headers: Record< string, string > = {} ): void {
	global.fetch = vi.fn( () =>
		Promise.resolve( {
			ok,
			status: ok ? 200 : 403,
			headers: new Headers( headers ),
			json: () => Promise.resolve( payload ),
		} as Response )
	) as unknown as typeof fetch;
}

afterEach( () => {
	vi.restoreAllMocks();
} );

describe( 'RecordsClient', () => {
	it( 'parses the schema envelope into an entity map', async () => {
		mockFetch( { entities: { company: COMPANY } } );
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		const entities = await client.schema();
		expect( entities.company ).toMatchObject( { key: 'company', postType: 'mcp_ai_company' } );
		expect( entities.company.columns ).toHaveLength( 2 );
	} );

	it( 'maps a wp/v2 post array onto RecordSummary rows', async () => {
		mockFetch( [
			{
				id: 12,
				date: '2026-10-01T08:00:00',
				status: 'publish',
				title: { rendered: 'Acme Ltd' },
				meta: { _company_industry: 'manufacturing', _company_status: 'prospect' },
			},
		] );
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		const page = await client.list( COMPANY );
		expect( page.items ).toHaveLength( 1 );
		expect( page.items[ 0 ] ).toMatchObject( {
			id: 12,
			title: 'Acme Ltd',
			status: 'publish',
		} );
		expect( page.items[ 0 ].meta._company_industry ).toBe( 'manufacturing' );
	} );

	it( 'reads the collection total from the X-WP-Total headers', async () => {
		mockFetch( [], true, { 'X-WP-Total': '320', 'X-WP-TotalPages': '7' } );
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		const page = await client.list( COMPANY, { page: 3, perPage: 50 } );
		expect( page ).toMatchObject( { total: 320, totalPages: 7, page: 3, perPage: 50 } );
	} );

	it( 'falls back to item counts when pagination headers are absent', async () => {
		mockFetch( [ { id: 1, title: { rendered: 'One' }, meta: {} } ] );
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		const page = await client.list( COMPANY, { perPage: 50 } );
		expect( page.total ).toBe( 1 );
		expect( page.totalPages ).toBe( 1 );
	} );

	it( 'requests edit context and passes the search term', async () => {
		const calls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			calls.push( String( input ) );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( [] ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		await client.list( COMPANY, { search: 'acme' } );
		expect( calls[ 0 ] ).toContain( '/wp/v2/mcp_ai_company' );
		expect( calls[ 0 ] ).toContain( 'context=edit' );
		expect( calls[ 0 ] ).toContain( 'search=acme' );
		expect( calls[ 0 ] ).toContain( 'page=1' );
	} );

	it( 'maps a single wp/v2 post on get()', async () => {
		mockFetch( {
			id: 12,
			date: '2026-10-01T08:00:00',
			status: 'publish',
			title: { raw: 'Acme Ltd' },
			meta: {},
		} );
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		const record = await client.get( COMPANY, 12 );
		expect( record ).not.toBeNull();
		expect( record!.title ).toBe( 'Acme Ltd' );
	} );

	it( 'throws the server message on a failed request', async () => {
		mockFetch( { code: 'rest_forbidden', message: 'Sorry, you are not allowed.' }, false );
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		await expect( client.schema() ).rejects.toThrow( 'Sorry, you are not allowed.' );
	} );

	it( 'POSTs research to /records/{entity}/research and maps the envelope', async () => {
		const calls: { url: string; body: unknown }[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			calls.push( { url: String( input ), body: JSON.parse( String( init?.body ?? '{}' ) ) } );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve( {
						title: 'Acme Ltd',
						fields: { _company_industry: 'manufacturing' },
						research_status: 'partial',
						research_warnings: [ 'report_failed_quality_checks', 'used_reasoning_fallback' ],
						research_validation: {
							passed: false,
							issues: [ 'drafting_notes_detected' ],
						},
					} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		const result = await client.research( COMPANY, 'acme ltd' );
		expect( calls[ 0 ].url ).toContain( '/records/company/research' );
		expect( calls[ 0 ].body ).toMatchObject( { query: 'acme ltd' } );
		expect( result.title ).toBe( 'Acme Ltd' );
		expect( result.fields._company_industry ).toBe( 'manufacturing' );
		expect( result.researchStatus ).toBe( 'partial' );
		expect( result.researchWarnings ).toEqual( [ 'report_failed_quality_checks', 'used_reasoning_fallback' ] );
		expect( result.researchValidation ).toEqual( {
			passed: false,
			issues: [ 'drafting_notes_detected' ],
		} );
	} );

	it( 'defaults research diagnostics when the server omits them', async () => {
		global.fetch = vi.fn( () =>
			Promise.resolve( {
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve( {
						title: 'Acme Ltd',
						fields: {},
					} ),
			} as Response )
		) as unknown as typeof fetch;
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		const result = await client.research( COMPANY, 'acme ltd' );
		expect( result.researchStatus ).toBe( 'complete' );
		expect( result.researchWarnings ).toEqual( [] );
		expect( result.researchValidation ).toEqual( { passed: true, issues: [] } );
	} );

	it( 'POSTs a reviewed record to wp/v2 with the meta fields', async () => {
		const calls: { url: string; body: Record< string, unknown > }[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			calls.push( { url: String( input ), body: JSON.parse( String( init?.body ?? '{}' ) ) } );
			return Promise.resolve( {
				ok: true,
				status: 201,
				json: () =>
					Promise.resolve( {
						id: 42,
						date: '2026-10-01T08:00:00',
						status: 'publish',
						title: { rendered: 'Acme Ltd' },
						meta: { _company_industry: 'manufacturing' },
					} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		const created = await client.create( COMPANY, 'Acme Ltd', { _company_industry: 'manufacturing' } );
		expect( calls[ 0 ].url ).toContain( '/wp/v2/mcp_ai_company' );
		expect( calls[ 0 ].body ).toMatchObject( {
			title: 'Acme Ltd',
			status: 'publish',
			meta: { _company_industry: 'manufacturing' },
		} );
		expect( created ).toMatchObject( { id: 42, title: 'Acme Ltd' } );
	} );

	it( 'lists remote records through the read-only proxy (Phase B)', async () => {
		const calls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			calls.push( String( input ) );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve( {
						success: true,
						data: {
							items: [
								{
									id: 42,
									date: '2026-10-25T10:00:00',
									status: 'publish',
									title: 'Remote Post',
									meta: {},
								},
							],
							total: 120,
							total_pages: 3,
							page: 2,
							per_page: 50,
							remote_site: 'store-1',
						},
					} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		const page = await client.listRemote(
			{ ...COMPANY, key: 'post' },
			{ search: 'acme', page: 2 },
			'store-1'
		);
		expect( calls[ 0 ] ).toContain( '/records/remote/post' );
		expect( calls[ 0 ] ).toContain( 'remote_site=store-1' );
		expect( calls[ 0 ] ).toContain( 'search=acme' );
		expect( calls[ 0 ] ).toContain( 'page=2' );
		expect( page.items ).toHaveLength( 1 );
		expect( page.items[ 0 ] ).toMatchObject( { id: 42, title: 'Remote Post' } );
		expect( page ).toMatchObject( { total: 120, totalPages: 3 } );
	} );

	it( 'fetches a remote record through the proxy (Phase B)', async () => {
		const calls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			calls.push( String( input ) );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve( {
						success: true,
						data: {
							item: {
								id: 12,
								date: '2026-10-25T10:00:00',
								status: 'publish',
								title: { raw: 'Remote Detail' },
								meta: {},
							},
						},
					} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new RecordsClient( { schemaEndpoint: SCHEMA, wpBase: WP_BASE, nonce: 'n' } );
		const record = await client.getRemote( { ...COMPANY, key: 'post' }, 12, 'store-1' );
		expect( calls[ 0 ] ).toContain( '/records/remote/post/12' );
		expect( calls[ 0 ] ).toContain( 'remote_site=store-1' );
		expect( record ).toMatchObject( { id: 12, title: 'Remote Detail' } );
	} );
} );

describe( 'filterEntitiesByGroup', () => {
	const entities: RecordEntityConfig[] = [
		{ ...COMPANY, key: 'company', group: 'crm' },
		{
			key: 'project',
			postType: 'mcp_ai_project',
			restBase: 'mcp_ai_project',
			label: 'Projects',
			singular: 'Project',
			icon: '📁',
			group: 'pm',
			columns: [],
		},
		{ ...COMPANY, key: 'profession', postType: 'mcp_ai_profession', restBase: 'mcp_ai_profession', group: 'professions' },
	];

	it( 'partitions entities by command-center group', () => {
		expect( filterEntitiesByGroup( entities, 'pm' ).map( ( e ) => e.key ) ).toEqual( [ 'project' ] );
		expect( filterEntitiesByGroup( entities, 'crm' ).map( ( e ) => e.key ) ).toEqual( [ 'company' ] );
		expect( filterEntitiesByGroup( entities, 'professions' ).map( ( e ) => e.key ) ).toEqual( [ 'profession' ] );
	} );

	it( 'returns every entity when no group is given', () => {
		expect( filterEntitiesByGroup( entities ) ).toHaveLength( 3 );
	} );
} );
