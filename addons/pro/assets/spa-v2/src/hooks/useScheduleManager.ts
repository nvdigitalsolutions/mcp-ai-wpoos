// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * useScheduleManager — Hook for the schedule + preset surface.
 */

import { useState, useCallback, useEffect, useMemo, useRef } from 'react';
import { __ } from '@wordpress/i18n';
import {
	ScheduleManagerClient,
	type FleetStats,
	type ScheduleItem,
	type SchedulePreset,
	type ScheduleRunStatus,
} from '../api/schedule-manager';

export interface UseScheduleManagerOptions {
	endpoint: string;
	nonce: string;
}

/**
 * Where the poll loop stands for one schedule.
 *
 * `unknown` is the give-up state: the server never reported a terminal status
 * inside the attempt budget, so the row is left with a "check history" hint
 * rather than a status it invented.
 */
export type RunPhase = 'pending' | 'running' | 'success' | 'failure' | 'timeout' | 'unknown';

export interface ScheduleRunState {
	status: RunPhase;
	runId?: string;
	message?: string;
	error?: string | null;
	duration?: number | null;
}

export interface UseScheduleManagerReturn {
	schedules: ScheduleItem[];
	presets: SchedulePreset[];
	fleetStats: FleetStats | null;
	loading: boolean;
	error: string | null;
	/** Failure of the schedules list specifically (presets stay loaded). */
	schedulesError: string | null;
	/** Failure of the fleet-stats aggregate (the two lists stay loaded). */
	fleetStatsError: string | null;
	actionError: string | null;
	/** Live run state per schedule id, while a Run now is in flight. */
	runStates: Record< string, ScheduleRunState >;
	refresh: () => Promise< void >;
	createSchedule: ( payload: Record< string, unknown > ) => Promise< boolean >;
	removeSchedule: ( id: string ) => Promise< boolean >;
	triggerSchedule: ( id: string ) => Promise< boolean >;
	toggleSchedule: ( id: string ) => Promise< boolean >;
	installPreset: ( id: string ) => Promise< boolean >;
}

const TERMINAL_PHASES: RunPhase[] = [ 'success', 'failure', 'timeout' ];

/**
 * 40 attempts with a 2 s → 10 s climb is ~5 minutes, the same budget the
 * classic UI uses. A longer run is reported as `unknown` with a pointer to the
 * history, never as a status the client made up.
 */
const MAX_POLL_ATTEMPTS = 40;

function pollDelay( attempt: number ): number {
	return Math.min( 10000, 2000 * Math.pow( 1.5, attempt ) );
}

function normalizePhase( status: string ): RunPhase {
	return status === 'pending' ||
		status === 'running' ||
		status === 'success' ||
		status === 'failure' ||
		status === 'timeout'
		? status
		: 'unknown';
}

