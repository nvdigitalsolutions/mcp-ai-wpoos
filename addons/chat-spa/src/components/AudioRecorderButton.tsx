/**
 * NV oOS Chat SPA — Audio recorder / transcribe / voice-chat buttons.
 *
 * Renders buttons in the composer toolbar for:
 *   - 🎤 Transcribe — record audio, upload, insert transcription
 *   - 🎙 Voice Chat — record → upload → transcribe → auto-submit
 *
 * Mirrors the legacy `handleTranscribeButtonClick` and
 * `handleVoiceChatButtonClick` patterns from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ToolsClient } from '../api/tools';
import { useAudioRecording, type RecorderState } from '../hooks/useAudioRecording';

export type RecorderMode = 'transcribe' | 'voice' | 'translate';

export interface AudioRecorderButtonProps {
	mode: RecorderMode;
	toolsEndpoint: string;
	uploadEndpoint: string;
	nonce: string;
	assistantId: number;
	disabled?: boolean;
	/** Called with the transcribed text (for transcribe/translate mode). */
	onTranscribed?: ( text: string ) => void;
	/** Called with the transcribed text (for voice-chat mode — triggers submit). */
	onVoiceSubmit?: ( text: string ) => void;
}

type BusyState = 'idle' | 'recording' | 'uploading' | 'transcribing';

const MODE_CONFIG: Record<
	RecorderMode,
	{ idleIcon: string; label: string; toolName: string }
> = {
	transcribe: {
				idleIcon: '🎤',
				label: 'Transcribe audio',
				toolName: 'transcribe_openai_audio',
			},
			voice: {
				idleIcon: '🎙',
				label: 'Voice chat',
				toolName: 'transcribe_openai_audio',
			},
			translate: {
				idleIcon: '🌐',
				label: 'Translate audio',
				toolName: 'transcribe_openai_audio',
	},
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
	const [ statusMsg, setStatusMsg ] = useState< string >( '' );

	const client = useMemo(
		() => new ToolsClient( { endpoint: toolsEndpoint, nonce } ),
		[ toolsEndpoint, nonce ]
	);

	const cleanRef = useRef< boolean >( true );
	useEffect( () => {
		return () => {
			cleanRef.current = false;
		};
	}, [] );

	const { idleIcon, label, toolName } = MODE_CONFIG[ mode ];

	// ── Record → Upload → Transcribe pipeline ──────────────────────────

	const processRecording = useCallback(
		async ( audioBlob: Blob ) => {
			if ( ! cleanRef.current ) return;

			setBusy( 'uploading' );
			setStatusMsg( __( 'Uploading audio…', 'nvoos-chat-spa' ) );

			try {
				// 1. Upload to WP media library.
				const ext =
					recorder.mimeType.includes( 'webm' )
						? 'webm'
						: recorder.mimeType.includes( 'ogg' )
						? 'ogg'
						: recorder.mimeType.includes( 'wav' )
						? 'wav'
						: 'mp4';
				const fileName = `voice-${ Date.now() }.${ ext }`;

				const media = await ToolsClient.uploadMedia(
					uploadEndpoint,
					nonce,
					audioBlob,
					fileName
				);

				if ( ! cleanRef.current ) return;

				// 2. Call the transcribe tool.
				setBusy( 'transcribing' );
				setStatusMsg( __( 'Transcribing…', 'nvoos-chat-spa' ) );

				const result = await client.execute(
					toolName,
					{ attachment_id: media.id },
					assistantId
				);

				if ( ! cleanRef.current ) return;

				const text =
					typeof result?.data?.text === 'string'
						? result.data.text
						: typeof result?.data?.message === 'string'
						? result.data.message
						: '';

				if ( ! text ) {
					throw new Error(
						__( 'No transcription returned.', 'nvoos-chat-spa' )
					);
				}

				setBusy( 'idle' );
				setStatusMsg( '' );

				// 3. Dispatch based on mode.
				if ( mode === 'voice' && onVoiceSubmit ) {
					onVoiceSubmit( text );
				} else if ( onTranscribed ) {
					onTranscribed( text );
				}
			} catch ( err ) {
				if ( ! cleanRef.current ) return;
				setBusy( 'idle' );
				setStatusMsg(
					( err as Error )?.message ||
						__( 'Transcription failed.', 'nvoos-chat-spa' )
				);
			}
		},
		[
			client,
			recorder.mimeType,
			uploadEndpoint,
			nonce,
			assistantId,
			toolName,
			mode,
			onTranscribed,
			onVoiceSubmit,
		]
	);

	// ── Click handler ──────────────────────────────────────────────────

	const handleClick = useCallback( async () => {
		if ( busy !== 'idle' ) return;

		if ( recorder.state === 'recording' ) {
			// Stop and process.
			try {
				const audioBlob = await recorder.stopRecording();
				await processRecording( audioBlob );
			} catch {
				setBusy( 'idle' );
			}
		} else {
			// Start recording.
			try {
				await recorder.startRecording();
			} catch {
				// Mic denied — error already surfaced.
			}
		}
	}, [ busy, recorder, processRecording ] );

	// ── Derived state for the button ───────────────────────────────────

	const isActive = recorder.state === 'recording';
	const isProcessing = busy === 'uploading' || busy === 'transcribing';
	const canUse = recorder.isSupported && ! disabled && ! isProcessing;

	let displayIcon = idleIcon;
	let ariaLabel = __( label, 'nvoos-chat-spa' );

	if ( isActive ) {
		displayIcon = '⏹';
		ariaLabel = __( 'Stop recording', 'nvoos-chat-spa' );
	} else if ( isProcessing ) {
		displayIcon = '⏳';
	}

	if ( ! recorder.isSupported ) {
		return null;
	}

	return (
		<>
			<button
				type="button"
				className={ `nvoos-chat-spa-audio-btn nvoos-chat-spa-audio-btn--${ mode }${
					isActive ? ' nvoos-chat-spa-audio-btn--recording' : ''
				}${ isProcessing ? ' nvoos-chat-spa-audio-btn--processing' : '' }` }
				aria-label={ ariaLabel }
				title={ ariaLabel }
				disabled={ ! canUse }
				onClick={ () => void handleClick() }
			>
				{ displayIcon }
			</button>
			{ statusMsg && (
				<span className="nvoos-chat-spa-audio-status" role="status">
					{ statusMsg }
				</span>
			) }
		</>
	);
}
