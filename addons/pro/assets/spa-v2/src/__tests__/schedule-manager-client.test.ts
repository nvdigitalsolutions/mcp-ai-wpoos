/**
 * ScheduleManagerClient contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { ScheduleManagerClient } from '../api/schedule-manager';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai-pro/v1';

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

describe( 'ScheduleManagerClient', () => {
	it( 'maps the list envelope (ok, schedules, total)', async () => {
		mockFetch( {
			ok: true,
			schedules: [
				{ id: 'sch_1', name: 'Daily digest', schedule_type: 'assistant_run', schedule: '0 9 * * *', enabled: true },
			],
			total: 1,
		} );
		const client = new ScheduleManagerClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const rows = await client.list();
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toMatchObject( { id: 'sch_1', schedule_type: 'assistant_run' } );
	} );

	it( 'maps the presets envelope', async () => {
		mockFetch( {
			ok: true,
			presets: [ { id: 'p1', name: 'Daily digest', category: 'reports', toolkit: 'analytics' } ],
			total: 1,
		} );
		const client = new ScheduleManagerClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const rows = await client.presets( { category: 'reports' } );
		expect( rows[ 0 ] ).toMatchObject( { id: 'p1', toolkit: 'analytics' } );
	} );

	it( 'POSTs create/update/trigger/toggle and DELETEs to the right routes', async () => {
		const calls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			calls.push( `${ init?.method ?? 'GET' } ${ String( input ) }` );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { ok: true } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new ScheduleManagerClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await client.create( { name: 'x', schedule_type: 'assistant_run' } );
		await client.update( 'sch_1', { enabled: false } );
		await client.trigger( 'sch_1' );
		await client.toggle( 'sch_1' );
		await client.remove( 'sch_1' );
		await client.installPreset( 'p1' );
		expect( calls[ 0 ] ).toContain( 'POST' );
		expect( calls[ 0 ] ).toContain( '/schedules' );
		expect( calls[ 1 ] ).toContain( 'POST' );
		expect( calls[ 1 ] ).toContain( '/schedules/sch_1' );
		expect( calls[ 2 ] ).toContain( '/schedules/sch_1/trigger' );
		expect( calls[ 3 ] ).toContain( '/schedules/sch_1/toggle' );
		expect( calls[ 4 ] ).toContain( 'DELETE' );
		expect( calls[ 4 ] ).toContain( '/schedules/sch_1' );
		expect( calls[ 5 ] ).toContain( '/presets/p1/install' );
	} );

	it( 'maps the history envelope', async () => {
		mockFetch( {
			ok: true,
			history: [ { run_id: 'r1', status: 'completed' } ],
		} );
		const client = new ScheduleManagerClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const rows = await client.history( 'sch_1' );
		expect( rows[ 0 ] ).toMatchObject( { run_id: 'r1' } );
	} );

	it( 'returns an empty list when the payload is absent', async () => {
		mockFetch( { ok: true } );
		const client = new ScheduleManagerClient( { endpoint: ENDPOINT, nonce: 'n' } );
		expect( await client.list() ).toEqual( [] );
		expect( await client.presets() ).toEqual( [] );
	} );

	it( 'throws on an ok=false envelope and on HTTP errors', async () => {
		mockFetch( { ok: false, message: 'Schedule not found.' } );
		const client = new ScheduleManagerClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await expect( client.trigger( 'nope' ) ).rejects.toThrow( 'Schedule not found.' );

		mockFetch( { message: 'Forbidden.' }, false );
		await expect( client.list() ).rejects.toThrow( 'Forbidden.' );
	} );
} );
