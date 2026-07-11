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
	tool_results?: Array< { slug?: string; result?: unknown } >;
	[ k: string ]: unknown;
}

function encodeChunk( typeId: string, payload: unknown ): Uint8Array {
	const line = `${ typeId }:${ JSON.stringify( payload ) }\n`;
	return new TextEncoder().encode( line );
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
		if ( Array.isArray( frame.tool_results ) && frame.tool_results.length > 0 ) {
			out.push( encodeChunk( '8', frame.tool_results ) );
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
			case 'tool_result': {
				const trToolId = typeof frame.tool_id === 'string' ? frame.tool_id : '';
				const trResult = frame.result ?? null;
				out.push(
					encodeChunk( 'a', {
						toolCallId: trToolId || '',
						result: trResult,
					} )
				);
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
