/**
 * NV oOS Chat SPA — typed wrappers around `mcp-ai/v1/threads`.
 *
 * The base plugin owns the threads REST namespace (see
 * `includes/rest/class-wp-mcp-ai-rest-threads-controller.php`):
 *
 *   GET /threads                  — list active threads for current user
 *   GET /threads/{id}/messages    — list messages in a thread
 *   POST /threads/{id}/messages   — send a message (SSE stream)
 *   POST /threads                 — create thread
 *   DELETE /threads/{id}          — archive thread
 *
 * This client only implements the read-only subset needed for the
 * sidebar integration.
 */

import { __ } from '@wordpress/i18n';

export interface ThreadSummary {
	id: number;
	title: string;
	status: string;
	model_name?: string;
	model_provider?: string;
	profile?: string;
	scope_type?: string;
	scope_id?: number;
	assistant_id?: number;
	message_count?: number;
	user_id?: number;
	created_at?: string;
	updated_at?: string;
}

export interface ThreadListResponse {
	threads: ThreadSummary[];
	total: number;
}

export interface ThreadMessage {
	role: 'user' | 'assistant' | 'system' | string;
	content: string;
	id?: string | number;
	checkpoint_id?: number;
	[ k: string ]: unknown;
}

export interface ThreadMessagesResponse {
	messages: ThreadMessage[];
	total: number;
}

export interface ThreadsClientOptions {
	endpoint: string;
	nonce: string;
}

export class ThreadsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: ThreadsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/**
	 * List active threads for the current user.
	 */
	async list( signal?: AbortSignal ): Promise< ThreadListResponse > {
		const url = new URL( this.endpoint, window.location.origin );
		url.searchParams.set( 'per_page', '50' );
		url.searchParams.set( 'status', 'active' );

		const data = await this.request< {
			success: boolean;
			data: ThreadListResponse;
		} >( { method: 'GET', url: url.toString(), signal } );

		return {
			threads: Array.isArray( data?.data?.threads ) ? data.data.threads : [],
			total: typeof data?.data?.total === 'number' ? data.data.total : 0,
		};
	}

	/**
	 * Get messages for a specific thread.
	 */
	async getMessages(
		threadId: number,
		signal?: AbortSignal
	): Promise< ThreadMessagesResponse > {
		const url = new URL(
			`${ this.endpoint }/${ threadId }/messages`,
			window.location.origin
		);
		url.searchParams.set( 'per_page', '200' );

		const data = await this.request< {
			success: boolean;
			data: ThreadMessagesResponse;
		} >( { method: 'GET', url: url.toString(), signal } );

		return {
			messages: Array.isArray( data?.data?.messages ) ? data.data.messages : [],
			total: typeof data?.data?.total === 'number' ? data.data.total : 0,
		};
	}

	private async request< T = unknown >( req: {
		method: 'GET' | 'POST';
		url: string;
		signal?: AbortSignal;
	} ): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
		};
		if ( this.nonce ) {
			headers[ 'X-WP-Nonce' ] = this.nonce;
		}
		const response = await fetch( req.url, {
			method: req.method,
			credentials: 'same-origin',
			headers,
			signal: req.signal,
		} );
		if ( ! response.ok ) {
			let detail = '';
			try {
				const errBody = ( await response.json() ) as {
					message?: string;
					code?: string;
				};
				detail = errBody?.message ?? errBody?.code ?? '';
			} catch {
				// Body wasn't JSON.
			}
			throw new Error(
				detail ||
					/* translators: %d: HTTP status code. */
					__( 'Threads request failed (status %d).', 'nvoos-chat-spa' ).replace(
						'%d',
						String( response.status )
					)
			);
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
