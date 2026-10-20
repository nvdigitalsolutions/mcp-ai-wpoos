/**
 * DiagnosticsClient contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { DiagnosticsClient } from '../api/diagnostics';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/diagnostics';

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

describe( 'DiagnosticsClient', () => {
	it( 'maps the overview envelope to the typed payload', async () => {
		mockFetch( {
			success: true,
			data: {
				generated_at: 123456,
				health: { status: 'ok', checks: {} },
				system: {
					wp_version: '6.9',
					php_version: '8.3',
					plugin_version: '2.4.0',
					memory_limit: '256M',
					is_multisite: false,
				},
				settings_dashboard: {
					constants: {},
					classes: {},
					dashboard_global_set: true,
					admin_settings_global: false,
					settings_init_function: true,
					sections_total: 8,
				},
				mcp_server: {
					auth0_configured: false,
					federation_enabled: false,
					federation_directory_enabled: false,
					mesh_enabled: false,
					tool_count: 42,
					mcp_routes_present: true,
				},
				providers: [
					{ slug: 'openai', label: 'OpenAI', has_credentials: true, key_source: 'settings' },
				],
				rest_context: {},
				pro_dashboard: {},
			},
		} );
		const client = new DiagnosticsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const overview = await client.overview();
		expect( overview ).not.toBeNull();
		expect( overview!.generated_at ).toBe( 123456 );
		expect( overview!.health.status ).toBe( 'ok' );
		expect( overview!.system.is_multisite ).toBe( false );
		expect( overview!.providers[ 0 ] ).toMatchObject( {
			slug: 'openai',
			has_credentials: true,
		} );
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
		const client = new DiagnosticsClient( { endpoint: ENDPOINT, nonce: 'abc123' } );
		await client.overview();
		expect( requestedUrl ).toContain( '/diagnostics/overview' );
		expect( requestedHeaders[ 'X-WP-Nonce' ] ).toBe( 'abc123' );
	} );

	it( 'returns null when the envelope carries no data', async () => {
		mockFetch( { success: true } );
		const client = new DiagnosticsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		expect( await client.overview() ).toBeNull();
	} );

	it( 'throws the server message on a refused request', async () => {
		mockFetch( { message: 'Administrator access required.' }, false );
		const client = new DiagnosticsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await expect( client.overview() ).rejects.toThrow( 'Administrator access required.' );
	} );

	it( 'passes an abort signal through to fetch', async () => {
		let receivedSignal: AbortSignal | undefined;
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			receivedSignal = init?.signal ?? undefined;
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true, data: {} } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const controller = new AbortController();
		const client = new DiagnosticsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.overview( controller.signal );
		expect( receivedSignal ).toBe( controller.signal );
	} );
} );
