/**
 * NV oOS Chat SPA — transcript session hook.
 *
 * Owns the cross-cutting state for "which conversation am I in right
 * now?" so `App.tsx` can stay focused on the `useChat` integration.
 *
 * Responsibilities:
 *   - Generate a session key the first time the user lands.
 *   - Persist the active session per-assistant in localStorage so a
 *     reload resumes the same conversation.
 *   - Hydrate `useChat`'s `initialMessages` from the server when the
 *     user picks an existing session from the sidebar.
 *   - Expose helpers to start a new chat or switch sessions.
 *
 * This hook does **not** push messages to the server — the assistant's
 * own response handler in NV oOS already persists turns to CCT after
 * each completed exchange. We only `POST /chat-transcripts` explicitly
 * when the user starts a new session, to flush whatever was in flight.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
	TranscriptsClient,
	activeSessionStorageKey,
	generateSessionKey,
	type TranscriptMessage,
	type TranscriptSession,
} from '../api/transcripts';

export interface UseTranscriptSessionOptions {
	endpoint: string;
	nonce: string;
	assistantId: number | string;
	/** Disables every server call when true (e.g. guest token surface). */
	disabled?: boolean;
}

export interface UseTranscriptSessionResult {
	/** The session key currently bound to `useChat`. */
	sessionKey: string;
	/** True while the initial messages for `sessionKey` are being fetched. */
	isLoading: boolean;
	/** Pre-loaded messages for the current session (empty array for fresh chats). */
	initialMessages: TranscriptMessage[];
	/** The session list shown in the sidebar (or `null` while loading). */
	sessions: TranscriptSession[] | null;
	/** Set when the transcripts feature is unavailable (e.g. JetEngine off). */
	unavailableMessage: string | null;
	/** Generic transient error from the latest list/get/delete call. */
	error: string | null;
	/** Force-refresh the session list. */
	refreshList: () => Promise< void >;
	/** Switch to an existing session, hydrating its messages. */
	selectSession: ( sessionKey: string ) => Promise< void >;
	/** Start a fresh conversation (new key, empty messages). */
	startNewSession: () => void;
	/** Delete a session from the server + sidebar. */
	deleteSession: ( sessionKey: string ) => Promise< void >;
	/** Load more sessions (pagination — GAP-19: v0.9.0). */
	loadMore: () => Promise< void >;
	/** Set search term and refresh (GAP-17: v0.9.0). */
	setSearch: ( term: string ) => void;
	/** Current search term. */
	searchTerm: string;
	/** Whether more pages are available. */
	hasMore: boolean;
	/** Update session title (GAP-18: v0.9.0). */
	updateTitle: ( sessionKey: string, title: string ) => Promise< void >;
}

/**
 * Coerce raw server-side message payloads into the minimal shape that
 * `useChat` accepts as `initialMessages`. We strip unknown roles and
 * non-string content, leaving the rest of the metadata intact.
 */
function normaliseMessages( raw: unknown ): TranscriptMessage[] {
	if ( ! Array.isArray( raw ) ) {
		return [];
	}
	const out: TranscriptMessage[] = [];
	for ( const item of raw ) {
		if ( ! item || typeof item !== 'object' ) {
			continue;
		}
		const m = item as Record< string, unknown >;
		const role = typeof m.role === 'string' ? m.role : '';
		// Skip system messages — they are internal LLM context and must never
		// be exposed to the frontend conversation view.
		if ( role !== 'user' && role !== 'assistant' && role !== 'tool' ) {
			continue;
		}
		const content = typeof m.content === 'string' ? m.content : '';

		// Drop consecutive duplicates (same role and content) — these are a
		// symptom of transcripts saved before the `persistFinishedTurn` fix
		// that appended `assistantMessage` on top of the already-complete
		// messages array.  Duplicates compound on every load–save cycle, so
		// stripping them here also self-heals existing transcripts.
		const prev = out[ out.length - 1 ];
		if ( prev && prev.role === role && prev.content === content ) {
			continue;
		}

		out.push( { ...m, role, content } );
	}
	return out;
}

