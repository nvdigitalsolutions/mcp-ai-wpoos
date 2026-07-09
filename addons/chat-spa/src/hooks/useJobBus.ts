/**
 * NV oOS Chat SPA — Job event bus bridge.
 *
 * Bridges the global `window.wpMcpAiJobBus` (a custom EventTarget used by
 * the legacy chat.js and cron-status SSE streams) into React state so
 * the SPA can render live JobCards and the Tasks Drawer.
 *
 * Events surfaced:
 *   job:started   → new job appears
 *   job:step      → step added to job
 *   job:progress  → progress / ETA updated
 *   job:completed → job marked done, result stored
 *   job:failed    → job marked failed, error stored
 *   job:cancelled → job removed
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.9.0
 */

import { useCallback, useEffect, useRef, useState } from 'react';

// ── Types ─────────────────────────────────────────────────────────────────

export interface JobStep {
	id?: string;
	label: string;
	status: 'pending' | 'running' | 'completed' | 'failed';
}

export type JobStatus = 'queued' | 'running' | 'polling' | 'completed' | 'failed' | 'cancelled';

export interface JobRecord {
	jobId: string;
	toolName: string;
	status: JobStatus;
	progress?: number; // 0–100
	eta?: string;
	message?: string;
	steps: JobStep[];
	result?: unknown;
	error?: string;
	startedAt: number;
}

export interface UseJobBusResult {
	/** All known jobs, keyed by jobId. */
	jobs: Record< string, JobRecord >;
	/** Number of currently running (non-terminal) jobs. */
	runningCount: number;
	/** Cancel a job via REST. */
	cancelJob: ( jobId: string ) => Promise< void >;
	/** Retry a failed job via REST. */
	retryJob: ( jobId: string ) => Promise< void >;
	/** Dismiss a terminal job from the local list. */
	dismissJob: ( jobId: string ) => void;
	/** Dismiss all terminal jobs. */
	dismissAllTerminal: () => void;
}

// ── Helpers ───────────────────────────────────────────────────────────────

function isTerminal( s: JobStatus ): boolean {
	return s === 'completed' || s === 'failed' || s === 'cancelled';
}

// ── Hook ──────────────────────────────────────────────────────────────────

export function useJobBus(
	cronStatusBaseUrl: string,
	nonce: string
): UseJobBusResult {
	const [ jobs, setJobs ] = useState< Record< string, JobRecord > >( {} );
	const jobsRef = useRef( jobs );
	jobsRef.current = jobs;

	// ── Subscribe to global job bus ─────────────────────────────────────

	useEffect( () => {
		const bus = ( window as unknown as { wpMcpAiJobBus?: EventTarget } )
			.wpMcpAiJobBus;
		if ( ! bus ) return;

		const handleJobEvent = ( e: Event ) => {
			const detail = ( e as CustomEvent ).detail as Record< string, unknown > | undefined;
			if ( ! detail ) return;

			const jobId = String( detail.job_id ?? detail.jobId ?? '' );
			if ( ! jobId ) return;

			const type = e.type;

			setJobs( ( prev ) => {
				const existing = prev[ jobId ];

				if ( type === 'job:cancelled' ) {
					const next = { ...prev };
					delete next[ jobId ];
					return next;
				}

				if ( type === 'job:completed' ) {
					return {
						...prev,
						[ jobId ]: {
							...( existing || {
								jobId,
								toolName: String( detail.tool_name ?? detail.toolName ?? '' ),
								status: 'completed',
								steps: [],
								startedAt: Date.now(),
							} ),
							status: 'completed',
							result: detail.result ?? detail.data ?? detail,
							progress: 100,
							message: String( detail.message ?? '' ),
						},
					};
				}

				if ( type === 'job:failed' ) {
					return {
						...prev,
						[ jobId ]: {
							...( existing || {
								jobId,
								toolName: String( detail.tool_name ?? detail.toolName ?? '' ),
								status: 'failed',
								steps: [],
								startedAt: Date.now(),
							} ),
							status: 'failed',
							error: String( detail.error ?? '' ),
						},
					};
				}

				if ( type === 'job:progress' ) {
					const updates: Partial< JobRecord > = {};
					if ( typeof detail.progress === 'number' ) {
						updates.progress = Math.max( 0, Math.min( 100, detail.progress ) );
					}
					if ( detail.eta ) updates.eta = String( detail.eta );
					if ( detail.message || detail.progress_message ) {
						updates.message = String( detail.message || detail.progress_message );
					}

					return {
						...prev,
						[ jobId ]: {
							...( existing || {
								jobId,
								toolName: String( detail.tool_name ?? detail.toolName ?? '' ),
								status: 'running',
								steps: [],
								startedAt: Date.now(),
							} ),
							...updates,
						},
					};
				}

				// Default: add/update the job.
				const status = ( detail.status as string )?.toLowerCase();
				const mappedStatus: JobStatus =
					status === 'completed' ? 'completed' :
					status === 'failed' || status === 'error' ? 'failed' :
					status === 'cancelled' ? 'cancelled' :
					status === 'queued' ? 'queued' :
					status === 'polling' ? 'polling' :
					'running';

				return {
					...prev,
					[ jobId ]: {
						...( existing || {
							jobId,
							toolName: String( detail.tool_name ?? detail.toolName ?? '' ),
							status: mappedStatus,
							steps: [],
							startedAt: Date.now(),
						} ),
						status: mappedStatus,
					},
				};
			} );
		};

		const events = [
			'job:started',
			'job:step',
			'job:progress',
			'job:completed',
			'job:failed',
			'job:cancelled',
		];
		events.forEach( ( evt ) => bus.addEventListener( evt, handleJobEvent ) );

		return () => {
			events.forEach( ( evt ) =>
				bus.removeEventListener( evt, handleJobEvent )
			);
		};
	}, [] );

	// ── Derived ──────────────────────────────────────────────────────────

	const runningCount = Object.values( jobs ).filter(
		( j ) => ! isTerminal( j.status )
	).length;

	// ── Actions ──────────────────────────────────────────────────────────

	const postJobAction = useCallback(
		async ( jobId: string, action: string ) => {
			const url = `${ cronStatusBaseUrl.replace( /\/+$/, '' ) }/cron-status/${ encodeURIComponent( jobId ) }/${ action }`;
			await fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
			} );
		},
		[ cronStatusBaseUrl, nonce ]
	);

	const cancelJob = useCallback(
		async ( jobId: string ) => {
			await postJobAction( jobId, 'cancel' );
		},
		[ postJobAction ]
	);

	const retryJob = useCallback(
		async ( jobId: string ) => {
			await postJobAction( jobId, 'retry' );
		},
		[ postJobAction ]
	);

	const dismissJob = useCallback( ( jobId: string ) => {
		setJobs( ( prev ) => {
			const next = { ...prev };
			delete next[ jobId ];
			return next;
		} );
	}, [] );

	const dismissAllTerminal = useCallback( () => {
		setJobs( ( prev ) => {
			const next: Record< string, JobRecord > = {};
			for ( const [ id, job ] of Object.entries( prev ) ) {
				if ( ! isTerminal( job.status ) ) {
					next[ id ] = job;
				}
			}
			return next;
		} );
	}, [] );

	return {
		jobs,
		runningCount,
		cancelJob,
		retryJob,
		dismissJob,
		dismissAllTerminal,
	};
}
