/**
 * ScheduleManagerPage render tests.
 *
 * Regression pin for React error #310 ("Rendered more hooks than during the
 * previous render"): the page used to return its loading/error states BEFORE
 * the four useMemo table builders, so the hook count changed when the data
 * arrived and the page crashed on every real load. Rendering through the
 * loading → loaded transition reproduces that exact sequence.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';

import { ScheduleManagerPage } from '../features/schedule-manager/ScheduleManagerPage';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai-pro/v1';

function setRuntime( scheduleManagerEndpoint: string ): void {
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
		apiUrl: 'https://example.com/wp-json/mcp-ai/v1',
		proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
		nonce: 'test-nonce',
		config: { mode: 'admin' },
		endpoints: {
			chat: 'https://example.com/wp-json/mcp-ai/v1/chat',
			chatClient: 'https://example.com/wp-json/mcp-ai/v1/chat-client',
			transcripts: 'https://example.com/wp-json/mcp-ai/v1/chat-transcripts',
			threads: 'https://example.com/wp-json/mcp-ai/v1/threads',
			tools: 'https://example.com/wp-json/mcp-ai/v1/tools',
			assistants: 'https://example.com/wp-json/mcp-ai/v1/assistants',
			settings: 'https://example.com/wp-json/mcp-ai/v1/settings',
			scheduleManager: scheduleManagerEndpoint,
		},
		user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [] },
		mentionTypes: [],
	};
}

function jsonResponse( payload: unknown ): Response {
	return {
		ok: true,
		status: 200,
		json: async () => payload,
	} as Response;
}

afterEach( () => {
	vi.restoreAllMocks();
	cleanup();
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = null;
} );

describe( 'ScheduleManagerPage', () => {
	it( 'renders the schedule table after the loading state resolves', async () => {
		setRuntime( ENDPOINT );

		vi.stubGlobal(
			'fetch',
			vi.fn( ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url.endsWith( '/schedules' ) ) {
					return Promise.resolve(
						jsonResponse( {
							ok: true,
							schedules: [
								{
									id: 'sched_1',
									name: 'Nightly digest',
									schedule_type: 'assistant_run',
									schedule: 'daily',
									enabled: true,
								},
							],
							total: 1,
						} )
					);
				}
				if ( url.endsWith( '/presets' ) ) {
					return Promise.resolve( jsonResponse( { ok: true, presets: [], total: 0 } ) );
				}
				return Promise.reject( new Error( `Unexpected fetch: ${ url }` ) );
			} )
		);

		render( <ScheduleManagerPage /> );

		// The initial loading state renders before any data arrives.
		expect( screen.getByText( 'Loading schedules…' ) ).toBeTruthy();

		// Resolving the data must not throw (React error #310 regression) —
		// the page continues past the loading state and shows the table.
		await waitFor( () => {
			expect( screen.getByText( 'Nightly digest' ) ).toBeTruthy();
		} );
		expect( screen.getByText( 'Schedules (1)' ) ).toBeTruthy();
	} );

	it( 'renders the empty state when no schedules exist', async () => {
		setRuntime( ENDPOINT );

		vi.stubGlobal(
			'fetch',
			vi.fn( ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url.endsWith( '/schedules' ) ) {
					return Promise.resolve( jsonResponse( { ok: true, schedules: [], total: 0 } ) );
				}
				if ( url.endsWith( '/presets' ) ) {
					return Promise.resolve( jsonResponse( { ok: true, presets: [], total: 0 } ) );
				}
				return Promise.reject( new Error( `Unexpected fetch: ${ url }` ) );
			} )
		);

		render( <ScheduleManagerPage /> );

		await waitFor( () => {
			expect( screen.getByText( 'No schedules yet.' ) ).toBeTruthy();
		} );
	} );

	it( 'renders the fleet overview card from /schedules/fleet-stats', async () => {
		setRuntime( ENDPOINT );

		vi.stubGlobal(
			'fetch',
			vi.fn( ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url.endsWith( '/schedules/fleet-stats' ) ) {
					return Promise.resolve(
						jsonResponse( {
							ok: true,
							stats: {
								total_schedules: 2,
								enabled_schedules: 1,
								total_runs: 5,
								success_runs: 3,
								failure_runs: 2,
								success_rate: 60,
								failures_24h: 1,
								overdue_schedules: 0,
								last_run: 1700000000,
							},
						} )
					);
				}
				if ( url.endsWith( '/schedules' ) ) {
					return Promise.resolve( jsonResponse( { ok: true, schedules: [], total: 0 } ) );
				}
				if ( url.endsWith( '/presets' ) ) {
					return Promise.resolve( jsonResponse( { ok: true, presets: [], total: 0 } ) );
				}
				return Promise.reject( new Error( `Unexpected fetch: ${ url }` ) );
			} )
		);

		render( <ScheduleManagerPage /> );

		await waitFor( () => {
			expect( screen.getByText( 'Fleet overview' ) ).toBeTruthy();
		} );
		// 'Schedules' appears in the tab, the card label and the table caption —
		// assert presence, not uniqueness.
		expect( screen.getAllByText( 'Schedules' ).length ).toBeGreaterThan( 0 );
		expect( screen.getByText( 'Total runs' ) ).toBeTruthy();
		expect( screen.getByText( 'Success rate' ) ).toBeTruthy();
		expect( screen.getByText( '60%' ) ).toBeTruthy();
		expect( screen.getByText( 'Failures (24 h)' ) ).toBeTruthy();
		expect( screen.getByText( 'Last run' ) ).toBeTruthy();
	} );

	it( 'flags the fleet when failures or overdue schedules need attention', async () => {
		setRuntime( ENDPOINT );

		vi.stubGlobal(
			'fetch',
			vi.fn( ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url.endsWith( '/schedules/fleet-stats' ) ) {
					return Promise.resolve(
						jsonResponse( {
							ok: true,
							stats: {
								total_schedules: 3,
								enabled_schedules: 3,
								total_runs: 20,
								success_runs: 14,
								failure_runs: 6,
								success_rate: 70,
								failures_24h: 3,
								overdue_schedules: 2,
								last_run: null,
							},
						} )
					);
				}
				if ( url.endsWith( '/schedules' ) ) {
					return Promise.resolve( jsonResponse( { ok: true, schedules: [], total: 0 } ) );
				}
				if ( url.endsWith( '/presets' ) ) {
					return Promise.resolve( jsonResponse( { ok: true, presets: [], total: 0 } ) );
				}
				return Promise.reject( new Error( `Unexpected fetch: ${ url }` ) );
			} )
		);

		render( <ScheduleManagerPage /> );

		await waitFor( () => {
			expect( screen.getByText( 'Needs attention' ) ).toBeTruthy();
		} );
		expect( screen.getByText( '70%' ) ).toBeTruthy();
	} );

	it( 'degrades gracefully when fleet-stats fails (lists still render)', async () => {
		setRuntime( ENDPOINT );

		vi.stubGlobal(
			'fetch',
			vi.fn( ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url.endsWith( '/schedules/fleet-stats' ) ) {
					return Promise.resolve( {
						ok: false,
						status: 500,
						json: async () => ( { message: 'Fleet stats unavailable.' } ),
					} as Response );
				}
				if ( url.endsWith( '/schedules' ) ) {
					return Promise.resolve(
						jsonResponse( {
							ok: true,
							schedules: [
								{
									id: 'sched_1',
									name: 'Nightly digest',
									schedule_type: 'assistant_run',
									schedule: 'daily',
									enabled: true,
								},
							],
							total: 1,
						} )
					);
				}
				if ( url.endsWith( '/presets' ) ) {
					return Promise.resolve( jsonResponse( { ok: true, presets: [], total: 0 } ) );
				}
				return Promise.reject( new Error( `Unexpected fetch: ${ url }` ) );
			} )
		);

		render( <ScheduleManagerPage /> );

		await waitFor( () => {
			expect( screen.getByText( 'Nightly digest' ) ).toBeTruthy();
		} );
		// The card never renders without stats — the lists stay usable.
		expect( screen.queryByText( 'Fleet overview' ) ).toBeNull();
	} );
} );
