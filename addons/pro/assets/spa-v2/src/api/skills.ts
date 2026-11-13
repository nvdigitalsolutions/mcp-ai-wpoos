/**
 * Pro SPA v2 — installed Agent Skills catalogue client.
 *
 * Consumes `GET mcp-ai/v1/skills` (admin-only): catalogue metadata only.
 * Skill instructions are injected server-side by the prompt builder and are
 * never shipped on this route.
 */

import { __ } from '@wordpress/i18n';

/** One installed skill as served by the catalogue route. */
export interface SkillOption {
	name: string;
	description: string;
	license: string;
	compatibility: string;
}

interface SkillsListResponse {
	success?: boolean;
	skills?: SkillOption[];
	total?: number;
	code?: string;
	message?: string;
}

export interface SkillsClientOptions {
	endpoint: string;
	nonce: string;
}

export class SkillsClient {
	private readonly endpoint: string;

	private readonly nonce: string;

	constructor( opts: SkillsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	async list( signal?: AbortSignal ): Promise< SkillOption[] > {
		const response = await fetch( this.endpoint, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			signal,
		} );

		const data = ( await response.json().catch( () => ( {} ) ) ) as
			| SkillsListResponse
			| undefined;

		if ( ! response.ok ) {
			throw new Error(
				data?.message ||
					__( 'Could not load the skills catalogue.', 'nvoos-pro-spa' )
			);
		}

		return Array.isArray( data?.skills ) ? data.skills : [];
	}
}
