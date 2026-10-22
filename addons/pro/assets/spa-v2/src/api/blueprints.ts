/**
 * Pro SPA v2 — Assistant blueprints client.
 *
 * Wraps mcp-ai-pro/v1/blueprints for the console BlueprintsPage
 * (Proposal 084 Phase 5c): the grouped catalogue, the detail payload, and
 * the install action — the REST bridge over the Unified Blueprints page's
 * promoted seams. Mirrors the DiagnosticsClient fetch discipline
 * (same-origin, X-WP-Nonce).
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

/** One blueprint row in the catalogue. */
export interface BlueprintSummary {
	slug: string;
	name: string;
	description: string;
	tool_count: number;
	installed: boolean;
	assistant_id: number;
}

/** One enabled toolkit group in the catalogue. */
export interface BlueprintToolkit {
	slug: string;
	label: string;
	blueprints: BlueprintSummary[];
}

/** GET /blueprints envelope. */
export interface BlueprintCatalogue {
	total: number;
	toolkits: BlueprintToolkit[];
}

/** GET /blueprints/{toolkit}/{slug} detail payload. */
export interface BlueprintDetails {
	toolkit: string;
	slug: string;
	name: string;
	description: string;
	instructions: string;
	tools: string[];
	defaults: Record< string, string | string[] | number > | null;
}

export interface BlueprintsClientOptions {
	endpoint: string;
	nonce: string;
}

export class BlueprintsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: BlueprintsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	private async request< T >(
		path: string,
		init: RequestInit = {},
	): Promise< T > {
		const response = await fetch( `${ this.endpoint }${ path }`, {
			...init,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': this.nonce,
				...( init.body ? { 'Content-Type': 'application/json' } : {} ),
				...( init.headers ?? {} ),
			},
		} );
		if ( ! response.ok ) {
			let detail = '';
			try {
				const err = ( await response.json() ) as { message?: string };
				detail = err?.message ?? '';
			} catch {
				// Not JSON — keep the generic message.
			}
			throw new Error( detail || __( 'Blueprints request failed.', 'nvoos-pro-spa' ) );
		}
		const data = ( await response.json() ) as { success?: boolean; data?: T };
		if ( data?.success !== true || ! data.data ) {
			throw new Error( __( 'Blueprints request failed.', 'nvoos-pro-spa' ) );
		}
		return data.data;
	}

	/** GET /blueprints — the grouped catalogue. */
	async list( signal?: AbortSignal ): Promise< BlueprintCatalogue > {
		return this.request< BlueprintCatalogue >( '', { method: 'GET', signal } );
	}

	/** GET /blueprints/{toolkit}/{slug} — instructions, tools, defaults. */
	async details( toolkit: string, slug: string ): Promise< BlueprintDetails > {
		return this.request< BlueprintDetails >(
			`/${ encodeURIComponent( toolkit ) }/${ encodeURIComponent( slug ) }`
		);
	}

	/** POST /blueprints/{toolkit}/{slug}/install — create the assistant. */
	async install(
		toolkit: string,
		slug: string,
		overwrite = false,
	): Promise< { toolkit: string; slug: string; assistant_id: number } > {
		return this.request( `/${ encodeURIComponent( toolkit ) }/${ encodeURIComponent( slug ) }/install`, {
			method: 'POST',
			body: JSON.stringify( { overwrite } ),
		} );
	}
}
