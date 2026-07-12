/**
 * Pro SPA v2 — SSE → AI SDK Data Stream Protocol adapter.
 *
 * Mirrors chat-spa's sse-adapter.ts with pro text domain.
 *
 * Translates NV oOS's OpenAI-compatible SSE format into the AI SDK Data Stream
 * Protocol so `useChat` from `@ai-sdk/react` can consume the stream natively.
 */

export interface ChatFetchOptions {
	endpoint: string;
	nonce: string;
	assistantId: number;
	guest: boolean;
	/** Optional override — when provided, sent to server as options.provider / options.model. */
	model?: string;
	provider?: string;
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
	out: Uint8Array[]
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

function translateFrame( frame: NvOosFrame ): Uint8Array[] {
	const out: Uint8Array[] = [];

	if ( typeof frame.code === 'string' && typeof frame.message === 'string' ) {
		out.push( encodeChunk( '3', frame.message ) );
		return out;
	}

	if ( Array.isArray( frame.choices ) && frame.choices.length > 0 ) {
		const delta = frame.choices[ 0 ]?.delta;
		if ( delta ) {
			if ( typeof delta.content === 'string' && delta.content ) {
				out.push( encodeChunk( '0', delta.content ) );
			}
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
				out
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

	if (
		frame.type === 'thinking' ||
		frame.type === 'generating' ||
		frame.type === 'processing_attachments' ||
		frame.type === 'loading_memory'
	) {
		out.push( encodeChunk( '8', [ frame ] ) );
		return out;
	}

	if ( frame.type === 'error' ) {
		const msg = typeof frame.message === 'string' ? frame.message : 'Unknown error';
		out.push( encodeChunk( '3', msg ) );
		return out;
	}

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
			// ChatPage.enhancedUsageMap picks them up for the
			// UsageBadges component (model badge, cost badge).
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
				out.push(
					encodeChunk( '9', {
						toolCallId: tsToolId || `tool-${ Date.now() }`,
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
							out
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
			if ( eventType === 'error' ) {
				parsed.type = 'error';
			}
			frames.push( parsed );
		} catch {
			frames.push( { type: 'message_delta', delta: raw } );
		}
	}
	return { frames, rest };
}

/**
 * Strip tool messages that are missing `tool_call_id` from the messages
 * array. When conversations are loaded from CCT, stored tool messages
 * lack the AI SDK–specific `tool_call_id` field. The REST endpoint
 * rejects these messages, so we remove them before forwarding the request
 * to the server. This matches the legacy chat-client behaviour.
 *
 * @since 2.1.0
 */
function sanitizeMessages( messages: unknown ): unknown {
	if ( ! Array.isArray( messages ) ) {
		return messages;
	}
	return ( messages as Array< Record< string, unknown > > ).filter( ( m ) => {
		if ( m.role !== 'tool' ) {
			return true;
		}
		// Keep tool messages only if they have a valid tool_call_id.
		return typeof m.tool_call_id === 'string' && m.tool_call_id.length > 0;
	} );
}

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

		let body: unknown = {};
		if ( typeof init?.body === 'string' ) {
			try {
				body = JSON.parse( init.body );
			} catch {
				body = {};
			}
		}

		// Strip tool messages that lack tool_call_id (v2.1.0).
		const bodyObj = body as Record< string, unknown >;
		if ( Array.isArray( bodyObj.messages ) ) {
			bodyObj.messages = sanitizeMessages( bodyObj.messages );
		}

		const merged: Record< string, unknown > = {
			...( body as Record< string, unknown > ),
			assistant_id: opts.assistantId,
			stream: true,
		};

		// Forward model/provider overrides when the user has selected a specific model.
		if ( opts.provider && opts.model ) {
			merged.options = {
				...( ( merged.options as Record< string, unknown > ) ?? {} ),
				provider: opts.provider,
				model: opts.model,
			};
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
				let buffer = '';
				try {
					// eslint-disable-next-line no-constant-condition
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
