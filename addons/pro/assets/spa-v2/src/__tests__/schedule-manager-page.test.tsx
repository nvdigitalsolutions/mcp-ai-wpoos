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
} );
