/**
 * WorkflowPresetsClient contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { WorkflowPresetsClient } from '../api/workflow-presets';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai-pro/v1/workflow-presets';

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

describe( 'WorkflowPresetsClient', () => {
	it( 'maps the list envelope (categories + summaries)', async () => {
		mockFetch( {
			ok: true,
			categories: { content: 'Content Creation & Publishing' },
			presets: [ { id: 'content_pipeline', name: 'Blog Post Pipeline', description: '', category: 'content', icon: '', tags: [ 'content' ] } ],
		} );
		const client = new WorkflowPresetsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const data = await client.list();
		expect( data.categories.content ).toBe( 'Content Creation & Publishing' );
		expect( data.presets[ 0 ] ).toMatchObject( { id: 'content_pipeline', category: 'content' } );
	} );

	it( 'POSTs the install route and maps the full preset', async () => {
		let requestedUrl = '';
		let requestedMethod = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			requestedUrl = String( input );
			requestedMethod = init?.method ?? 'GET';
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve( {
						ok: true,
						preset: {
							id: 'content_pipeline',
							name: 'Blog Post Pipeline',
							description: '',
							category: 'content',
							icon: '',
							tags: [ 'content' ],
							nodes: [ { id: 'node_1', type: 'input', data: { label: 'Topic' } } ],
							edges: [],
						},
					} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new WorkflowPresetsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const preset = await client.install( 'content_pipeline' );
		expect( requestedMethod ).toBe( 'POST' );
		expect( requestedUrl ).toContain( '/content_pipeline/install' );
		expect( preset!.nodes[ 0 ] ).toMatchObject( { id: 'node_1', type: 'input' } );
	} );

	it( 'throws the server message on a failed install', async () => {
		mockFetch( { ok: false, message: 'Workflow preset "x" does not exist.' } );
		const client = new WorkflowPresetsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await expect( client.install( 'x' ) ).rejects.toThrow( 'does not exist' );
	} );

	it( 'returns empty collections when the payload is absent', async () => {
		mockFetch( { ok: true } );
		const client = new WorkflowPresetsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const data = await client.list();
		expect( data.presets ).toEqual( [] );
		expect( data.categories ).toEqual( {} );
	} );
} );
