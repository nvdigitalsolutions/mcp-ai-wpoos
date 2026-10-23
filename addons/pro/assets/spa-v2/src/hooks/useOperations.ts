/**
 * useOperations — Hook for the operations surfaces (cron + DLQ).
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { OperationsClient, type CronJobRecord, type DlqItemRecord, type TimelinePage } from '../api/operations';

export interface UseOperationsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseOperationsReturn {
	cronJobs: CronJobRecord[];
	dlqItems: DlqItemRecord[];
	timeline: TimelinePage;
	loading: boolean;
	mutating: boolean;
	error: string | null;
	refresh: () => Promise< void >;
	removeCronJob: ( jobId: string ) => Promise< void >;
	retryDlq: ( itemId: string ) => Promise< void >;
	dismissDlq: ( itemId: string ) => Promise< void >;
	loadTimelinePage: ( page?: number, assistantId?: number ) => Promise< void >;
}

export function useOperations( options: UseOperationsOptions ): UseOperationsReturn {
	const client = useMemo(
		() => new OperationsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ cronJobs, setCronJobs ] = useState< CronJobRecord[] >( [] );
	const [ dlqItems, setDlqItems ] = useState< DlqItemRecord[] >( [] );
	const [ timeline, setTimeline ] = useState< TimelinePage >( { runs: [], total: 0, page: 1, per_page: 20 } );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ mutating, setMutating ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const loadTimelinePage = useCallback(
		async ( page = 1, assistantId = 0 ) => {
			if ( ! options.endpoint ) {
				return;
			}
			try {
				setTimeline( await client.timeline( page, assistantId ) );
			} catch {
				// Timeline failure must not blank the cron/DLQ tabs — the
				// shared error banner in refresh() still surfaces it.
				setTimeline( { runs: [], total: 0, page: 1, per_page: 20 } );
			}
		},
		[ client, options.endpoint ]
	);

	const refresh = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const [ jobs, items, timelinePage ] = await Promise.all( [
				client.cronJobs(),
				client.dlqItems(),
				client.timeline( 1 ),
			] );
			setCronJobs( jobs );
			setDlqItems( items );
			setTimeline( timelinePage );
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
		async ( action: () => Promise< void > ) => {
			if ( ! options.endpoint ) {
				return;
			}
			setMutating( true );
			setError( null );
			try {
				await action();
				await refresh();
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
			} finally {
				setMutating( false );
			}
		},
		[ options.endpoint, refresh ]
	);

	const removeCronJob = useCallback( ( jobId: string ) => run( () => client.removeCronJob( jobId ) ), [ run, client ] );
	const retryDlq = useCallback( ( itemId: string ) => run( () => client.retryDlq( itemId ) ), [ run, client ] );
	const dismissDlq = useCallback( ( itemId: string ) => run( () => client.dismissDlq( itemId ) ), [ run, client ] );

	return { cronJobs, dlqItems, timeline, loading, mutating, error, refresh, removeCronJob, retryDlq, dismissDlq, loadTimelinePage };
}
