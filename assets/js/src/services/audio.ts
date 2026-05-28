/**
 * Audio Service for NV oOS Chat — TypeScript edition.
 *
 * Handles speech synthesis (text-to-speech), audio transcription (speech-to-text),
 * audio translation, voice chat, and VAD (voice activity detection) with browser
 * MediaRecorder API support.
 *
 * Converts the legacy IIFE at `assets/js/chat-audio-service.js` to an ESM module
 * with full TypeScript types while preserving backward-compatible globals.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── Interfaces ───────────────────────────────────────────────────────

/** Subset of chat config needed by this service. */
export interface AudioServiceConfig {
	toolsEndpoint: string;
	assistantId: string | number;
	vadEnabled?: boolean;
	vadSilenceThreshold?: number;
	vadMinSpeech?: number;
	vadAudioThreshold?: number;
}

/** A single cached speech-audio entry (minimal shape). */
export interface SpeechCacheItem {
	url: string;
}

/** Active speech playback tracking. */
export interface ActiveSpeechEntry {
	button: HTMLElement;
	audio: HTMLAudioElement;
	text: string;
}

/** Result returned by the speech-generation endpoint. */
export interface AudioResult {
	url: string;
	attachmentId?: string | number;
	format?: string;
	mimeType?: string;
}

/** Uploaded attachment record returned by helpers.uploadAudioForTranscription. */
export interface AttachmentUploadRecord {
	id: string | number;
	name?: string;
	filename?: string;
}

/**
 * The chat-state bag consumed by audio-service helpers.
 *
 * This is intentionally a focused shape — the real chat-state object
 * may carry many more properties, but this service only reads the
 * fields declared here.
 */
export interface AudioServiceState {
	config: AudioServiceConfig;
	speechCache: Record< string, SpeechCacheItem >;
	activeSpeech: ActiveSpeechEntry | null;
	recordingStream: MediaStream | null;
	recordedChunks: Blob[];
	mediaRecorder: MediaRecorder | null;
	recordingShouldProcess: boolean;
	isRecording: boolean;
	transcribing: boolean;
	busy: boolean;
	transcribeButton: HTMLElement | null;
	transcribeInput: HTMLInputElement | null;
	canUploadAttachments: boolean;
	uploading: number;
	container: HTMLElement;
	translating: boolean;
	isTranslateRecording: boolean;
	translateButton: HTMLElement | null;
	translateInput: HTMLInputElement | null;
	translateRecordingStream: MediaStream | null;
	translateRecordedChunks: Blob[];
	translateMediaRecorder: MediaRecorder | null;
	translateRecordingShouldProcess: boolean;
	voiceChatProcessing: boolean;
	isVoiceChatRecording: boolean;
	voiceChatButton: HTMLElement | null;
	voiceChatStream: MediaStream | null;
	voiceChatChunks: Blob[];
	voiceChatRecorder: MediaRecorder | null;
	voiceChatShouldProcess: boolean;
	voiceChatModeActive: boolean;
	textarea: HTMLTextAreaElement;
	vadAudioContext: AudioContext | null;
	vadAnalyser: AnalyserNode | null;
	vadSilenceStart: number | null;
	vadSpeechStart: number;
	vadLastSpeechTime: number;
	vadEnabled: boolean;
	vadSilenceThreshold: number;
	vadMinSpeechDuration: number;
	vadAudioThreshold: number;
	vadMonitorInterval: ReturnType< typeof setInterval > | null;
}

/**
 * Helper functions injected by the chat initialisation layer.
 */
export interface AudioHelpers {
	getString: ( key: string, fallback?: string ) => string;
	setStatus: ( container: HTMLElement, message: string ) => void;
	uploadAudioForTranscription?: ( state: AudioServiceState, file: File ) => Promise< AttachmentUploadRecord >;
	requestTranscription?: (
		state: AudioServiceState,
		record: AttachmentUploadRecord,
		translate?: boolean,
	) => Promise< unknown >;
	insertTranscriptionResult?: (
		state: AudioServiceState,
		result: unknown,
		uploadedRecord: AttachmentUploadRecord | File,
	) => void;
	sendMessage?: ( state: AudioServiceState ) => void;
}

/** Signature for the per-request JSON header factory. */
export type RequestHeadersBuilder = ( state: AudioServiceState ) => Record< string, string >;

// ── Constants ────────────────────────────────────────────────────────

/** Tool slug for OpenAI speech generation. */
export const SPEECH_TOOL_NAME = 'generate_openai_speech';

/** CSS class applied to speech buttons. */
export const SPEECH_BUTTON_CLASS = 'wp-mcp-ai-speech-button';

/** CSS class marking a bubble that has a speech button. */
export const SPEECH_ENABLED_CLASS = 'wp-mcp-ai-speech-enabled';

/** CSS class for speech button in error state. */
export const SPEECH_ERROR_CLASS = 'wp-mcp-ai-speech-button--error';

/** SVG icon for the "play" state. */
export const SPEECH_PLAY_ICON =
	'<svg class="wp-mcp-ai-speech-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M6 4l9 6-9 6V4z"></path></svg>';

/** SVG icon for the "stop" state. */
export const SPEECH_STOP_ICON =
	'<svg class="wp-mcp-ai-speech-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><rect x="6" y="5" width="8" height="10" rx="1"></rect></svg>';

/** Spinner markup for the "loading" state. */
export const SPEECH_SPINNER_ICON =
	'<span class="wp-mcp-ai-speech-spinner" aria-hidden="true"></span>';

/** Tool slug for OpenAI transcription (used by server-side route resolution). */
export const TRANSCRIBE_TOOL_NAME = 'transcribe_openai_audio';

/** CSS class applied while transcribe recording is active. */
export const TRANSCRIBE_RECORDING_CLASS = 'wp-mcp-ai-chat__transcribe--recording';

/** Maximum upload size for transcription / translation audio (25 MB). */
export const MAX_TRANSCRIBE_BYTES = 26214400;

/** CSS class applied while translate recording is active. */
export const TRANSLATE_RECORDING_CLASS = 'wp-mcp-ai-chat__translate--recording';

/** CSS class applied while voice-chat recording is active. */
export const VOICE_CHAT_RECORDING_CLASS = 'wp-mcp-ai-chat__voice-chat--recording';

/** CSS class applied while voice-chat audio is processing. */
export const VOICE_CHAT_PROCESSING_CLASS = 'wp-mcp-ai-chat__voice-chat--processing';

/** CSS class applied when VAD detects speech. */
export const VOICE_CHAT_LISTENING_CLASS = 'wp-mcp-ai-chat__voice-chat--listening';

// ── VAD defaults (overridable via WordPress config) ───────────────────

/** Default silence threshold before auto-stop (ms). */
export const VAD_DEFAULT_SILENCE_THRESHOLD_MS = 700;

/** Minimum speech duration before silence threshold is honoured (ms). */
export const VAD_DEFAULT_MIN_SPEECH_DURATION_MS = 300;

/** dB threshold above which audio is considered speech. */
export const VAD_DEFAULT_AUDIO_LEVEL_THRESHOLD = -50;

/** Polling interval for the VAD analyser (ms). */
export const VAD_CHECK_INTERVAL_MS = 100;

// ── Module-level state ───────────────────────────────────────────────

/** Registry of object URLs created by this module for later cleanup. */
let objectUrlRegistry: string[] = [];

// ── Object URL management ────────────────────────────────────────────

/**
 * Register an object URL for later cleanup.
 */
export function registerObjectUrl( url: string | null | undefined ): void {
	if ( ! url ) {
		return;
	}

	objectUrlRegistry.push( url );
}

/**
 * Revoke all registered object URLs to free memory.
 */
export function revokeObjectUrls(): void {
	if ( ! objectUrlRegistry.length ) {
		return;
	}

	objectUrlRegistry.forEach( ( url: string ) => {
		try {
			URL.revokeObjectURL( url );
		} catch {
			// Ignore revoke errors.
		}
	} );

	objectUrlRegistry = [];
}

// ── Capability detection ─────────────────────────────────────────────

/**
 * Check if the browser supports audio recording.
 */
export function supportsAudioRecording(): boolean {
	return (
		typeof window !== 'undefined' &&
		!! window.navigator &&
		!! navigator.mediaDevices &&
		typeof navigator.mediaDevices.getUserMedia === 'function' &&
		typeof window.MediaRecorder !== 'undefined'
	);
}

