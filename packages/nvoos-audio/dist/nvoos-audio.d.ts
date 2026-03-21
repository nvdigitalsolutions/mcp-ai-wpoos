/**
 * Browser audio I/O: TTS, STT, voice chat with VAD.
 * Zero external dependencies — uses only standard browser MediaRecorder / Web Audio APIs.
 * @package @nvdigitalsolutions/nvoos-audio
 */

export interface AudioConfig {
  /** CSS class for speech play/stop buttons. Default: 'wp-mcp-ai-speech-button' */
  speechButtonClass?: string;
  /** CSS class applied to speech-enabled elements. Default: 'wp-mcp-ai-speech-enabled' */
  speechEnabledClass?: string;
  /** CSS class applied to speech button on error. Default: 'wp-mcp-ai-speech-button--error' */
  speechErrorClass?: string;
  /** CSS class while recording for transcription. Default: 'wp-mcp-ai-chat__transcribe--recording' */
  transcribeRecordingClass?: string;
  /** CSS class while recording for translation. Default: 'wp-mcp-ai-chat__translate--recording' */
  translateRecordingClass?: string;
  /** CSS class while recording for voice chat. Default: 'wp-mcp-ai-chat__voice-chat--recording' */
  voiceChatRecordingClass?: string;
  /** CSS class while processing voice chat. Default: 'wp-mcp-ai-chat__voice-chat--processing' */
  voiceChatProcessingClass?: string;
  /** CSS class while VAD is listening. Default: 'wp-mcp-ai-chat__voice-chat--listening' */
  voiceChatListeningClass?: string;
}

/**
 * Configure CSS class names used by the audio service.
 * Call once before using any other functions.
 */
export declare function configure(options: AudioConfig): void;

/** Register a Blob object URL for deferred revocation. */
export declare function registerObjectUrl(url: string): void;

/** Revoke all registered object URLs (call on component unmount or page unload). */
export declare function revokeObjectUrls(): void;

/**
 * Returns true if the browser supports audio recording via MediaRecorder.
 */
export declare function supportsAudioRecording(): boolean;

/**
 * Attach a text-to-speech play/stop button to a message bubble element.
 * Clicking the button calls the configured TTS endpoint and plays back the audio.
 *
 * @param bubble - Host element that will receive the button
 * @param text   - Text to synthesise (falls back to bubble's textContent)
 * @param state  - Shared chat state object (must contain `config` with endpoint URLs)
 * @param helpers - Optional helper callbacks (getString, setStatus …)
 */
export declare function attachSpeechButton(
  bubble: HTMLElement,
  text: string | null | undefined,
  state: Record<string, unknown>,
  helpers?: Record<string, unknown>
): void;

/** Update the speech button's visual state ('idle' | 'loading' | 'playing'). */
export declare function updateSpeechButtonIcon(button: HTMLElement, stateName: string): void;

/** Stop ongoing TTS playback for a given button. */
export declare function stopSpeechPlayback(state: Record<string, unknown>, button: HTMLElement): void;

/** Handle transcribe button click — starts / stops MediaRecorder. */
export declare function handleTranscribeButtonClick(
  button: HTMLElement,
  state: Record<string, unknown>,
  helpers?: Record<string, unknown>
): void;

/** Handle file input change for audio transcription. */
export declare function handleTranscribeFileSelection(
  input: HTMLInputElement,
  state: Record<string, unknown>,
  helpers?: Record<string, unknown>
): void;

/** Update transcribe button visual state ('idle' | 'recording' | 'processing'). */
export declare function updateTranscribeButtonState(button: HTMLElement | null, stateName: string): void;

/** Extract plain transcription result text from a tool response object. */
export declare function extractTranscriptionResult(response: unknown): { text: string; language?: string; duration?: number; segments?: unknown[] } | null;

/** Handle translate button click — starts / stops MediaRecorder for translation. */
export declare function handleTranslateButtonClick(
  button: HTMLElement,
  state: Record<string, unknown>,
  helpers?: Record<string, unknown>
): void;

/** Handle file input change for audio translation. */
export declare function handleTranslateFileSelection(
  input: HTMLInputElement,
  state: Record<string, unknown>,
  helpers?: Record<string, unknown>
): void;

/** Update translate button visual state. */
export declare function updateTranslateButtonState(button: HTMLElement | null, stateName: string): void;

/** Handle voice chat button click — records then submits the chat form. */
export declare function handleVoiceChatButtonClick(
  button: HTMLElement,
  state: Record<string, unknown>,
  helpers?: Record<string, unknown>
): void;

/** Update voice chat button visual state ('idle' | 'recording' | 'processing' | 'listening'). */
export declare function updateVoiceChatButtonState(state: Record<string, unknown>): void;

/** Transcription service object (insertTranscriptionResult helper, etc.). */
export declare const wpMcpAiChatTranscription: {
  insertTranscriptionResult(
    state: Record<string, unknown>,
    result: Record<string, unknown>,
    record: unknown,
    formatDuration: (seconds: number) => string
  ): void;
  [key: string]: unknown;
};

export declare const SPEECH_BUTTON_CLASS: string;
export declare const SPEECH_ENABLED_CLASS: string;
export declare const TRANSCRIBE_RECORDING_CLASS: string;
export declare const TRANSLATE_RECORDING_CLASS: string;
export declare const VOICE_CHAT_RECORDING_CLASS: string;
export declare const VOICE_CHAT_PROCESSING_CLASS: string;
/** Maximum transcription upload size in bytes (25 MB). */
export declare const MAX_TRANSCRIBE_BYTES: number;

declare const _default: {
  configure: typeof configure;
  registerObjectUrl: typeof registerObjectUrl;
  revokeObjectUrls: typeof revokeObjectUrls;
  supportsAudioRecording: typeof supportsAudioRecording;
  attachSpeechButton: typeof attachSpeechButton;
  updateSpeechButtonIcon: typeof updateSpeechButtonIcon;
  stopSpeechPlayback: typeof stopSpeechPlayback;
  handleTranscribeButtonClick: typeof handleTranscribeButtonClick;
  handleTranscribeFileSelection: typeof handleTranscribeFileSelection;
  updateTranscribeButtonState: typeof updateTranscribeButtonState;
  extractTranscriptionResult: typeof extractTranscriptionResult;
  handleTranslateButtonClick: typeof handleTranslateButtonClick;
  handleTranslateFileSelection: typeof handleTranslateFileSelection;
  updateTranslateButtonState: typeof updateTranslateButtonState;
  handleVoiceChatButtonClick: typeof handleVoiceChatButtonClick;
  updateVoiceChatButtonState: typeof updateVoiceChatButtonState;
  wpMcpAiChatTranscription: typeof wpMcpAiChatTranscription;
  SPEECH_BUTTON_CLASS: string;
  SPEECH_ENABLED_CLASS: string;
  TRANSCRIBE_RECORDING_CLASS: string;
  TRANSLATE_RECORDING_CLASS: string;
  VOICE_CHAT_RECORDING_CLASS: string;
  VOICE_CHAT_PROCESSING_CLASS: string;
  MAX_TRANSCRIBE_BYTES: number;
};
export default _default;
