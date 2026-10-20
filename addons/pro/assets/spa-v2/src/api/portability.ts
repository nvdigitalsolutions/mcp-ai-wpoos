/**
 * Pro SPA v2 — Assistant portability client (export/import).
 *
 * Wraps mcp-ai/v1/assistants/export and /assistants/import for the
 * PortabilityPage. Mirrors the ToolsClient fetch discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface PortabilityClientOptions {
	endpoint: string;
	nonce: string;
}

export interface ImportReport {
	[ k: string ]: unknown;
}

export class PortabilityClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: PortabilityClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/**
	 * Export a bundle. Omit ids to export all assistants.
	 */
	async exportAssistants(
		ids?: number[],
		includeA2a = true,
		signal?: AbortSignal
	): Promise< { success?: boolean; data?: Record< string, unknown > } > {
		const body: Record< string, unknown > = { format: 'json', include_a2a: includeA2a };
		if ( ids && ids.length > 0 ) {
			body.ids = ids;
		}
		return this.request( 'POST', `${ this.endpoint }/export`, body, signal );
	}

	/**
	 * Import a bundle from a JSON string or a media attachment.
	 */
	async importAssistants(
		payload: {
			json?: string;
			attachment_id?: number;
			mode?: 'skip' | 'overwrite' | 'duplicate';
			dry_run?: boolean;
			status_override?: 'draft' | 'publish' | 'private';
		},
		signal?: AbortSignal
	): Promise< { success?: boolean; data?: ImportReport } > {
		return this.request( 'POST', `${ this.endpoint }/import`, payload, signal );
	}

	private async request< T >(
		method: 'POST',
		url: string,
		body?: unknown,
		signal?: AbortSignal
	): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
		};
		if ( body !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		const response = await fetch( url, {
			method,
			credentials: 'same-origin',
			headers,
			body: body !== undefined ? JSON.stringify( body ) : undefined,
			signal,
		} );
		if ( ! response.ok ) {
			let detail = '';
			try {
				const err = ( await response.json() ) as { message?: string };
				detail = err?.message ?? '';
			} catch {
				// Not JSON — keep the generic message.
			}
			throw new Error( detail || __( 'Portability request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
