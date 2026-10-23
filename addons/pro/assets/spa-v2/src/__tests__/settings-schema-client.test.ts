/**
 * SettingsClient schema/values contract tests (2.4.0, Proposal 084 Phase 2).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { SettingsClient } from '../api/settings';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/settings';

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

describe( 'SettingsClient schema surface', () => {
	it( 'maps the schema envelope', async () => {
		mockFetch( {
			success: true,
			data: {
				tabs: { general: 'General' },
				fields: [
					{
						key: 'enable_logging',
						type: 'checkbox',
						section_id: 'general',
						section: 'General Settings',
						tab: 'general',
						label: 'Enable Logging',
						description: '',
						sensitive: false,
					},
				],
			},
		} );
		const client = new SettingsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const schema = await client.schema();
		expect( schema.tabs ).toEqual( { general: 'General' } );
		expect( schema.fields ).toHaveLength( 1 );
		expect( schema.fields[ 0 ] ).toMatchObject( { key: 'enable_logging', type: 'checkbox' } );
	} );

	it( 'maps the values envelope', async () => {
		mockFetch( {
			success: true,
			data: { values: { enable_logging: true, openai_api_key: '**************' } },
		} );
		const client = new SettingsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const values = await client.values();
		expect( values ).toEqual( { enable_logging: true, openai_api_key: '**************' } );
	} );

	it( 'POSTs partial updates to /values and returns the refreshed payload', async () => {
		let requestedUrl = '';
		let requestedBody = '';
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			requestedUrl = String( input );
			requestedBody = String( init?.body ?? '' );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve( { success: true, data: { values: { enable_logging: true } } } ),
			} as Response );
		} ) as unknown as typeof fetch;

		const client = new SettingsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const values = await client.updateValues( { enable_logging: true } );

		expect( requestedUrl ).toContain( '/settings/values' );
		expect( JSON.parse( requestedBody ) ).toEqual( { enable_logging: true } );
		expect( values ).toEqual( { enable_logging: true } );
	} );
} );
