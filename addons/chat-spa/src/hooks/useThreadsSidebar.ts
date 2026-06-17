/**
 * NV oOS Chat SPA — threads sidebar hook.
 *
 * Owns the state for the "Threads" tab in the sidebar:
 *   - Fetches the list of active threads on mount.
 *   - Loads thread messages when a thread is selected.
 *   - Returns the threads list, loading/error states, and a `selectThread`
 *     callback that hydrates the chat with the thread's messages.
 *
 * This hook is intentionally read-only: selecting a thread loads its
 * messages into the chat surface but does NOT switch the chat transport
 * to POST /threads/{id}/messages. The chat stays on /chat-client.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
	ThreadsClient,
	type ThreadSummary,
} from '../api/threads';

export interface UseThreadsSidebarOptions {
	endpoint: string;
	nonce: string;
	/** Disables server calls when true (e.g. guest token surface). */
	disabled?: boolean;
}

export interface UseThreadsSidebarResult {
	/** The thread list (or `null` while loading). */
	threads: ThreadSummary[] | null;
	/** True while the thread list is being fetched. */
	isLoading: boolean;
	/** Generic transient error from the latest list/get call. */
	error: string | null;
	/** True when the threads feature is unavailable. */
	unavailable: boolean;
	/** Currently selected thread (by id), or null. */
	activeThreadId: number | null;
	/** Load a thread's messages and return them for the chat. */
	selectThread: ( threadId: number ) => Promise< void >;
	/** Clear the active thread selection (go back to transcripts). */
	deselectThread: () => void;
	/** Force-refresh the thread list. */
	refreshList: () => Promise< void >;
	/** Messages loaded from the selected thread (for use as initialMessages). */
	threadInitialMessages: Array< { role: string; content: string; id: string } > | null;
}

export function useThreadsSidebar(
	options: UseThreadsSidebarOptions
): UseThreadsSidebarResult {
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
	const [ threadInitialMessages, setThreadInitialMessages ] = useState<
		Array< { role: string; content: string; id: string } > | null
	>( null );

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
				const data = await client.getMessages( threadId, controller.signal );
				if ( controller.signal.aborted ) {
					return;
				}
				// Normalise thread messages into the shape useChat expects.
				const messages = ( data.messages || [] )
					.filter( ( m ) => m.role === 'user' || m.role === 'assistant' || m.role === 'system' )
					.map( ( m, idx ) => ( {
						role: m.role,
						content: typeof m.content === 'string' ? m.content : '',
						id: `thread-${ threadId }-${ m.id ?? idx }`,
					} ) );
				setThreadInitialMessages( messages );
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
		setThreadInitialMessages( null );
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
		threadInitialMessages,
	};
}
