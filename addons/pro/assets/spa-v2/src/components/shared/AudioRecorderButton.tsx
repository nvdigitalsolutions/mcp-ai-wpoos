/**
 * Pro SPA v2 — Audio recorder / transcribe / voice-chat buttons.
 *
 * 🎤 Transcribe — record, upload, insert transcription
 * 🎙 Voice Chat — record → upload → transcribe → auto-submit
 *
 * Uses the ToolsClient from api/tools so the fetch path matches the
 * legacy chat, chat-spa, and the Speech playback hook.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ToolsClient } from '../../api/tools';
import { useAudioRecording } from '../../hooks/useAudioRecording';

export type RecorderMode = 'transcribe' | 'voice';

export interface AudioRecorderButtonProps {
	mode: RecorderMode;
	toolsEndpoint: string;
	uploadEndpoint: string;
	nonce: string;
	assistantId: number;
	disabled?: boolean;
	onTranscribed?: ( text: string ) => void;
	onVoiceSubmit?: ( text: string ) => void;
}

type BusyState = 'idle' | 'recording' | 'uploading' | 'transcribing';

const MODE_CONFIG: Record< RecorderMode, { icon: string; label: string } > = {
	transcribe: { icon: '🎤', label: 'Transcribe audio' },
	voice: { icon: '🎙', label: 'Voice chat' },
};

export function AudioRecorderButton( {
	mode,
	toolsEndpoint,
	uploadEndpoint,
	nonce,
	assistantId,
	disabled = false,
	onTranscribed,
	onVoiceSubmit,
}: AudioRecorderButtonProps ): JSX.Element | null {
	const recorder = useAudioRecording();
	const [ busy, setBusy ] = useState< BusyState >( 'idle' );
	const [ statusMsg, setStatusMsg ] = useState( '' );
	const cleanRef = useRef( true );
	useEffect( () => () => { cleanRef.current = false; }, [] );

	const client = useMemo(
		() => new ToolsClient( { endpoint: toolsEndpoint, nonce } ),
		[ toolsEndpoint, nonce ]
	);

	const { icon, label } = MODE_CONFIG[ mode ];

	const processRecording = useCallback( async ( audioBlob: Blob ) => {
		if ( ! cleanRef.current ) return;
		setBusy( 'uploading' ); setStatusMsg( __( 'Uploading…', 'nvoos-pro-spa' ) );
		try {
			const ext = recorder.mimeType.includes( 'webm' )
				? 'webm'
				: recorder.mimeType.includes( 'ogg' )
				? 'ogg'
				: 'mp4';
			const fileName = `voice-${ Date.now() }.${ ext }`;

			const media = await ToolsClient.uploadMedia(
				uploadEndpoint,
				nonce,
				audioBlob,
				fileName
			);

			if ( ! cleanRef.current ) return;

			setBusy( 'transcribing' ); setStatusMsg( __( 'Transcribing…', 'nvoos-pro-spa' ) );

			const result = await client.execute(
				'transcribe_openai_audio',
				{ attachment_id: media.id },
				assistantId
			);

			if ( ! cleanRef.current ) return;

			const text: string =
				( result?.data?.text as string ) ||
				( result?.data?.message as string ) ||
				'';
			if ( ! text ) throw new Error( __( 'No transcription returned.', 'nvoos-pro-spa' ) );

			setBusy( 'idle' ); setStatusMsg( '' );
			if ( mode === 'voice' && onVoiceSubmit ) {
				onVoiceSubmit( text );
			} else {
				onTranscribed?.( text );
			}
		} catch ( err ) {
			if ( cleanRef.current ) {
				setBusy( 'idle' );
				setStatusMsg( ( err as Error )?.message || __( 'Failed.', 'nvoos-pro-spa' ) );
			}
		}
	}, [ recorder.mimeType, uploadEndpoint, nonce, client, assistantId, mode, onTranscribed, onVoiceSubmit ] );

	const handleClick = useCallback( async () => {
		if ( busy !== 'idle' ) return;
		if ( recorder.state === 'recording' ) {
			try {
				const b = await recorder.stopRecording();
				await processRecording( b );
			} catch {
				setBusy( 'idle' );
			}
		} else {
			try {
				await recorder.startRecording();
			} catch {
				/* denied – user cancelled or browser blocked */
			}
		}
	}, [ busy, recorder, processRecording ] );

	const isActive = recorder.state === 'recording';
	const isProc = busy === 'uploading' || busy === 'transcribing';
	const canUse = recorder.isSupported && ! disabled && ! isProc;
	let displayIcon = icon;
	if ( isActive ) displayIcon = '⏹';
	else if ( isProc ) displayIcon = '⏳';

	if ( ! recorder.isSupported ) return null;

	return (
		<>
			<button
				type="button"
				className={
					`nvoos-pro-spa-audio-btn nvoos-pro-spa-audio-btn--${ mode }` +
					( isActive ? ' nvoos-pro-spa-audio-btn--recording' : '' ) +
					( isProc ? ' nvoos-pro-spa-audio-btn--processing' : '' )
				}
				aria-label={ __( label, 'nvoos-pro-spa' ) }
				title={ __( label, 'nvoos-pro-spa' ) }
				disabled={ ! canUse }
				onClick={ () => void handleClick() }
			>
				{ displayIcon }
			</button>
			{ statusMsg && (
				<span className="nvoos-pro-spa-audio-status" role="status">
					{ statusMsg }
				</span>
			) }
		</>
	);
}
