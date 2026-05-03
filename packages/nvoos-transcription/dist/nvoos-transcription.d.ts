/**
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
