/**
 * Pro SPA v2 — chat profile client.
 *
 * Typed wrapper around `mcp-ai/v1/chat-profile`. The server resolves the
 * effective profile (user meta → site default) — the client can only read
 * the catalogue and request a switch that the server may refuse (403).
 */

import { __ } from '@wordpress/i18n';

import type { ChatProfileSummary } from './config';

export interface ChatProfileState {
	success: boolean;
	profiles: ChatProfileSummary[];
	current: string;
	enabled: boolean;
}

export class ChatProfileClient {
	private readonly base: string;
	private readonly nonce: string;

	constructor( opts: { endpoint: string; nonce: string } ) {
		this.base = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	async getState( signal?: AbortSignal ): Promise< ChatProfileState > {
		const data = await this.request< ChatProfileState >( {
			method: 'GET',
			url: this.base,
			signal,
		} );
		return {
			success: data?.success === true,
			profiles: Array.isArray( data?.profiles ) ? data.profiles : [],
			current: typeof data?.current === 'string' ? data.current : 'write',
			enabled: data?.enabled !== false,
		};
	}

	async setProfile( slug: string ): Promise< ChatProfileState > {
		const data = await this.request< ChatProfileState >( {
			method: 'POST',
			url: this.base,
			body: { profile: slug },
		} );
		return {
			success: data?.success === true,
			profiles: Array.isArray( data?.profiles ) ? data.profiles : [],
			current: typeof data?.current === 'string' ? data.current : slug,
			enabled: data?.enabled !== false,
		};
	}

	private async request< T >( opts: {
		method: 'GET' | 'POST';
		url: string;
		body?: unknown;
		signal?: AbortSignal;
	} ): Promise< T > {
		const headers: Record< string, string > = { Accept: 'application/json' };
		if ( this.nonce ) {
			headers[ 'X-WP-Nonce' ] = this.nonce;
		}
		if ( opts.body !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		const response = await fetch( opts.url, {
			method: opts.method,
			credentials: 'same-origin',
			headers,
			body: opts.body !== undefined ? JSON.stringify( opts.body ) : undefined,
			signal: opts.signal,
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
				// Body was not JSON — fall through.
			}
			throw new Error(
				detail ||
					__( 'Chat profile request failed (status %d).', 'nvoos-pro-spa' ).replace(
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
