/**
 * Pro SPA v2 — Teams client.
 *
 * Wraps mcp-ai/v1/teams (catalogue, 2.4.0) and /teams/{id}/members for the
 * TeamsPage. Mirrors the ToolsClient fetch discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface TeamSummary {
	id: number;
	title: string;
	member_count: number;
}

export interface TeamMember {
	id?: number;
	title?: string;
	description?: string;
	category?: string;
	expertise?: string[];
	tools_count?: number;
	[ k: string ]: unknown;
}

export interface TeamMembersResponse {
	success?: boolean;
	team_id?: number;
	team_title?: string;
	members?: TeamMember[];
	[ k: string ]: unknown;
}

export interface TeamsClientOptions {
	endpoint: string;
	nonce: string;
}

export class TeamsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: TeamsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** List all teams with member counts. */
	async list( signal?: AbortSignal ): Promise< TeamSummary[] > {
		const data = await this.request< { success?: boolean; teams?: TeamSummary[] } >(
			'GET',
			this.endpoint,
			undefined,
			signal
		);
		return Array.isArray( data?.teams ) ? data.teams : [];
	}

	/** Fetch the profession members of one team. */
	async members( teamId: number, signal?: AbortSignal ): Promise< TeamMembersResponse > {
		return this.request< TeamMembersResponse >(
			'GET',
			`${ this.endpoint }/${ teamId }/members`,
			undefined,
			signal
		);
	}

	private async request< T >(
		method: 'GET',
		url: string,
		body?: unknown,
		signal?: AbortSignal
	): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
		};
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
			throw new Error( detail || __( 'Teams request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
