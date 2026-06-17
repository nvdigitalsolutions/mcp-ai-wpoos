// Adaptation script: Convert WordPress chat-transcription-service.js to a standalone NPM package.
// The source is already structured around (state, helpers) parameters — we just strip the IIFE,
// rename the public surface, replace WP-specific class names + DOM selectors with configurable
// defaults, and clean up legacy `window.console &&` guards.

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting chat-transcription-service.js for NPM distribution...\n');

const sourceFile = path.join(__dirname, 'chat-transcription-service.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Strip IIFE.
console.log('   → Converting from IIFE to ES module');
code = code.replace(/\(function\(\) \{\s*'use strict';/, '');

// Step 2: Drop the global export + IIFE close.
code = code.replace(
	/\s*\/\/ Expose service globally for chat\.js and other modules\s*\n\s*window\.wpMcpAiChatTranscription = wpMcpAiChatTranscription;\s*\n\s*\}\)\(\);\s*$/,
	''
);

// Step 3: Rename the public surface.
console.log('   → Renaming wpMcpAiChatTranscription → TranscriptionService');
code = code.replace(/const wpMcpAiChatTranscription =/, 'const TranscriptionService =');

// Step 4: Replace the hard-coded recording CSS class with a configurable lookup.
// We keep the property `TRANSCRIBE_RECORDING_CLASS` for back-compat but make it
// reflect _config so consumers can override it via configure().
console.log('   → Making CSS class name configurable');
code = code.replace(
	/TRANSCRIBE_RECORDING_CLASS: 'wp-mcp-ai-chat__transcribe--recording',/,
	"get TRANSCRIBE_RECORDING_CLASS() { return _config.recordingClass; },"
);

// Step 5: Replace the hard-coded data-attribute selector.
console.log('   → Making file-select-trigger selector configurable');
code = code.replace(
	/document\.querySelectorAll\('\[data-wp-mcp-ai-file-select-trigger\]'\)/,
	'document.querySelectorAll(_config.fileSelectTriggerSelector)'
);

// Step 6: Drop legacy console-presence guards (Node 18+ and all evergreen browsers
// always define `console`).
console.log('   → Removing legacy `window.console &&` guards');
code = code.replace(/if \(window\.console && console\.(error|warn|log)\) \{\s*/g, 'if (typeof console !== "undefined") {\n\t\t\t\t');

// Step 7: Tidy log prefix to match package identity.
code = code.replace(/\[NV oOS\] /g, '[nvoos-transcription] ');

// Step 8: Prepend module-level configuration + configure().
const configBlock = `const _config = {
	/**
	 * CSS class added to the transcribe button while recording.
	 * Default mirrors the upstream WordPress plugin for drop-in compatibility.
	 */
	recordingClass: 'nvoos-transcribe--recording',

	/**
	 * Selector used by handleTranscribeButtonClick() to decide whether file-select
	 * fallback should be skipped (i.e. when no file inputs are wired up). Provide
	 * a selector that matches your file-trigger buttons.
	 */
	fileSelectTriggerSelector: '[data-nvoos-file-select-trigger]'
};

/**
 * Configure the transcription service.
 *
 * @param {Object} options
 * @param {string} [options.recordingClass]            CSS class added to the transcribe button while recording.
 * @param {string} [options.fileSelectTriggerSelector] Selector for file-select trigger buttons.
 */
export function configure(options) {
	options = options || {};
	if (options.recordingClass)            _config.recordingClass = options.recordingClass;
	if (options.fileSelectTriggerSelector) _config.fileSelectTriggerSelector = options.fileSelectTriggerSelector;
}

`;

// Step 9: ES module exports.
const exportBlock = `

// ES Module exports
export { TranscriptionService };
export default TranscriptionService;
`;

const finalCode = configBlock + code.trim() + exportBlock;

const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) fs.mkdirSync(distDir, { recursive: true });

fs.writeFileSync(path.join(distDir, 'nvoos-transcription.js'), finalCode);
console.log('   → Generated dist/nvoos-transcription.js');

const dtsContent = `/**
 * MediaRecorder-based audio recording + tool-call transcription pipeline.
 * @package @nvdigitalsolutions/nvoos-transcription
 */

export interface TranscriptionConfig {
	/** CSS class added to the transcribe button while recording. */
	recordingClass?: string;
	/** Selector for file-select trigger buttons. */
	fileSelectTriggerSelector?: string;
}

/** Caller-provided chat-state shape. The service mutates these properties. */
export interface TranscriptionState {
	transcribeButton?: HTMLElement | null;
	transcribeInput?: HTMLInputElement | null;
	textarea?: HTMLTextAreaElement | null;
	container?: HTMLElement | null;

	isRecording?: boolean;
	transcribing?: boolean;
	uploading?: number;
	busy?: boolean;
	canUploadAttachments?: boolean;

	mediaRecorder?: MediaRecorder | null;
	recordingStream?: MediaStream | null;
	recordedChunks?: Blob[];
	recordingShouldProcess?: boolean;

	/** Endpoint config used by upload + transcription requests. */
	config?: {
		filesEndpoint?: string;
		toolsEndpoint?: string;
		nonce?: string;
		assistantId?: string | number;
		[key: string]: any;
	};

	[key: string]: any;
}

export interface TranscriptionHelpers {
	getString?: (key: string, fallback?: string) => string;
	setStatus?: (container: HTMLElement | null | undefined, message: string) => void;
	transcribeAudioFile?: (state: TranscriptionState, file: File, helpers: TranscriptionHelpers) => void;
	stopRecordingStream?: (state: TranscriptionState) => void;
	setTranscribeRecordingState?: (state: TranscriptionState, recording: boolean, getString?: any, setStatus?: any) => void;
	updateTranscribeButtonState?: (state: TranscriptionState) => void;
	startTranscribeRecording?: (state: TranscriptionState, helpers: TranscriptionHelpers) => void;
	[key: string]: any;
}

export interface TranscriptionServiceShape {
	readonly TRANSCRIBE_TOOL_NAME: string;
	readonly TRANSCRIBE_RECORDING_CLASS: string;
	readonly MAX_TRANSCRIBE_BYTES: number;

	supportsAudioRecording(): boolean;
	stopRecordingStream(state: TranscriptionState): void;
	setTranscribeRecordingState(state: TranscriptionState, recording: boolean, getString?: any, setStatus?: any): void;
	updateTranscribeButtonState(state: TranscriptionState): void;
	handleTranscribeButtonClick(state: TranscriptionState, helpers: TranscriptionHelpers): void;
	startTranscribeRecording(state: TranscriptionState, helpers: TranscriptionHelpers): void;
	stopTranscribeRecording(state: TranscriptionState, helpers: TranscriptionHelpers): void;
	handleTranscribeFileSelection(event: Event, state: TranscriptionState, transcribeAudioFile: (state: TranscriptionState, file: File) => void): void;
	transcribeAudioFile(state: TranscriptionState, file: File, helpers: TranscriptionHelpers): void;
	uploadAudioForTranscription(state: TranscriptionState, file: File, helpers: TranscriptionHelpers): Promise<any>;
	requestTranscription(state: TranscriptionState, record: any, helpers: TranscriptionHelpers): Promise<any>;
	extractTranscriptionResult(body: any): any;
	insertTranscriptionResult(state: TranscriptionState, result: any, record: any, formatDuration: (s: number) => string): void;
}

export declare function configure(options: TranscriptionConfig): void;
export declare const TranscriptionService: TranscriptionServiceShape;
export default TranscriptionService;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-transcription.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
