/**
 * NV oOS SSE → AI SDK Data Stream Protocol adapter.
 *
 * Background
 * ----------
 * `@ai-sdk/react`'s `useChat` hook expects responses on its configured `api`
 * URL to follow the **AI SDK Data Stream Protocol** (newline-delimited typed
 * JSON: `0:"text"`, `2:[toolCall]`, `8:[annotation]`, `e:{finish}`,
 * `d:{data}`).
 *
 * NV oOS today emits an OpenAI-compatible SSE format on `mcp-ai/v1/chat-client`:
 *
 *     event: message
 *     data: { "choices": [{ "delta": { "content": "Hello" } }] }
 *
 *     event: message
 *     data: { "type": "content_block_delta", "delta": { "type": "thinking_delta", "thinking": "..." } }
 *
 *     event: message
 *     data: { "type": "thinking", "message": "Processing…" }
 *
 *     event: error
 *     data: { "code": "...", "message": "..." }
 *
 *     data: [DONE]
 *
 * Per the addon plan (§3, option A), we ship a **client-side adapter** for
 * v1: a custom `fetch` that POSTs to the existing endpoint, reads the
 * native SSE stream, and emits a `Response` whose body is a Data Stream
 * Protocol `ReadableStream<Uint8Array>` that `useChat` can consume with
 * `streamProtocol: 'data'`.
 *
 * v2 may graduate to server-side native Data Stream emission (option B);
 * keeping the translation client-side here means zero PHP changes and full
 * backward compatibility with every other NV oOS chat client.
 *
 * References
 * ----------
 * - AI SDK Data Stream Protocol:
 *   https://sdk.vercel.ai/docs/ai-sdk-ui/stream-protocol#data-stream-protocol
 * - NV oOS SSE frames: see `WP_MCP_AI_REST_Chat_Controller::handle_chat_client_request()`
 */

export interface ChatFetchOptions {
	/** Absolute URL of the NV oOS chat-client REST route. */
	endpoint: string;
	/** WP REST nonce (`X-WP-Nonce`) — empty for guest sessions. */
	nonce: string;
	/** Optional NV oOS assistant post ID. */
	assistantId: number;
	/** When true, sends an `X-WP-MCP-AI-Guest` header instead of the nonce. */
	guest: boolean;
	/** When true, forwards allow_sensitive_tools to the chat endpoint. */
	allowSensitiveTools?: boolean;
}

interface NvOosFrame {
	type?: string;
	delta?: string | { content?: string; reasoning_content?: string; thinking?: string; type?: string };
	text?: string;
	content?: string;
	id?: string;
	name?: string;
	arguments?: unknown;
	result?: unknown;
	choices?: Array< { delta?: { content?: string; reasoning_content?: string; thinking?: string } } >;
	code?: string;
	message?: string;
	/** Server-side tool result entries from the agentic loop final payload. */
	tool_results?: Array< {
		role?: string;
		name?: string;
		tool_call_id?: string;
		content?: unknown;
		usage?: unknown;
		cost?: unknown;
		capability_flags?: string[];
		[ k: string ]: unknown;
	} >;
	[ k: string ]: unknown;
}

/**
 * Encode a single AI SDK Data Stream chunk.
 *
 * Format: `<typeId>:<JSON-encoded payload>\n`.
 */
function encodeChunk( typeId: string, payload: unknown ): Uint8Array {
	const line = `${ typeId }:${ JSON.stringify( payload ) }\n`;
	return new TextEncoder().encode( line );
}

/**
 * Emit tool call start + result events for each tool_result entry so that
 * useChat populates `toolInvocations` on the assistant message.  This
 * allows `ToolCallCard` (in MessageView.tsx) to render rich content
 * (images, videos, files, charts) instead of the raw-JSON `AnnotationPill`
 * that type-8 annotations produce.
 *
 * Mirrors the legacy chat.js behaviour where `handleChatResponse` iterates
 * `data.tool_results` and calls `normaliseToolResultForDisplay` on each.
 */
