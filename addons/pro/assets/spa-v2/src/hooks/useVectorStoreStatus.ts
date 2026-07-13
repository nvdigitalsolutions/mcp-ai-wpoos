/**
 * Pro SPA v2 — Vector store status hook.
 *
 * Fetches the /vector-store-preload endpoint for the selected assistant
 * on mount and on assistant change, exposing loading / ready / error state
 * so the UI can reflect whether the assistant's knowledge base is available.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.1.0
 */

import { useEffect, useRef, useState, useCallback } from 'react';
import { __ } from '@wordpress/i18n';

export interface VectorStoreStatus {
	/** The vector store is being fetched / is syncing. */
	loading: boolean;
	/** Vector store is ready for queries. */
	ready: boolean;
	/** Error message if the fetch or store status indicates a problem. */
	error: string | null;
	/** Human-readable store name, if available. */
	name: string | null;
	/** Number of files in the store, if reported. */
	fileCount: number | null;
	/** Raw status string from the API (e.g. "completed", "in_progress"). */
	status: string | null;
	/** Whether the assistant has a vector store configured at all. */
	hasStore: boolean;
	/** Informational message from the API (e.g. "No vector store configured"). */
	message: string | null;
}

const CACHE: Record< number, { ts: number; data: VectorStoreStatus } > = {};
const CACHE_TTL_MS = 60_000; // 1 minute

/**
	 * Fetch vector store status for an assistant.
	 *
	 * Returns a stable `VectorStoreStatus` object that updates
	 * when the assistant ID changes (on page load or assistant switch).
	 */
export function useVectorStoreStatus(
	apiRoot: string,
	nonce: string,
	assistantId: number,
): VectorStoreStatus {
	const [ state, setState ] = useState< VectorStoreStatus >( () => {
		const cached = CACHE[ assistantId ];
		if ( cached && Date.now() - cached.ts < CACHE_TTL_MS ) {
			return cached.data;
		}
		return { loading: false, ready: false, error: null, name: null, fileCount: null, status: null, hasStore: false, message: null };
	} );

	const fetchRef = useRef< AbortController | null >( null );

	const fetchStatus = useCallback( async ( id: number, signal: AbortSignal ) => {
		if ( id <= 0 ) {
			setState( { loading: false, ready: false, error: null, name: null, fileCount: null, status: null, hasStore: false, message: null } );
			return;
		}

		setState( ( prev ) => ( { ...prev, loading: true, error: null } ) );

		if ( typeof console !== 'undefined' && console.info ) {
			console.info(
				'[NV oOS Pro SPA] Pre-loading vector store for assistant',
				{ assistant_id: id },
			);
		}

		try {
			const url = new URL(
				`${ apiRoot.replace( /\/+$/, '' ) }/vector-store-preload`,
				window.location.origin,
			);
			url.searchParams.set( 'assistant_id', String( id ) );

			const resp = await fetch( url.toString(), {
				method: 'GET',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-WP-Nonce': nonce },
				signal,
			} );

			if ( ! resp.ok ) {
				throw new Error( `HTTP ${ resp.status }` );
			}

			const json: Record< string, unknown > = await resp.json();
			const data: Record< string, unknown > =
				( json.data as Record< string, unknown > ) ?? json;

			const hasStore = Boolean( data.has_vector_store );

			if ( ! hasStore ) {
				const msg = ( data.message as string ) ?? __( 'No vector store configured.', 'nvoos-pro-spa' );
				setState( { loading: false, ready: false, error: null, name: null, fileCount: null, status: null, hasStore: false, message: msg } );
				return;
			}

			const status = String( data.status ?? '' );
				const ready = status === 'completed';

				if ( typeof console !== 'undefined' && console.info ) {
					console.info(
						ready
							? '[NV oOS Pro SPA] Vector store pre-loaded successfully'
							: '[NV oOS Pro SPA] Vector store status',
						{ id: data.id ?? data.vector_store_id, name: data.name, status, file_counts: data.file_counts },
					);
				}

			// Handle API-level error (e.g. OpenAI call failed).
			if ( status === 'error' ) {
				const errMsg = ( data.error as string ) ?? __( 'Vector store unavailable.', 'nvoos-pro-spa' );
				setState( { loading: false, ready: false, error: errMsg, name: null, fileCount: null, status, hasStore: true, message: null } );
				return;
			}

			const fileCounts =
				( data.file_counts as Record< string, number > | undefined ) ??
				( data.fileCounts as Record< string, number > | undefined );
			const fileCount = fileCounts?.total ?? fileCounts?.completed ?? null;

			const newState: VectorStoreStatus = {
				loading: false,
				ready,
				error: null,
				name: ( data.name as string ) ?? null,
				fileCount: typeof fileCount === 'number' ? fileCount : null,
				status: status || null,
				hasStore: true,
				message: null,
			};

			CACHE[ id ] = { ts: Date.now(), data: newState };
			setState( newState );
		} catch ( err ) {
			if ( ( err as Error )?.name === 'AbortError' ) return;
			setState( {
				loading: false,
				ready: false,
				error: ( err as Error )?.message ?? 'Unknown error',
				name: null,
				fileCount: null,
				status: null,
				hasStore: false,
				message: null,
			} );
		}
	}, [ apiRoot, nonce ] );

	useEffect( () => {
		fetchRef.current?.abort();
		const ac = new AbortController();
		fetchRef.current = ac;

		if ( assistantId > 0 ) {
			fetchStatus( assistantId, ac.signal );
		} else {
			setState( { loading: false, ready: false, error: null, name: null, fileCount: null, status: null, hasStore: false, message: null } );
		}

		return () => {
			ac.abort();
		};
	}, [ assistantId, fetchStatus ] );

	return state;
}
