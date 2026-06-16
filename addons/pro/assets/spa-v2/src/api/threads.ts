/**
 * Pro SPA v2 — typed wrappers around `mcp-ai/v1/threads`.
 *
 * Read-only subset for sidebar integration and thread browsing.
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

	async create(
		assistantId: number,
		model: { provider?: string; name?: string },
		profile: string,
		scope: Record< string, unknown >,
		signal?: AbortSignal
	): Promise< ThreadSummary > {
		const data = await this.request< {
			success: boolean;
			data: ThreadSummary;
		} >( {
			method: 'POST',
			url: this.endpoint,
			body: { assistant_id: assistantId, model, profile, scope },
			signal,
		} );
		return data?.data ?? ( {} as ThreadSummary );
	}

	async archive( threadId: number, signal?: AbortSignal ): Promise< void > {
		await this.request( {
			method: 'DELETE',
			url: `${ this.endpoint }/${ threadId }`,
			signal,
		} );
	}

	async restore( threadId: number, signal?: AbortSignal ): Promise< void > {
		await this.request( {
			method: 'POST',
			url: `${ this.endpoint }/${ threadId }/restore`,
			signal,
		} );
	}

	async summarize( threadId: number, signal?: AbortSignal ): Promise< { new_thread_id?: number } > {
		const data = await this.request< {
			success: boolean;
			data: { new_thread_id?: number };
		} >( {
			method: 'POST',
			url: `${ this.endpoint }/${ threadId }/summarize`,
			signal,
		} );
		return data?.data ?? {};
	}

	private async request< T = unknown >( req: {
		method: 'GET' | 'POST' | 'DELETE';
		url: string;
		body?: unknown;
		signal?: AbortSignal;
	} ): Promise< T > {
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
					__( 'Threads request failed (status %d).', 'nvoos-pro-spa' ).replace(
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
