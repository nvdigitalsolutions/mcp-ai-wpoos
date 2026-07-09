/**
 * NV oOS Chat SPA — Audio recording hook.
 *
 * Manages MediaRecorder lifecycle for microphone capture:
 *   - Starts recording via `navigator.mediaDevices.getUserMedia`
 *   - Assembles recorded chunks into a Blob on stop
 *   - Handles unsupported-browser detection
 *
 * Mirrors the legacy `startTranscribeRecording` / `stopTranscribeRecording`
 * pattern from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { useCallback, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';

export type RecorderState = 'idle' | 'recording' | 'stopping';

export interface UseAudioRecordingResult {
	/** Current recorder state. */
	state: RecorderState;
	/** The recorded audio blob (available after `stopRecording` resolves). */
	blob: Blob | null;
	/** MIME type of the recorded blob (e.g. "audio/webm"). */
	mimeType: string;
	/** Error message if mic access was denied or unsupported. */
	error: string | null;
	/** Start recording. Throws if mic access denied. */
	startRecording: () => Promise< void >;
	/** Stop recording. Returns the Blob when ready. */
	stopRecording: () => Promise< Blob >;
	/** Cancel recording without producing a blob. */
	cancelRecording: () => void;
	/** Whether the browser supports MediaRecorder at all. */
	isSupported: boolean;
}

function getSupportedMimeType(): string {
	// Preferred MIME types in order of quality/compression.
	const candidates = [
		'audio/webm;codecs=opus',
		'audio/webm',
		'audio/ogg;codecs=opus',
		'audio/mp4',
		'audio/wav',
	];
	for ( const mime of candidates ) {
		if ( MediaRecorder.isTypeSupported( mime ) ) {
			return mime;
		}
	}
	return 'audio/webm'; // Fallback
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

	const isSupported =
		typeof window !== 'undefined' &&
		typeof navigator !== 'undefined' &&
		typeof navigator.mediaDevices?.getUserMedia === 'function' &&
		typeof MediaRecorder !== 'undefined';

	const stopTracks = useCallback( () => {
		if ( streamRef.current ) {
			streamRef.current.getTracks().forEach( ( t ) => t.stop() );
			streamRef.current = null;
		}
	}, [] );

	const startRecording = useCallback( async () => {
		if ( ! isSupported ) {
			setError( __( 'Audio recording is not supported in this browser.', 'nvoos-chat-spa' ) );
			throw new Error( 'MediaRecorder not supported' );
		}

		setError( null );
		chunksRef.current = [];
		const mime = getSupportedMimeType();
		mimeRef.current = mime;
		setMimeType( mime );

		try {
			const stream = await navigator.mediaDevices.getUserMedia( { audio: true } );
			streamRef.current = stream;

			const recorder = new MediaRecorder( stream, {
				mimeType: mime,
			} );

			recorder.ondataavailable = ( e: BlobEvent ) => {
				if ( e.data.size > 0 ) {
					chunksRef.current.push( e.data );
				}
			};

			recorder.onstop = () => {
				const assembled = new Blob( chunksRef.current, { type: mime } );
				setBlob( assembled );
				setState( 'idle' );
				stopTracks();
				if ( resolveRef.current ) {
					resolveRef.current( assembled );
					resolveRef.current = null;
				}
			};

			recorder.onerror = () => {
				setError( __( 'Recording error.', 'nvoos-chat-spa' ) );
				setState( 'idle' );
				stopTracks();
				resolveRef.current = null;
			};

			recorderRef.current = recorder;
			recorder.start();
			setState( 'recording' );
		} catch ( err ) {
			const msg =
				( err as Error )?.name === 'NotAllowedError'
					? __( 'Microphone access denied.', 'nvoos-chat-spa' )
					: __( 'Could not start recording.', 'nvoos-chat-spa' );
			setError( msg );
			throw err;
		}
	}, [ isSupported, stopTracks ] );

	const stopRecording = useCallback( async (): Promise< Blob > => {
		if ( ! recorderRef.current || recorderRef.current.state === 'inactive' ) {
			return blob ?? new Blob();
		}
		setState( 'stopping' );
		return new Promise< Blob >( ( resolve ) => {
			resolveRef.current = resolve;
			recorderRef.current?.stop();
		} );
	}, [ blob ] );

	const cancelRecording = useCallback( () => {
		if ( recorderRef.current && recorderRef.current.state !== 'inactive' ) {
			recorderRef.current.onstop = null; // Prevent blob resolve
			recorderRef.current.stop();
		}
		stopTracks();
		chunksRef.current = [];
		resolveRef.current = null;
		setBlob( null );
		setState( 'idle' );
	}, [ stopTracks ] );

	return {
		state,
		blob,
		mimeType,
		error,
		startRecording,
		stopRecording,
		cancelRecording,
		isSupported,
	};
}
