/**
 * useThreads — Hook for managing agent conversation threads.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { ThreadsClient, type ThreadSummary, type ThreadMessage } from '../api/threads';
import { useUIStore } from '../stores/uiStore';

export interface UseThreadsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseThreadsReturn {
	threads: ThreadSummary[];
	total: number;
	activeThreadId: number | null;
	loading: boolean;
	error: string | null;

	setActiveThread: ( id: number | null ) => void;
	fetchThreads: () => Promise< void >;
	createThread: (
		assistantId?: number,
		model?: { provider?: string; name?: string },
		profile?: string,
		scope?: Record< string, unknown >
	) => Promise< ThreadSummary | null >;
	archiveThread: ( id: number ) => Promise< void >;
	restoreThread: ( id: number ) => Promise< void >;
	summarizeThread: ( id: number ) => Promise< { new_thread_id?: number } >;
	getMessages: ( threadId: number ) => Promise< ThreadMessage[] >;
}

export function useThreads( options: UseThreadsOptions ): UseThreadsReturn {
	const client = useMemo(
		() => new ThreadsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ threads, setThreads ] = useState< ThreadSummary[] >( [] );
	const [ total, setTotal ] = useState< number >( 0 );
	const [ activeThreadId, setActiveThreadId ] = useState< number | null >( null );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const addToast = useUIStore( ( s ) => s.addToast );

	const fetchThreads = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const result = await client.list();
			setThreads( result.threads );
			setTotal( result.total );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client ] );

	useEffect( () => {
		void fetchThreads();
	}, [ fetchThreads ] );

	const setActiveThread = useCallback( ( id: number | null ) => {
		setActiveThreadId( id );
	}, [] );

	const createThread = useCallback(
		async (
			assistantId = 0,
			model: { provider?: string; name?: string } = {},
			profile = 'write',
			scope: Record< string, unknown > = {}
		): Promise< ThreadSummary | null > => {
			try {
				const thread = await client.create( assistantId, model, profile, scope );
				setThreads( ( prev ) => [ thread, ...prev ] );
				setTotal( ( prev ) => prev + 1 );
				setActiveThreadId( thread.id );
				addToast( 'Thread created', 'success' );
				return thread;
			} catch ( err ) {
				const msg = err instanceof Error ? err.message : String( err );
				setError( msg );
				addToast( msg, 'error' );
				return null;
			}
		},
		[ client, addToast ]
	);

	const archiveThread = useCallback(
		async ( id: number ) => {
			try {
				await client.archive( id );
				setThreads( ( prev ) => prev.filter( ( t ) => t.id !== id ) );
				setTotal( ( prev ) => prev - 1 );
				if ( activeThreadId === id ) {
					setActiveThreadId( null );
				}
				addToast( 'Thread archived', 'success' );
			} catch ( err ) {
				addToast( err instanceof Error ? err.message : String( err ), 'error' );
			}
		},
		[ client, activeThreadId, addToast ]
	);

	const restoreThread = useCallback(
		async ( id: number ) => {
			try {
				await client.restore( id );
				void fetchThreads();
				addToast( 'Thread restored', 'success' );
			} catch ( err ) {
				addToast( err instanceof Error ? err.message : String( err ), 'error' );
			}
		},
		[ client, fetchThreads, addToast ]
	);

	const summarizeThread = useCallback(
		async ( id: number ) => {
			try {
				const result = await client.summarize( id );
				void fetchThreads();
				if ( result.new_thread_id ) {
					setActiveThreadId( result.new_thread_id );
				}
				addToast( 'Thread summarized', 'success' );
				return result;
			} catch ( err ) {
				addToast( err instanceof Error ? err.message : String( err ), 'error' );
				return {};
			}
		},
		[ client, fetchThreads, addToast ]
	);

	const getMessages = useCallback(
		async ( threadId: number ): Promise< ThreadMessage[] > => {
			try {
				const result = await client.getMessages( threadId );
				return result.messages;
			} catch {
				return [];
			}
		},
		[ client ]
	);

	return {
		threads,
		total,
		activeThreadId,
		loading,
		error,
		setActiveThread,
		fetchThreads,
		createThread,
		archiveThread,
		restoreThread,
		summarizeThread,
		getMessages,
	};
}