function emitToolResultsAsToolCalls(
	toolResults: Array< Record< string, unknown > >,
	out: Uint8Array[],
	emittedIds?: Set< string >
): void {
	for ( const tr of toolResults ) {
		const toolName = typeof tr.name === 'string' ? tr.name : '';
		if ( ! toolName ) continue;

		// Build a unique, stable toolCallId.  Prefer the server-supplied
		// tool_call_id; fall back to a synthetic id so downstream
		// tool-message validators (REST) never see an empty tool_call_id.
		const toolCallId =
			typeof tr.tool_call_id === 'string' && tr.tool_call_id.length > 0
				? tr.tool_call_id
				: `tool-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2, 8 ) }`;

		// Skip if this tool invocation was already streamed in real-time
		// via tool_start / tool_result SSE events during the agentic loop.
		if ( emittedIds?.has( toolCallId ) ) {
			continue;
		}

		// Parse the content field (JSON string on the wire) into an
		// object so the downstream normaliseToolResult() in
		// ToolCallCard can extract attachments and rich metadata.
		let result: unknown = tr.content;
		if ( typeof result === 'string' && result.trim() ) {
			try {
				const parsed = JSON.parse( result );
				if ( parsed && typeof parsed === 'object' ) {
					result = parsed;
				}
			} catch {
				// Keep the original string if parsing fails.
			}
		}

		// Preserve usage, cost, and capability_flags on the result
		// object so aggregateToolUsageAndCost-equivalent logic
		// (via ChatPage.enhancedUsageMap) can discover them.
		if ( result && typeof result === 'object' ) {
			const r = result as Record< string, unknown >;
			if ( tr.usage !== undefined && r.usage === undefined ) r.usage = tr.usage;
			if ( tr.cost !== undefined && r.cost === undefined ) r.cost = tr.cost;
			if ( tr.capability_flags !== undefined && r.capability_flags === undefined ) {
				r.capability_flags = tr.capability_flags;
			}
		}

		// Emit tool call start (type 9) …
		out.push(
			encodeChunk( '9', {
				toolCallId,
				toolName,
				args: {},
			} )
		);

		// … then tool result (type a) so the invocation is completed.
		out.push(
			encodeChunk( 'a', {
				toolCallId,
				result,
			} )
		);
	}
}

/**
 * Translate a single decoded NV oOS SSE frame into zero-or-more Data Stream
 * Protocol chunks.
 *
 * NV oOS emits OpenAI-compatible SSE format:
 *   - Text deltas: { choices: [{ delta: { content: "..." } }] }
 *   - Reasoning: { choices: [{ delta: { reasoning_content: "..." } }] }
 *   - Thinking blocks: { type: "content_block_delta", delta: { type: "thinking_delta", thinking: "..." } }
 *   - Status events: { type: "thinking"|"generating"|"processing_attachments"|"loading_memory" }
 *   - Error events: { code: "...", message: "..." }
 *   - Final payload: { data: {...}, choices: [...], tool_results: [...] }
 */
