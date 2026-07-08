/**
 * Pro SPA v2 — typed wrappers around `mcp-ai/v1/assistants`.
 */

import { __ } from '@wordpress/i18n';

export interface AssistantRecord {
	id: number;
	title: string;
	model?: string;
	provider?: string;
	temperature?: number;
	max_tokens?: number;
	system_prompt?: string;
	capabilities?: string[];
	is_preset?: boolean;
}

export interface AssistantsListResponse {
	assistants: AssistantRecord[];
	total: number;
}

export interface AssistantsClientOptions {
	endpoint: string;
	nonce: string;
}

export class AssistantsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: AssistantsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	async list( signal?: AbortSignal ): Promise< AssistantsListResponse > {
		const url = new URL( this.endpoint, window.location.origin );
		url.searchParams.set( 'per_page', '100' );
		// The REST endpoint returns { assistants: [...], default_assistant, rest } directly.
		const data = await this.request< AssistantsListResponse >( {
			method: 'GET',
			url: url.toString(),
			signal,
		} );
		return {
			assistants: Array.isArray( data?.assistants ) ? data.assistants : [],
			total: typeof data?.total === 'number' ? data.total : 0,
		};
	}

	async get( id: number, signal?: AbortSignal ): Promise< AssistantRecord > {
		const url = new URL( `${ this.endpoint }/${ id }`, window.location.origin );
		const data = await this.request< { success: boolean; data: AssistantRecord } >( {
			method: 'GET',
			url: url.toString(),
			signal,
		} );
		return data?.data ?? ( {} as AssistantRecord );
	}

	async update(
		id: number,
		changes: Partial< AssistantRecord >,
		signal?: AbortSignal
	): Promise< AssistantRecord > {
		const data = await this.request< { success: boolean; data: AssistantRecord } >( {
			method: 'PUT',
			url: `${ this.endpoint }/${ id }`,
			body: changes,
			signal,
		} );
		return data?.data ?? ( {} as AssistantRecord );
	}

	async create(
		fields: Partial< AssistantRecord >,
		signal?: AbortSignal
	): Promise< AssistantRecord > {
		const data = await this.request< { success: boolean; data: AssistantRecord } >( {
			method: 'POST',
			url: this.endpoint,
			body: fields,
			signal,
		} );
		return data?.data ?? ( {} as AssistantRecord );
	}

	async delete( id: number, signal?: AbortSignal ): Promise< void > {
		await this.request( {
			method: 'DELETE',
			url: `${ this.endpoint }/${ id }`,
			signal,
		} );
	}

	private async request< T = unknown >( req: {
		method: 'GET' | 'POST' | 'PUT' | 'DELETE';
		url: string;
		body?: unknown;
		signal?: AbortSignal;
	} ): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
		};
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
			throw new Error(
				__( 'Assistants request failed.', 'nvoos-pro-spa' )
			);
		}
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
