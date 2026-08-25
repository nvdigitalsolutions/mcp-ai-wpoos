/**
 * useTranscripts — Hook for transcript session management.
 *
 * Mirrors chat-spa's useTranscriptSession with pro text domain.
 *
 * The active session key is persisted per-assistant so a reload resumes the
 * same conversation. Resuming also hydrates that conversation's messages —
 * without it the sidebar would highlight a saved conversation while the
 * message pane showed an empty composer.
 *
 * Conversations are owned by an assistant, so the hook reports the owning
 * assistant of whatever conversation it loads (`onSessionAssistantChange`)
 * and starts a fresh conversation when the caller switches assistants.
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
	/**
	 * Called with the id of the assistant that owns the conversation whenever
	 * one is loaded from the server (restored on mount, or picked from the
	 * sidebar). Lets the caller keep the assistant selector — and therefore
	 * the model and the conversation list — pointed at the conversation on
	 * screen. Only fired when the owner differs from `assistantId`.
	 */
	onSessionAssistantChange?: ( assistantId: number ) => void;
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

/** Coerce a REST assistant id (number or numeric string) to a positive int. */
function toAssistantId( raw: unknown ): number {
	if ( typeof raw === 'number' ) {
		return Number.isFinite( raw ) && raw > 0 ? raw : 0;
	}
	if ( typeof raw === 'string' ) {
		const parsed = parseInt( raw, 10 );
		return ! Number.isNaN( parsed ) && parsed > 0 ? parsed : 0;
	}
	return 0;
}

export function useTranscripts(
	options: UseTranscriptsOptions
): UseTranscriptsReturn {
	const {
		endpoint,
		nonce,
		assistantId,
		disabled = false,
		onSessionAssistantChange,
	} = options;

	const client = useMemo(
		() => new TranscriptsClient( { endpoint, nonce, assistantId } ),
		[ endpoint, nonce, assistantId ]
	);

	const storageKey = useMemo(
		() => activeSessionStorageKey( assistantId ),
		[ assistantId ]
	);

	// True when the initial key was restored from localStorage — i.e. the user
	// is resuming a conversation rather than starting a brand-new one.
	const restoredSessionRef = useRef( false );

	const [ sessionKey, setSessionKey ] = useState< string >( () => {
		if ( typeof window === 'undefined' ) {
			return generateSessionKey();
		}
		try {
			const stored = window.localStorage.getItem( storageKey );
			if ( stored && /^[a-zA-Z0-9_-]+$/.test( stored ) ) {
				restoredSessionRef.current = true;
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

	// Track the latest in-flight load so a stale response can be ignored when
	// the user switches conversations before the previous fetch resolves.
	const abortRef = useRef< AbortController | null >( null );

	// "Latest value" refs so async callbacks can read current props/state
	// without being re-created (and re-triggering their effects).
	const assistantIdRef = useRef( assistantId );
	const assistantChangeRef = useRef( onSessionAssistantChange );
	const sessionsRef = useRef< TranscriptSession[] | null >( null );
	useEffect( () => {
		assistantIdRef.current = assistantId;
		assistantChangeRef.current = onSessionAssistantChange;
		sessionsRef.current = sessions;
	} );

	// Assistant switch we requested ourselves (because the user opened one of
	// that assistant's conversations) — must not be treated as a manual switch.
	const pendingAssistantRef = useRef( 0 );

	/**
	 * Report the assistant that owns the conversation just loaded so the
	 * caller can move the assistant selector to match it.
	 */
	const notifySessionAssistant = useCallback( ( raw: unknown ) => {
		const nextId = toAssistantId( raw );
		if ( nextId <= 0 || String( nextId ) === String( assistantIdRef.current ) ) {
			return;
		}
		const handler = assistantChangeRef.current;
		if ( ! handler ) {
			return;
		}
		pendingAssistantRef.current = nextId;
		handler( nextId );
	}, [] );

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

				// The sidebar summary carries assistant_id too — use it when the
				// detail payload omits it (legacy rows).
				const summary = Array.isArray( sessionsRef.current )
					? sessionsRef.current.find( ( s ) => s.session_key === nextKey )
					: undefined;
				notifySessionAssistant(
					detail?.session?.assistant_id ?? summary?.assistant_id
				);
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
		[ client, disabled, notifySessionAssistant, sessionKey ]
	);

	const startNewSession = useCallback( () => {
		abortRef.current?.abort();
		setError( null );
		setInitialMessages( [] );
		setSessionKey( generateSessionKey() );
	}, [] );

	// ── Resume the stored conversation on first mount ─────────────────────
	// Restoring only the key would leave the sidebar highlighting a saved
	// conversation next to an empty composer, and the next turn would be
	// appended to that conversation without its history. When the stored
	// session can no longer be read (deleted, empty, or owned by someone
	// else) rotate to a fresh key so "New Conversation" is selected instead.
	const hydratedRef = useRef( false );
	useEffect( () => {
		if ( hydratedRef.current ) {
			return;
		}
		hydratedRef.current = true;
		if ( disabled || ! restoredSessionRef.current ) {
			return;
		}

		const controller = new AbortController();
		abortRef.current = controller;
		setIsLoading( true );

		void ( async () => {
			try {
				const detail = await client.get( sessionKey, controller.signal );
				if ( controller.signal.aborted ) {
					return;
				}
				const messages = normaliseMessages( detail?.session?.messages );
				if ( messages.length === 0 ) {
					setSessionKey( generateSessionKey() );
					return;
				}
				setInitialMessages( messages );
				notifySessionAssistant( detail?.session?.assistant_id );
			} catch {
				if ( ! controller.signal.aborted ) {
					setSessionKey( generateSessionKey() );
				}
			} finally {
				if ( ! controller.signal.aborted ) {
					setIsLoading( false );
				}
			}
		} )();
	}, [ client, disabled, notifySessionAssistant, sessionKey ] );

	// ── Assistant switch → start a fresh conversation ─────────────────────
	// The list, the storage key and the saved rows are all scoped by
	// assistant_id, so carrying the previous assistant's session across a
	// switch would leave its messages on screen beside another assistant's
	// conversation list. Skipped when we asked for the switch ourselves
	// because the user opened one of that assistant's conversations.
	const previousAssistantRef = useRef( assistantId );
	useEffect( () => {
		const previous = previousAssistantRef.current;
		const pending = pendingAssistantRef.current;
		previousAssistantRef.current = assistantId;
		pendingAssistantRef.current = 0;

		if ( String( previous ) === String( assistantId ) ) {
			return;
		}
		if ( String( pending ) === String( assistantId ) ) {
			return;
		}
		startNewSession();
	}, [ assistantId, startNewSession ] );

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