function translateFrame(
	frame: NvOosFrame,
	emittedIds?: Set< string >
): Uint8Array[] {
	const out: Uint8Array[] = [];

	// ── Error frames (event: error) ─────────────────────────────────
	if ( typeof frame.code === 'string' && typeof frame.message === 'string' ) {
		out.push( encodeChunk( '3', frame.message ) );
		return out;
	}

	// ── OpenAI choices[0].delta.content ─────────────────────────────
	if ( Array.isArray( frame.choices ) && frame.choices.length > 0 ) {
		const delta = frame.choices[ 0 ]?.delta;
		if ( delta ) {
			// Text content.
			if ( typeof delta.content === 'string' && delta.content ) {
				out.push( encodeChunk( '0', delta.content ) );
			}
			// Reasoning content (extended thinking).
			const reasoning =
				typeof delta.reasoning_content === 'string'
					? delta.reasoning_content
					: typeof delta.thinking === 'string'
						? delta.thinking
						: '';
			if ( reasoning ) {
				out.push( encodeChunk( 'g', reasoning ) );
			}
		}
		// If this frame also carries tool_results, emit individual
		// tool-call-start + result events (type 9 + type a) so
		// useChat populates toolInvocations.  ToolCallCard then
		// renders images, videos, files, and charts inline
		// instead of showing raw JSON annotation pills.
		if ( Array.isArray( frame.tool_results ) && frame.tool_results.length > 0 ) {
			emitToolResultsAsToolCalls(
				frame.tool_results as Array< Record< string, unknown > >,
				out,
				emittedIds
			);
			// Still emit capability_flags as a type-8 annotation so
			// CapabilityFlagBadges can render them.
			const caps = extractCapabilityFlags(
				frame.tool_results as Array< Record< string, unknown > >
			);
			if ( caps.length > 0 ) {
				out.push( encodeChunk( '8', [ { type: 'capabilities', flags: caps } ] ) );
			}
		}
		return out;
	}

	// ── Content block delta (thinking blocks) ───────────────────────
	if (
		frame.type === 'content_block_delta' &&
		frame.delta &&
		typeof frame.delta === 'object'
	) {
		const b = frame.delta;
		if ( b.type === 'thinking_delta' && typeof b.thinking === 'string' ) {
			out.push( encodeChunk( 'g', b.thinking ) );
		}
		return out;
	}

	// ── Status events (thinking / generating / processing) ──────────
	if (
		frame.type === 'thinking' ||
		frame.type === 'generating' ||
		frame.type === 'processing_attachments' ||
		frame.type === 'loading_memory'
	) {
		// Forward as annotation so the UI can show status pills.
		out.push( encodeChunk( '8', [ frame ] ) );
		return out;
	}

	// ── Error frames tagged by parseSseBuffer ───────────────────────
	if ( frame.type === 'error' ) {
		const msg = typeof frame.message === 'string' ? frame.message : 'Unknown error';
		out.push( encodeChunk( '3', msg ) );
		return out;
	}

	// ── Fallback for legacy / unknown formats ───────────────────────
	const type = frame.type ?? '';

	switch ( type ) {
		case 'message_delta':
		case 'text_delta':
		case 'delta': {
			const text =
				typeof frame.delta === 'string'
					? frame.delta
					: typeof frame.text === 'string'
						? frame.text
						: typeof frame.content === 'string'
							? frame.content
							: '';
			if ( text ) {
				out.push( encodeChunk( '0', text ) );
			}
			break;
		}
		case 'tool_call_started': {
			out.push(
				encodeChunk( '9', {
					toolCallId: String( frame.id ?? '' ),
					toolName: String( frame.name ?? '' ),
					args: frame.arguments ?? {},
				} )
			);
			break;
		}
		case 'tool_call_completed': {
			out.push(
				encodeChunk( 'a', {
					toolCallId: String( frame.id ?? '' ),
					result: frame.result ?? null,
				} )
			);
			break;
		}
		case 'memory_event':
		case 'annotation': {
			out.push( encodeChunk( '8', [ frame ] ) );
			break;
		}
		case 'done':
		case 'finish': {
			out.push(
				encodeChunk( 'e', {
					finishReason: 'stop',
					usage: ( frame.usage as object | undefined ) ?? {},
				} )
			);
			// Forward model + cost as a type-8 data annotation so
			// the ChatPage can pick them up for usage badges
			// (model badge, cost badge).
			const data: Record< string, unknown > = {};
			if ( frame.model ) data.model = frame.model;
			if ( frame.provider ) data.provider = frame.provider;
			if ( frame.cost ) data.cost = frame.cost;
			if ( Object.keys( data ).length > 0 ) {
				out.push( encodeChunk( '8', [ { type: 'data', data } ] ) );
			}
			break;
		}
		// Agentic loop start — forward as annotation.
			case 'start': {
				out.push( encodeChunk( '8', [ frame ] ) );
				break;
			}
			// Tool call start — emit as AI SDK type 9 (toolCall) so useChat
			// populates toolInvocations on the assistant message.
			case 'tool_start': {
				const tsToolName = typeof frame.tool_name === 'string' ? frame.tool_name : '';
				const tsToolId = typeof frame.tool_id === 'string' ? frame.tool_id : '';
				const resolvedId = tsToolId || `tool-${ Date.now() }`;
				// Track this id so emitToolResultsAsToolCalls skips the
				// duplicate in the final message frame.
				if ( tsToolId ) {
					emittedIds?.add( tsToolId );
				}
				out.push(
					encodeChunk( '9', {
						toolCallId: resolvedId,
						toolName: tsToolName,
						args: {},
					} )
				);
				break;
			}
			// Tool result — emit as AI SDK type a (toolResult) so useChat
			// completes the toolInvocation on the message.
			// Always supply a non-empty toolCallId even when the server
			// sends an empty tool_id — otherwise the REST validator on
			// the next turn rejects the tool message for missing
			// tool_call_id.
			case 'tool_result': {
				const trToolId = typeof frame.tool_id === 'string' ? frame.tool_id : '';
				const trResult = frame.result ?? null;
				out.push(
					encodeChunk( 'a', {
						toolCallId: trToolId || `tool-${ Date.now() }`,
						result: trResult,
					} )
				);
				break;
			}
			default: {
				// Completion frames with data — mark as 'data' type.
				if ( frame.data || frame.choices || frame.model ) {
					out.push( encodeChunk( '8', [ { ...frame, type: 'data' } ] ) );
					// Emit tool_results as individual tool-call events
					// so ToolCallCard renders rich content (images,
					// videos, files, charts) instead of raw JSON
					// annotation pills.
					if ( Array.isArray( frame.tool_results ) && frame.tool_results.length > 0 ) {
						emitToolResultsAsToolCalls(
							frame.tool_results as Array< Record< string, unknown > >,
							out,
							emittedIds
						);
						const caps = extractCapabilityFlags(
							frame.tool_results as Array< Record< string, unknown > >
						);
						if ( caps.length > 0 ) {
							out.push( encodeChunk( '8', [ { type: 'capabilities', flags: caps } ] ) );
						}
					}
				} else {
					// Truly unknown — forward but flag.
					out.push( encodeChunk( '8', [ { type: 'unknown', frame } ] ) );
				}
			}
	}
	return out;
}

