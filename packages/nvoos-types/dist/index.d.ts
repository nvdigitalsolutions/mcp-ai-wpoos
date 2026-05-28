/**
 * Canonical shared types for the NV oOS TypeScript layer.
 *
 * This module is the single source of truth for types used across the
 * admin, chat, and service layers.  When converting JS → TS, import
 * from here rather than re-declaring ad-hoc shapes.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── AI Provider ──────────────────────────────────────────────────────

export type AiProvider =
	| 'openai'
	| 'gemini'
	| 'anthropic'
	| 'deepseek'
	| 'openrouter'
	| 'ollama'
	| 'lmstudio'
	| 'huggingface'
	| 'cloudflare'
	| 'baseten'
	| 'kimi'
	| 'nvidia';

// ── Tool types ───────────────────────────────────────────────────────

export type ToolRiskLevel = 'low' | 'medium' | 'high' | 'critical';

export interface ToolDefinition {
	id: string;
	name: string;
	description: string;
	risk: ToolRiskLevel;
	requiresApproval: boolean;
	devicePreview?: {
		previewText?: string;
		device_metadata?: Record< string, unknown >;
	};
	capabilityFlags?: string[];
}

// ── Chat Configuration ───────────────────────────────────────────────

/** The shape of `wpMcpAiChat` / `wpMcpAiAdmin` globals localised by PHP. */
export interface GlobalChatConfig {
	restUrl: string;
	/** Upload endpoint for file attachments. */
	uploadEndpoint: string;
	/** Endpoint to prepare (pre-process) an attachment. */
	prepareEndpoint: string;
	/** Endpoint for fetching stored messages (history). */
	messagesEndpoint: string;
	/** Endpoint for file-list / file-download operations. */
	filesEndpoint: string;
	/** Endpoint for tool execution. */
	toolsEndpoint: string;
	/** Endpoint for transcript CRUD. */
	transcriptsEndpoint: string;
	/** WordPress REST nonce (`X-WP-Nonce`). */
	nonce: string;
	/** Number of session entries per history page. Default 20. */
	historyPerPage: number;
	/** Maximum prior user/assistant messages sent in a chat request. Default 8. */
	maxHistoryMessages: number;
	/** Timeout in ms for async tool polling (SSE or fallback). Default 300 000. */
	asyncToolTimeout: number;
	/** UI strings keyed by slug (i18n). */
	strings: Record< string, string >;
}

/** Per-instance chat state (the `state` closure captured by `init()`). */
export interface ChatInstanceState {
	conversation: ChatMessage[];
	busy: boolean;
	uploading: boolean;
	config: GlobalChatConfig;
	canUploadAttachments: boolean;
	originalAssistantId: string;
	// DOM references.
	container: HTMLElement;
	textarea: HTMLTextAreaElement;
	messagesEl: HTMLElement;
	statusEl: HTMLElement;
	attachmentsContainer: HTMLElement;
	attachmentsList: HTMLElement;
	attachmentsHeader: HTMLElement;
	attachButton: HTMLElement;
	fileInput: HTMLInputElement;
	transcribeButton: HTMLElement | null;
	transcribeInput: HTMLInputElement | null;
	translateButton: HTMLElement | null;
	translateInput: HTMLInputElement | null;
	voiceChatButton: HTMLElement | null;
	toolShortcutsContainer: HTMLElement | null;
	toolShortcutsWrapper: HTMLElement | null;
	toolShortcutsToggle: HTMLElement | null;
	toolShortcutsExpanded: boolean;
	transcriptToggle: HTMLElement | null;
	historyToggle: HTMLElement | null;
	historyContainer: HTMLElement | null;
	historyStatus: string;
	historyList: HTMLElement | null;
	historyRefresh: HTMLElement | null;
	historyLoadMore: HTMLElement | null;
	transcriptExpanded: boolean;
	historyVisible: boolean;
	historyLoaded: boolean;
	historyLoading: boolean;
	historyLoadPromise: Promise< void > | null;
	historySessions: HistorySession[];
	historyCurrentPage: number;
	historyTotalSessions: number;
	historyPerPage: number;
	historySessionDetails: Record< string, HistorySessionDetail >;
	activeHistorySessionKey: string | null;
	pendingAttachments: PendingAttachment[];
	attachmentLibrary: Record< string, AttachmentRecord >;
	attachmentBlobUrls: Record< string, string >;
	validationNotice: unknown;
	speechCache: Record< string, SpeechCacheEntry >;
	activeSpeech: { button: HTMLElement; audio: HTMLAudioElement } | null;
	pendingCrawlTasks: Record< string, PendingCrawlTask >;
	pendingAsyncTools: Record< string, PendingAsyncTool >;
	transcribing: boolean;
	isRecording: boolean;
	recordingStream: MediaStream | null;
	recordedChunks: Blob[];
	mediaRecorder: MediaRecorder | null;
	recordingShouldProcess: boolean;
	pendingMessageBundle: MessageBundleEntry[] | null;
	messageBundleTimer: ReturnType< typeof setTimeout > | null;
	cptActionsContainer: HTMLElement | null;
	lastToolResults: Record< string, unknown >;
	embeddedClient: unknown;
}

// ── Chat Messages ────────────────────────────────────────────────────

