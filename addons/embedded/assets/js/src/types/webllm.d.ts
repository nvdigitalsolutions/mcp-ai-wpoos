/**
 * Type definitions for WebLLM integration
 *
 * Provides TypeScript interfaces for WebLLM engine, chat completions,
 * and tool calling functionality.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

/**
 * WebLLM Engine interface
 */
export interface WebLLMEngine {
	chat: {
		completions: {
			create(options: ChatCompletionOptions): Promise<ChatCompletion | AsyncIterableIterator<ChatCompletionChunk>>;
		};
	};
	unload(): Promise<void>;
	runtimeStatsText(): Promise<string>;
	interruptGenerate(): void;
	resetChat(): void;
}

/**
 * Chat completion options
 */
export interface ChatCompletionOptions {
	messages: Message[];
	temperature?: number;
	max_tokens?: number;
	top_p?: number;
	frequency_penalty?: number;
	presence_penalty?: number;
	stream?: boolean;
	tools?: Tool[];
	tool_choice?: 'auto' | 'none' | ToolChoice;
	stop?: string | string[];
	n?: number;
	logprobs?: boolean;
	top_logprobs?: number;
}

/**
 * Tool choice specification
 */
export interface ToolChoice {
	type: 'function';
	function: {
		name: string;
	};
}

/**
 * Chat message
 */
export interface Message {
	role: 'system' | 'user' | 'assistant' | 'tool';
	content: string | ContentPart[];
	name?: string;
	tool_calls?: ToolCall[];
	tool_call_id?: string;
}

/**
 * Content part for multimodal messages
 */
export interface ContentPart {
	type: 'text' | 'image_url';
	text?: string;
	image_url?: {
		url: string;
		detail?: 'auto' | 'low' | 'high';
	};
}

/**
 * Tool definition
 */
export interface Tool {
	type: 'function';
	function: FunctionDefinition;
}

/**
 * Function definition for tool calling
 */
export interface FunctionDefinition {
	name: string;
	description: string;
	parameters: JSONSchema;
}

/**
 * JSON Schema for function parameters
 */
export interface JSONSchema {
	type: 'object' | 'string' | 'number' | 'boolean' | 'array' | 'null';
	properties?: Record<string, JSONSchema>;
	required?: string[];
	items?: JSONSchema;
	enum?: any[];
	description?: string;
	default?: any;
	minimum?: number;
	maximum?: number;
	minLength?: number;
	maxLength?: number;
	pattern?: string;
	format?: string;
}

/**
 * Tool call in assistant message
 */
export interface ToolCall {
	id: string;
	type: 'function';
	function: {
		name: string;
		arguments: string; // JSON string
	};
}

/**
 * Chat completion response
 */
export interface ChatCompletion {
	id: string;
	object: 'chat.completion';
	created: number;
	model: string;
	choices: Choice[];
	usage: Usage;
	system_fingerprint?: string;
}

/**
 * Chat completion choice
 */
export interface Choice {
	index: number;
	message: Message;
	finish_reason: 'stop' | 'length' | 'tool_calls' | 'content_filter' | null;
	logprobs?: LogProbs | null;
}

/**
 * Chat completion chunk for streaming
 */
export interface ChatCompletionChunk {
	id: string;
	object: 'chat.completion.chunk';
	created: number;
	model: string;
	choices: ChunkChoice[];
	system_fingerprint?: string;
}

/**
 * Chunk choice for streaming
 */
export interface ChunkChoice {
	index: number;
	delta: {
		role?: 'assistant';
		content?: string;
		tool_calls?: ToolCallDelta[];
	};
	finish_reason: 'stop' | 'length' | 'tool_calls' | 'content_filter' | null;
	logprobs?: LogProbs | null;
}

/**
 * Tool call delta for streaming
 */
export interface ToolCallDelta {
	index: number;
	id?: string;
	type?: 'function';
	function?: {
		name?: string;
		arguments?: string; // Partial JSON string
	};
}

/**
 * Token usage information
 */
export interface Usage {
	prompt_tokens: number;
	completion_tokens: number;
	total_tokens: number;
}

/**
 * Log probabilities
 */
export interface LogProbs {
	content: TokenLogProb[] | null;
}

/**
 * Token log probability
 */
export interface TokenLogProb {
	token: string;
	logprob: number;
	bytes: number[] | null;
	top_logprobs: TopLogProb[];
}

/**
 * Top log probability
 */
export interface TopLogProb {
	token: string;
	logprob: number;
	bytes: number[] | null;
}

/**
 * Model initialization progress callback
 */
export interface ProgressCallback {
	(report: InitProgressReport): void;
}

/**
 * Model initialization progress report
 */
export interface InitProgressReport {
	progress: number;
	text: string;
	timeElapsed: number;
}

/**
 * WebLLM configuration options
 */
export interface WebLLMConfig {
	model: string;
	temperature?: number;
	max_tokens?: number;
	top_p?: number;
	frequency_penalty?: number;
	presence_penalty?: number;
}

/**
 * WordPress tool context for execution
 */
export interface WPToolContext {
	user_id?: number;
	capabilities?: string[];
	nonce?: string;
	assistant_id?: number;
}

/**
 * WordPress tool result
 */
export interface WPToolResult {
	success: boolean;
	data?: any;
	error?: string;
	message?: string;
}
