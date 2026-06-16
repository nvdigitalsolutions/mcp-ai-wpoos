/**
 * Pro SPA v2 — typed wrappers around `mcp-ai/v1/tools`.
 */

import { __ } from '@wordpress/i18n';

export interface ToolDefinition {
	slug: string;
	name: string;
	description: string;
	category: string;
	required_capability: string;
	parameters?: Record< string, unknown >;
	enabled?: boolean;
}

export interface ToolsListResponse {
	tools: ToolDefinition[];
	total: number;
}

export interface ToolsClientOptions {
	endpoint: string;
	nonce: string;
}

export class ToolsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: ToolsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	async list( signal?: AbortSignal ): Promise< ToolsListResponse > {
		const url = new URL( this.endpoint, window.location.origin );
		url.searchParams.set( 'per_page', '200' );
		const data = await this.request< {
			success: boolean;
			data: ToolsListResponse;
		} >( { method: 'GET', url: url.toString(), signal } );
		return {
			tools: Array.isArray( data?.data?.tools ) ? data.data.tools : [],
			total: typeof data?.data?.total === 'number' ? data.data.total : 0,
		};
	}

	async execute(
		slug: string,
		args: Record< string, unknown >,
		signal?: AbortSignal
	): Promise< unknown > {
		const data = await this.request< { success: boolean; data: unknown } >( {
			method: 'POST',
			url: `${ this.endpoint }/run`,
			body: { slug, arguments: args },
			signal,
		} );
		return data?.data;
	}

	private async request< T = unknown >( req: {
		method: 'GET' | 'POST';
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
				__( 'Tools request failed.', 'nvoos-pro-spa' )
			);
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
