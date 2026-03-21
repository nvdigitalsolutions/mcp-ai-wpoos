// Adaptation script: Convert WordPress audio + transcription services to standalone NPM package

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting chat-audio-service + chat-transcription-service for NPM distribution...\n');

// ─── 1. Read source files ────────────────────────────────────────────────────
console.log('   → Reading source files');
let audioCode         = fs.readFileSync(path.join(__dirname, 'chat-audio-service.js'), 'utf8');
let transcriptionCode = fs.readFileSync(path.join(__dirname, 'chat-transcription-service.js'), 'utf8');

// ─── 2. Strip IIFE wrappers ───────────────────────────────────────────────────
console.log('   → Stripping IIFE wrappers');

// Audio service: (function(window) { 'use strict'; … })(window);
// Step 1 – Remove opening line.
audioCode = audioCode.replace(/^\(function\(window\) \{\s*[\r\n]+\s*'use strict';\s*[\r\n]+/, '');
// Step 2 – Remove the "// Export public API\n\twindow.wpMcpAiChatAudio = { … };" block.
//           The block starts with the comment and ends before the outer IIFE close.
audioCode = audioCode.replace(/[\r\n]+\s*\/\/ Export public API[\r\n]+\s*window\.wpMcpAiChatAudio\s*=\s*\{[\s\S]*?\n\s*\};/, '');
// Step 3 – Remove the trailing IIFE close "}\n})(window);\n"
audioCode = audioCode.replace(/[\r\n]+\}\)\(window\);\s*$/, '');

// Transcription service: (function() { 'use strict'; … })();
// Step 1 – Remove opening line.
transcriptionCode = transcriptionCode.replace(/^\(function\(\) \{\s*[\r\n]+\s*'use strict';\s*[\r\n]+/, '');
// Step 2 – Remove the global assignment and IIFE close.
transcriptionCode = transcriptionCode.replace(
	/[\r\n]+\s*\/\/ Expose service globally[\s\S]*?window\.wpMcpAiChatTranscription\s*=\s*wpMcpAiChatTranscription;\s*[\r\n]+\}\)\(\);\s*$/,
	''
);

// ─── 3. Make WordPress CSS class constants configurable ───────────────────────
console.log('   → Making CSS class constants configurable via configure()');

// Turn const declarations into let so configure() can override them
const constToLet = [
	'SPEECH_BUTTON_CLASS',
	'SPEECH_ENABLED_CLASS',
	'SPEECH_ERROR_CLASS',
	'TRANSCRIBE_RECORDING_CLASS',
	'TRANSLATE_RECORDING_CLASS',
	'VOICE_CHAT_RECORDING_CLASS',
	'VOICE_CHAT_PROCESSING_CLASS',
	'VOICE_CHAT_LISTENING_CLASS',
];
constToLet.forEach(name => {
	audioCode = audioCode.replace(new RegExp(`const (${name} = )`), 'let $1');
});

// ─── 4. Add configure() to audio module ──────────────────────────────────────
const configureBlock = `
/**
 * Configure NV oOS audio service class names and tool names.
 *
 * @param {Object} options
 * @param {string} [options.speechButtonClass]          - CSS class for speech buttons
 * @param {string} [options.speechEnabledClass]         - CSS class on speech-enabled elements
 * @param {string} [options.speechErrorClass]           - CSS class on speech error state
 * @param {string} [options.transcribeRecordingClass]   - CSS class while transcribing
 * @param {string} [options.translateRecordingClass]    - CSS class while translating
 * @param {string} [options.voiceChatRecordingClass]    - CSS class while recording for voice chat
 * @param {string} [options.voiceChatProcessingClass]   - CSS class while processing voice chat
 * @param {string} [options.voiceChatListeningClass]    - CSS class while VAD is listening
 * @param {string} [options.speechToolName]             - Tool name for TTS API calls
 * @param {string} [options.transcribeToolName]         - Tool name for STT API calls
 */
function configure(options) {
	if (!options) return;
	if (options.speechButtonClass)        SPEECH_BUTTON_CLASS = options.speechButtonClass;
	if (options.speechEnabledClass)       SPEECH_ENABLED_CLASS = options.speechEnabledClass;
	if (options.speechErrorClass)         SPEECH_ERROR_CLASS = options.speechErrorClass;
	if (options.transcribeRecordingClass) TRANSCRIBE_RECORDING_CLASS = options.transcribeRecordingClass;
	if (options.translateRecordingClass)  TRANSLATE_RECORDING_CLASS = options.translateRecordingClass;
	if (options.voiceChatRecordingClass)  VOICE_CHAT_RECORDING_CLASS = options.voiceChatRecordingClass;
	if (options.voiceChatProcessingClass) VOICE_CHAT_PROCESSING_CLASS = options.voiceChatProcessingClass;
	if (options.voiceChatListeningClass)  VOICE_CHAT_LISTENING_CLASS = options.voiceChatListeningClass;
}

`;