// ═════════════════════════════════════════════════════════════════════
// Speech Synthesis (Text-to-Speech)
// ═════════════════════════════════════════════════════════════════════

/**
 * Normalize speech text by trimming whitespace.
 */
export function normalizeSpeechText( text: unknown ): string {
	if ( typeof text !== 'string' ) {
		return '';
	}

	return text.trim();
}

/**
 * Update speech button icon based on state.
 */
export function updateSpeechButtonIcon(
	button: HTMLElement | null | undefined,
	stateName: string,
): void {
	if ( ! button ) {
		return;
	}

	if ( button.classList ) {
		button.classList.remove( SPEECH_ERROR_CLASS );
	}

	button.dataset.state = stateName;

	if ( stateName === 'loading' ) {
		button.innerHTML = SPEECH_SPINNER_ICON;
		button.setAttribute( 'aria-label', 'Generating audio...' );
		button.setAttribute( 'title', 'Generating audio...' );
		button.setAttribute( 'aria-busy', 'true' );
		return;
	}

	button.removeAttribute( 'aria-busy' );

	if ( stateName === 'playing' ) {
		button.innerHTML = SPEECH_STOP_ICON;
		button.setAttribute( 'aria-label', 'Stop audio playback' );
		button.setAttribute( 'title', 'Stop audio playback' );
		return;
	}

	button.innerHTML = SPEECH_PLAY_ICON;
	button.setAttribute( 'aria-label', 'Play response audio' );
	button.setAttribute( 'title', 'Play response audio' );
}

/**
 * Clear cached speech audio for the given text.
 */
export function clearSpeechCacheEntry(
	state: AudioServiceState | null | undefined,
	text: string | null | undefined,
): void {
	if ( ! state || ! state.speechCache || ! text ) {
		return;
	}

	delete state.speechCache[ text ];
}

/**
 * Set the speech button to error state.
 */
export function setSpeechButtonErrorState(
	state: AudioServiceState | null | undefined,
	button: HTMLElement | null | undefined,
	text: string | null | undefined,
): void {
	if ( ! button ) {
		return;
	}

	button.dataset.state = 'error';
	button.innerHTML = SPEECH_PLAY_ICON;
	button.setAttribute( 'aria-label', 'Unable to generate audio' );
	button.setAttribute( 'title', 'Unable to generate audio' );
	button.removeAttribute( 'aria-busy' );
	(button as HTMLButtonElement).disabled = false;

	if ( button.classList ) {
		button.classList.add( SPEECH_ERROR_CLASS );
	}

	const attachedAudio = ( button as unknown as Record< string, unknown > )._wpMcpAiAudio as HTMLAudioElement | undefined;
	if ( attachedAudio ) {
		try {
			attachedAudio.pause();
		} catch {
			// Ignore pause errors.
		}
	}

	( button as unknown as Record< string, unknown > )._wpMcpAiAudio = null;

	if ( state && state.activeSpeech && state.activeSpeech.button === button ) {
		state.activeSpeech = null;
	}

	clearSpeechCacheEntry( state, text as string );
}

/**
 * Stop speech audio playback.
 */
export function stopSpeechPlayback(
	state: AudioServiceState | null | undefined,
	button: HTMLElement | null | undefined,
): void {
	if ( ! state || ! button ) {
		return;
	}

	let audio: HTMLAudioElement | null | undefined =
		( button as unknown as Record< string, unknown > )._wpMcpAiAudio as HTMLAudioElement | undefined;
	if ( ! audio && state.activeSpeech && state.activeSpeech.button === button ) {
		audio = state.activeSpeech.audio;
	}

	if ( audio ) {
		try {
			audio.pause();
		} catch {
			// Ignore pause errors.
		}

		try {
			audio.currentTime = 0;
		} catch {
			// Ignore seek errors.
		}
	}

	if ( state.activeSpeech && state.activeSpeech.button === button ) {
		state.activeSpeech = null;
	}

	updateSpeechButtonIcon( button, 'idle' );
}

/**
 * Start speech audio playback.
 */
export function startSpeechPlayback(
	state: AudioServiceState,
	button: HTMLElement,
	audio: HTMLAudioElement | null | undefined,
	text: string,
): void {
	if ( ! audio ) {
		return;
	}

	if ( state.activeSpeech && state.activeSpeech.audio && state.activeSpeech.audio !== audio ) {
		try {
			state.activeSpeech.audio.pause();
		} catch {
			// Ignore pause errors.
		}

		if ( state.activeSpeech.button ) {
			updateSpeechButtonIcon( state.activeSpeech.button, 'idle' );
		}
	}

	audio.currentTime = 0;

	const playPromise: Promise< void > | undefined = audio.play();
	if ( playPromise && typeof playPromise.then === 'function' ) {
		playPromise.catch( () => {
			const currentText: string = button.dataset ? button.dataset.speechText || text : text;
			setSpeechButtonErrorState( state, button, currentText );
		} );
	}
}

/**
 * Create an audio element with event listeners for speech playback.
 */
export function createSpeechAudio(
	state: AudioServiceState,
	button: HTMLElement,
	url: string,
	text: string,
): HTMLAudioElement {
	const audio = new Audio( url );
	audio.preload = 'auto';

	audio.addEventListener( 'ended', () => {
		if ( state.activeSpeech && state.activeSpeech.audio === audio ) {
			state.activeSpeech = null;
		}
		updateSpeechButtonIcon( button, 'idle' );
	} );

	audio.addEventListener( 'pause', () => {
		if ( button.dataset && button.dataset.state === 'error' ) {
			return;
		}

		if ( ! audio.duration || audio.currentTime < audio.duration ) {
			if ( state.activeSpeech && state.activeSpeech.audio === audio ) {
				state.activeSpeech = null;
			}
			updateSpeechButtonIcon( button, 'idle' );
		}
	} );

	audio.addEventListener( 'play', () => {
		state.activeSpeech = { button, audio, text };
		updateSpeechButtonIcon( button, 'playing' );
	} );

	audio.addEventListener( 'error', () => {
		setSpeechButtonErrorState( state, button, text );
	} );

	return audio;
}

/**
 * Ensure an audio element exists and start playback.
 */
export function ensureSpeechAudio(
	state: AudioServiceState,
	button: HTMLElement,
	url: string | null | undefined,
	text: string,
): void {
	if ( ! url ) {
		return;
	}

	let audio: HTMLAudioElement | undefined = ( button as unknown as Record< string, unknown > )
		._wpMcpAiAudio as HTMLAudioElement | undefined;
	if ( ! audio || audio.src !== url ) {
		audio = createSpeechAudio( state, button, url, text );
		( button as unknown as Record< string, unknown > )._wpMcpAiAudio = audio;
	}

	startSpeechPlayback( state, button, audio, text );
}

/**
 * Request speech audio from the server.
 */
export function requestSpeechAudio(
	state: AudioServiceState,
	text: string,
	buildJsonHeaders: RequestHeadersBuilder,
): Promise< AudioResult > {
	if ( ! state || ! state.config || ! state.config.toolsEndpoint ) {
		return Promise.reject( new Error( 'Speech tool unavailable.' ) );
	}

	const payload = {
		assistant_id: state.config.assistantId,
		tool: SPEECH_TOOL_NAME,
		arguments: {
			text,
		},
	};

	return fetch( state.config.toolsEndpoint, {
		method: 'POST',
		headers: buildJsonHeaders( state ),
		credentials: 'same-origin',
		body: JSON.stringify( payload ),
	} )
		.then( ( response: Response ) => {
			return response
				.json()
				.catch( () => null )
				.then( ( body: unknown ) => {
					if ( ! response.ok ) {
						throw response;
					}
					if ( ! body || typeof body !== 'object' ) {
						return Promise.reject( new Error( 'Invalid response.' ) );
					}
					return body as Record< string, unknown >;
				} );
		} )
		.then( ( body: Record< string, unknown > ) => {
			const result = Object.prototype.hasOwnProperty.call( body, 'result' )
				? ( body.result as Record< string, unknown > | undefined )
				: body;

			if ( ! result || typeof result !== 'object' || ! result.url ) {
				return Promise.reject( new Error( 'Missing audio result.' ) );
			}

			return {
				url: result.url as string,
				attachmentId: result.attachment_id as string | number | undefined,
				format: result.format as string | undefined,
				mimeType: result.mime_type as string | undefined,
			};
		} );
}

