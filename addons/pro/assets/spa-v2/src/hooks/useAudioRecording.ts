/**
 * Pro SPA v2 — Audio recording hook.
 *
 * Manages MediaRecorder lifecycle for microphone capture.
 * Mirrors chat-spa's useAudioRecording with pro namespace.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { useCallback, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';

export type RecorderState = 'idle' | 'recording' | 'stopping';

export interface UseAudioRecordingResult {
	state: RecorderState;
	blob: Blob | null;
	mimeType: string;
	error: string | null;
	startRecording: () => Promise< void >;
	stopRecording: () => Promise< Blob >;
	cancelRecording: () => void;
	isSupported: boolean;
}

function getSupportedMimeType(): string {
	const candidates = [ 'audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/wav' ];
	for ( const m of candidates ) if ( MediaRecorder.isTypeSupported( m ) ) return m;
	return 'audio/webm';
}

export function useAudioRecording(): UseAudioRecordingResult {
	const [ state, setState ] = useState< RecorderState >( 'idle' );
	const [ blob, setBlob ] = useState< Blob | null >( null );
	const [ mimeType, setMimeType ] = useState< string >( '' );
	const [ error, setError ] = useState< string | null >( null );
	const streamRef = useRef< MediaStream | null >( null );
	const recorderRef = useRef< MediaRecorder | null >( null );
	const chunksRef = useRef< Blob[] >( [] );
	const resolveRef = useRef< ( ( b: Blob ) => void ) | null >( null );
	const mimeRef = useRef< string >( '' );

	const isSupported = typeof window !== 'undefined' && typeof navigator?.mediaDevices?.getUserMedia === 'function' && typeof MediaRecorder !== 'undefined';

	const stopTracks = useCallback( () => {
		streamRef.current?.getTracks().forEach( ( t ) => t.stop() );
		streamRef.current = null;
	}, [] );

	const startRecording = useCallback( async () => {
		if ( ! isSupported ) throw new Error( 'MediaRecorder not supported' );
		setError( null ); chunksRef.current = [];
		const m = getSupportedMimeType(); mimeRef.current = m; setMimeType( m );
		const stream = await navigator.mediaDevices.getUserMedia( { audio: true } );
		streamRef.current = stream;
		const recorder = new MediaRecorder( stream, { mimeType: m } );
		recorder.ondataavailable = ( e: BlobEvent ) => { if ( e.data.size > 0 ) chunksRef.current.push( e.data ); };
		recorder.onstop = () => { const b = new Blob( chunksRef.current, { type: m } ); setBlob( b ); setState( 'idle' ); stopTracks(); resolveRef.current?.( b ); resolveRef.current = null; };
		recorder.onerror = () => { setError( __( 'Recording error.', 'nvoos-pro-spa' ) ); setState( 'idle' ); stopTracks(); resolveRef.current = null; };
		recorderRef.current = recorder; recorder.start(); setState( 'recording' );
	}, [ isSupported, stopTracks ] );

	const stopRecording = useCallback( async () => {
		if ( ! recorderRef.current || recorderRef.current.state === 'inactive' ) return blob ?? new Blob();
		setState( 'stopping' );
		return new Promise< Blob >( ( r ) => { resolveRef.current = r; recorderRef.current?.stop(); } );
	}, [ blob ] );

	const cancelRecording = useCallback( () => {
		if ( recorderRef.current && recorderRef.current.state !== 'inactive' ) { recorderRef.current.onstop = null; recorderRef.current.stop(); }
		stopTracks(); chunksRef.current = []; resolveRef.current = null; setBlob( null ); setState( 'idle' );
	}, [ stopTracks ] );

	return { state, blob, mimeType, error, startRecording, stopRecording, cancelRecording, isSupported };
}