/**
 * Parse one or more SSE frames out of a streaming text buffer.
 *
 * Returns the parsed frames and the leftover (incomplete) buffer.
 */
/**
 * Extract unique capability_flags from an array of tool_result entries.
 * Each entry may carry a `capability_flags: string[]` property.
 */
function extractCapabilityFlags(
	toolResults: Array< Record< string, unknown > >
): string[] {
	const seen = new Set< string >();
	for ( const entry of toolResults ) {
		const flags = entry.capability_flags;
		if ( Array.isArray( flags ) ) {
			for ( const f of flags ) {
				if ( typeof f === 'string' && f.length > 0 ) {
					seen.add( f );
				}
			}
		}
	}
	return [ ...seen ];
}

function parseSseBuffer( buffer: string ): { frames: NvOosFrame[]; rest: string } {
	const frames: NvOosFrame[] = [];
	const parts = buffer.split( '\n\n' );
	const rest = parts.pop() ?? '';
	for ( const part of parts ) {
		const lines = part.split( '\n' );
		const dataLines: string[] = [];
		let eventType = '';
		for ( const line of lines ) {
			if ( line.startsWith( 'event:' ) ) {
				eventType = line.slice( 6 ).trim();
			} else if ( line.startsWith( 'data:' ) ) {
				dataLines.push( line.slice( 5 ).trimStart() );
			}
		}
		if ( dataLines.length === 0 ) {
			continue;
		}
		const raw = dataLines.join( '\n' );
		if ( raw === '' || raw === '[DONE]' ) {
			continue;
		}
		try {
			const parsed = JSON.parse( raw ) as NvOosFrame;
			// When the SSE event type is 'error', tag the parsed frame so
			// translateFrame can immediately emit an error chunk.
			if ( eventType === 'error' ) {
				parsed.type = 'error';
			}
			frames.push( parsed );
		} catch {
			// Non-JSON SSE payloads are forwarded as a text delta so they
			// are not silently dropped during development.
			frames.push( { type: 'message_delta', delta: raw } );
		}
	}
	return { frames, rest };
}

