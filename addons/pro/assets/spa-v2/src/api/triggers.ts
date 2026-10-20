/**
 * Pro SPA v2 — Workflow triggers client.
 *
 * Wraps mcp-ai/v1/triggers (list/toggle/delete) for the TriggersPage.
 * Mirrors the ToolsClient fetch discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface TriggerRecord {
	id: number;
	name: string;
	type: string;
	config: Record< string, unknown >;
	workflow_id: number;
	enabled: boolean;
	last_fired_at: number;
	webhook_url: string;
	[ k: string ]: unknown;
}

export interface TriggersClientOptions {
	endpoint: string;
	nonce: string;
}

export class TriggersClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: TriggersClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** List all workflow triggers. */
	async list( signal?: AbortSignal ): Promise< TriggerRecord[] > {
		const data = await this.request< TriggerRecord[] | { triggers?: TriggerRecord[] } >(
			'GET',
			this.endpoint,
			undefined,
			signal
		);
		if ( Array.isArray( data ) ) {
			return data;
		}
		return Array.isArray( data?.triggers ) ? data.triggers : [];
	}

	/** Toggle a trigger's enabled state. */
	async setEnabled( id: number, enabled: boolean, signal?: AbortSignal ): Promise< TriggerRecord > {
		return this.request< TriggerRecord >( 'PUT', `${ this.endpoint }/${ id }`, { enabled }, signal );
	}

	/** Delete a trigger. */
	async remove( id: number, signal?: AbortSignal ): Promise< { deleted?: boolean } > {
		return this.request< { deleted?: boolean } >( 'DELETE', `${ this.endpoint }/${ id }`, undefined, signal );
	}

	private async request< T >(
		method: 'GET' | 'PUT' | 'DELETE',
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
			throw new Error( detail || __( 'Triggers request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
