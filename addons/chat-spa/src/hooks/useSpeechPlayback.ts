/**
 * NV oOS Chat SPA — TTS speech playback hook.
 *
 * Manages text-to-speech for assistant message bubbles:
 *   - Caches fetched audio by normalised text hash
 *   - Plays / pauses / stops
 *   - Handles loading and error states
 *
 * Mirrors the legacy `createSpeechAudio`, `requestSpeechAudio`,
 * `handleSpeechButtonClick` pattern from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { useCallback, useMemo, useRef, useState } from 'react';
import { ToolsClient, type ToolExecutionResult } from '../api/tools';

export type SpeechState = 'idle' | 'loading' | 'playing' | 'error';

interface CachedSpeech {
	audio: HTMLAudioElement;
	url: string;
	attachmentId?: number;
}

export interface UseSpeechPlaybackOptions {
	toolsEndpoint: string;
	nonce: string;
	assistantId: number;
}

export interface UseSpeechPlaybackResult {
	/** Start (or resume) playback for the given text. */
	play: ( text: string ) => Promise< void >;
	/** Stop playback and reset to idle. */
	stop: () => void;
	/** The current state for the given text, or 'idle' if none. */
	stateFor: ( text: string ) => SpeechState;
}

/**
 * Normalise text for cache lookup — mirrors legacy `normalizeSpeechText`.
 */
function normaliseText( text: string ): string {
	return text.trim().slice( 0, 500 );
}

function textKey( text: string ): string {
	const n = normaliseText( text );
	// Simple hash for cache key.
	let hash = 0;
	for ( let i = 0; i < n.length; i++ ) {
		hash = ( ( hash << 5 ) - hash + n.charCodeAt( i ) ) | 0;
	}
	return `speech-${ ( hash >>> 0 ).toString( 36 ) }`;
}

export function useSpeechPlayback(
	options: UseSpeechPlaybackOptions
): UseSpeechPlaybackResult {
	const { toolsEndpoint, nonce, assistantId } = options;

	const client = useMemo(
		() => new ToolsClient( { endpoint: toolsEndpoint, nonce } ),
		[ toolsEndpoint, nonce ]
	);

	// In-memory cache: key → CachedSpeech (persists for the SPA session).
	const cacheRef = useRef< Map< string, CachedSpeech > >( new Map() );

	// Per-text states.
	const [ states, setStates ] = useState< Record< string, SpeechState > >( {} );
	const abortRef = useRef< AbortController | null >( null );

	// Keep a ref to the currently-playing audio so `stop()` can reach it.
	const activeAudioRef = useRef< HTMLAudioElement | null >( null );

	const setState = useCallback( ( key: string, s: SpeechState ) => {
		setStates( ( prev ) => ( { ...prev, [ key ]: s } ) );
	}, [] );

	const stop = useCallback( () => {
		if ( activeAudioRef.current ) {
			activeAudioRef.current.pause();
			activeAudioRef.current.currentTime = 0;
			activeAudioRef.current = null;
		}
		abortRef.current?.abort();
		// Reset all states to idle.
		setStates( ( prev ) => {
			const next: Record< string, SpeechState > = {};
			for ( const k of Object.keys( prev ) ) {
				if ( prev[ k ] !== 'idle' ) {
					next[ k ] = 'idle';
				}
			}
			return next;
		} );
	}, [] );

	const play = useCallback(
		async ( text: string ) => {
			const n = normaliseText( text );
			if ( ! n ) return;

			const key = textKey( text );

			// If already playing this text, stop.
			const current = states[ key ];
			if ( current === 'playing' ) {
				stop();
				return;
			}
			if ( current === 'loading' ) {
				// Abort the in-flight request.
				abortRef.current?.abort();
				setState( key, 'idle' );
				return;
			}

			// Stop any other active playback.
			stop();

			// Check cache.
			const cached = cacheRef.current.get( key );
			if ( cached ) {
				activeAudioRef.current = cached.audio;
				cached.audio.currentTime = 0;
				try {
					await cached.audio.play();
				} catch {
					// Autoplay blocked or other error — surface as error state.
					setState( key, 'error' );
					return;
				}
				setState( key, 'playing' );
				cached.audio.onended = () => setState( key, 'idle' );
				cached.audio.onerror = () => setState( key, 'error' );
				return;
			}

			// Fetch from server.
			setState( key, 'loading' );
			const controller = new AbortController();
			abortRef.current = controller;

			try {
				const result: ToolExecutionResult = await client.execute(
					'speech',
					{ text: n },
					assistantId,
					controller.signal
				);

				if ( controller.signal.aborted ) return;

				const url = result?.data?.url;
				if ( ! url || typeof url !== 'string' ) {
					throw new Error( 'No audio URL in speech response.' );
				}

				const audio = new Audio( url );
				audio.preload = 'auto';

				// Cache.
				cacheRef.current.set( key, {
					audio,
					url,
					attachmentId:
						typeof result?.data?.attachment_id === 'number'
							? result.data.attachment_id
							: undefined,
				} );

				activeAudioRef.current = audio;

				audio.onended = () => setState( key, 'idle' );
				audio.onerror = () => setState( key, 'error' );

				await audio.play();
				setState( key, 'playing' );
			} catch ( err ) {
				if ( ( err as Error )?.name === 'AbortError' ) return;
				setState( key, 'error' );
			}
		},
		[ client, assistantId, states, stop, setState ]
	);

	const stateFor = useCallback(
		( text: string ): SpeechState => {
			const key = textKey( text );
			return states[ key ] ?? 'idle';
		},
		[ states ]
	);

	return { play, stop, stateFor };
}
