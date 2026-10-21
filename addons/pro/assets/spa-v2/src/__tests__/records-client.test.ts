/**
 * RecordsClient contract tests (fetch mocked) — Phase 4 W11.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { RecordsClient, type RecordEntityConfig } from '../api/records';

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
		const rows = await client.list( COMPANY, '' );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toMatchObject( {
			id: 12,
			title: 'Acme Ltd',
			status: 'publish',
		} );
		expect( rows[ 0 ].meta._company_industry ).toBe( 'manufacturing' );
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
		await client.list( COMPANY, 'acme' );
		expect( calls[ 0 ] ).toContain( '/wp/v2/mcp_ai_company' );
		expect( calls[ 0 ] ).toContain( 'context=edit' );
		expect( calls[ 0 ] ).toContain( 'search=acme' );
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
} );