/**
 * Handle speech button click event.
 */
export function handleSpeechButtonClick(
	state: AudioServiceState | null | undefined,
	button: HTMLElement | null | undefined,
	buildJsonHeaders: RequestHeadersBuilder,
): void {
	if ( ! state || ! button ) {
		return;
	}

	const text = normalizeSpeechText( button.dataset.speechText || '' );
	if ( ! text ) {
		return;
	}

	const currentState = button.dataset.state;
	if ( currentState === 'loading' ) {
		return;
	}

	if ( currentState === 'playing' ) {
		stopSpeechPlayback( state, button );
		return;
	}

	if ( ! state.speechCache ) {
		state.speechCache = Object.create( null ) as Record< string, SpeechCacheItem >;
	}

	const cache = state.speechCache[ text ];
	if ( cache && cache.url ) {
		ensureSpeechAudio( state, button, cache.url, text );
		return;
	}

	updateSpeechButtonIcon( button, 'loading' );
	(button as HTMLButtonElement).disabled = true;

	requestSpeechAudio( state, text, buildJsonHeaders )
		.then( ( info: AudioResult ) => {
			if ( ! info || ! info.url ) {
				throw new Error( 'Invalid speech response' );
			}

			state.speechCache[ text ] = { url: info.url };
			ensureSpeechAudio( state, button, info.url, text );
		} )
		.catch( () => {
			setSpeechButtonErrorState( state, button, text );
		} )
		.finally( () => {
			(button as HTMLButtonElement).disabled = false;
			if ( button.dataset.state === 'loading' ) {
				updateSpeechButtonIcon( button, 'idle' );
			}
		} );
}

/**
 * Resolve speech text from a bubble or explicit text.
 */
export function resolveSpeechText(
	bubble: HTMLElement | null | undefined,
	text: string | undefined,
): string {
	const provided = normalizeSpeechText( text || '' );
	if ( provided ) {
		return provided;
	}

	if ( bubble && bubble.dataset && bubble.dataset.speechText ) {
		const stored = normalizeSpeechText( bubble.dataset.speechText );
		if ( stored ) {
			return stored;
		}
	}

	if ( ! bubble ) {
		return '';
	}

	let textContent = '';
	if ( typeof bubble.textContent === 'string' ) {
		textContent = bubble.textContent;
	} else if ( bubble.innerText ) {
		textContent = bubble.innerText;
	}

	return normalizeSpeechText( textContent );
}

/**
 * Attach a speech button to a message bubble.
 */
export function attachSpeechButton(
	bubble: HTMLElement | null | undefined,
	state: AudioServiceState | null | undefined,
	text: string | undefined,
	buildJsonHeaders: RequestHeadersBuilder,
): void {
	if ( ! bubble || ! state || ! state.config || ! state.config.toolsEndpoint || ! state.config.assistantId ) {
		return;
	}

	const normalisedText = resolveSpeechText( bubble, text );
	if ( ! normalisedText ) {
		return;
	}

	if ( bubble.classList ) {
		bubble.classList.add( SPEECH_ENABLED_CLASS );
	}

	if ( bubble.dataset ) {
		bubble.dataset.speechText = normalisedText;
	}

	if ( ! state.speechCache ) {
		state.speechCache = Object.create( null ) as Record< string, SpeechCacheItem >;
	}

	const existing = bubble.querySelector( '.' + SPEECH_BUTTON_CLASS ) as HTMLElement | null;
	if ( existing ) {
		const previousText = normalizeSpeechText( existing.dataset.speechText || '' );

		if ( previousText && previousText !== normalisedText ) {
			stopSpeechPlayback( state, existing );
			clearSpeechCacheEntry( state, previousText );
		}

		existing.dataset.speechText = normalisedText;
		(existing as HTMLButtonElement).disabled = false;
		updateSpeechButtonIcon( existing, 'idle' );
		return;
	}

	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = SPEECH_BUTTON_CLASS;
	button.dataset.speechText = normalisedText;
	button.setAttribute( 'aria-label', 'Play response audio' );
	button.setAttribute( 'title', 'Play response audio' );

	updateSpeechButtonIcon( button, 'idle' );

	button.addEventListener( 'click', function ( event: Event ) {
		event.preventDefault();
		event.stopPropagation();
		handleSpeechButtonClick( state, button, buildJsonHeaders );
	} );

	bubble.appendChild( button );
}

// ═════════════════════════════════════════════════════════════════════
// Audio Transcription (Speech-to-Text)
// ═════════════════════════════════════════════════════════════════════

/**
 * Stop the recording stream and release media tracks.
 */
export function stopRecordingStream( state: AudioServiceState | null | undefined ): void {
	if ( ! state || ! state.recordingStream ) {
		return;
	}

	const tracks: MediaStreamTrack[] = state.recordingStream.getTracks
		? state.recordingStream.getTracks()
		: [];
	tracks.forEach( ( track: MediaStreamTrack ) => {
		try {
			track.stop();
		} catch {
			// Ignore stop errors.
		}
	} );

	state.recordingStream = null;
}

/**
 * Set the transcribe recording state and update UI.
 */
export function setTranscribeRecordingState(
	state: AudioServiceState | null | undefined,
	recording: boolean,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state ) {
		return;
	}

	state.isRecording = !! recording;

	const button = state.transcribeButton;
	if ( button && button.classList ) {
		if ( state.isRecording ) {
			button.classList.add( TRANSCRIBE_RECORDING_CLASS );
		} else {
			button.classList.remove( TRANSCRIBE_RECORDING_CLASS );
		}
	}

	if ( button && helpers && helpers.getString ) {
		const label = state.isRecording
			? helpers.getString( 'stopRecording', 'Stop recording' )
			: helpers.getString( 'transcribeAudio', 'Transcribe audio' );
		button.setAttribute( 'aria-label', label );
		button.setAttribute( 'title', label );
	}

	if ( state.container && helpers && helpers.setStatus && helpers.getString ) {
		if ( state.isRecording ) {
			helpers.setStatus( state.container, helpers.getString( 'recording', 'Recording… tap to stop.' ) );
		} else if ( ! state.transcribing && ! state.busy ) {
			helpers.setStatus( state.container, '' );
		}
	}
}

/**
 * Update the transcribe button state based on chat state.
 */
export function updateTranscribeButtonState( state: AudioServiceState | null | undefined ): void {
	if ( ! state ) {
		return;
	}

	const button = state.transcribeButton;
	const input = state.transcribeInput;

	const canUse = !! state.canUploadAttachments;
	let disabled = ! canUse || state.busy || state.uploading > 0 || state.transcribing;

	if ( state.isRecording ) {
		disabled = false;
	}

	if ( button ) {
		(button as HTMLButtonElement).disabled = disabled;

		if ( ! canUse ) {
			button.hidden = true;
		} else {
			button.hidden = false;
		}
	}

	if ( input ) {
		input.disabled =
			! canUse || state.busy || state.uploading > 0 || state.transcribing || state.isRecording;
	}
}

/**
 * Handle transcribe button click event.
 */
export function handleTranscribeButtonClick(
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || state.transcribing ) {
		return;
	}

	if ( state.isRecording ) {
		stopTranscribeRecording( state, helpers );
		return;
	}

	if ( ! state.canUploadAttachments ) {
		return;
	}

	if ( supportsAudioRecording() ) {
		let shouldRecord = true;

		if ( state.transcribeInput && helpers && helpers.getString ) {
			const message = helpers.getString(
				'transcribeChooseSource',
				'Press OK to record with your microphone, or Cancel to choose an audio file.',
			);

			if ( typeof window !== 'undefined' && typeof window.confirm === 'function' ) {
				shouldRecord = window.confirm( message );
			}
		}

		if ( shouldRecord ) {
			startTranscribeRecording( state, helpers );
			return;
		}
	}

	if ( state.transcribeInput && ! state.transcribeInput.disabled ) {
		state.transcribeInput.click();
	}
}

/**
 * Start audio recording for transcription.
 */