export function useTranscriptSession(
	options: UseTranscriptSessionOptions
): UseTranscriptSessionResult {
	const { endpoint, nonce, assistantId, disabled = false } = options;

	const client = useMemo(
		() => new TranscriptsClient( { endpoint, nonce, assistantId } ),
		[ endpoint, nonce, assistantId ]
	);

	const storageKey = useMemo( () => activeSessionStorageKey( assistantId ), [ assistantId ] );

	// Lazily compute the initial session key so SSR / first render is stable.
	const [ sessionKey, setSessionKey ] = useState< string >( () => {
		if ( typeof window === 'undefined' ) {
			return generateSessionKey();
		}
		try {
			const stored = window.localStorage.getItem( storageKey );
			if ( stored && /^[a-zA-Z0-9_-]+$/.test( stored ) ) {
				return stored;
			}
		} catch {
			// localStorage may be unavailable (private mode); fall through.
		}
		return generateSessionKey();
	} );

	const [ initialMessages, setInitialMessages ] = useState< TranscriptMessage[] >( [] );
	const [ isLoading, setIsLoading ] = useState< boolean >( false );
	const [ sessions, setSessions ] = useState< TranscriptSession[] | null >( null );
	const [ unavailableMessage, setUnavailableMessage ] = useState< string | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	// Pagination & search (GAP-17, GAP-19: v0.9.0).
	const [ currentPage, setCurrentPage ] = useState( 1 );
	const [ totalSessions, setTotalSessions ] = useState( 0 );
	const [ searchTerm, setSearchTerm ] = useState( '' );
	const perPage = 20;

	// Persist the active session id on every change. We do this in an effect
	// rather than inside the setters so external callers (e.g. tests) can
	// drive `setSessionKey` directly and observe the same persistence path.
	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return;
		}
		try {
			window.localStorage.setItem( storageKey, sessionKey );
		} catch {
			// Ignore quota / private-mode failures.
		}
	}, [ sessionKey, storageKey ] );

	// Track the latest in-flight load so a stale response can be ignored if
	// the user clicks a different session before the previous fetch returns.
	const abortRef = useRef< AbortController | null >( null );

	const refreshList = useCallback( async () => {
		if ( disabled ) {
			setSessions( [] );
			return;
		}
		try {
			setCurrentPage( 1 );
			const data = await client.list( { search: searchTerm || undefined } );
			if ( data.message ) {
				setUnavailableMessage( data.message );
				setSessions( [] );
				return;
			}
			setUnavailableMessage( null );
			setSessions( data.sessions );
			setTotalSessions( data.total );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
			setSessions( [] );
		}
	}, [ client, disabled, searchTerm ] );

	// Initial list fetch on mount (and whenever the assistant changes).
	useEffect( () => {
		void refreshList();
	}, [ refreshList ] );

	const selectSession = useCallback(
		async ( nextKey: string ) => {
			if ( disabled || ! nextKey || nextKey === sessionKey ) {
				return;
			}
			abortRef.current?.abort();
			const controller = new AbortController();
			abortRef.current = controller;
			setIsLoading( true );
			setError( null );
			try {
				const detail = await client.get( nextKey, controller.signal );
				if ( controller.signal.aborted ) {
					return;
				}
				const messages = normaliseMessages( detail?.session?.messages );
				setInitialMessages( messages );
				setSessionKey( nextKey );
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
		[ client, disabled, sessionKey ]
	);

	const startNewSession = useCallback( () => {
		abortRef.current?.abort();
		setError( null );
		setInitialMessages( [] );
		setSessionKey( generateSessionKey() );
	}, [] );

	const deleteSession = useCallback(
		async ( target: string ) => {
			if ( disabled || ! target ) {
				return;
			}
			try {
				await client.delete( target );
				setSessions( ( prev ) =>
					Array.isArray( prev ) ? prev.filter( ( s ) => s.session_key !== target ) : prev
				);
				// If the user nuked the conversation they were sitting in,
				// rotate to a fresh session so the chat surface stays live.
				if ( target === sessionKey ) {
					startNewSession();
				}
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
			}
		},
		[ client, disabled, sessionKey, startNewSession ]
	);

	const loadMore = useCallback( async () => {
		if ( disabled ) return;
		try {
			const nextPage = currentPage + 1;
			const data = await client.list( { page: nextPage, search: searchTerm || undefined } );
			if ( data.message ) return;
			setSessions( ( prev ) =>
				Array.isArray( prev ) ? [ ...prev, ...data.sessions ] : data.sessions
			);
			setCurrentPage( nextPage );
			setTotalSessions( data.total );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		}
	}, [ client, disabled, currentPage, searchTerm ] );

	const setSearch = useCallback(
		( term: string ) => {
			setSearchTerm( term );
		},
		[]
	);

	// Trigger refresh when search term changes (debounced).
	useEffect( () => {
		const timer = setTimeout( () => {
			void refreshList();
		}, 300 );
		return () => clearTimeout( timer );
	}, [ searchTerm, refreshList ] );

	const updateTitle = useCallback(
		async ( sessionKeyToUpdate: string, title: string ) => {
			if ( disabled ) return;
			await client.updateTitle( sessionKeyToUpdate, title );
			setSessions( ( prev ) =>
				Array.isArray( prev )
					? prev.map( ( s ) =>
							s.session_key === sessionKeyToUpdate ? { ...s, title } : s
						)
					: prev
			);
		},
		[ client, disabled ]
	);

	const hasMore = ( sessions?.length ?? 0 ) < totalSessions;

	return {
		sessionKey,
		isLoading,
		initialMessages,
		sessions,
		unavailableMessage,
		error,
		refreshList,
		selectSession,
		startNewSession,
		deleteSession,
		loadMore,
		setSearch,
		searchTerm,
		hasMore,
		updateTitle,
	};
}
