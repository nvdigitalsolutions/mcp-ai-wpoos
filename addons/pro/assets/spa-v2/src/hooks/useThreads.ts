/**
 * useThreads — Read-only hook for browsing agent conversation threads.
 *
 * Mirrors chat-spa's `useThreadsSidebar`: threads are a browse view;
 * conversations (transcripts) own the chat transport. This hook only
 * fetches lists and messages — it does NOT create, archive, restore,
 * or summarize threads (those operations are available at the API
 * layer but not exposed through the chat flow).
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
	ThreadsClient,
	type ThreadSummary,
} from '../api/threads';

export interface UseThreadsOptions {
	endpoint: string;
	nonce: string;
	/** Disables server calls when true (e.g. missing config). */
	disabled?: boolean;
}

export interface UseThreadsReturn {
	/** Thread list (null = not yet loaded). */
	threads: ThreadSummary[] | null;
	/** True while the thread list is being fetched. */
	isLoading: boolean;
	/** Generic transient error from the latest list/get call. */
	error: string | null;
	/** True when the threads feature is unavailable. */
	unavailable: boolean;
	/** Currently selected thread (by id), or null. */
	activeThreadId: number | null;
	/** Select a thread and load its messages. */
	selectThread: ( threadId: number ) => Promise< void >;
	/** Clear the active thread selection. */
	deselectThread: () => void;
	/** Force-refresh the thread list. */
	refreshList: () => Promise< void >;
}

export function useThreads(
	options: UseThreadsOptions
): UseThreadsReturn {
	const { endpoint, nonce, disabled = false } = options;

	const client = useMemo(
		() => new ThreadsClient( { endpoint, nonce } ),
		[ endpoint, nonce ]
	);

	const [ threads, setThreads ] = useState< ThreadSummary[] | null >( null );
	const [ isLoading, setIsLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ unavailable, setUnavailable ] = useState< boolean >( false );
	const [ activeThreadId, setActiveThreadId ] = useState< number | null >( null );

	const abortRef = useRef< AbortController | null >( null );

	const refreshList = useCallback( async () => {
		if ( disabled ) {
			setThreads( [] );
			return;
		}
		setIsLoading( true );
		setError( null );
		try {
			const data = await client.list();
			setThreads( data.threads );
			setUnavailable( false );
		} catch ( err ) {
			// If the threads route doesn't exist or returns an error,
			// mark the feature unavailable and show an empty state.
			const msg = err instanceof Error ? err.message : String( err );
			if ( msg.includes( '404' ) || msg.includes( 'not found' ) || msg.includes( 'no route' ) ) {
				setUnavailable( true );
				setThreads( [] );
			} else {
				setError( msg );
				setThreads( [] );
			}
		} finally {
			setIsLoading( false );
		}
	}, [ client, disabled ] );

	// Initial list fetch on mount.
	useEffect( () => {
		void refreshList();
	}, [ refreshList ] );

	const selectThread = useCallback(
		async ( threadId: number ) => {
			if ( disabled || ! threadId ) {
				return;
			}
			abortRef.current?.abort();
			const controller = new AbortController();
			abortRef.current = controller;
			setIsLoading( true );
			setError( null );
			try {
				// Load messages — caller retrieves them via getMessages.
				await client.getMessages( threadId, controller.signal );
				if ( controller.signal.aborted ) {
					return;
				}
				setActiveThreadId( threadId );
			} catch ( err ) {
				if ( ! controller.signal.aborted ) {
					setError( err instanceof Error ? err.message : String( err ) );
				}
			} finally {
				if ( ! controller.signal.aborted ) {
					setIsLoading( false );
				}
			}
		},
		[ client, disabled ]
	);

	const deselectThread = useCallback( () => {
		abortRef.current?.abort();
		setActiveThreadId( null );
		setError( null );
	}, [] );

	return {
		threads,
		isLoading,
		error,
		unavailable,
		activeThreadId,
		selectThread,
		deselectThread,
		refreshList,
	};
}
