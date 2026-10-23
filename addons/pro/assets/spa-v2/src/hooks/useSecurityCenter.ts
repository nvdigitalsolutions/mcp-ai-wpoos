/**
 * useSecurityCenter — Hook for the security posture surface.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { SecurityCenterClient } from '../api/security';

export interface UseSecurityCenterOptions {
	endpoint: string;
	nonce: string;
}

export interface UseSecurityCenterReturn {
	posture: Record< string, unknown > | null;
	headers: { security_headers_enabled?: boolean; hsts_enabled?: boolean; headers?: Record< string, string > } | null;
	snapshots: Record< string, unknown >[];
	loading: boolean;
	busy: boolean;
	error: string | null;
	/** Last action result payload (null = none yet). */
	lastAction: Record< string, unknown > | null;
	refresh: () => Promise< void >;
	saveSnapshot: () => Promise< void >;
	restoreSnapshot: ( snapshotId: string ) => Promise< void >;
	runSelfTest: () => Promise< void >;
}

export function useSecurityCenter( options: UseSecurityCenterOptions ): UseSecurityCenterReturn {
	const client = useMemo(
		() => new SecurityCenterClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ posture, setPosture ] = useState< Record< string, unknown > | null >( null );
	const [ headers, setHeaders ] = useState< UseSecurityCenterReturn[ 'headers' ] >( null );
	const [ snapshots, setSnapshots ] = useState< Record< string, unknown >[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ busy, setBusy ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ lastAction, setLastAction ] = useState< Record< string, unknown > | null >( null );

	const refresh = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const [ postureResult, headersResult, snapshotsResult ] = await Promise.all( [
				client.posture(),
				client.previewHeaders(),
				client.snapshots(),
			] );
			setPosture( postureResult );
			setHeaders( headersResult );
			setSnapshots( Array.isArray( snapshotsResult.snapshots ) ? snapshotsResult.snapshots : [] );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint ] );

	useEffect( () => {
		void refresh();
	}, [ refresh ] );

	const run = useCallback(
		async ( action: () => Promise< Record< string, unknown > > ) => {
			if ( ! options.endpoint ) {
				return;
			}
			setBusy( true );
			setError( null );
			try {
				setLastAction( await action() );
				await refresh();
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
			} finally {
				setBusy( false );
			}
		},
		[ options.endpoint, refresh ]
	);

	const saveSnapshot = useCallback( () => run( () => client.saveSnapshot() ), [ run, client ] );
	const restoreSnapshot = useCallback(
		( snapshotId: string ) => run( () => client.restoreSnapshot( snapshotId ) ),
		[ run, client ]
	);
	const runSelfTest = useCallback( () => run( () => client.selfTest() ), [ run, client ] );

	return {
		posture,
		headers,
		snapshots,
		loading,
		busy,
		error,
		lastAction,
		refresh,
		saveSnapshot,
		restoreSnapshot,
		runSelfTest,
	};
}