// Insert configure() before the first function in audioCode
audioCode = audioCode.replace(
	/(\s*\/\/ Object URL registry)/,
	configureBlock + '$1'
);

// ─── 5. Remove residual window.console guards (replace with plain console) ─────
console.log('   → Normalising console calls');
audioCode = audioCode.replace(/if \(window\.console && console\.(error|warn|log)\)/g, 'if (console && console.$1)');
audioCode = audioCode.replace(/window\.console && console\./g, 'console && console.');

// ─── 6. Combine modules and add exports ───────────────────────────────────────
console.log('   → Combining modules and adding ES exports');

const combined = `${audioCode.trim()}

// ─── Transcription Service ──────────────────────────────────────────────────

${transcriptionCode.trim()}

// ─── ES Module exports ───────────────────────────────────────────────────────
export {
	// Configuration
	configure,

	// Object URL management
	registerObjectUrl,
	revokeObjectUrls,

	// Capabilities
	supportsAudioRecording,

	// Speech synthesis (TTS)
	attachSpeechButton,
	updateSpeechButtonIcon,
	stopSpeechPlayback,

	// Audio transcription (STT)
	handleTranscribeButtonClick,
	handleTranscribeFileSelection,
	updateTranscribeButtonState,
	extractTranscriptionResult,

	// Audio translation
	handleTranslateButtonClick,
	handleTranslateFileSelection,
	updateTranslateButtonState,

	// Voice chat
	handleVoiceChatButtonClick,
	updateVoiceChatButtonState,

	// Transcription service object
	wpMcpAiChatTranscription,

	// Constants (read-only references; use configure() to change defaults)
	SPEECH_BUTTON_CLASS,
	SPEECH_ENABLED_CLASS,
	TRANSCRIBE_RECORDING_CLASS,
	TRANSLATE_RECORDING_CLASS,
	VOICE_CHAT_RECORDING_CLASS,
	VOICE_CHAT_PROCESSING_CLASS,
	MAX_TRANSCRIBE_BYTES,
};

export default {
	configure,
	registerObjectUrl,
	revokeObjectUrls,
	supportsAudioRecording,
	attachSpeechButton,
	updateSpeechButtonIcon,
	stopSpeechPlayback,
	handleTranscribeButtonClick,
	handleTranscribeFileSelection,
	updateTranscribeButtonState,
	extractTranscriptionResult,
	handleTranslateButtonClick,
	handleTranslateFileSelection,
	updateTranslateButtonState,
	handleVoiceChatButtonClick,
	updateVoiceChatButtonState,
	wpMcpAiChatTranscription,
	SPEECH_BUTTON_CLASS,
	SPEECH_ENABLED_CLASS,
	TRANSCRIBE_RECORDING_CLASS,
	TRANSLATE_RECORDING_CLASS,
	VOICE_CHAT_RECORDING_CLASS,
	VOICE_CHAT_PROCESSING_CLASS,
	MAX_TRANSCRIBE_BYTES,
};
`;

// ─── 7. Write dist ────────────────────────────────────────────────────────────
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) {
	fs.mkdirSync(distDir, { recursive: true });
}
fs.writeFileSync(path.join(distDir, 'nvoos-audio.js'), combined);
console.log('   → Generated dist/nvoos-audio.js');

// ─── 8. TypeScript definitions ────────────────────────────────────────────────
const dts = `/**
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
 * @param state  - Shared chat state object (must contain \`config\` with endpoint URLs)
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
`;

fs.writeFileSync(path.join(distDir, 'nvoos-audio.d.ts'), dts);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