export function startTranscribeRecording(
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || ! supportsAudioRecording() ) {
		return;
	}

	state.recordingShouldProcess = false;

	if ( state.transcribeButton ) {
		(state.transcribeButton as HTMLButtonElement).disabled = true;
	}

	navigator.mediaDevices
		.getUserMedia( { audio: true } )
		.then( ( stream: MediaStream ) => {
			state.recordingStream = stream;
			state.recordedChunks = [];

			try {
				state.mediaRecorder = new MediaRecorder( stream );
			} catch {
				stopRecordingStream( state );
				if ( helpers && helpers.setStatus && helpers.getString ) {
					helpers.setStatus(
						state.container,
						helpers.getString(
							'recordingError',
							'Could not access your microphone. Please allow access or upload an audio file instead.',
						),
					);
				}
				updateTranscribeButtonState( state );
				return;
			}

			if ( ! state.mediaRecorder ) {
				stopRecordingStream( state );
				updateTranscribeButtonState( state );
				return;
			}

			state.recordingShouldProcess = true;

			state.mediaRecorder.addEventListener( 'dataavailable', ( event: BlobEvent ) => {
				if ( event && event.data && event.data.size ) {
					state.recordedChunks.push( event.data );
				}
			} );

			state.mediaRecorder.addEventListener( 'stop', () => {
				const chunks: Blob[] = state.recordedChunks || [];
				const mimeType: string =
					state.mediaRecorder && state.mediaRecorder.mimeType
						? state.mediaRecorder.mimeType
						: 'audio/webm';
				let baseMimeType: string =
					typeof mimeType === 'string' ? mimeType.split( ';' )[ 0 ] : '';
				if ( ! baseMimeType && typeof mimeType === 'string' ) {
					baseMimeType = mimeType;
				}

				stopRecordingStream( state );
				setTranscribeRecordingState( state, false, helpers );

				if ( ! state.recordingShouldProcess ) {
					state.mediaRecorder = null;
					state.recordedChunks = [];
					return;
				}

				let blob: Blob | null = null;
				try {
					let blobType: string = baseMimeType || mimeType;
					if ( blobType && typeof blobType === 'string' ) {
						blobType = blobType.split( ';' )[ 0 ];
					}
					blob = new Blob( chunks, { type: blobType || 'audio/webm' } );
				} catch {
					// Ignore blob creation errors.
				}

				state.mediaRecorder = null;
				state.recordedChunks = [];

				if ( ! blob || ! blob.size ) {
					updateTranscribeButtonState( state );
					return;
				}

				let extension = '';
				if ( baseMimeType && baseMimeType.indexOf( '/' ) !== -1 ) {
					extension = baseMimeType.split( '/' )[ 1 ] || '';
				}

				let safeExtension: string = extension
					? extension.replace( /[^a-z0-9]/gi, '' )
					: 'webm';
				if ( ! safeExtension ) {
					safeExtension = 'webm';
				}
				const fileName = 'transcription-' + Date.now() + '.' + safeExtension;

				let file: File;
				try {
					let fileType: string = blob && blob.type ? blob.type : baseMimeType || 'audio/webm';
					if ( fileType && typeof fileType === 'string' ) {
						fileType = fileType.split( ';' )[ 0 ];
					}
					file = new File( [ blob ], fileName, { type: fileType || 'audio/webm' } );
				} catch {
					file = blob as unknown as File;
					( file as unknown as Record< string, unknown > ).name = fileName;
					if ( file.type && typeof file.type === 'string' ) {
						( file as unknown as Record< string, unknown > ).type = file.type.split( ';' )[ 0 ];
					}
					if ( ! file.type && baseMimeType ) {
						( file as unknown as Record< string, unknown > ).type = baseMimeType;
					}
				}

				transcribeAudioFile( state, file, helpers );
			} );

			state.mediaRecorder.start();
			setTranscribeRecordingState( state, true, helpers );
			updateTranscribeButtonState( state );
		} )
		.catch( () => {
			stopRecordingStream( state );
			if ( helpers && helpers.setStatus && helpers.getString ) {
				helpers.setStatus(
					state.container,
					helpers.getString(
						'recordingError',
						'Could not access your microphone. Please allow access or upload an audio file instead.',
					),
				);
			}

			if ( state.transcribeInput && ! state.transcribeInput.disabled ) {
				state.transcribeInput.click();
			}

			updateTranscribeButtonState( state );
		} );
}

/**
 * Stop transcribe recording.
 */
export function stopTranscribeRecording(
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || ! state.mediaRecorder ) {
		return;
	}

	state.recordingShouldProcess = true;

	try {
		if ( state.mediaRecorder.state !== 'inactive' ) {
			state.mediaRecorder.stop();
		}
	} catch {
		stopRecordingStream( state );
		setTranscribeRecordingState( state, false, helpers );
		updateTranscribeButtonState( state );
	}
}

/**
 * Handle transcribe file input selection.
 */
export function handleTranscribeFileSelection(
	event: Event | null | undefined,
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || ! state.canUploadAttachments ) {
		return;
	}

	if ( ! event || ! ( event.target as HTMLInputElement ) || ! ( event.target as HTMLInputElement ).files ) {
		return;
	}

	const fileList: FileList = ( event.target as HTMLInputElement ).files!;
	const files: File[] = Array.prototype.slice.call( fileList );
	( event.target as HTMLInputElement ).value = '';

	if ( ! files.length ) {
		return;
	}

	const file: File = files[ 0 ];
	transcribeAudioFile( state, file, helpers );
}

/**
 * Transcribe an audio file.
 */
export function transcribeAudioFile(
	state: AudioServiceState | null | undefined,
	file: File | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || ! file || ! state.canUploadAttachments || state.transcribing ) {
		return;
	}

	if ( file.size && file.size > MAX_TRANSCRIBE_BYTES ) {
		if ( helpers && helpers.setStatus && helpers.getString ) {
			helpers.setStatus(
				state.container,
				helpers.getString(
					'transcriptionFileTooLarge',
					'The selected audio file is too large. Please choose a file under 25MB.',
				),
			);
		}
		updateTranscribeButtonState( state );
		return;
	}

	state.transcribing = true;
	updateTranscribeButtonState( state );

	if ( helpers && helpers.setStatus && helpers.getString ) {
		helpers.setStatus( state.container, helpers.getString( 'transcribing', 'Transcribing audio…' ) );
	}

	let uploadedRecord: AttachmentUploadRecord | null = null;

	if ( ! helpers || ! helpers.uploadAudioForTranscription || ! helpers.requestTranscription ) {
		state.transcribing = false;
		updateTranscribeButtonState( state );
		return;
	}

	helpers
		.uploadAudioForTranscription( state, file )
		.then( ( record: AttachmentUploadRecord ) => {
			uploadedRecord = record;
			if ( ! record || typeof record.id === 'undefined' ) {
				throw new Error( 'Upload failed' );
			}

			// Note: DO NOT add transcription audio to attachmentLibrary.
			// These are temporary files for transcription only, not message attachments.
			// Adding them to attachmentLibrary causes them to persist and be reused.

			// Add small delay to ensure WordPress has fully processed the uploaded file.
			return new Promise< AttachmentUploadRecord >( ( resolve ) => {
				setTimeout( () => {
					resolve( record );
				}, 150 );
			} );
		} )
		.then( ( record: AttachmentUploadRecord ) => {
			return helpers.requestTranscription!( state, record );
		} )
		.then( ( response: unknown ) => {
			const result = extractTranscriptionResult( response );
			if ( helpers.insertTranscriptionResult ) {
				helpers.insertTranscriptionResult( state, result, uploadedRecord || file );
			}

			let label = '';
			if ( uploadedRecord && uploadedRecord.name ) {
				label = uploadedRecord.name;
			} else if ( file && file.name ) {
				label = file.name;
			}

			if ( helpers && helpers.setStatus && helpers.getString ) {
				const messageLabel: string =
					label || helpers.getString( 'transcribeAudio', 'Transcribe audio' );
				const formatString = ( template: string, value: string ): string =>
					template.replace( '%s', value );
				const message = formatString(
					helpers.getString( 'transcriptionSuccess', 'Inserted transcription from "%s".' ),
					messageLabel,
				);
				helpers.setStatus( state.container, message );
			}
		} )
		.catch( ( error: Record< string, unknown > & Error ) => {
			let errorMessage: string =
				helpers.getString( 'transcriptionError', 'The transcription request failed. Please try again.' );

			if ( error && ( error as Record< string, unknown > ).status === 404 ) {
				errorMessage = helpers.getString(
					'transcriptionEndpointNotFound',
					'Transcription service is temporarily unavailable. Please try again later.',
				);
			} else if ( error && error.message === 'Tools endpoint unavailable' ) {
				errorMessage = helpers.getString(
					'transcriptionNotConfigured',
					'Transcription is not properly configured. Please contact support.',
				);
			}

			if ( helpers && helpers.setStatus ) {
				helpers.setStatus( state.container, errorMessage );
			}

			if ( window.console && console.error ) {
				console.error( 'Transcription failed', {
					error,
					message: error ? error.message : 'Unknown error',
					status: error ? ( error as Record< string, unknown > ).status : undefined,
				} );
			}
		} )
		.finally( () => {
			state.transcribing = false;
			updateTranscribeButtonState( state );
		} );
}

