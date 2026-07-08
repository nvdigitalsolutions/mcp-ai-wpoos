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
	tool_results?: Array< { slug?: string; result?: unknown } >;
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
function translateFrame( frame: NvOosFrame ): Uint8Array[] {
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
		// If this frame also carries tool_results, emit them as annotations.
		if ( Array.isArray( frame.tool_results ) && frame.tool_results.length > 0 ) {
			out.push( encodeChunk( '8', frame.tool_results ) );
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
			break;
		}
		// Agentic loop events — forward with native type so the UI labels them.
			case 'start':
			case 'tool_start':
			case 'tool_result': {
				out.push( encodeChunk( '8', [ frame ] ) );
				break;
			}
			default: {
				// Completion frames with data — mark as 'data' type.
				if ( frame.data || frame.choices || frame.model ) {
					out.push( encodeChunk( '8', [ { ...frame, type: 'data' } ] ) );
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
		const merged = {
			...( body as Record< string, unknown > ),
			assistant_id: opts.assistantId,
			stream: true,
		};

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
							for ( const chunk of translateFrame( frame ) ) {
								controller.enqueue( chunk );
							}
						}
					}
					// Flush any trailing frame.
					buffer += decoder.decode();
					if ( buffer.trim() ) {
						const { frames } = parseSseBuffer( buffer + '\n\n' );
						for ( const frame of frames ) {
							for ( const chunk of translateFrame( frame ) ) {
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