export interface ChatMessage {
	role: 'user' | 'assistant' | 'system' | 'tool';
	content: string | ChatMessageContent[];
	tool_call_id?: string;
	name?: string;
	tool_calls?: ToolCall[];
	display?: ChatMessageDisplay;
	id?: string;
	_isContinuation?: boolean;
	_continuationToolCallId?: string;
	_jobId?: string;
}

export type ChatMessageContent =
	| ChatMessageContentText
	| ChatMessageContentImage;

export interface ChatMessageContentText {
	type: 'text';
	text: string;
}

export interface ChatMessageContentImage {
	type: 'image_url';
	image_url: { url: string };
}

export interface ChatMessageDisplay {
	bubbleType?: string;
	text?: string;
	attachments?: DisplayAttachment[];
}

export interface DisplayAttachment {
	url: string;
	label: string;
	downloadName?: string;
	meta?: Record< string, string >;
}

export interface ToolCall {
	id: string;
	type: 'function';
	function: {
		name: string;
		arguments: string;
	};
}

// ── SSE / Streaming ──────────────────────────────────────────────────

export interface SseEvent {
	type: string;
	data?: string;
	id?: string;
	retry?: number;
}

export interface StreamDelta {
	type: 'message_delta' | 'text_delta' | 'delta';
	delta?: string;
	text?: string;
	content?: string;
}

export interface ToolCallStarted {
	type: 'tool_call_started';
	id: string;
	name: string;
	arguments?: Record< string, unknown >;
}

export interface ToolCallCompleted {
	type: 'tool_call_completed';
	id: string;
	/** The raw tool result as returned by the PHP tool executor. */
	result?: ToolResult;
}

export interface SseStatusMessage {
	type: 'status' | 'thinking' | 'tool_execution' | 'error';
	message?: string;
	/** Unix timestamp in ms (client-side). */
	showTime?: number;
	startTime?: number;
	job_id?: string;
	tool_name?: string;
}

// ── Tool Execution ───────────────────────────────────────────────────

export interface ToolResult {
	success: boolean;
	data?: unknown;
	message?: string;
	error?: string;
	job_id?: string;
	status?: string;
	wasAsync?: boolean;
	hasCachedResult?: boolean;
}

export interface ToolExecutionPayload {
	tool: string;
	arguments: Record< string, unknown >;
	assistant_id?: string | number;
}

export interface ToolMessagePayload {
	role: 'tool';
	tool_call_id: string;
	name: string;
	content: string;
}

// ── Attachments ──────────────────────────────────────────────────────

export interface PendingAttachment {
	id: number;
	fileId: string;
	name: string;
	originalName?: string;
	url: string;
	mime: string;
	size: number;
	isImage: boolean;
}

export interface AttachmentRecord {
	id?: string;
	fileId?: string;
	name?: string;
	url?: string;
	mime?: string;
	size?: number;
	downloadName?: string;
	meta?: Record< string, string >;
	isImage?: boolean;
}

export interface AttachmentLibraryEntry {
	url: string;
	label: string;
	downloadName?: string;
	meta?: Record< string, string >;
}

// ── Speech ───────────────────────────────────────────────────────────

export interface SpeechCacheEntry {
	text: string;
	url: string;
	format?: string;
	attachmentId?: number;
	mimeType?: string;
	button?: HTMLElement;
	audio?: HTMLAudioElement;
}

// ── History ──────────────────────────────────────────────────────────

export interface HistorySession {
	session_key: string;
	assistant_id: string;
	message_count: number;
	created_at?: string;
	updated_at?: string;
}

export interface HistorySessionDetail {
	session_key: string;
	assistant_id: string;
	messages: ChatMessage[];
	message_count: number;
}

// ── Async / Background Jobs ──────────────────────────────────────────

export interface PendingAsyncTool {
	jobId: string;
	toolName: string;
	toolCallId: string;
	assistantId: string;
	status: string;
	hasMessage: boolean;
	state?: string;
	start?: number;
	timeout?: number;
	timer?: ReturnType< typeof setInterval >;
	sseConnection?: unknown;
}

export interface PendingCrawlTask {
	taskId: string;
	state: 'pending' | 'polling' | 'completed' | 'failed';
	pollDelay: number;
	timeout: number;
	start: number;
	timer?: ReturnType< typeof setInterval >;
	toolName: string;
}

// ── Message Bundling ─────────────────────────────────────────────────

export interface MessageBundleEntry {
	message: ChatMessage;
	previousConversationLength: number;
	pendingAttachments: PendingAttachment[];
	messageElement: HTMLElement;
	inputValue: string;
}

// ── Memory ───────────────────────────────────────────────────────────

export interface MemoryEvent {
	type: 'memory_event';
	action: 'store' | 'retrieve' | 'forget';
	data?: unknown;
}

// ── Agent / Multi-Agent ──────────────────────────────────────────────

export interface AgentStatus {
	name: string;
	role?: string;
	task?: string;
	status: 'active' | 'completed' | 'error';
}

export interface DelegationNotice {
	agent_name: string;
	task: string;
	status: 'active' | 'completed' | 'error';
}

// ── Consumer Overrides ────────────────────────────────────────────────

/**
 * Consumers can augment the NvOos global types in their own project:
 *
 * @example
 *   declare global {
 *     interface Window {
 *       wpMcpAiChat: import('@nvdigitalsolutions/nvoos-types').GlobalChatConfig;
 *     }
 *   }
 */

export {};
