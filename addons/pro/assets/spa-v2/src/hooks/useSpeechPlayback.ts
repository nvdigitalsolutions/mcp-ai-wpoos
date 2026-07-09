/**
 * Pro SPA v2 — Speech playback hook.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { useCallback, useMemo, useRef, useState } from 'react';
import type { SpeechState } from '../components/shared/SpeechButton';

function normaliseText( t: string ): string { return t.trim().slice( 0, 500 ); }
function textKey( t: string ): string { const n = normaliseText( t ); let h = 0; for ( let i = 0; i < n.length; i++ ) h = ( ( h << 5 ) - h + n.charCodeAt( i ) ) | 0; return `speech-${ ( h >>> 0 ).toString( 36 ) }`; }

interface CachedSpeech { audio: HTMLAudioElement; url: string; }

export interface UseSpeechPlaybackOptions { toolsEndpoint: string; nonce: string; assistantId: number; }

export interface UseSpeechPlaybackResult {
	play: ( text: string ) => Promise< void >;
	stop: () => void;
	stateFor: ( text: string ) => SpeechState;
}

export function useSpeechPlayback( opts: UseSpeechPlaybackOptions ): UseSpeechPlaybackResult {
	const { toolsEndpoint, nonce, assistantId } = opts;
	const cacheRef = useRef< Map< string, CachedSpeech > >( new Map() );
	const [ states, setStates ] = useState< Record< string, SpeechState > >( {} );
	const abortRef = useRef< AbortController | null >( null );
	const activeRef = useRef< HTMLAudioElement | null >( null );

	const setState = useCallback( ( k: string, s: SpeechState ) => setStates( ( p ) => ( { ...p, [ k ]: s } ) ), [] );

	const stop = useCallback( () => {
		activeRef.current?.pause(); if ( activeRef.current ) activeRef.current.currentTime = 0;
		activeRef.current = null; abortRef.current?.abort();
		setStates( ( p ) => { const n: Record< string, SpeechState > = {}; for ( const k of Object.keys( p ) ) if ( p[ k ] !== 'idle' ) n[ k ] = 'idle'; return n; } );
	}, [] );

	const play = useCallback( async ( text: string ) => {
		const n = normaliseText( text ); if ( ! n ) return;
		const key = textKey( text ); const cur = states[ key ];
		if ( cur === 'playing' ) { stop(); return; }
		if ( cur === 'loading' ) { abortRef.current?.abort(); setState( key, 'idle' ); return; }
		stop();
		const cached = cacheRef.current.get( key );
		if ( cached ) { activeRef.current = cached.audio; cached.audio.currentTime = 0; try { await cached.audio.play(); } catch { setState( key, 'error' ); return; } setState( key, 'playing' ); cached.audio.onended = () => setState( key, 'idle' ); cached.audio.onerror = () => setState( key, 'error' ); return; }
		setState( key, 'loading' );
		const ctrl = new AbortController(); abortRef.current = ctrl;
		try {
			const resp = await fetch( toolsEndpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify( { tool: 'speech', arguments: { text: n }, assistant_id: assistantId } ), signal: ctrl.signal } );
			if ( ctrl.signal.aborted ) return;
			if ( ! resp.ok ) throw new Error( 'Speech request failed' );
			const data = ( await resp.json() ) as { data?: { url?: string } };
			const url = data?.data?.url;
			if ( ! url ) throw new Error( 'No audio URL' );
			const audio = new Audio( url ); audio.preload = 'auto';
			cacheRef.current.set( key, { audio, url } ); activeRef.current = audio;
			audio.onended = () => setState( key, 'idle' ); audio.onerror = () => setState( key, 'error' );
			await audio.play(); setState( key, 'playing' );
		} catch ( err ) { if ( ( err as Error )?.name === 'AbortError' ) return; setState( key, 'error' ); }
	}, [ toolsEndpoint, nonce, assistantId, states, stop, setState ] );

	const stateFor = useCallback( ( text: string ): SpeechState => states[ textKey( text ) ] ?? 'idle', [ states ] );
	return { play, stop, stateFor };
}