export function useScheduleManager(
	options: UseScheduleManagerOptions
): UseScheduleManagerReturn {
	const client = useMemo(
		() => new ScheduleManagerClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ schedules, setSchedules ] = useState< ScheduleItem[] >( [] );
	const [ presets, setPresets ] = useState< SchedulePreset[] >( [] );
	const [ fleetStats, setFleetStats ] = useState< FleetStats | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ schedulesError, setSchedulesError ] = useState< string | null >( null );
	const [ fleetStatsError, setFleetStatsError ] = useState< string | null >( null );
	const [ actionError, setActionError ] = useState< string | null >( null );
	const [ runStates, setRunStates ] = useState< Record< string, ScheduleRunState > >( {} );

	/** One pending poll timer per schedule, cleared on unmount. */
	const timers = useRef< Record< string, ReturnType< typeof setTimeout > > >( {} );
	const mounted = useRef< boolean >( true );

	const refresh = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		setSchedulesError( null );
		setFleetStatsError( null );
		try {
			// The two lists share the REST base but are separate requests —
			// one failing must never blank the other, and the schedules tab
			// must surface its own failure instead of a silent "No schedules
			// yet." empty state.
			const [ scheduleResult, presetResult, fleetResult ] = await Promise.allSettled( [
				client.list(),
				client.presets(),
				client.fleetStats(),
			] );
			if ( scheduleResult.status === 'fulfilled' ) {
				setSchedules( scheduleResult.value );
			} else {
				setSchedulesError(
					scheduleResult.reason instanceof Error
						? scheduleResult.reason.message
						: String( scheduleResult.reason )
				);
			}
			if ( presetResult.status === 'fulfilled' ) {
				setPresets( presetResult.value );
			} else {
				setError(
					presetResult.reason instanceof Error
						? presetResult.reason.message
						: String( presetResult.reason )
				);
			}
			if ( fleetResult.status === 'fulfilled' ) {
				setFleetStats( fleetResult.value );
			} else {
				setFleetStatsError(
					fleetResult.reason instanceof Error
						? fleetResult.reason.message
						: String( fleetResult.reason )
				);
			}
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint ] );

	useEffect( () => {
		void refresh();
	}, [ refresh ] );

	useEffect( () => {
		mounted.current = true;

		return () => {
			mounted.current = false;
			Object.values( timers.current ).forEach( ( handle ) => clearTimeout( handle ) );
			timers.current = {};
		};
	}, [] );

	const clearTimer = useCallback( ( id: string ) => {
		const handle = timers.current[ id ];
		if ( handle ) {
			clearTimeout( handle );
			delete timers.current[ id ];
		}
	}, [] );

	const setRunState = useCallback( ( id: string, state: ScheduleRunState | null ) => {
		if ( ! mounted.current ) {
			return;
		}
		setRunStates( ( prev ) => {
			const next = { ...prev };
			if ( state === null ) {
				delete next[ id ];
			} else {
				next[ id ] = state;
			}
			return next;
		} );
	}, [] );

	/**
	 * Poll one run until it reaches a terminal status, the attempt budget runs
	 * out, or the component unmounts.
	 *
	 * A thrown request is treated as transient: the previous state stays on
	 * screen and the loop retries, because a proxy blip 3 s into a 90 s run must
	 * not be reported as a failure.
	 */
	const pollRun = useCallback(
		async ( id: string, runId: string | number, attempt: number ) => {
			clearTimer( id );

			let status: ScheduleRunStatus | null = null;

			try {
				status = await client.runStatus( id, runId );
			} catch ( err ) {
				if ( attempt >= MAX_POLL_ATTEMPTS ) {
					setRunState( id, {
						status: 'failure',
						runId: String( runId ),
						error: err instanceof Error ? err.message : String( err ),
					} );
					return;
				}
			}

			if ( status ) {
				const phase = normalizePhase( status.status );

				setRunState( id, {
					status: phase,
					runId: String( runId ),
					error: status.error ?? null,
					duration: status.duration ?? null,
				} );

				if ( status.finished || TERMINAL_PHASES.indexOf( phase ) !== -1 ) {
					// Terminal: refresh once so run_count / last_run catch up.
					await refresh();
					return;
				}
			}

			if ( attempt >= MAX_POLL_ATTEMPTS ) {
				setRunState( id, {
					status: 'unknown',
					runId: String( runId ),
					message: __(
						'Still running. The run history shows the outcome when it finishes.',
						'nvoos-pro-spa'
					),
				} );
				return;
			}

			timers.current[ id ] = setTimeout( () => {
				void pollRun( id, runId, attempt + 1 );
			}, pollDelay( attempt ) );
		},
		[ clearTimer, client, refresh, setRunState ]
	);

	const runAction = useCallback(
		async ( action: () => Promise< void > ): Promise< boolean > => {
			setActionError( null );
			try {
				await action();
				await refresh();
				return true;
			} catch ( err ) {
				setActionError( err instanceof Error ? err.message : String( err ) );
				return false;
			}
		},
		[ refresh ]
	);

	const createSchedule = useCallback(
		( payload: Record< string, unknown > ) => runAction( () => client.create( payload ) ),
		[ client, runAction ]
	);

	const removeSchedule = useCallback(
		( id: string ) => runAction( () => client.remove( id ) ),
		[ client, runAction ]
	);

	/**
	 * Queue a manual run and follow it.
	 *
	 * Both runtimes answer with an acknowledgement (`queued` + `run_id`); the
	 * handler executes in a queue worker, so waiting on the response would hold
	 * the console open past the proxy timeout. A backend that answers without a
	 * handle (older WordPress build) still gets a plain refresh.
	 */
	const triggerSchedule = useCallback(
		async ( id: string ): Promise< boolean > => {
			setActionError( null );
			clearTimer( id );

			try {
				const result = await client.trigger( id );

				if ( result.queued && result.run_id !== undefined ) {
					const runId = String( result.run_id );

					setRunState( id, {
						status: 'pending',
						runId,
						message: result.message,
					} );

					// One beat before the first poll: a plain task hook is
					// usually finished by then, so the user sees Pending on the
					// way to the real outcome rather than a flash of Running.
					timers.current[ id ] = setTimeout( () => {
						void pollRun( id, runId, 0 );
					}, 2000 );

					return true;
				}

				await refresh();
				return true;
			} catch ( err ) {
				setActionError( err instanceof Error ? err.message : String( err ) );
				return false;
			}
		},
		[ clearTimer, client, pollRun, refresh, setRunState ]
	);

	const toggleSchedule = useCallback(
		( id: string ) => runAction( () => client.toggle( id ) ),
		[ client, runAction ]
	);

	const installPreset = useCallback(
		( id: string ) => runAction( () => client.installPreset( id ) ),
		[ client, runAction ]
	);

	return {
		schedules,
		presets,
		fleetStats,
		loading,
		error,
		schedulesError,
		fleetStatsError,
		actionError,
		runStates,
		refresh,
		createSchedule,
		removeSchedule,
		triggerSchedule,
		toggleSchedule,
		installPreset,
	};
}
