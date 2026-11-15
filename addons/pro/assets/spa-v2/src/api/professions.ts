/**
 * Professions client — the assistant editor's profession catalogue.
 *
 * Wraps `/api/v1/professions` (Proposal 090-g, Phase 4). Laravel-only: the
 * WordPress runtime has no professions REST route, so callers must handle an
 * empty endpoint and hide the selector.
 */

import { __ } from '@wordpress/i18n';

export interface ProfessionSummary {
	slug: string;
	title: string;
	category: string;
	category_slug: string;
	description: string;
	expertise: string[];
	tools_count: number;
}

export interface ProfessionDetail extends ProfessionSummary {
	role_description: string;
	warnings: string[];
	knowledge_base: string;
	recommended_tools: string[];
}

export interface ProfessionList {
	professions: ProfessionSummary[];
	total: number;
	catalogue_version: string | null;
}

export interface ProfessionsClientOptions {
	endpoint: string;
	nonce: string;
}

export class ProfessionsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: ProfessionsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	async list( signal?: AbortSignal ): Promise< ProfessionList > {
		const data = await this.request< Partial< ProfessionList > >( this.endpoint, signal );

		return {
			professions: Array.isArray( data?.professions ) ? data.professions : [],
			total: typeof data?.total === 'number' ? data.total : 0,
			catalogue_version:
				typeof data?.catalogue_version === 'string' ? data.catalogue_version : null,
		};
	}

	async get( slug: string, signal?: AbortSignal ): Promise< ProfessionDetail > {
		const data = await this.request< { success?: boolean; data?: ProfessionDetail } >(
			`${ this.endpoint }/${ encodeURIComponent( slug ) }`,
			signal
		);

		if ( ! data?.data ) {
			throw new Error( __( 'Profession not found.', 'nvoos-pro-spa' ) );
		}

		return data.data;
	}

	private async request< T >( url: string, signal?: AbortSignal ): Promise< T > {
		const response = await fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': this.nonce,
			},
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
			throw new Error( detail || __( 'Professions request failed.', 'nvoos-pro-spa' ) );
		}

		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