/**
 * Build a custom `fetch` for `useChat` that bridges NV oOS's native SSE
 * format into the AI SDK Data Stream Protocol.
 *
 * The `useChat` hook calls this fetch with the request body it would normally
 * send to a Vercel-AI-SDK-style server. We forward that body to NV oOS's
 * `mcp-ai/v1/chat-client` endpoint (which already accepts an array of chat
 * messages), then transform the response.
 */
export function createChatFetch( opts: ChatFetchOptions ): typeof globalThis.fetch {
	return async function chatFetch(
		_input: RequestInfo | URL,
		init?: RequestInit
	): Promise< Response > {
		const headers = new Headers( init?.headers ?? {} );
		headers.set( 'Content-Type', 'application/json' );
		headers.set( 'Accept', 'text/event-stream' );
		if ( opts.guest ) {
			headers.set( 'X-WP-MCP-AI-Guest', '1' );
		} else if ( opts.nonce ) {
			headers.set( 'X-WP-Nonce', opts.nonce );
		}

		// The AI SDK posts `{ messages: [...], id?: string }`. NV oOS expects
		// an `assistant_id` alongside the messages; we merge it in here so
		// the React component does not need to know the wire shape.
		let body: unknown = {};
		if ( typeof init?.body === 'string' ) {
			try {
				body = JSON.parse( init.body );
			} catch {
				body = {};
			}
		}
		const merged: Record< string, unknown > = {
			...( body as Record< string, unknown > ),
			assistant_id: opts.assistantId,
			stream: true,
		};

		if ( opts.allowSensitiveTools ) {
			merged.allow_sensitive_tools = true;
		}

		const upstream = await fetch( opts.endpoint, {
			method: 'POST',
			headers,
			body: JSON.stringify( merged ),
			credentials: 'same-origin',
			signal: init?.signal,
		} );

		if ( ! upstream.ok || ! upstream.body ) {
			return upstream;
		}

		const reader = upstream.body.getReader();
		const decoder = new TextDecoder();

		const translated = new ReadableStream< Uint8Array >( {
			async start( controller ) {
				// Deduplication: skip tool_results from the final message
				// frame when those tools were already streamed in real-time
				// via tool_start SSE events during the agentic loop.
				const emittedToolIds = new Set< string >();
				let buffer = '';
				try {
					// eslint-disable-next-line no-constant-condition -- Stream reader loop exits via the `done` check returned by reader.read().
					while ( true ) {
						const { value, done } = await reader.read();
						if ( done ) {
							break;
						}
						buffer += decoder.decode( value, { stream: true } );
						const { frames, rest } = parseSseBuffer( buffer );
						buffer = rest;
						for ( const frame of frames ) {
							for ( const chunk of translateFrame( frame, emittedToolIds ) ) {
								controller.enqueue( chunk );
							}
						}
					}
					// Flush any trailing frame.
					buffer += decoder.decode();
					if ( buffer.trim() ) {
						const { frames } = parseSseBuffer( buffer + '\n\n' );
						for ( const frame of frames ) {
							for ( const chunk of translateFrame( frame, emittedToolIds ) ) {
								controller.enqueue( chunk );
							}
						}
					}
				} catch ( e ) {
					controller.enqueue(
						encodeChunk( '3', String( ( e as Error )?.message ?? e ) )
					);
				} finally {
					controller.close();
				}
			},
			cancel() {
				reader.cancel().catch( () => undefined );
			},
		} );

		return new Response( translated, {
			status: 200,
			headers: { 'Content-Type': 'text/plain; charset=utf-8' },
		} );
	};
}
