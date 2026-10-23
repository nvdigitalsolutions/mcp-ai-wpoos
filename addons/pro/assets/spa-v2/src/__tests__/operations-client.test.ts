/**
 * OperationsClient contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { OperationsClient } from '../api/operations';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/operations';

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

describe( 'OperationsClient', () => {
	it( 'maps the cron envelope', async () => {
		mockFetch( {
			success: true,
			data: { jobs: [ { job_id: 'job-1', hook: 'wp_mcp_ai_test' } ], total: 1 },
		} );
		const client = new OperationsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const jobs = await client.cronJobs();
		expect( jobs ).toEqual( [ { job_id: 'job-1', hook: 'wp_mcp_ai_test' } ] );
	} );

	it( 'DELETEs a cron job by id', async () => {
		let requestedUrl = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			requestedUrl = String( input );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new OperationsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.removeCronJob( 'job-1' );
		expect( requestedUrl ).toContain( '/operations/cron/job-1' );
	} );

	it( 'maps the DLQ envelope and filters dismissed', async () => {
		mockFetch( {
			success: true,
			data: { items: [ { item_id: 'd1', type: 'cron_job', identifier: 'hook', failure_reason: 'boom', dismissed: false, created_at: '2026-01-01' } ] },
		} );
		const client = new OperationsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const items = await client.dlqItems( false );
		expect( items ).toHaveLength( 1 );
		expect( items[ 0 ] ).toMatchObject( { item_id: 'd1', type: 'cron_job' } );
	} );

	it( 'POSTs retry and dismiss to the item routes', async () => {
		const urls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			urls.push( String( input ) );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new OperationsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.retryDlq( 'd1' );
		await client.dismissDlq( 'd1' );
		expect( urls[ 0 ] ).toContain( '/operations/dlq/d1/retry' );
		expect( urls[ 1 ] ).toContain( '/operations/dlq/d1/dismiss' );
	} );

	it( 'maps the timeline envelope and passes page/assistant query args', async () => {
		const urls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			urls.push( String( input ) );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve( {
						success: true,
						data: {
							runs: [ { run_id: 'r1', assistant_id: 7, total_tokens: 42 } ],
							total: 1,
							page: 2,
							per_page: 20,
						},
					} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new OperationsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const page = await client.timeline( 2, 7 );
		expect( page.runs ).toHaveLength( 1 );
		expect( page.runs[ 0 ] ).toMatchObject( { run_id: 'r1', assistant_id: 7 } );
		expect( urls[ 0 ] ).toContain( '/operations/timeline' );
		expect( urls[ 0 ] ).toContain( 'page=2' );
		expect( urls[ 0 ] ).toContain( 'assistant_id=7' );
	} );

	it( 'returns an empty timeline page when the payload is absent', async () => {
		mockFetch( { success: true } );
		const client = new OperationsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const page = await client.timeline();
		expect( page.runs ).toEqual( [] );
		expect( page.total ).toBe( 0 );
	} );
} );
