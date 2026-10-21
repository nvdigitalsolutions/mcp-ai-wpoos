/**
 * Pro SPA v2 — Workflow builder client (Proposal 084 Phase 3b, W10.2a).
 *
 * Wraps the existing base REST surface mcp-ai/v1/orchestration/workflows
 * (WP_MCP_AI_REST_Workflow_CPT_Controller) — list/create/get/update/
 * delete/execute/export/import on the mcp_ai_workflow CPT. The controller
 * predates this port: lists come back as bare arrays, details as bare
 * objects, and failures as WP_Error JSON.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface WorkflowSummary {
	id: number;
	title: string;
	version: string;
	tags: string[];
	modified: string;
}

export interface WorkflowGraphNode {
	id: string;
	type?: string;
	label?: string;
	[ k: string ]: unknown;
}

export interface WorkflowGraphEdge {
	id?: string;
	source?: string;
	target?: string;
	[ k: string ]: unknown;
}

export interface WorkflowGraph {
	nodes: WorkflowGraphNode[];
	edges: WorkflowGraphEdge[];
}

export interface WorkflowDetail extends WorkflowSummary {
	description: string;
	graph: WorkflowGraph;
}

export interface WorkflowBuilderClientOptions {
	endpoint: string;
	nonce: string;
}

export class WorkflowBuilderClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: WorkflowBuilderClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	private async request< T >( path: string, init?: RequestInit ): Promise< T > {
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
			throw new Error( detail || __( 'Workflow request failed.', 'nvoos-pro-spa' ) );
		}
		return ( await response.json() ) as T;
	}

	/** GET /orchestration/workflows — bare array of summaries. */
	async list( signal?: AbortSignal ): Promise< WorkflowSummary[] > {
		const rows = await this.request< WorkflowSummary[] | null >( '', { signal } );
		return Array.isArray( rows ) ? rows : [];
	}

	/** GET /orchestration/workflows/{id} — bare detail object. */
	async get( id: number, signal?: AbortSignal ): Promise< WorkflowDetail | null > {
		try {
			return await this.request< WorkflowDetail >( `/${ id }`, { signal } );
		} catch {
			return null;
		}
	}

	/** POST /orchestration/workflows — create with name/graph/tags. */
	async create( payload: {
		name: string;
		graph?: WorkflowGraph;
		tags?: string[];
	} ): Promise< void > {
		await this.request( '', {
			method: 'POST',
			body: JSON.stringify( payload ),
		} );
	}

	/** PUT /orchestration/workflows/{id} — update graph/name/tags. */
	async update(
		id: number,
		payload: { name?: string; graph?: WorkflowGraph; tags?: string[] }
	): Promise< void > {
		await this.request( `/${ id }`, {
			method: 'PUT',
			body: JSON.stringify( payload ),
		} );
	}

	/** DELETE /orchestration/workflows/{id} — trash the workflow. */
	async remove( id: number ): Promise< void > {
		await this.request( `/${ id }`, { method: 'DELETE' } );
	}

	/** POST /orchestration/workflows/{id}/execute — run via Engine V2. */
	async execute( id: number ): Promise< void > {
		await this.request( `/${ id }/execute`, { method: 'POST' } );
	}
}