/**
 * Extract transcription result from a response body.
 */
export function extractTranscriptionResult( body: unknown ): unknown {
	if ( ! body || typeof body !== 'object' ) {
		return null;
	}

	if ( Object.prototype.hasOwnProperty.call( body as Record< string, unknown >, 'result' ) ) {
		return ( body as Record< string, unknown > ).result;
	}

	return body;
}

// ═════════════════════════════════════════════════════════════════════
// Audio Translation
// ═════════════════════════════════════════════════════════════════════

/**
 * Handle translate button click event.
 */
export function handleTranslateButtonClick(
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || state.translating ) {
		return;
	}

	if ( state.isTranslateRecording ) {
		stopTranslateRecording( state, helpers );
		return;
	}

	if ( ! state.canUploadAttachments ) {
		return;
	}

	if ( supportsAudioRecording() ) {
		let shouldRecord = true;

		if ( state.translateInput && helpers && helpers.getString ) {
			const message = helpers.getString(
				'translateChooseSource',
				'Press OK to record with your microphone, or Cancel to choose an audio file.',
			);

			if ( typeof window !== 'undefined' && typeof window.confirm === 'function' ) {
				shouldRecord = window.confirm( message );
			}
		}

		if ( shouldRecord ) {
			startTranslateRecording( state, helpers );
			return;
		}
	}

	if ( state.translateInput && ! state.translateInput.disabled ) {
		state.translateInput.click();
	}
}

/**
 * Start audio recording for translation.
 */
export function startTranslateRecording(
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || ! supportsAudioRecording() ) {
		return;
	}

	state.translateRecordingShouldProcess = false;

	if ( state.translateButton ) {
		(state.translateButton as HTMLButtonElement).disabled = true;
	}

	navigator.mediaDevices
		.getUserMedia( { audio: true } )
		.then( ( stream: MediaStream ) => {
			state.translateRecordingStream = stream;
			state.translateRecordedChunks = [];

			try {
				state.translateMediaRecorder = new MediaRecorder( stream );
			} catch {
				stopTranslateRecordingStream( state );
				if ( helpers && helpers.setStatus && helpers.getString ) {
					helpers.setStatus(
						state.container,
						helpers.getString(
							'recordingError',
							'Could not access your microphone. Please allow access or upload an audio file instead.',
						),
					);
				}
				updateTranslateButtonState( state );
				return;
			}

			if ( ! state.translateMediaRecorder ) {
				stopTranslateRecordingStream( state );
				updateTranslateButtonState( state );
				return;
			}

			state.translateRecordingShouldProcess = true;

			state.translateMediaRecorder.addEventListener( 'dataavailable', ( event: BlobEvent ) => {
				if ( event && event.data && event.data.size ) {
					state.translateRecordedChunks.push( event.data );
				}
			} );

			state.translateMediaRecorder.addEventListener( 'stop', () => {
				const chunks: Blob[] = state.translateRecordedChunks || [];
				const mimeType: string =
					state.translateMediaRecorder && state.translateMediaRecorder.mimeType
						? state.translateMediaRecorder.mimeType
						: 'audio/webm';
				let baseMimeType: string =
					typeof mimeType === 'string' ? mimeType.split( ';' )[ 0 ] : '';
				if ( ! baseMimeType && typeof mimeType === 'string' ) {
					baseMimeType = mimeType;
				}

				stopTranslateRecordingStream( state );
				setTranslateRecordingState( state, false, helpers );

				if ( ! state.translateRecordingShouldProcess ) {
					state.translateRecordedChunks = [];
					updateTranslateButtonState( state );
					return;
				}

				if ( ! chunks || ! chunks.length ) {
					if ( helpers && helpers.setStatus && helpers.getString ) {
						helpers.setStatus(
							state.container,
							helpers.getString( 'voiceChatNoData', 'No audio was recorded.' ),
						);
					}
					updateTranslateButtonState( state );
					return;
				}

				const blob = new Blob( chunks, { type: baseMimeType || 'audio/webm' } );
				state.translateRecordedChunks = [];

				processTranslateAudio( state, blob, helpers );
			} );

			state.translateMediaRecorder.start();
			state.translateRecordingShouldProcess = true;
			setTranslateRecordingState( state, true, helpers );
		} )
		.catch( () => {
			if ( helpers && helpers.setStatus && helpers.getString ) {
				helpers.setStatus(
					state.container,
					helpers.getString(
						'recordingError',
						'Could not access your microphone. Please allow access or upload an audio file instead.',
					),
				);
			}
			updateTranslateButtonState( state );
		} );
}

/**
 * Stop translate recording.
 */
export function stopTranslateRecording(
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state ) {
		return;
	}

	setTranslateRecordingState( state, false, helpers );

	if ( state.translateMediaRecorder && state.translateMediaRecorder.state !== 'inactive' ) {
		state.translateMediaRecorder.stop();
	} else {
		stopTranslateRecordingStream( state );
		updateTranslateButtonState( state );
	}
}

/**
 * Stop translate recording stream and release media tracks.
 */
export function stopTranslateRecordingStream(
	state: AudioServiceState | null | undefined,
): void {
	if ( ! state || ! state.translateRecordingStream ) {
		return;
	}

	const tracks: MediaStreamTrack[] = state.translateRecordingStream.getTracks
		? state.translateRecordingStream.getTracks()
		: [];
	tracks.forEach( ( track: MediaStreamTrack ) => {
		try {
			track.stop();
		} catch {
			// Ignore stop errors.
		}
	} );

	state.translateRecordingStream = null;
}

/**
 * Set translate recording state and update UI.
 */
export function setTranslateRecordingState(
	state: AudioServiceState | null | undefined,
	recording: boolean,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state ) {
		return;
	}

	state.isTranslateRecording = !! recording;

	const button = state.translateButton;
	if ( button && button.classList ) {
		if ( state.isTranslateRecording ) {
			button.classList.add( TRANSLATE_RECORDING_CLASS );
		} else {
			button.classList.remove( TRANSLATE_RECORDING_CLASS );
		}
	}

	if ( button && helpers && helpers.getString ) {
		const label = state.isTranslateRecording
			? helpers.getString( 'stopRecording', 'Stop recording' )
			: helpers.getString( 'translateAudio', 'Translate audio' );
		button.setAttribute( 'aria-label', label );
		button.setAttribute( 'title', label );
	}

	if ( state.container && helpers && helpers.setStatus && helpers.getString ) {
		if ( state.isTranslateRecording ) {
			helpers.setStatus(
				state.container,
				helpers.getString( 'recording', 'Recording… tap to stop.' ),
			);
		} else if ( ! state.translating && ! state.busy ) {
			helpers.setStatus( state.container, '' );
		}
	}
}

/**
 * Update translate button state based on chat state.
 */
export function updateTranslateButtonState(
	state: AudioServiceState | null | undefined,
): void {
	if ( ! state ) {
		return;
	}

	const button = state.translateButton;
	const input = state.translateInput;

	const canUse = !! state.canUploadAttachments;
	let disabled = ! canUse || state.busy || state.uploading > 0 || state.translating;

	if ( state.isTranslateRecording ) {
		disabled = false;
	}

	if ( button ) {
		(button as HTMLButtonElement).disabled = disabled;

		if ( ! canUse ) {
			button.hidden = true;
		} else {
			button.hidden = false;
		}
	}

	if ( input ) {
		input.disabled =
			! canUse ||
			state.busy ||
			state.uploading > 0 ||
			state.translating ||
			state.isTranslateRecording;
	}
}

