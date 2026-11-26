// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Pro SPA v2 — Job event bus bridge with SSE fallback.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 * @since   2.1.0  Added SSE cron-status stream and job-bus auto-creation.
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

// ---------------------------------------------------------------------------
// Ensure window.wpMcpAiJobBus exists (v2.1.0).
// The main chat (chat.js / cron-status-service.js) normally creates this bus,
// but when the Pro SPA loads on its own the bus may not exist yet.
// We create a minimal EventTarget that the rest of the system can use.
// The full bus created by chat.js has extra methods like handleJobUpdate(),
// getCached(), watchJob(), and a cache.  Those are optional conveniences;
// the Pro SPA's useJobBus only uses addEventListener / dispatchEvent.
// ---------------------------------------------------------------------------

function ensureJobBus(): EventTarget {
	const w = window as unknown as {
		wpMcpAiJobBus?: EventTarget;
	};
	if ( w.wpMcpAiJobBus ) return w.wpMcpAiJobBus;

	const bus = new EventTarget();
	w.wpMcpAiJobBus = bus;
	if ( typeof console !== 'undefined' && console.info ) {
		console.info(
			'[NV oOS Pro SPA] Global job event bus created (main chat did not provide one).',
		);
	}
	return bus;
}

// ---------------------------------------------------------------------------
// SSE cron-status stream helpers (v2.1.0).
// ---------------------------------------------------------------------------

interface CronStatusSseEvent {
	job_id?: string;
	jobId?: string;
	status?: string;
	tool_name?: string;
	progress?: number;
	message?: string;
	error?: string;
	result?: unknown;
	data?: unknown;
	eta?: string;
	steps?: Array< Record< string, unknown > >;
}

interface CronStatusSsePayload {
	jobs?: CronStatusSseEvent[];
	[ key: string ]: unknown;
}

/**
 * Reconnect backoff for the cron-status stream. A healthy stream is resumed
 * promptly (the server caps each connection at 6 minutes so workers recycle);
 * genuine failures back off exponentially.
 */
const SSE_RECONNECT_BASE_MS = 1_000;
const SSE_RECONNECT_MAX_MS = 30_000;
const SSE_RECONNECT_MAX_ATTEMPT = 5;

interface CronStatusStreamHandlers {
	/** Aborts this single connection attempt. */
	signal: AbortSignal;
	/**
	 * Invoked exactly once when the attempt ends. `healthy` is true when at
	 * least one SSE frame arrived before the connection closed.
	 */
	onClosed: ( healthy: boolean ) => void;
}

/**
 * Open a single cron-status SSE connection, dispatching job events onto the
 * global bus and reporting closure back to the caller for reconnection.
 */
