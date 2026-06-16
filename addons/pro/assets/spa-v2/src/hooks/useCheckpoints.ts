/**
 * Pro SPA v2 — useCheckpoints hook.
 *
 * Provides checkpoint operations (list, restore) backed by direct fetch
 * calls to the WordPress REST API. Designed to work with the CheckpointBar
 * and DiffReviewPanel components.
 *
 * @since 2.0.0
 */

import { useCallback, useEffect, useState } from 'react';
import type { Checkpoint } from '../components/shared/CheckpointBar';

export interface UseCheckpointsOptions {
	/** Full URL to the checkpoints REST endpoint. */
	endpoint: string;
	/** WordPress REST nonce for the X-WP-Nonce header. */
	nonce: string;
	/** Optional thread ID to scope checkpoint operations. */
	threadId?: number;
}

export interface UseCheckpointsReturn {
	checkpoints: Checkpoint[];
	loading: boolean;
	error: string | null;
	fetchCheckpoints: () => Promise< void >;
	restoreCheckpoint: (
		checkpointId: string | number,
		threadId: number
	) => Promise< void >;
}

interface CheckpointsApiResponse {
	success: boolean;
	data?: {
		checkpoints?: Checkpoint[];
	};
}

/**
 * Hook for interacting with the checkpoints REST API.
 *
 * @param options          - Hook configuration.
 * @param options.endpoint - REST endpoint URL for checkpoints.
 * @param options.nonce    - WP REST nonce.
 * @param options.threadId - Thread ID to scope operations.
 *
 * @returns Checkpoint state and operations.
 */
export function useCheckpoints(
	options: UseCheckpointsOptions
): UseCheckpointsReturn {
	const { endpoint, nonce, threadId } = options;

	const [ checkpoints, setCheckpoints ] = useState< Checkpoint[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const fetchCheckpoints = useCallback( async () => {
		setLoading( true );
		setError( null );

		try {
			let url = endpoint;
			if ( threadId ) {
				const sep = url.includes( '?' ) ? '&' : '?';
				url = `${ url }${ sep }thread_id=${ threadId }`;
			}

			const res = await fetch( url, {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': nonce,
				},
			} );

			if ( ! res.ok ) {
				throw new Error( `HTTP ${ res.status }` );
			}

			const data: CheckpointsApiResponse = await res.json();

			if ( data.success && data.data?.checkpoints ) {
				setCheckpoints( data.data.checkpoints );
			} else {
				setCheckpoints( [] );
			}
		} catch ( err ) {
			const message =
				err instanceof Error ? err.message : String( err );
			setError( message );
		} finally {
			setLoading( false );
		}
	}, [ endpoint, nonce, threadId ] );

	const restoreCheckpoint = useCallback(
		async (
			checkpointId: string | number,
			threadIdToRestore: number
		): Promise< void > => {
			try {
				const body = new URLSearchParams();
				body.append( 'checkpoint_id', String( checkpointId ) );
				body.append( 'thread_id', String( threadIdToRestore ) );

				const res = await fetch( endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
						'X-WP-Nonce': nonce,
					},
					body: body.toString(),
				} );

				if ( ! res.ok ) {
					throw new Error( `HTTP ${ res.status }` );
				}

				const data: CheckpointsApiResponse = await res.json();

				if ( ! data.success ) {
					throw new Error( 'Restore failed' );
				}

				// Refresh the list after a successful restore.
				await fetchCheckpoints();
			} catch ( err ) {
				const message =
					err instanceof Error ? err.message : String( err );
				setError( message );
				throw err;
			}
		},
		[ endpoint, nonce, fetchCheckpoints ]
	);

	// Fetch on mount and when threadId changes.
	useEffect( () => {
		void fetchCheckpoints();
	}, [ fetchCheckpoints ] );

	return {
		checkpoints,
		loading,
		error,
		fetchCheckpoints,
		restoreCheckpoint,
	};
}