/**
 * Handle translate file input change.
 */
export function handleTranslateFileSelection(
	event: Event | null | undefined,
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! event || ! event.target || ! state || state.translating ) {
		return;
	}

	const files: FileList | null = ( event.target as HTMLInputElement ).files;
	if ( ! files || ! files.length ) {
		return;
	}

	const file: File = files[ 0 ];
	( event.target as HTMLInputElement ).value = '';

	if ( file.size > MAX_TRANSCRIBE_BYTES ) {
		if ( helpers && helpers.setStatus && helpers.getString ) {
			helpers.setStatus(
				state.container,
				helpers.getString(
					'translationFileTooLarge',
					'The selected audio file is too large. Please choose a file under 25MB.',
				),
			);
		}
		return;
	}

	processTranslateAudio( state, file, helpers );
}

/**
 * Process audio for translation.
 */
export function processTranslateAudio(
	state: AudioServiceState | null | undefined,
	blob: Blob | File | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || ! blob || state.translating ) {
		return;
	}

	if ( blob.size > MAX_TRANSCRIBE_BYTES ) {
		if ( helpers && helpers.setStatus && helpers.getString ) {
			helpers.setStatus(
				state.container,
				helpers.getString(
					'translationFileTooLarge',
					'The selected audio file is too large. Please choose a file under 25MB.',
				),
			);
		}
		updateTranslateButtonState( state );
		return;
	}

	state.translating = true;
	updateTranslateButtonState( state );

	if ( helpers && helpers.setStatus && helpers.getString ) {
		helpers.setStatus( state.container, helpers.getString( 'translating', 'Translating audio…' ) );
	}

	const file: File =
		blob instanceof File
			? blob
			: new File( [ blob ], 'audio-' + Date.now() + '.webm', {
					type: blob.type || 'audio/webm',
					lastModified: Date.now(),
			  } );

	let uploadedRecord: AttachmentUploadRecord | null = null;

	if ( ! helpers || ! helpers.uploadAudioForTranscription || ! helpers.requestTranscription ) {
		state.translating = false;
		updateTranslateButtonState( state );
		return;
	}

	helpers
		.uploadAudioForTranscription( state, file )
		.then( ( record: AttachmentUploadRecord ) => {
			uploadedRecord = record;
			if ( ! record || typeof record.id === 'undefined' ) {
				throw new Error( 'Upload failed' );
			}

			// Note: DO NOT add translation audio to attachmentLibrary.
			// These are temporary files for translation only, not message attachments.
			// Adding them to attachmentLibrary causes them to persist and be reused.

			// Add small delay to ensure WordPress has fully processed the uploaded file.
			return new Promise< AttachmentUploadRecord >( ( resolve ) => {
				setTimeout( () => {
					resolve( record );
				}, 150 );
			} );
		} )
		.then( ( record: AttachmentUploadRecord ) => {
			// Request translation (translate=true).
			return helpers.requestTranscription!( state, record, true );
		} )
		.then( ( response: unknown ) => {
			const result = extractTranscriptionResult( response ) as Record< string, unknown > | null;

			if ( ! result || ! result.text || ! ( result.text as string ).trim() ) {
				throw new Error( 'No text translated' );
			}

			const translatedText: string = ( result.text as string ).trim();
			insertTranslatedText( state, translatedText, helpers );

			if ( helpers && helpers.setStatus && helpers.getString ) {
				const fileName: string =
					uploadedRecord && uploadedRecord.filename ? uploadedRecord.filename : 'audio file';
				const successMessage: string = helpers.getString(
					'translationSuccess',
					'Inserted translation from "%s".',
				);
				helpers.setStatus( state.container, successMessage.replace( '%s', fileName ) );
			}
		} )
		.catch( ( error: Record< string, unknown > & Error ) => {
			let errorMessage: string =
				helpers.getString( 'translationError', 'The translation request failed. Please try again.' );

			if ( error && ( error as Record< string, unknown > ).status === 404 ) {
				errorMessage = helpers.getString(
					'translationEndpointNotFound',
					'Translation service is temporarily unavailable. Please try again later.',
				);
			} else if (
				error &&
				( error.message === 'Tools endpoint unavailable' ||
					error.message === 'Could not locate transcription tool' )
			) {
				errorMessage = helpers.getString(
					'translationNotConfigured',
					'Translation is not properly configured. Please contact support.',
				);
			}

			if ( helpers && helpers.setStatus ) {
				helpers.setStatus( state.container, errorMessage );
			}

			if ( window.console && console.error ) {
				console.error( 'Translation failed', {
					error,
					message: error ? error.message : 'Unknown error',
					status: error ? ( error as Record< string, unknown > ).status : undefined,
				} );
			}
		} )
		.finally( () => {
			state.translating = false;
			updateTranslateButtonState( state );
		} );
}

/**
 * Insert translated text into the chat textarea.
 */
export function insertTranslatedText(
	state: AudioServiceState | null | undefined,
	text: string | null | undefined,
	_helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || ! state.textarea || ! text ) {
		return;
	}

	const trimmedText = text.trim();
	if ( ! trimmedText ) {
		return;
	}

	const existing: string = state.textarea.value || '';
	const trimmedExisting = existing.replace( /\s+$/, '' );
	const newValue: string = trimmedExisting ? trimmedExisting + '\n\n' + trimmedText : trimmedText;

	state.textarea.value = newValue;
	state.textarea.focus();

	try {
		const caret = newValue.length;
		state.textarea.setSelectionRange( caret, caret );
	} catch {
		// Ignore selection errors.
	}
}

// ═════════════════════════════════════════════════════════════════════
// Voice Activity Detection (VAD)
// ═════════════════════════════════════════════════════════════════════

/**
 * Initialize VAD monitoring for voice chat recording.
 *
 * Uses Web Audio API to analyze audio levels in real-time.
 */
export function initVoiceActivityDetection(
	state: AudioServiceState | null | undefined,
	stream: MediaStream | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || ! stream ) {
		return;
	}

	// Check if VAD is enabled in WordPress settings.
	const vadEnabled: boolean =
		state.config && typeof state.config.vadEnabled !== 'undefined' ? state.config.vadEnabled : true;

	if ( ! vadEnabled ) {
		if ( window.console && console.log ) {
			console.log( 'VAD: Voice Activity Detection disabled in settings' );
		}
		return;
	}

	// Get configurable VAD settings from WordPress.
	const silenceThreshold: number =
		state.config && state.config.vadSilenceThreshold
			? state.config.vadSilenceThreshold
			: VAD_DEFAULT_SILENCE_THRESHOLD_MS;

	const minSpeechDuration: number =
		state.config && state.config.vadMinSpeech
			? state.config.vadMinSpeech
			: VAD_DEFAULT_MIN_SPEECH_DURATION_MS;

	const audioThreshold: number =
		state.config && typeof state.config.vadAudioThreshold !== 'undefined'
			? state.config.vadAudioThreshold
			: VAD_DEFAULT_AUDIO_LEVEL_THRESHOLD;

	try {
		const AudioContextCtor: typeof AudioContext | undefined =
			window.AudioContext ||
			( window as Window & { webkitAudioContext?: typeof AudioContext } ).webkitAudioContext;

		if ( ! AudioContextCtor ) {
			// VAD not supported; fall back to manual mode.
			return;
		}

		state.vadAudioContext = new AudioContextCtor();
		state.vadAnalyser = state.vadAudioContext.createAnalyser();
		state.vadAnalyser.fftSize = 2048;
		state.vadAnalyser.smoothingTimeConstant = 0.8;

		const source = state.vadAudioContext.createMediaStreamSource( stream );
		source.connect( state.vadAnalyser );

		// Initialize VAD state with configurable thresholds.
		state.vadSilenceStart = null;
		state.vadSpeechStart = Date.now();
		state.vadLastSpeechTime = Date.now();
		state.vadEnabled = true;
		state.vadSilenceThreshold = silenceThreshold;
		state.vadMinSpeechDuration = minSpeechDuration;
		state.vadAudioThreshold = audioThreshold;

		// Start monitoring audio levels.
		state.vadMonitorInterval = setInterval( () => {
			checkVoiceActivity( state, helpers );
		}, VAD_CHECK_INTERVAL_MS );

		if ( window.console && console.log ) {
			console.log( 'VAD: Voice Activity Detection initialized', {
				silenceThreshold: silenceThreshold + 'ms',
				minSpeechDuration: minSpeechDuration + 'ms',
				audioThreshold: audioThreshold + 'dB',
			} );
		}
	} catch ( error: unknown ) {
		if ( window.console && console.warn ) {
			console.warn( 'VAD: Could not initialize Voice Activity Detection', error );
		}
		// Continue without VAD.
	}
}