function openCronStatusStream(
	cronStatusBaseUrl: string,
	nonce: string,
	handlers: CronStatusStreamHandlers,
): void {
	const { signal, onClosed } = handlers;
	let sawFrame = false;
	const url = new URL(
		`${ cronStatusBaseUrl.replace( /\/+$/, '' ) }/cron-status`,
		window.location.origin,
	);
	url.searchParams.set( 'stream', 'true' );
	url.searchParams.set( 'limit', '10' );

	if ( typeof console !== 'undefined' && console.info ) {
		console.info(
			'[NV oOS Pro SPA] SSE cron status connection established',
			{ url: url.toString().replace( /_wpnonce=[^&]+/, '_wpnonce=...' ) },
		);
	}

	fetch( url.toString(), {
		method: 'GET',
		credentials: 'same-origin',
		headers: {
			Accept: 'text/event-stream',
			'X-WP-Nonce': nonce,
		},
		signal,
	} )
	.then( async ( response ) => {
			if ( ! response.ok || ! response.body ) {
				throw new Error( `SSE connection failed: ${ response.status }` );
			}

			const reader = response.body.getReader();
			const decoder = new TextDecoder();
			let buffer = '';

			while ( true ) {
				const { done, value } = await reader.read();
				if ( done ) break;

				buffer += decoder.decode( value, { stream: true } );
				const lines = buffer.split( '\n' );
				buffer = lines.pop() ?? '';

				let eventType = 'message';
				let data = '';

				for ( const line of lines ) {
					if ( line.startsWith( 'event:' ) ) {
						eventType = line.slice( 6 ).trim();
					} else if ( line.startsWith( 'data:' ) ) {
						data += line.slice( 5 );
					} else if ( line === '' && data ) {
						sawFrame = true;
						handleSseFrame( eventType, data );
						eventType = 'message';
						data = '';
					}
				}
			}
		} )
		.catch( ( err ) => {
			if ( ( err as Error )?.name !== 'AbortError' ) {
				if ( typeof console !== 'undefined' && console.warn ) {
					console.warn(
						'[NV oOS Pro SPA] Cron-status SSE stream closed:',
						( err as Error )?.message ?? err,
					);
				}
			}
		} )
		.finally( () => {
			onClosed( sawFrame );
		} );

	function handleSseFrame( eventType: string, raw: string ): void {
		const bus = ( window as unknown as { wpMcpAiJobBus?: EventTarget } )
			.wpMcpAiJobBus;
		if ( ! bus ) return;

		try {
			const payload: CronStatusSsePayload = JSON.parse( raw );

			// The server sends a "cron_status" frame containing an array of jobs.
			if ( eventType === 'cron_status' || eventType === 'message' ) {
				const jobs: CronStatusSseEvent[] = payload.jobs ?? [];
				for ( const job of jobs ) {
					dispatchJobEvent( bus, job );
				}
				return;
			}

			// Single-job frames: cron_job_status, job_status_update, etc.
			if ( eventType === 'cron_job_status' || eventType === 'cron_job_status_update' || eventType === 'job_status_update' ) {
				dispatchJobEvent( bus, payload as unknown as CronStatusSseEvent );
			}
		} catch {
			// Non-JSON frames (e.g. keepalive comments) are safe to ignore.
		}
	}

	function dispatchJobEvent(
		bus: EventTarget,
		job: CronStatusSseEvent,
	): void {
		const jobId = String( job.job_id ?? job.jobId ?? '' );
		if ( ! jobId ) return;

		const detail: Record< string, unknown > = { ...job, job_id: jobId };
		const status = ( job.status ?? '' ).toLowerCase();

		let eventName: string;
		switch ( status ) {
			case 'completed':
				eventName = 'job:completed';
				break;
			case 'failed':
			case 'error':
				eventName = 'job:failed';
				break;
			case 'cancelled':
				eventName = 'job:cancelled';
				break;
			case 'started':
			case 'pending':
			case 'running':
			case 'queued':
			case 'polling':
			case 'delegated':
				eventName = 'job:started';
				break;
			default:
				eventName = 'job:progress';
		}

		bus.dispatchEvent(
				new CustomEvent( eventName, { detail } ),
			);
	}
}

// ---------------------------------------------------------------------------
// REST polling fallback (used when SSE is unavailable).
// ---------------------------------------------------------------------------

async function fetchCronStatusRest(
	cronStatusBaseUrl: string,
	nonce: string,
): Promise< CronStatusSsePayload | null > {
	try {
		const url = `${ cronStatusBaseUrl.replace( /\/+$/, '' ) }/cron-status?limit=10`;
		const resp = await fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': nonce,
			},
		} );
		if ( ! resp.ok ) return null;
		return ( await resp.json() ) as CronStatusSsePayload;
	} catch {
		return null;
	}
}

// ---------------------------------------------------------------------------
// Main hook
// ---------------------------------------------------------------------------

