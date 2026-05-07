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
 * NV oOS today emits a custom SSE format on `mcp-ai/v1/chat-client`:
 *
 *     data: { "type": "message_delta", "delta": "Hello" }
 *
 *     data: { "type": "tool_call_started", "id": "...", "name": "..." }
 *
 *     data: { "type": "tool_call_completed", "id": "...", "result": {...} }
 *
 *     data: { "type": "memory_event", ... }
 *
 *     data: { "type": "done" }
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
	delta?: string;
	text?: string;
	content?: string;
	id?: string;
	name?: string;
	arguments?: unknown;
	result?: unknown;
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
 */
function translateFrame( frame: NvOosFrame ): Uint8Array[] {
	const out: Uint8Array[] = [];
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
				encodeChunk( '2', [
					{
						toolCallId: String( frame.id ?? '' ),
						toolName: String( frame.name ?? '' ),
						args: frame.arguments ?? {},
					},
				] )
			);
			break;
		}
		case 'tool_call_completed': {
			out.push(
				encodeChunk( '8', [
					{
						toolCallId: String( frame.id ?? '' ),
						result: frame.result ?? null,
					},
				] )
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
		default: {
			// Forward unknown frames as data annotations so consumers can
			// inspect them without breaking the stream.
			out.push( encodeChunk( '8', [ { type: 'unknown', frame } ] ) );
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
		for ( const line of lines ) {
			if ( line.startsWith( 'data:' ) ) {
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
