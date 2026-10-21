/**
 * Pro SPA v2 — Workflow presets client (Phase 3b W10.2c).
 *
 * Wraps mcp-ai-pro/v1/workflow-presets: category/summary list + full
 * preset install (nodes/edges) for loading into the console canvas.
 * Speaks the Pro `{ ok, ... }` envelope.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';
import type { WorkflowGraph } from './workflow-builder';

export interface WorkflowPresetSummary {
	id: string;
	name: string;
	description: string;
	category: string;
	icon: string;
	tags: string[];
}

export interface WorkflowPresetFull extends WorkflowPresetSummary {
	nodes: WorkflowGraph[ 'nodes' ];
	edges: WorkflowGraph[ 'edges' ];
}

interface OkEnvelope {
	ok?: boolean;
	message?: string;
}

export interface WorkflowPresetsClientOptions {
	endpoint: string;
	nonce: string;
}

export class WorkflowPresetsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: WorkflowPresetsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	private async request< T >( path: string, init?: RequestInit ): Promise< T | null > {
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
			throw new Error( detail || __( 'Workflow presets request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			const body = ( await response.json() ) as T & OkEnvelope;
			if ( body?.ok === false ) {
				throw new Error( body.message || __( 'Workflow presets request failed.', 'nvoos-pro-spa' ) );
			}
			return body;
		} catch ( err ) {
			if ( err instanceof Error && err.message ) {
				throw err;
			}
			return null;
		}
	}

	/** GET /workflow-presets — categories + summaries. */
	async list(
		signal?: AbortSignal
	): Promise< { categories: Record< string, string >; presets: WorkflowPresetSummary[] } > {
		const body = await this.request< {
			categories?: Record< string, string >;
			presets?: WorkflowPresetSummary[];
		} >( '', { signal } );
		return {
			categories: body?.categories ?? {},
			presets: Array.isArray( body?.presets ) ? body.presets : [],
		};
	}

	/** POST /workflow-presets/{id}/install — the full preset graph. */
	async install( id: string ): Promise< WorkflowPresetFull | null > {
		const body = await this.request< { preset?: WorkflowPresetFull } >(
			`/${ encodeURIComponent( id ) }/install`,
			{ method: 'POST' }
		);
		return body?.preset ?? null;
	}
}