/**
 * Check voice activity and trigger auto-stop on silence.
 */
export function checkVoiceActivity(
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || ! state.vadEnabled || ! state.vadAnalyser || ! state.isVoiceChatRecording ) {
		return;
	}

	try {
		const bufferLength: number = state.vadAnalyser.frequencyBinCount;
		const dataArray = new Uint8Array( bufferLength );
		state.vadAnalyser.getByteFrequencyData( dataArray );

		let sum = 0;
		for ( let i = 0; i < bufferLength; i++ ) {
			sum += dataArray[ i ];
		}
		const average = sum / bufferLength;

		const dB = 20 * Math.log10( average / 255 );

		const now = Date.now();
		const speechDuration = now - state.vadSpeechStart;

		const audioThreshold: number =
			state.vadAudioThreshold || VAD_DEFAULT_AUDIO_LEVEL_THRESHOLD;
		const silenceThreshold: number =
			state.vadSilenceThreshold || VAD_DEFAULT_SILENCE_THRESHOLD_MS;
		const minSpeechDuration: number =
			state.vadMinSpeechDuration || VAD_DEFAULT_MIN_SPEECH_DURATION_MS;

		const isSpeech: boolean = dB > audioThreshold;

		if ( isSpeech ) {
			// Speech detected.
			state.vadLastSpeechTime = now;
			state.vadSilenceStart = null;

			// Update UI to show "listening" state.
			if ( state.voiceChatButton && state.voiceChatButton.classList ) {
				state.voiceChatButton.classList.add( VOICE_CHAT_LISTENING_CLASS );
			}
		} else {
			// Silence detected.
			if ( state.vadSilenceStart === null ) {
				state.vadSilenceStart = now;
			}

			const silenceDuration = now - state.vadSilenceStart;

			// Remove "listening" class during silence.
			if ( state.voiceChatButton && state.voiceChatButton.classList ) {
				state.voiceChatButton.classList.remove( VOICE_CHAT_LISTENING_CLASS );
			}

			// Check if we should auto-stop.
			if ( speechDuration >= minSpeechDuration && silenceDuration >= silenceThreshold ) {
				if ( window.console && console.log ) {
					console.log( 'VAD: Auto-stopping after ' + silenceDuration + 'ms of silence' );
				}

				// Auto-stop recording.
				stopVoiceActivityDetection( state );
				stopVoiceChatRecording( state, helpers );
			}
		}
	} catch ( error: unknown ) {
		if ( window.console && console.error ) {
			console.error( 'VAD: Error checking voice activity', error );
		}
	}
}

/**
 * Stop VAD monitoring and clean up resources.
 */
export function stopVoiceActivityDetection(
	state: AudioServiceState | null | undefined,
): void {
	if ( ! state ) {
		return;
	}

	state.vadEnabled = false;

	if ( state.vadMonitorInterval ) {
		clearInterval( state.vadMonitorInterval );
		state.vadMonitorInterval = null;
	}

	if ( state.vadAudioContext ) {
		try {
			state.vadAudioContext.close();
		} catch {
			// Ignore close errors.
		}
		state.vadAudioContext = null;
	}

	state.vadAnalyser = null;
	state.vadSilenceStart = null;
	state.vadSpeechStart = 0;
	state.vadLastSpeechTime = 0;

	// Remove listening class.
	if ( state.voiceChatButton && state.voiceChatButton.classList ) {
		state.voiceChatButton.classList.remove( VOICE_CHAT_LISTENING_CLASS );
	}
}

// ═════════════════════════════════════════════════════════════════════
// Voice Chat
// ═════════════════════════════════════════════════════════════════════

/**
 * Handle voice chat button click event.
 */
export function handleVoiceChatButtonClick(
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || state.voiceChatProcessing ) {
		return;
	}

	if ( state.isVoiceChatRecording ) {
		stopVoiceChatRecording( state, helpers );
		return;
	}

	if ( ! state.canUploadAttachments ) {
		return;
	}

	if ( supportsAudioRecording() ) {
		startVoiceChatRecording( state, helpers );
	} else if ( helpers && helpers.setStatus && helpers.getString ) {
		helpers.setStatus(
			state.container,
			helpers.getString( 'voiceChatUnavailable', 'Voice chat is not available in your browser.' ),
		);
	}
}

/**
 * Start voice chat recording.
 */
export function startVoiceChatRecording(
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || ! supportsAudioRecording() ) {
		return;
	}

	state.voiceChatShouldProcess = false;
	updateVoiceChatButtonState( state );

	navigator.mediaDevices
		.getUserMedia( { audio: true } )
		.then( ( stream: MediaStream ) => {
			state.voiceChatStream = stream;
			state.voiceChatChunks = [];

			try {
				state.voiceChatRecorder = new MediaRecorder( stream );
			} catch {
				stopVoiceChatStream( state );
				if ( helpers && helpers.setStatus && helpers.getString ) {
					helpers.setStatus(
						state.container,
						helpers.getString(
							'voiceChatRecorderError',
							'Could not start voice recording.',
						),
					);
				}
				updateVoiceChatButtonState( state );
				return;
			}

			state.voiceChatRecorder.addEventListener( 'dataavailable', ( event: BlobEvent ) => {
				if ( event.data && event.data.size > 0 ) {
					state.voiceChatChunks.push( event.data );
				}
			} );

			state.voiceChatRecorder.addEventListener( 'stop', () => {
				stopVoiceChatStream( state );

				if ( ! state.voiceChatShouldProcess ) {
					state.voiceChatChunks = [];
					updateVoiceChatButtonState( state );
					return;
				}

				if ( ! state.voiceChatChunks || ! state.voiceChatChunks.length ) {
					if ( helpers && helpers.setStatus && helpers.getString ) {
						helpers.setStatus(
							state.container,
							helpers.getString( 'voiceChatNoData', 'No audio was recorded.' ),
						);
					}
					updateVoiceChatButtonState( state );
					return;
				}

				const blob = new Blob( state.voiceChatChunks, { type: 'audio/webm' } );
				state.voiceChatChunks = [];

				processVoiceChatAudio( state, blob, helpers );
			} );

			state.voiceChatRecorder.start();
			state.voiceChatShouldProcess = true;
			setVoiceChatRecordingState( state, true, helpers );
			updateVoiceChatButtonState( state );

			// Initialize Voice Activity Detection for hands-free auto-stop.
			initVoiceActivityDetection( state, stream, helpers );
		} )
		.catch( () => {
			if ( helpers && helpers.setStatus && helpers.getString ) {
				helpers.setStatus(
					state.container,
					helpers.getString(
						'voiceChatPermissionDenied',
						'Microphone access was denied.',
					),
				);
			}
			updateVoiceChatButtonState( state );
		} );
}

/**
 * Stop voice chat recording.
 */
export function stopVoiceChatRecording(
	state: AudioServiceState | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state ) {
		return;
	}

	// Stop VAD monitoring.
	stopVoiceActivityDetection( state );

	setVoiceChatRecordingState( state, false, helpers );

	if ( state.voiceChatRecorder && state.voiceChatRecorder.state !== 'inactive' ) {
		state.voiceChatRecorder.stop();
	} else {
		stopVoiceChatStream( state );
		updateVoiceChatButtonState( state );
	}
}

/**
 * Stop voice chat stream and release media tracks.
 */
export function stopVoiceChatStream( state: AudioServiceState | null | undefined ): void {
	if ( ! state || ! state.voiceChatStream ) {
		return;
	}

	try {
		state.voiceChatStream.getTracks().forEach( ( track: MediaStreamTrack ) => {
			track.stop();
		} );
	} catch {
		// Ignore stop errors.
	}

	state.voiceChatStream = null;
}

