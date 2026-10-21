/**
 * WorkflowBuilderClient contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { WorkflowBuilderClient } from '../api/workflow-builder';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/orchestration/workflows';

function mockFetch( payload: unknown, ok = true ): void {
	global.fetch = vi.fn( () =>
		Promise.resolve( {
			ok,
			status: ok ? 200 : 404,
			json: () => Promise.resolve( payload ),
		} as Response )
	) as unknown as typeof fetch;
}

afterEach( () => {
	vi.restoreAllMocks();
} );

describe( 'WorkflowBuilderClient', () => {
	it( 'maps the bare-array list envelope', async () => {
		mockFetch( [
			{ id: 7, title: 'Support triage', version: '1.0.0', tags: [ 'ops' ], modified: '2026-01-01' },
		] );
		const client = new WorkflowBuilderClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const rows = await client.list();
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toMatchObject( { id: 7, title: 'Support triage', version: '1.0.0' } );
	} );

	it( 'maps the bare-object detail envelope', async () => {
		mockFetch( {
			id: 7,
			title: 'Support triage',
			description: 'Routes tickets',
			version: '1.0.1',
			tags: [ 'ops' ],
			graph: {
				nodes: [ { id: 'n1', type: 'tool', label: 'Web search' } ],
				edges: [],
			},
			modified: '2026-01-02',
		} );
		const client = new WorkflowBuilderClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const detail = await client.get( 7 );
		expect( detail!.graph.nodes[ 0 ] ).toMatchObject( { id: 'n1', type: 'tool' } );
		expect( detail!.version ).toBe( '1.0.1' );
	} );

	it( 'POSTs/PUTs/DELETEs to the right routes', async () => {
		const calls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			calls.push( `${ init?.method ?? 'GET' } ${ String( input ) }` );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( {} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new WorkflowBuilderClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.create( { name: 'New flow', tags: [ 'x' ] } );
		await client.update( 7, { name: 'Renamed' } );
		await client.execute( 7 );
		await client.remove( 7 );
		expect( calls[ 0 ] ).toContain( 'POST' );
		expect( calls[ 0 ] ).toContain( '/workflows' );
		expect( calls[ 1 ] ).toContain( 'PUT' );
		expect( calls[ 1 ] ).toContain( '/workflows/7' );
		expect( calls[ 2 ] ).toContain( '/workflows/7/execute' );
		expect( calls[ 3 ] ).toContain( 'DELETE' );
		expect( calls[ 3 ] ).toContain( '/workflows/7' );
	} );

	it( 'throws the server message on a failed request', async () => {
		mockFetch( { code: 'not_found', message: 'Workflow not found.' }, false );
		const client = new WorkflowBuilderClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await expect( client.remove( 99 ) ).rejects.toThrow( 'Workflow not found.' );
	} );

	it( 'returns null when a single fetch fails', async () => {
		mockFetch( { code: 'not_found', message: 'Workflow not found.' }, false );
		const client = new WorkflowBuilderClient( { endpoint: ENDPOINT, nonce: 'n' } );
		expect( await client.get( 99 ) ).toBeNull();
	} );
} );
