/**
 * Pro SPA v2 — Token Manager client.
 *
 * Wraps mcp-ai/v1/token-manager for the TokenManagerPage
 * (Proposal 084 Phase 3). Mirrors the DiagnosticsClient fetch discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export type TokenCredentialStatus = 'active' | 'revoked';

export interface TokenCredential {
	id: string;
	status: TokenCredentialStatus;
	assistant_id: number;
	assistant_title: string;
	created_at: string;
	created_by: number;
	created_by_name: string;
	revoked_at: string;
	revoked_by: number;
	expires_at: string;
}

export interface TokenManagerStats {
	total: number;
	active: number;
	revoked: number;
	assistants: number;
}

export interface TokenRestriction {
	user_id: number;
	user_login: string;
	display_name: string;
	type: string;
	type_label: string;
	scope: string;
	reason: string;
	triggered_at: number;
}

export interface TokenManagerOverview {
	stats: TokenManagerStats;
	credentials: TokenCredential[];
	restrictions: TokenRestriction[];
}

export interface TokenManagerClientOptions {
	endpoint: string;
	nonce: string;
}

export class TokenManagerClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: TokenManagerClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	private async request< T >(
		path: string,
		init?: RequestInit
	): Promise< T | null > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
			...( init?.body ? { 'Content-Type': 'application/json' } : {} ),
		};
		const response = await fetch( `${ this.endpoint }${ path }`, {
			method: init?.method ?? 'GET',
			credentials: 'same-origin',
			headers,
			body: init?.body,
			signal: init?.signal,
		} );
		if ( ! response.ok ) {
			let detail = '';
			try {
				const err = ( await response.json() ) as { message?: string };
				detail = err?.message ?? '';
			} catch {
				// Not JSON — keep the generic message.
			}
			throw new Error( detail || __( 'Token manager request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			const data = ( await response.json() ) as { success?: boolean; data?: T };
			return data?.data ?? null;
		} catch {
			return null;
		}
	}

	/** GET /token-manager/overview — stats + credentials + restrictions. */
	async overview( signal?: AbortSignal ): Promise< TokenManagerOverview | null > {
		const data = await this.request< TokenManagerOverview >( '/overview', { signal } );
		return data ?? {
			stats: { total: 0, active: 0, revoked: 0, assistants: 0 },
			credentials: [],
			restrictions: [],
		};
	}

	/** POST /token-manager/credentials/{assistant}/{credential}/revoke. */
	async revokeCredential(
		assistantId: number,
		credentialId: string
	): Promise< void > {
		await this.request< unknown >(
			`/credentials/${ assistantId }/${ encodeURIComponent( credentialId ) }/revoke`,
			{ method: 'POST' }
		);
	}

	/** POST /token-manager/credentials/{assistant}/{credential}/delete. */
	async deleteCredential(
		assistantId: number,
		credentialId: string
	): Promise< void > {
		await this.request< unknown >(
			`/credentials/${ assistantId }/${ encodeURIComponent( credentialId ) }/delete`,
			{ method: 'POST' }
		);
	}

	/** POST /token-manager/restrictions/{user}/lift. */
	async liftRestriction( userId: number, type = 'all' ): Promise< void > {
		await this.request< unknown >( `/restrictions/${ userId }/lift`, {
			method: 'POST',
			body: JSON.stringify( { type } ),
		} );
	}
}