/**
 * Set voice chat recording state and update UI.
 */
export function setVoiceChatRecordingState(
	state: AudioServiceState | null | undefined,
	recording: boolean,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state ) {
		return;
	}

	state.isVoiceChatRecording = !! recording;

	const button = state.voiceChatButton;
	if ( button && button.classList ) {
		if ( state.isVoiceChatRecording ) {
			button.classList.add( VOICE_CHAT_RECORDING_CLASS );
		} else {
			button.classList.remove( VOICE_CHAT_RECORDING_CLASS );
		}
	}

	if ( button && helpers && helpers.getString ) {
		const label = state.isVoiceChatRecording
			? helpers.getString( 'stopVoiceChat', 'Stop voice chat' )
			: helpers.getString( 'voiceChat', 'Voice chat' );
		button.setAttribute( 'aria-label', label );
		button.setAttribute( 'title', label );
	}

	if ( state.container && helpers && helpers.setStatus && helpers.getString ) {
		if ( state.isVoiceChatRecording ) {
			helpers.setStatus(
				state.container,
				helpers.getString( 'voiceChatRecording', 'Recording… tap to stop and send.' ),
			);
		} else if ( ! state.voiceChatProcessing && ! state.busy ) {
			helpers.setStatus( state.container, '' );
		}
	}
}

/**
 * Update voice chat button state based on chat state.
 */
export function updateVoiceChatButtonState(
	state: AudioServiceState | null | undefined,
): void {
	if ( ! state ) {
		return;
	}

	const button = state.voiceChatButton;

	const canUse = !! state.canUploadAttachments;
	let disabled = ! canUse || state.busy || state.uploading > 0 || state.voiceChatProcessing;

	if ( state.isVoiceChatRecording ) {
		disabled = false;
	}

	if ( button ) {
		(button as HTMLButtonElement).disabled = disabled;

		if ( ! canUse ) {
			button.hidden = true;
		} else {
			button.hidden = false;
		}
	}
}

/**
 * Process voice chat audio and send as a message.
 */
export function processVoiceChatAudio(
	state: AudioServiceState | null | undefined,
	blob: Blob | null | undefined,
	helpers: AudioHelpers | null | undefined,
): void {
	if ( ! state || ! blob || state.voiceChatProcessing ) {
		return;
	}

	if ( blob.size > MAX_TRANSCRIBE_BYTES ) {
		if ( helpers && helpers.setStatus && helpers.getString ) {
			helpers.setStatus(
				state.container,
				helpers.getString(
					'voiceChatFileTooLarge',
					'The recorded audio is too large. Please try a shorter message.',
				),
			);
		}
		updateVoiceChatButtonState( state );
		return;
	}

	state.voiceChatProcessing = true;
	updateVoiceChatButtonState( state );

	const button: HTMLElement | null = state.voiceChatButton;
	if ( button && button.classList ) {
		button.classList.add( VOICE_CHAT_PROCESSING_CLASS );
	}

	if ( helpers && helpers.setStatus && helpers.getString ) {
		helpers.setStatus(
			state.container,
			helpers.getString( 'voiceChatProcessing', 'Processing your voice message…' ),
		);
	}

	const file: File = new File( [ blob ], 'voice-chat-' + Date.now() + '.webm', {
		type: 'audio/webm',
		lastModified: Date.now(),
	} );

	if ( ! helpers || ! helpers.uploadAudioForTranscription || ! helpers.requestTranscription ) {
		state.voiceChatProcessing = false;
		updateVoiceChatButtonState( state );
		if ( button && button.classList ) {
			button.classList.remove( VOICE_CHAT_PROCESSING_CLASS );
		}
		return;
	}

	helpers
		.uploadAudioForTranscription( state, file )
		.then( ( record: AttachmentUploadRecord ) => {
			if ( ! record || typeof record.id === 'undefined' ) {
				throw new Error( 'Upload failed' );
			}

			// Note: DO NOT add voice chat audio to attachmentLibrary.
			// These are temporary files for transcription only, not message attachments.
			// Adding them to attachmentLibrary causes them to persist and be reused.

			// Add small delay to ensure WordPress has fully processed the uploaded file.
			return new Promise< AttachmentUploadRecord >( ( resolve ) => {
				setTimeout( () => {
					resolve( record );
				}, 150 );
			} );
		} )
		.then( ( record: AttachmentUploadRecord ) => {
			return helpers.requestTranscription!( state, record );
		} )
		.then( ( response: unknown ) => {
			const result = extractTranscriptionResult( response ) as Record< string, unknown > | null;

			if ( ! result || ! result.text || ! ( result.text as string ).trim() ) {
				throw new Error( 'No text transcribed' );
			}

			// Enable voice chat mode to auto-play the response.
			state.voiceChatModeActive = true;

			const transcribedText: string = ( result.text as string ).trim();

			// Set textarea value temporarily for form submission.
			if ( state.textarea ) {
				state.textarea.value = transcribedText;
			}

			if ( helpers && helpers.setStatus && helpers.getString ) {
				helpers.setStatus(
					state.container,
					helpers.getString( 'voiceChatSending', 'Sending your message…' ),
				);
			}

			// Trigger form submission which will clear the textarea.
			const form = state.container
				? state.container.querySelector( '.wp-mcp-ai-chat__form' )
				: null;
			if ( form ) {
				const submitEvent = new Event( 'submit', {
					bubbles: true,
					cancelable: true,
				} );
				form.dispatchEvent( submitEvent );
			} else if ( helpers && helpers.sendMessage ) {
				// Fallback to sendMessage helper if form not found.
				helpers.sendMessage( state );
				// Clear textarea after sending since sendMessage doesn't do it automatically.
				if ( state.textarea ) {
					state.textarea.value = '';
				}
			}
		} )
		.catch( ( error: Record< string, unknown > & Error ) => {
			let errorMessage: string = helpers.getString(
				'voiceChatError',
				'Voice chat failed. Please try again or type your message.',
			);

			if ( error && ( error as Record< string, unknown > ).status === 404 ) {
				errorMessage = helpers.getString(
					'voiceChatEndpointNotFound',
					'Voice chat service is temporarily unavailable. Please type your message instead.',
				);
			} else if ( error && error.message === 'Tools endpoint unavailable' ) {
				errorMessage = helpers.getString(
					'voiceChatNotConfigured',
					'Voice chat is not properly configured. Please type your message instead.',
				);
			}

			if ( helpers && helpers.setStatus ) {
				helpers.setStatus( state.container, errorMessage );
			}

			if ( window.console && console.error ) {
				console.error( 'Voice chat failed', {
					error,
					message: error ? error.message : 'Unknown error',
					status: error ? ( error as Record< string, unknown > ).status : undefined,
					endpoint: state.config ? state.config.toolsEndpoint : 'not configured',
				} );
			}
		} )
		.finally( () => {
			state.voiceChatProcessing = false;
			updateVoiceChatButtonState( state );

			if ( button && button.classList ) {
				button.classList.remove( VOICE_CHAT_PROCESSING_CLASS );
			}
		} );
}

// ── Backward-compatible global ───────────────────────────────────────

( window as unknown as Record< string, unknown > ).wpMcpAiChatAudio = {
	// Object URL management
	registerObjectUrl,
	revokeObjectUrls,

	// Capabilities
	supportsAudioRecording,

	// Speech synthesis (text-to-speech)
	attachSpeechButton,
	updateSpeechButtonIcon,
	stopSpeechPlayback,
	SPEECH_BUTTON_CLASS,
	SPEECH_ENABLED_CLASS,

	// Audio transcription (speech-to-text)
	handleTranscribeButtonClick,
	handleTranscribeFileSelection,
	updateTranscribeButtonState,
	extractTranscriptionResult,
	TRANSCRIBE_RECORDING_CLASS,
	MAX_TRANSCRIBE_BYTES,

	// Audio translation
	handleTranslateButtonClick,
	handleTranslateFileSelection,
	updateTranslateButtonState,
	TRANSLATE_RECORDING_CLASS,

	// Voice chat
	handleVoiceChatButtonClick,
	updateVoiceChatButtonState,
	VOICE_CHAT_RECORDING_CLASS,
	VOICE_CHAT_PROCESSING_CLASS,
};