export function useJobBus(
	cronStatusBaseUrl: string,
	nonce: string,
): UseJobBusResult {
	const [ jobs, setJobs ] = useState< Record< string, JobRecord > >( {} );
	const sseRef = useRef< AbortController | null >( null );
	const pollRef = useRef< ReturnType< typeof setInterval > | null >( null );
	// Tracks whether the SSE stream is currently delivering frames, so the REST
	// fallback only fires when SSE is genuinely unavailable.
	const sseHealthyRef = useRef( false );

	// ── 1. Ensure the global job bus exists ──────────────────────────────
	useEffect( () => {
		ensureJobBus();
		if ( typeof console !== 'undefined' && console.info ) {
			console.info(
				'[NV oOS Pro SPA] Global job event listeners initialized',
			);
		}
	}, [] );

	// ── 2. Listen to global job bus events ────────────────────────────────
	useEffect( () => {
		const bus = ( window as unknown as { wpMcpAiJobBus?: EventTarget } )
			.wpMcpAiJobBus;
		if ( ! bus ) return;

		const handler = ( e: Event ) => {
			const d = ( e as CustomEvent ).detail as Record< string, unknown > | undefined;
			if ( ! d ) return;
			const jobId = String( d.job_id ?? d.jobId ?? '' );
			if ( ! jobId ) return;
			const type = e.type;

			setJobs( ( prev ) => {
				const ex = prev[ jobId ];
				if ( type === 'job:cancelled' ) {
					const n = { ...prev };
					delete n[ jobId ];
					return n;
				}
				if ( type === 'job:completed' ) {
					return {
						...prev,
						[ jobId ]: {
							...( ex || {
								jobId,
								toolName: String( d.tool_name ?? '' ),
								status: 'completed',
								steps: [],
								startedAt: Date.now(),
							} ),
							status: 'completed',
							result: d.result ?? d.data ?? d,
							progress: 100,
							message: String( d.message ?? '' ),
						},
					};
				}
				if ( type === 'job:failed' ) {
					return {
						...prev,
						[ jobId ]: {
							...( ex || {
								jobId,
								toolName: String( d.tool_name ?? '' ),
								status: 'failed',
								steps: [],
								startedAt: Date.now(),
							} ),
							status: 'failed',
							error: String( d.error ?? '' ),
						},
					};
				}
				if ( type === 'job:progress' ) {
					const u: Partial< JobRecord > = {};
					if ( typeof d.progress === 'number' ) {
						u.progress = Math.max( 0, Math.min( 100, d.progress ) );
					}
					if ( d.eta ) u.eta = String( d.eta );
					if ( d.message || d.progress_message ) {
						u.message = String( d.message || d.progress_message );
					}
					return {
						...prev,
						[ jobId ]: {
							...( ex || {
								jobId,
								toolName: String( d.tool_name ?? '' ),
								status: 'running',
								steps: [],
								startedAt: Date.now(),
							} ),
							...u,
						},
					};
				}

				const s = ( d.status as string )?.toLowerCase();
				const ms: JobStatus =
					s === 'completed'
						? 'completed'
						: s === 'failed' || s === 'error'
						? 'failed'
						: s === 'cancelled'
						? 'cancelled'
						: s === 'queued'
						? 'queued'
						: s === 'polling'
						? 'polling'
						: 'running';
				return {
					...prev,
					[ jobId ]: {
						...( ex || {
							jobId,
							toolName: String( d.tool_name ?? '' ),
							status: ms,
							steps: [],
							startedAt: Date.now(),
						} ),
						status: ms,
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
		events.forEach( ( ev ) => bus.addEventListener( ev, handler ) );
		return () => {
			events.forEach( ( ev ) =>
				bus.removeEventListener( ev, handler ),
			);
		};
	}, [] );

	// ── 3. SSE cron-status stream (v2.1.0) ────────────────────────────────
	// The server caps each connection (SSE_JOB_MAX_POLLS × interval, 6 min) so
	// a PHP-FPM worker is recycled rather than pinned for the whole session.
	// Without reconnecting, the console silently lost job updates after the
	// cap and fell back to polling until it gave up entirely.
	useEffect( () => {
		if ( ! cronStatusBaseUrl ) return;

		const lifecycle = new AbortController();
		sseRef.current = lifecycle;
		let attempt = 0;
		let timer: ReturnType< typeof setTimeout > | null = null;

		const connect = () => {
			if ( lifecycle.signal.aborted ) return;
			openCronStatusStream( cronStatusBaseUrl, nonce, {
				signal: lifecycle.signal,
				onClosed: ( healthy ) => {
					if ( lifecycle.signal.aborted ) return;
					sseHealthyRef.current = healthy;

					let delay: number;
					if ( healthy ) {
						// Expected cap reached — resume almost immediately.
						attempt = 0;
						delay = SSE_RECONNECT_BASE_MS;
					} else {
						delay = Math.min(
							SSE_RECONNECT_MAX_MS,
							SSE_RECONNECT_BASE_MS * 2 ** attempt,
						);
						attempt = Math.min(
							attempt + 1,
							SSE_RECONNECT_MAX_ATTEMPT,
						);
					}

					timer = setTimeout( connect, delay );
				},
			} );
		};

		connect();

		return () => {
			lifecycle.abort();
			if ( timer !== null ) {
				clearTimeout( timer );
			}
			sseHealthyRef.current = false;
			sseRef.current = null;
		};
	}, [ cronStatusBaseUrl, nonce ] );

	// ── 4. REST polling fallback after initial SSE attempt ─────────────────
	useEffect( () => {
		if ( ! cronStatusBaseUrl ) return;

		// After 3 seconds, if we have zero jobs the SSE may have failed
		// silently.  Start a gentle REST poll as backup.  The poll stops
		// after the first successful fetch with data.
		let attempts = 0;
		const maxAttempts = 20; // 20 × 15 s = 5 minutes
		const timer = setInterval( async () => {
			attempts++;
			if ( attempts > maxAttempts ) {
				clearInterval( timer );
				pollRef.current = null;
				return;
			}
			// The SSE stream now reconnects itself. Polling on top of a healthy
			// stream costs a full WordPress bootstrap (~2.7 s, ~310 MB RSS on
			// this fleet) per tick, so stand down as soon as SSE is delivering.
			if ( sseHealthyRef.current ) {
				clearInterval( timer );
				pollRef.current = null;
				return;
			}
			const payload = await fetchCronStatusRest(
				cronStatusBaseUrl,
				nonce,
			);
			if ( ! payload ) return;

			const bus = ( window as unknown as { wpMcpAiJobBus?: EventTarget } )
				.wpMcpAiJobBus;
			if ( ! bus ) return;

			const jobList: CronStatusSseEvent[] = payload.jobs ?? [];
			for ( const job of jobList ) {
				const jobId = String( job.job_id ?? job.jobId ?? '' );
				if ( ! jobId ) continue;
				const status = ( job.status ?? '' ).toLowerCase();
				let evType: string;
				switch ( status ) {
					case 'completed':
						evType = 'job:completed';
						break;
					case 'failed':
					case 'error':
						evType = 'job:failed';
						break;
					default:
						evType = 'job:progress';
				}
				bus.dispatchEvent(
					new CustomEvent( evType, {
						detail: { ...job, job_id: jobId },
					} ),
				);
			}

			// If we got data, slow polling dramatically.
			if ( jobList.length > 0 ) {
				attempts = Math.max( attempts, maxAttempts - 2 );
			}
		}, 15_000 );
		pollRef.current = timer;

		return () => {
			clearInterval( timer );
			pollRef.current = null;
		};
	}, [ cronStatusBaseUrl, nonce ] );

	// ── Derived counts ────────────────────────────────────────────────────
	const runningCount = Object.values( jobs ).filter(
		( j ) => ! isTerminal( j.status ),
	).length;
	const failedCount = Object.values( jobs ).filter(
		( j ) => j.status === 'failed',
	).length;

	// ── REST actions ──────────────────────────────────────────────────────
	const postJobAction = useCallback(
		async ( jobId: string, action: string ) => {
			await fetch(
				`${ cronStatusBaseUrl.replace( /\/+$/, '' ) }/cron-status/${ encodeURIComponent( jobId ) }/${ action }`,
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
				},
			);
		},
		[ cronStatusBaseUrl, nonce ],
	);

	const cancelJob = useCallback(
		async ( id: string ) => {
			await postJobAction( id, 'cancel' );
		},
		[ postJobAction ],
	);
	const retryJob = useCallback(
		async ( id: string ) => {
			await postJobAction( id, 'retry' );
		},
		[ postJobAction ],
	);
	const dismissJob = useCallback( ( id: string ) => {
		setJobs( ( p ) => {
			const n = { ...p };
			delete n[ id ];
			return n;
		} );
	}, [] );
	const dismissAllTerminal = useCallback( () => {
		setJobs( ( p ) => {
			const n: Record< string, JobRecord > = {};
			for ( const [ k, v ] of Object.entries( p ) ) {
				if ( ! isTerminal( v.status ) ) n[ k ] = v;
			}
			return n;
		} );
	}, [] );

	return {
		jobs,
		runningCount,
		failedCount,
		cancelJob,
		retryJob,
		dismissJob,
		dismissAllTerminal,
	};
}
