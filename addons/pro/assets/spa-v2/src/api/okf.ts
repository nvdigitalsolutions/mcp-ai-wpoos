/**
 * Pro SPA v2 — typed wrappers around `mcp-ai-pro/v1/okf`.
 *
 * Routes exposed by WP_MCP_AI_Pro_REST_Okf:
 *
 *   GET /bundles                              — { bundles: OkfBundle[] }
 *   GET /bundles/{bundle}/concepts            — { bundle, concepts: OkfConceptSummary[], total }
 *   GET /bundles/{bundle}/concepts/{concept}  — { bundle, concept_id, frontmatter, body, links, trust_tier, stale }
 *   GET /search?q=                            — { query, results: OkfSearchResult[], total }
 *   GET /skills?assistant_id=N                — { assistant_id, skills: OkfSkill[] }
 *
 * @since 2.1.1
 */

import { __ } from '@wordpress/i18n';

export interface OkfBundle {
	name: string;
	protected: boolean;
	concept_count: number;
	stale_count: number;
	deprecated_count: number;
	conformant: boolean;
	issue_count: number;
	types: string[];
	trust_tiers: number[];
	modified: number;
}

export interface OkfConceptSummary {
	concept_id: string;
	type: string;
	title: string;
	description: string;
	tags: string[];
	status: 'draft' | 'stable' | 'deprecated' | string;
	trust_tier: 'unverified' | 'machine-confirmed' | 'human-reviewed' | string;
	stale: boolean;
	/** Present on cross-bundle search results only. */
	bundle?: string;
}

export interface OkfFrontmatter {
	title?: string;
	description?: string;
	type?: string;
	tags?: string[];
	status?: string;
	verified?: unknown;
	stale_after?: string;
	generated?: string;
	usage_window?: string;
}

export interface OkfConceptDetail {
	bundle: string;
	concept_id: string;
	frontmatter: OkfFrontmatter;
	body: string;
	links: string[];
	trust_tier: OkfConceptSummary[ 'trust_tier' ];
	stale: boolean;
}

export interface OkfSearchResult extends OkfConceptSummary {
	bundle: string;
}

export interface OkfSkill {
	name: string;
	bundle: string;
	concept_id: string;
	title: string;
	description: string;
	type: string;
	status: string;
	trust_tier: OkfConceptSummary[ 'trust_tier' ];
	stale: boolean;
	loadable: boolean;
	error: string;
}

export interface OkfConceptQuery {
	q?: string;
	type?: string;
	status?: string;
	trust_tier?: string;
	include_stale?: boolean;
	limit?: number;
}

export interface OkfClientOptions {
	endpoint: string;
	nonce: string;
}

export class OkfClient {
	private readonly base: string;
	private readonly nonce: string;

	constructor( opts: OkfClientOptions ) {
		this.base = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	async listBundles( signal?: AbortSignal ): Promise< OkfBundle[] > {
		const data = await this.request< { bundles?: OkfBundle[] } >(
			'GET',
			`${ this.base }/bundles`,
			undefined,
			signal
		);
		return Array.isArray( data?.bundles ) ? data.bundles : [];
	}

	async listConcepts(
		bundle: string,
		query: OkfConceptQuery = {},
		signal?: AbortSignal
	): Promise< { concepts: OkfConceptSummary[]; total: number } > {
		const url = new URL(
			`${ this.base }/bundles/${ encodeURIComponent( bundle ) }/concepts`,
			window.location.origin
		);
		if ( query.q ) url.searchParams.set( 'q', query.q );
		if ( query.type ) url.searchParams.set( 'type', query.type );
		if ( query.status ) url.searchParams.set( 'status', query.status );
		if ( query.trust_tier ) url.searchParams.set( 'trust_tier', query.trust_tier );
		if ( typeof query.include_stale === 'boolean' ) {
			url.searchParams.set( 'include_stale', query.include_stale ? '1' : '0' );
		}
		if ( query.limit ) url.searchParams.set( 'limit', String( query.limit ) );

		const data = await this.request< {
			concepts?: OkfConceptSummary[];
			total?: number;
		} >( 'GET', url.toString(), undefined, signal );
		return {
			concepts: Array.isArray( data?.concepts ) ? data.concepts : [],
			total: typeof data?.total === 'number' ? data.total : 0,
		};
	}

	async getConcept(
		bundle: string,
		conceptId: string,
		signal?: AbortSignal
	): Promise< OkfConceptDetail > {
		return this.request< OkfConceptDetail >(
			'GET',
			`${ this.base }/bundles/${ encodeURIComponent( bundle ) }/concepts/${ encodeURIComponent( conceptId ) }`,
			undefined,
			signal
		);
	}

	async search(
		q: string,
		signal?: AbortSignal
	): Promise< { results: OkfSearchResult[]; total: number } > {
		const url = new URL( `${ this.base }/search`, window.location.origin );
		url.searchParams.set( 'q', q );

		const data = await this.request< {
			results?: OkfSearchResult[];
			total?: number;
		} >( 'GET', url.toString(), undefined, signal );
		return {
			results: Array.isArray( data?.results ) ? data.results : [],
			total: typeof data?.total === 'number' ? data.total : 0,
		};
	}

	async listSkills(
		assistantId: number | string,
		signal?: AbortSignal
	): Promise< OkfSkill[] > {
		const url = new URL( `${ this.base }/skills`, window.location.origin );
		if ( assistantId ) {
			url.searchParams.set( 'assistant_id', String( assistantId ) );
		}

		const data = await this.request< { skills?: OkfSkill[] } >(
			'GET',
			url.toString(),
			undefined,
			signal
		);
		return Array.isArray( data?.skills ) ? data.skills : [];
	}

	private async request< T = unknown >(
		method: 'GET',
		url: string,
		_body?: undefined,
		signal?: AbortSignal
	): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
		};
		if ( this.nonce ) {
			headers[ 'X-WP-Nonce' ] = this.nonce;
		}

		const response = await fetch( url, {
			method,
			credentials: 'same-origin',
			headers,
			signal,
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
				// Not JSON.
			}
			throw new Error(
				detail ||
					__( 'OKF request failed (status %d).', 'nvoos-pro-spa' ).replace(
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
