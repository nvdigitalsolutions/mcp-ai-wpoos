/**
 * Org teams client — the tenant's people, not the agent-team catalogue.
 *
 * Wraps `/api/v1/org/teams` (Proposal 090-g, Phase 3). The path is namespaced
 * because `mcp-ai/v1/teams` already means "a collection of professions" — the
 * surface `TeamsPage` renders. This surface is Laravel-only until the
 * WordPress runtime grows the same contract, so callers must handle an empty
 * endpoint (see OrgTeamsPage).
 */

import { __ } from '@wordpress/i18n';

/** The constrained role set; the database enum is the authority. */
export const ORG_TEAM_ROLES = [ 'owner', 'admin', 'manager', 'member', 'viewer' ] as const;

export type OrgTeamRole = ( typeof ORG_TEAM_ROLES )[ number ];

export interface OrgTeamSummary {
	id: number;
	name: string;
	slug: string;
	/** The current user's role on this team. */
	role: string;
	member_count: number;
}

export interface OrgTeamMember {
	id: number;
	team_id: number;
	user_id: number;
	role: string;
	profession_slug: string | null;
	invited_by: number | null;
	joined_at: string | null;
	/** Present when the server joined the user record. */
	name?: string;
	email?: string;
}

export interface OrgTeamDetail {
	id: number;
	name: string;
	slug: string;
	description: string | null;
	member_count: number;
	created_at?: string | null;
	updated_at?: string | null;
	members?: OrgTeamMember[];
}

export interface OrgTeamCreateInput {
	name: string;
	description?: string | null;
	slug?: string;
}

export interface OrgTeamUpdateInput {
	name?: string;
	description?: string | null;
	slug?: string;
}

export interface OrgTeamMemberInput {
	user_id: number;
	role?: OrgTeamRole;
	profession_slug?: string | null;
}

export interface OrgTeamsClientOptions {
	endpoint: string;
	nonce: string;
}

type Json = Record< string, unknown > | undefined;

export class OrgTeamsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: OrgTeamsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** List the teams the current user belongs to. */
	async list( signal?: AbortSignal ): Promise< OrgTeamSummary[] > {
		const data = await this.request< { teams?: OrgTeamSummary[] } >( 'GET', this.endpoint, undefined, signal );

		return Array.isArray( data?.teams ) ? data.teams : [];
	}

	/** Fetch one team with its members. Throws when the team is gone. */
	async get( teamId: number, signal?: AbortSignal ): Promise< OrgTeamDetail > {
		const data = await this.request< { success?: boolean; data?: OrgTeamDetail } >(
			'GET',
			`${ this.endpoint }/${ teamId }`,
			undefined,
			signal
		);

		if ( ! data?.data ) {
			throw new Error( __( 'Team not found.', 'nvoos-pro-spa' ) );
		}

		return data.data;
	}

	async create( input: OrgTeamCreateInput ): Promise< OrgTeamDetail > {
		return this.request< OrgTeamDetail >( 'POST', this.endpoint, input );
	}

	async update( teamId: number, input: OrgTeamUpdateInput ): Promise< OrgTeamDetail > {
		const data = await this.request< { success?: boolean; data?: OrgTeamDetail } >(
			'PATCH',
			`${ this.endpoint }/${ teamId }`,
			input
		);

		if ( ! data?.data ) {
			throw new Error( __( 'Team not updated.', 'nvoos-pro-spa' ) );
		}

		return data.data;
	}

	async remove( teamId: number ): Promise< void > {
		await this.request< Json >( 'DELETE', `${ this.endpoint }/${ teamId }` );
	}

	async addMember( teamId: number, input: OrgTeamMemberInput ): Promise< OrgTeamMember > {
		// A re-add is a role change and answers 200 with an envelope; a fresh
		// add answers 201 with the member record. Both are success.
		const data = await this.request< OrgTeamMember & { data?: OrgTeamMember } >(
			'POST',
			`${ this.endpoint }/${ teamId }/members`,
			input
		);

		const member = data?.data ?? data;

		if ( ! member || typeof member.user_id !== 'number' ) {
			throw new Error( __( 'Member not saved.', 'nvoos-pro-spa' ) );
		}

		return member;
	}

	async changeRole( teamId: number, userId: number, role: OrgTeamRole ): Promise< OrgTeamMember > {
		const data = await this.request< { success?: boolean; data?: OrgTeamMember } >(
			'PATCH',
			`${ this.endpoint }/${ teamId }/members/${ userId }`,
			{ role }
		);

		if ( ! data?.data ) {
			throw new Error( __( 'Role not updated.', 'nvoos-pro-spa' ) );
		}

		return data.data;
	}

	async removeMember( teamId: number, userId: number ): Promise< void > {
		await this.request< Json >( 'DELETE', `${ this.endpoint }/${ teamId }/members/${ userId }` );
	}

	private async request< T >(
		method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
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
				detail = typeof err?.message === 'string' ? err.message : '';
			} catch {
				// Not JSON — keep the generic message.
			}
			throw new Error( detail || __( 'Org teams request failed.', 'nvoos-pro-spa' ) );
		}

		if ( 204 === response.status ) {
			return undefined as unknown as T;
		}

		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
