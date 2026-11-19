/**
 * The Run-now poll loop.
 *
 * One implementation serves both runtimes: WordPress queues an Action
 * Scheduler / WP-Cron run, Laravel dispatches a queue job, and both answer
 * `POST /trigger` with an acknowledgement plus a run id. The hook must paint
 * Pending immediately, then follow `GET /runs/{id}` to a terminal status and
 * refresh the list exactly once.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';

import { useScheduleManager } from '../hooks/useScheduleManager';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai-pro/v1';

function json( payload: unknown ): Response {
	return {
		ok: true,
		status: 200,
		json: () => Promise.resolve( payload ),
	} as Response;
}

/**
 * Serve the whole surface, with the run-status responses replayed in order.
 *
 * @param runStatuses Successive `/runs/{id}` payloads (the last one repeats).
 * @return Every request, as `METHOD url`.
 */
function installFetch( runStatuses: Array< Record< string, unknown > > ): string[] {
	const calls: string[] = [];
	let pollIndex = 0;

	global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
		const url = String( input );
		const method = init?.method ?? 'GET';
		calls.push( `${ method } ${ url }` );

		if ( url.endsWith( '/schedules' ) ) {
			return Promise.resolve(
				json( { ok: true, schedules: [ { id: 'sch_1', name: 'Digest', enabled: true } ], total: 1 } )
			);
		}
		if ( url.includes( '/presets' ) ) {
			return Promise.resolve( json( { ok: true, presets: [], total: 0 } ) );
		}
		if ( url.includes( '/fleet-stats' ) ) {
			return Promise.resolve( json( { ok: true, stats: { total_schedules: 1 } } ) );
		}
		if ( url.endsWith( '/trigger' ) ) {
			return Promise.resolve(
				json( { ok: true, queued: true, run_id: 'abc123', message: 'queued' } )
			);
		}
		if ( url.includes( '/runs/abc123' ) ) {
			const next = runStatuses[ Math.min( pollIndex, runStatuses.length - 1 ) ];
			pollIndex += 1;
			return Promise.resolve( json( { ok: true, ...next } ) );
		}

		return Promise.resolve( json( { ok: true } ) );
	} ) as unknown as typeof fetch;

	return calls;
}

/**
 * Let the hook's mount-time refresh (and any queued microtasks) settle.
 */
async function settle(): Promise< void > {
	await act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );
}

afterEach( () => {
	vi.useRealTimers();
	vi.restoreAllMocks();
} );

describe( 'useScheduleManager — run polling', () => {
	it( 'paints Pending, then the terminal status, and refreshes once', async () => {
		vi.useFakeTimers();
		const calls = installFetch( [
			{ status: 'pending', finished: false },
			{ status: 'success', finished: true, duration: 1.5, error: null },
		] );

		const { result } = renderHook( () =>
			useScheduleManager( { endpoint: ENDPOINT, nonce: 'n' } )
		);

		await settle();

		await act( async () => {
			await result.current.triggerSchedule( 'sch_1' );
		} );

		// The acknowledgement is painted before any poll happens.
		expect( result.current.runStates.sch_1 ).toMatchObject( {
			status: 'pending',
			runId: 'abc123',
		} );

		const listCallsBefore = calls.filter(
			( call ) => call === `GET ${ ENDPOINT }/schedules`
		).length;

		// First poll: still in flight.
		await act( async () => {
			await vi.advanceTimersByTimeAsync( 2000 );
		} );
		expect( result.current.runStates.sch_1.status ).toBe( 'pending' );

		// Second poll returns the terminal status.
		await act( async () => {
			await vi.advanceTimersByTimeAsync( 2500 );
		} );

		expect( result.current.runStates.sch_1.status ).toBe( 'success' );
		expect( result.current.runStates.sch_1.duration ).toBe( 1.5 );

		const polls = calls.filter( ( call ) => call.includes( '/runs/abc123' ) );
		expect( polls ).toHaveLength( 2 );

		// Terminal status refreshes the list, so run_count / last_run catch up.
		const listCallsAfter = calls.filter(
			( call ) => call === `GET ${ ENDPOINT }/schedules`
		).length;
		expect( listCallsAfter ).toBeGreaterThan( listCallsBefore );
	} );

	it( 'keeps polling through a transient failure', async () => {
		vi.useFakeTimers();

		let poll = 0;
		global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
			const url = String( input );

			if ( url.includes( '/runs/abc123' ) ) {
				poll += 1;

				return poll === 1
					? Promise.reject( new Error( 'Network blip' ) )
					: Promise.resolve( json( { ok: true, status: 'running', finished: false } ) );
			}
			if ( url.endsWith( '/trigger' ) ) {
				return Promise.resolve(
					json( { ok: true, queued: true, run_id: 'abc123', message: 'queued' } )
				);
			}
			if ( url.endsWith( '/schedules' ) ) {
				return Promise.resolve( json( { ok: true, schedules: [], total: 0 } ) );
			}

			return Promise.resolve( json( { ok: true, presets: [] } ) );
		} ) as unknown as typeof fetch;

		const { result } = renderHook( () =>
			useScheduleManager( { endpoint: ENDPOINT, nonce: 'n' } )
		);

		await settle();

		await act( async () => {
			await result.current.triggerSchedule( 'sch_1' );
		} );

		// A thrown poll must not be reported as a failure.
		await act( async () => {
			await vi.advanceTimersByTimeAsync( 2000 );
		} );
		expect( result.current.runStates.sch_1.status ).toBe( 'pending' );

		await act( async () => {
			await vi.advanceTimersByTimeAsync( 3500 );
		} );
		expect( result.current.runStates.sch_1.status ).toBe( 'running' );
	} );

	it( 'falls back to a plain refresh when the backend returns no handle', async () => {
		vi.useFakeTimers();

		const calls: string[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			const url = String( input );
			calls.push( `${ init?.method ?? 'GET' } ${ url }` );

			if ( url.endsWith( '/trigger' ) ) {
				return Promise.resolve( json( { ok: true } ) );
			}
			if ( url.endsWith( '/schedules' ) ) {
				return Promise.resolve( json( { ok: true, schedules: [], total: 0 } ) );
			}

			return Promise.resolve( json( { ok: true, presets: [] } ) );
		} ) as unknown as typeof fetch;

		const { result } = renderHook( () =>
			useScheduleManager( { endpoint: ENDPOINT, nonce: 'n' } )
		);

		await settle();

		await act( async () => {
			await result.current.triggerSchedule( 'sch_1' );
		} );

		expect( result.current.runStates.sch_1 ).toBeUndefined();
		expect( calls.some( ( call ) => call.includes( '/runs/' ) ) ).toBe( false );
		expect(
			calls.filter( ( call ) => call === `GET ${ ENDPOINT }/schedules` ).length
		).toBeGreaterThan( 1 );
	} );
} );
