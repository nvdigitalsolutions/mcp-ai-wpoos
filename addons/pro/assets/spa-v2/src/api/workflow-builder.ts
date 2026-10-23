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

export interface WorkflowRunSummary {
	id: number;
	status: string;
	started_at?: string;
	finished_at?: string;
	workflow_id?: number;
	[ k: string ]: unknown;
}

export interface WorkflowNodeExecutePayload {
	node_type: 'action' | 'tool' | 'agent';
	node_id: string;
	workflow_id: string;
	context?: Record< string, unknown >;
	command?: string;
	params?: Record< string, unknown >;
	tool_name?: string;
	tool_arguments?: Record< string, unknown >;
	agent_id?: string;
	prompt?: string;
}

export interface WorkflowBuilderClientOptions {
	endpoint: string;
	nonce: string;
	/** Node-execution REST base (mcp-ai-pro/v1/workflow-builder). */
	nodesEndpoint?: string;
	/** Workflow runs REST base (mcp-ai/v1/orchestration/runs). */
	runsEndpoint?: string;
}

export class WorkflowBuilderClient {
	private readonly endpoint: string;
	private readonly nonce: string;
	private readonly nodesEndpoint: string;
	private readonly runsEndpoint: string;

	constructor( opts: WorkflowBuilderClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
		this.nodesEndpoint = ( opts.nodesEndpoint ?? '' ).replace( /\/+$/, '' );
		this.runsEndpoint = ( opts.runsEndpoint ?? '' ).replace( /\/+$/, '' );
	}

	private async requestTo< T >( base: string, path: string, init?: RequestInit ): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
			...( init?.body ? { 'Content-Type': 'application/json' } : {} ),
		};
		const response = await fetch( `${ base }${ path }`, {
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
		const rows = await this.requestTo< WorkflowSummary[] | null >( this.endpoint, '', { signal } );
		return Array.isArray( rows ) ? rows : [];
	}

	/** GET /orchestration/workflows/{id} — bare detail object. */
	async get( id: number, signal?: AbortSignal ): Promise< WorkflowDetail | null > {
		try {
			return await this.requestTo< WorkflowDetail >( this.endpoint, `/${ id }`, { signal } );
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
		await this.requestTo( this.endpoint, '', {
			method: 'POST',
			body: JSON.stringify( payload ),
		} );
	}

	/** PUT /orchestration/workflows/{id} — update graph/name/tags. */
	async update(
		id: number,
		payload: { name?: string; graph?: WorkflowGraph; tags?: string[] }
	): Promise< void > {
		await this.requestTo( this.endpoint, `/${ id }`, {
			method: 'PUT',
			body: JSON.stringify( payload ),
		} );
	}

	/** DELETE /orchestration/workflows/{id} — trash the workflow. */
	async remove( id: number ): Promise< void > {
		await this.requestTo( this.endpoint, `/${ id }`, { method: 'DELETE' } );
	}

	/** POST /orchestration/workflows/{id}/execute — run via Engine V2. */
	async execute( id: number ): Promise< void > {
		await this.requestTo( this.endpoint, `/${ id }/execute`, { method: 'POST' } );
	}

	/** POST /workflow-builder/nodes/execute — run a single node. */
	async executeNode( payload: WorkflowNodeExecutePayload ): Promise< void > {
		if ( ! this.nodesEndpoint ) {
			throw new Error( __( 'Node execution is not available.', 'nvoos-pro-spa' ) );
		}
		await this.requestTo( this.nodesEndpoint, '/nodes/execute', {
			method: 'POST',
			body: JSON.stringify( payload ),
		} );
	}

	/** GET /orchestration/runs?workflow_id=… — run history for a workflow. */
	async runs( workflowId: number ): Promise< WorkflowRunSummary[] > {
		if ( ! this.runsEndpoint ) {
			return [];
		}
		try {
			const rows = await this.requestTo< unknown >(
				this.runsEndpoint,
				`?workflow_id=${ workflowId }`
			);
			return Array.isArray( rows ) ? ( rows as WorkflowRunSummary[] ) : [];
		} catch {
			return [];
		}
	}
}
