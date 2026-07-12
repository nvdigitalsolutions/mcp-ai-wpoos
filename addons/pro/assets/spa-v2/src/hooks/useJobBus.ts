/**
 * Pro SPA v2 — Job event bus bridge.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import type { JobRecord, JobStatus } from '../components/shared/JobCard';

export interface UseJobBusResult {
	jobs: Record< string, JobRecord >;
	runningCount: number;
	failedCount: number;
	cancelJob: ( jobId: string ) => Promise< void >;
	retryJob: ( jobId: string ) => Promise< void >;
	dismissJob: ( jobId: string ) => void;
	dismissAllTerminal: () => void;
}

function isTerminal( s: JobStatus ): boolean {
	return s === 'completed' || s === 'failed' || s === 'cancelled';
}

export function useJobBus( cronStatusBaseUrl: string, nonce: string ): UseJobBusResult {
	const [ jobs, setJobs ] = useState< Record< string, JobRecord > >( {} );

	useEffect( () => {
		const bus = ( window as unknown as { wpMcpAiJobBus?: EventTarget } ).wpMcpAiJobBus;
		if ( ! bus ) return;

		const handler = ( e: Event ) => {
			const d = ( e as CustomEvent ).detail as Record< string, unknown > | undefined;
			if ( ! d ) return;
			const jobId = String( d.job_id ?? d.jobId ?? '' );
			if ( ! jobId ) return;
			const type = e.type;

			setJobs( ( prev ) => {
				const ex = prev[ jobId ];
				if ( type === 'job:cancelled' ) { const n = { ...prev }; delete n[ jobId ]; return n; }
				if ( type === 'job:completed' ) return { ...prev, [ jobId ]: { ...( ex || { jobId, toolName: String( d.tool_name ?? '' ), status: 'completed', steps: [], startedAt: Date.now() } ), status: 'completed', result: d.result ?? d.data ?? d, progress: 100, message: String( d.message ?? '' ) } };
				if ( type === 'job:failed' ) return { ...prev, [ jobId ]: { ...( ex || { jobId, toolName: String( d.tool_name ?? '' ), status: 'failed', steps: [], startedAt: Date.now() } ), status: 'failed', error: String( d.error ?? '' ) } };
				if ( type === 'job:progress' ) {
					const u: Partial< JobRecord > = {};
					if ( typeof d.progress === 'number' ) u.progress = Math.max( 0, Math.min( 100, d.progress ) );
					if ( d.eta ) u.eta = String( d.eta );
					if ( d.message || d.progress_message ) u.message = String( d.message || d.progress_message );
					return { ...prev, [ jobId ]: { ...( ex || { jobId, toolName: String( d.tool_name ?? '' ), status: 'running', steps: [], startedAt: Date.now() } ), ...u } };
				}
				const s = ( d.status as string )?.toLowerCase();
				const ms: JobStatus = s === 'completed' ? 'completed' : s === 'failed' || s === 'error' ? 'failed' : s === 'cancelled' ? 'cancelled' : s === 'queued' ? 'queued' : s === 'polling' ? 'polling' : 'running';
				return { ...prev, [ jobId ]: { ...( ex || { jobId, toolName: String( d.tool_name ?? '' ), status: ms, steps: [], startedAt: Date.now() } ), status: ms } };
			} );
		};

		[ 'job:started', 'job:step', 'job:progress', 'job:completed', 'job:failed', 'job:cancelled' ].forEach( ( ev ) => bus.addEventListener( ev, handler ) );
		return () => { [ 'job:started', 'job:step', 'job:progress', 'job:completed', 'job:failed', 'job:cancelled' ].forEach( ( ev ) => bus.removeEventListener( ev, handler ) ); };
	}, [] );

	const runningCount = Object.values( jobs ).filter( ( j ) => ! isTerminal( j.status ) ).length;
	const failedCount = Object.values( jobs ).filter( ( j ) => j.status === 'failed' ).length;

	const postJobAction = useCallback( async ( jobId: string, action: string ) => {
		await fetch( `${ cronStatusBaseUrl.replace( /\/+$/, '' ) }/cron-status/${ encodeURIComponent( jobId ) }/${ action }`, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
		} );
	}, [ cronStatusBaseUrl, nonce ] );

	const cancelJob = useCallback( async ( id: string ) => { await postJobAction( id, 'cancel' ); }, [ postJobAction ] );
	const retryJob = useCallback( async ( id: string ) => { await postJobAction( id, 'retry' ); }, [ postJobAction ] );
	const dismissJob = useCallback( ( id: string ) => { setJobs( ( p ) => { const n = { ...p }; delete n[ id ]; return n; } ); }, [] );
	const dismissAllTerminal = useCallback( () => { setJobs( ( p ) => { const n: Record< string, JobRecord > = {}; for ( const [ k, v ] of Object.entries( p ) ) { if ( ! isTerminal( v.status ) ) n[ k ] = v; } return n; } ); }, [] );

	return { jobs, runningCount, failedCount, cancelJob, retryJob, dismissJob, dismissAllTerminal };
}
