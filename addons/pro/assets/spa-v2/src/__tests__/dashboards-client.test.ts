/**
 * DashboardsClient contract tests (fetch mocked) — Phase 4 W13.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { DashboardsClient, formatMetric } from '../api/dashboards';

const SCHEMA = 'https://example.com/wp-json/mcp-ai-pro/v1/dashboards/schema';

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

describe( 'DashboardsClient', () => {
	it( 'parses the schema envelope into a dashboard list', async () => {
		mockFetch( {
			dashboards: {
				cre: {
					id: 'cre',
					label: 'CRE Debt Portfolio',
					description: 'Portfolio stats',
					icon: '🏦',
					filters: [ { key: 'loan_status', label: 'Loan Status', type: 'select' } ],
					metrics: [ { key: 'total_loans', label: 'Loans', format: 'number' } ],
				},
			},
		} );
		const client = new DashboardsClient( { schemaEndpoint: SCHEMA, nonce: 'n' } );
		const dashboards = await client.schema();
		expect( dashboards ).toHaveLength( 1 );
		expect( dashboards[ 0 ] ).toMatchObject( { id: 'cre', label: 'CRE Debt Portfolio' } );
		expect( dashboards[ 0 ].metrics[ 0 ] ).toMatchObject( { key: 'total_loans' } );
	} );

	it( 'requests data with the filters serialized', async () => {
		const calls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			calls.push( String( input ) );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { dashboard: 'cre', data: { total_loans: 3 } } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new DashboardsClient( { schemaEndpoint: SCHEMA, nonce: 'n' } );
		const data = await client.data( 'cre', { loan_status: 'performing' } );
		expect( calls[ 0 ] ).toContain( '/dashboards/cre/data' );
		expect( calls[ 0 ] ).toContain( 'filters=' );
		expect( data.total_loans ).toBe( 3 );
	} );

	it( 'returns an empty payload for a data-less envelope', async () => {
		mockFetch( { dashboard: 'cre' } );
		const client = new DashboardsClient( { schemaEndpoint: SCHEMA, nonce: 'n' } );
		const data = await client.data( 'cre', {} );
		expect( data.total_loans ).toBeUndefined();
	} );

	it( 'throws the server message on a failed request', async () => {
		mockFetch( { code: 'rest_forbidden', message: 'Sorry, you are not allowed.' }, false );
		const client = new DashboardsClient( { schemaEndpoint: SCHEMA, nonce: 'n' } );
		await expect( client.schema() ).rejects.toThrow( 'Sorry, you are not allowed.' );
	} );
} );

describe( 'formatMetric', () => {
	it( 'formats money, percent and number metrics', () => {
		expect( formatMetric( 1234567, 'money' ) ).toContain( '1,234,567' );
		expect( formatMetric( 5.75, 'percent' ) ).toBe( '5.75%' );
		expect( formatMetric( 42, 'number' ) ).toBe( '42' );
	} );

	it( 'falls back to zero for non-numeric values', () => {
		expect( formatMetric( undefined, 'number' ) ).toBe( '0' );
		expect( formatMetric( 'nope', 'percent' ) ).toBe( '0.00%' );
	} );
} );
