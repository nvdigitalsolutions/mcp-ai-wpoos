/**
 * useTranscripts — Hook for transcript session management.
 *
 * Mirrors chat-spa's useTranscriptSession with pro text domain.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
	TranscriptsClient,
	activeSessionStorageKey,
	generateSessionKey,
	type TranscriptMessage,
	type TranscriptSession,
} from '../api/transcripts';

export interface UseTranscriptsOptions {
	endpoint: string;
	nonce: string;
	assistantId: number | string;
	disabled?: boolean;
}

export interface UseTranscriptsReturn {
	sessionKey: string;
	isLoading: boolean;
	initialMessages: TranscriptMessage[];
	sessions: TranscriptSession[] | null;
	unavailableMessage: string | null;
	error: string | null;
	refreshList: () => Promise< void >;
	selectSession: ( sessionKey: string ) => Promise< void >;
	startNewSession: () => void;
	deleteSession: ( sessionKey: string ) => Promise< void >;
}

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

		const prev = out[ out.length - 1 ];
		if ( prev && prev.role === role && prev.content === content ) {
			continue;
		}

		out.push( { ...m, role, content } );
	}
	return out;
}

export function useTranscripts(
	options: UseTranscriptsOptions
): UseTranscriptsReturn {
	const { endpoint, nonce, assistantId, disabled = false } = options;

	const client = useMemo(
		() => new TranscriptsClient( { endpoint, nonce, assistantId } ),
		[ endpoint, nonce, assistantId ]
	);

	const storageKey = useMemo(
		() => activeSessionStorageKey( assistantId ),
		[ assistantId ]
	);

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
			// localStorage unavailable.
		}
		return generateSessionKey();
	} );

	const [ initialMessages, setInitialMessages ] = useState< TranscriptMessage[] >( [] );
	const [ isLoading, setIsLoading ] = useState< boolean >( false );
	const [ sessions, setSessions ] = useState< TranscriptSession[] | null >( null );
	const [ unavailableMessage, setUnavailableMessage ] = useState< string | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return;
		}
		try {
			window.localStorage.setItem( storageKey, sessionKey );
		} catch {
			// Ignore.
		}
	}, [ sessionKey, storageKey ] );

	const abortRef = useRef< AbortController | null >( null );

	const refreshList = useCallback( async () => {
		if ( disabled ) {
			setSessions( [] );
			return;
		}
		try {
			const data = await client.list();
			if ( data.message ) {
				setUnavailableMessage( data.message );
				setSessions( [] );
				return;
			}
			setUnavailableMessage( null );
			setSessions( data.sessions );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
			setSessions( [] );
		}
	}, [ client, disabled ] );

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
				if ( target === sessionKey ) {
					startNewSession();
				}
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
			}
		},
		[ client, disabled, sessionKey, startNewSession ]
	);

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
	};
}
