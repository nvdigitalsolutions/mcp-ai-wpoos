/**
 * useScheduleManager — Hook for the schedule + preset surface.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import {
	ScheduleManagerClient,
	type ScheduleItem,
	type SchedulePreset,
} from '../api/schedule-manager';

export interface UseScheduleManagerOptions {
	endpoint: string;
	nonce: string;
}

export interface UseScheduleManagerReturn {
	schedules: ScheduleItem[];
	presets: SchedulePreset[];
	loading: boolean;
	error: string | null;
	/** Failure of the schedules list specifically (presets stay loaded). */
	schedulesError: string | null;
	actionError: string | null;
	refresh: () => Promise< void >;
	createSchedule: ( payload: Record< string, unknown > ) => Promise< boolean >;
	removeSchedule: ( id: string ) => Promise< boolean >;
	triggerSchedule: ( id: string ) => Promise< boolean >;
	toggleSchedule: ( id: string ) => Promise< boolean >;
	installPreset: ( id: string ) => Promise< boolean >;
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
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ schedulesError, setSchedulesError ] = useState< string | null >( null );
	const [ actionError, setActionError ] = useState< string | null >( null );

	const refresh = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		setSchedulesError( null );
		try {
			// The two lists share the REST base but are separate requests —
			// one failing must never blank the other, and the schedules tab
			// must surface its own failure instead of a silent "No schedules
			// yet." empty state.
			const [ scheduleResult, presetResult ] = await Promise.allSettled( [
				client.list(),
				client.presets(),
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
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint ] );

	useEffect( () => {
		void refresh();
	}, [ refresh ] );

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

	const triggerSchedule = useCallback(
		( id: string ) => runAction( () => client.trigger( id ) ),
		[ client, runAction ]
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
		loading,
		error,
		schedulesError,
		actionError,
		refresh,
		createSchedule,
		removeSchedule,
		triggerSchedule,
		toggleSchedule,
		installPreset,
	};
}
