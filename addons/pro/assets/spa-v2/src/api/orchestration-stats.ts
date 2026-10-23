/**
 * Pro SPA v2 — Orchestration stats client.
 *
 * Wraps mcp-ai/v1/orchestration/stats for the OrchestrationStatsPage
 * (Proposal 084 Phase 3). Mirrors the DiagnosticsClient fetch discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface OrchestrationAgent {
	id: number;
	title: string;
	slug: string;
	status: string;
	description: string;
	provider: string;
	model: string;
	tools: string[];
	tool_count: number;
	primary_roles: string[];
	last_used: string;
	workflow_pattern: string;
}

export interface OrchestrationMultiAgent {
	installed: boolean;
	total_agents: number;
	active_agents: number;
	total_tools: number;
	is_pro_active: boolean;
	patterns: Record< string, OrchestrationAgent[] >;
	agents: OrchestrationAgent[];
}

export interface OrchestrationSystemStatus {
	cron: Record< string, unknown >;
	async: Record< string, unknown >;
	sse: Record< string, unknown >;
	health: Record< string, unknown >;
}

export interface OrchestrationStats {
	total_professions: number;
	seeded_professions: number;
	with_task_patterns: number;
	seeder_version: string;
	roles: Record< string, number >;
	system_status: OrchestrationSystemStatus;
}

export interface OrchestrationWorkflow {
	workflow_id: string;
	state: string;
	tasks_total: number;
	tasks_done: number;
	created_at: string;
	updated_at: string;
	started_at: string;
	completed_at: string;
	team_id: number;
	task_type: string;
}

export interface OrchestrationStatsOverview {
	multi_agent: OrchestrationMultiAgent;
	orchestration: OrchestrationStats;
	recent_workflows: OrchestrationWorkflow[];
}

export interface OrchestrationStatsClientOptions {
	endpoint: string;
	nonce: string;
}

export class OrchestrationStatsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: OrchestrationStatsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** GET /orchestration/stats — the consolidated stats payload. */
	async overview( signal?: AbortSignal ): Promise< OrchestrationStatsOverview | null > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
		};
		const response = await fetch( `${ this.endpoint }`, {
			method: 'GET',
			credentials: 'same-origin',
			headers,
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
			throw new Error( detail || __( 'Orchestration stats request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			const data = ( await response.json() ) as { success?: boolean; data?: OrchestrationStatsOverview };
			return data?.data ?? null;
		} catch {
			return null;
		}
	}
}
