/**
 * ProOpsClient contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { ProOpsClient } from '../api/pro-ops';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai-pro/v1/ops';

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

describe( 'ProOpsClient', () => {
	it( 'maps the overview envelope to the typed payload', async () => {
		mockFetch( {
			success: true,
			data: {
				status: {
					overall: 'operational',
					components: [
						{ slug: 'core', name: 'Core', group: 'platform', public: true, status: 'operational' },
					],
					last_checked: 1700000000,
				},
				incidents: [
					{
						id: 3,
						title: 'Degraded API',
						phase: 'resolved',
						severity: 'major',
						services: [ 'api' ],
						resolved_at: '2026-01-02',
						date: '2026-01-01',
					},
				],
				maintenance: [
					{
						id: 5,
						title: 'DB upgrade',
						status: 'scheduled',
						start: '2026-02-01',
						end: '2026-02-01',
						banner_enabled: true,
					},
				],
				webhooks: [
					{
						id: 'tg-1',
						type: 'telegram',
						label: 'Telegram Bot',
						expected_url: 'https://example.com/wp-json/mcp-ai/v1/telegram/webhook/tg-1',
						webhook_set: true,
						last_checked_text: '',
					},
				],
			},
		} );
		const client = new ProOpsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const overview = await client.overview();
		expect( overview!.status.overall ).toBe( 'operational' );
		expect( overview!.status.components[ 0 ] ).toMatchObject( { slug: 'core', public: true } );
		expect( overview!.incidents[ 0 ] ).toMatchObject( { phase: 'resolved', severity: 'major' } );
		expect( overview!.maintenance[ 0 ].banner_enabled ).toBe( true );
		expect( overview!.webhooks[ 0 ] ).toMatchObject( { type: 'telegram', webhook_set: true } );
	} );

	it( 'hits the /overview route with the nonce header', async () => {
		let requestedUrl = '';
		let requestedHeaders: Record< string, string > = {};
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			requestedUrl = String( input );
			requestedHeaders = ( init?.headers as Record< string, string > ) ?? {};
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true, data: {} } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new ProOpsClient( { endpoint: ENDPOINT, nonce: 'abc123' } );
		await client.overview();
		expect( requestedUrl ).toContain( '/ops/overview' );
		expect( requestedHeaders[ 'X-WP-Nonce' ] ).toBe( 'abc123' );
	} );

	it( 'returns null when the envelope carries no data', async () => {
		mockFetch( { success: true } );
		const client = new ProOpsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		expect( await client.overview() ).toBeNull();
	} );

	it( 'throws the server message on a refused request', async () => {
		mockFetch( { message: 'Administrator access required.' }, false );
		const client = new ProOpsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await expect( client.overview() ).rejects.toThrow( 'Administrator access required.' );
	} );
} );
