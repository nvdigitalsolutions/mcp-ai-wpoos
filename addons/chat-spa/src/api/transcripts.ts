/**
 * NV oOS Chat SPA — typed wrappers around `mcp-ai/v1/chat-transcripts`.
 *
 * The base plugin already owns the transcripts REST namespace (see
 * `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`):
 *
 *   GET    /chat-transcripts                — list sessions for the current user
 *   POST   /chat-transcripts                — save (or merge) a session
 *   GET    /chat-transcripts/{session_key}  — load full transcript
 *   DELETE /chat-transcripts/{session_key}  — delete a session
 *
 * The list endpoint can also return `{ message: '…unavailable' }` when
 * JetEngine CCT is not active. Callers should treat that case as
 * "feature disabled" and render an empty state.
 */

import { __ } from '@wordpress/i18n';

export interface TranscriptSession {
	session_key: string;
	assistant_id?: number | string;
	assistant_model?: string;
	turn_count?: number;
	started_at?: string;
	completed_at?: string;
	first_created?: string;
	last_created?: string;
}

export interface TranscriptListResponse {
	sessions: TranscriptSession[];
	total: number;
	per_page: number;
	page: number;
	/** Present only when the underlying CCT is unavailable. */
	message?: string;
}

export interface TranscriptMessage {
	role: 'system' | 'user' | 'assistant' | 'tool' | string;
	content?: string;
	[ k: string ]: unknown;
}

export interface TranscriptDetailResponse {
	session: {
		session_key: string;
		assistant_id?: number | string;
		messages?: TranscriptMessage[];
		[ k: string ]: unknown;
	} | null;
	message?: string;
}

export interface TranscriptsClientOptions {
	endpoint: string;
	nonce: string;
	assistantId: number | string;
}

interface InternalRequest {
	method: 'GET' | 'POST' | 'DELETE';
	url: string;
	body?: unknown;
	signal?: AbortSignal;
}

/**
 * Generate a stable per-conversation session key.
 *
 * Mirrors the legacy `assets/js/chat.js` format (`wp-mcp-ai-session-<hex>`)
 * so transcripts saved by the SPA remain compatible with the legacy UI's
 * lookup logic.
 */
export function generateSessionKey(): string {
	if (
		typeof window !== 'undefined' &&
		window.crypto &&
		typeof window.crypto.getRandomValues === 'function'
	) {
		const array = new Uint8Array( 16 );
		window.crypto.getRandomValues( array );
		return (
			'wp-mcp-ai-session-' +
			Array.from( array, ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) ).join( '' )
		);
	}
	return 'wp-mcp-ai-session-' + Date.now().toString( 16 );
}

/**
 * Per-assistant localStorage key for the active session id, so reloads
 * resume the same conversation that was open before.
 */
export function activeSessionStorageKey( assistantId: number | string ): string {
	const id = typeof assistantId === 'string' ? assistantId : String( assistantId || 0 );
	return `nvoos-chat-spa.active-session.${ id }`;
}

export class TranscriptsClient {
	private readonly endpoint: string;
	private readonly nonce: string;
	private readonly assistantId: number | string;

	constructor( opts: TranscriptsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
		this.assistantId = opts.assistantId;
	}

	async list( signal?: AbortSignal ): Promise< TranscriptListResponse > {
		const url = new URL( this.endpoint, window.location.origin );
		if ( this.assistantId ) {
			url.searchParams.set( 'assistant_id', String( this.assistantId ) );
		}
		url.searchParams.set( 'per_page', '50' );
		const data = await this.request< TranscriptListResponse >( {
			method: 'GET',
			url: url.toString(),
			signal,
		} );
		// Defensive shape coercion — CCT-disabled responses still carry a
		// `message` field but may omit `sessions`.
		return {
			sessions: Array.isArray( data?.sessions ) ? data.sessions : [],
			total: typeof data?.total === 'number' ? data.total : 0,
			per_page: typeof data?.per_page === 'number' ? data.per_page : 50,
			page: typeof data?.page === 'number' ? data.page : 1,
			message: typeof data?.message === 'string' ? data.message : undefined,
		};
	}

	async get( sessionKey: string, signal?: AbortSignal ): Promise< TranscriptDetailResponse > {
		const url = new URL(
			`${ this.endpoint }/${ encodeURIComponent( sessionKey ) }`,
			window.location.origin
		);
		if ( this.assistantId ) {
			url.searchParams.set( 'assistant_id', String( this.assistantId ) );
		}
		const data = await this.request< TranscriptDetailResponse >( {
			method: 'GET',
			url: url.toString(),
			signal,
		} );
		return {
			session: data?.session ?? null,
			message: typeof data?.message === 'string' ? data.message : undefined,
		};
	}

	async save(
		sessionKey: string,
		messages: TranscriptMessage[],
		responseMetadata?: Record< string, unknown >,
		signal?: AbortSignal
	): Promise< void > {
		await this.request( {
			method: 'POST',
			url: this.endpoint,
			body: {
				assistant_id: this.assistantId || 0,
				session_key: sessionKey,
				messages,
				response_metadata: responseMetadata ?? {},
			},
			signal,
		} );
	}

	async delete( sessionKey: string, signal?: AbortSignal ): Promise< void > {
		const url = `${ this.endpoint }/${ encodeURIComponent( sessionKey ) }`;
		await this.request( { method: 'DELETE', url, signal } );
	}

	private async request< T = unknown >( req: InternalRequest ): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
		};
		if ( this.nonce ) {
			headers[ 'X-WP-Nonce' ] = this.nonce;
		}
		if ( req.body !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		const response = await fetch( req.url, {
			method: req.method,
			credentials: 'same-origin',
			headers,
			body: req.body !== undefined ? JSON.stringify( req.body ) : undefined,
			signal: req.signal,
		} );
		if ( ! response.ok ) {
			let detail = '';
			try {
				const errBody = ( await response.json() ) as { message?: string; code?: string };
				detail = errBody?.message ?? errBody?.code ?? '';
			} catch {
				// Body wasn't JSON — fall through.
			}
			throw new Error(
				detail ||
					/* translators: %d: HTTP status code. */
					__( 'Transcripts request failed (status %d).', 'nvoos-chat-spa' ).replace(
						'%d',
						String( response.status )
					)
			);
		}
		// 204 No Content (e.g. successful DELETE) has no body.
		if ( response.status === 204 ) {
			return undefined as unknown as T;
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
