/**
 * Pro SPA v2 — Command Center client.
 *
 * Wraps mcp-ai-pro/v1/command-center/overview for the console Dashboard's
 * command-center section (Proposal 084 Phase 5d): the KPI row, the agent
 * status map, the restriction banner count, and the recent activity feed —
 * the same collectors the wp-admin overview tab renders. Read-only;
 * approval handling and restriction lift stay on their own ported surfaces.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export type AgentStatus = 'online' | 'idle' | 'offline';

export interface CommandCenterKpis {
	total_agents: number;
	agents_online: number;
	active_tasks: number;
	pending_approvals: number;
	tokens_today: number;
	uptime: number;
}

export interface AgentStatusEntry {
	status: AgentStatus;
	last_active: number;
}

/** One activity-log entry (the recorder's shape; logger-merged rows may vary). */
export interface ActivityEvent {
	type?: string;
	agent_id?: number;
	agent_name?: string;
	message?: string;
	timestamp?: number;
	[ k: string ]: unknown;
}

export interface CommandCenterOverview {
	kpis: CommandCenterKpis;
	restriction_count: number;
	agent_statuses: Record< number, AgentStatusEntry >;
	recent_events: ActivityEvent[];
}

export interface CommandCenterClientOptions {
	endpoint: string;
	nonce: string;
}

export class CommandCenterClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: CommandCenterClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** GET /command-center/overview — the dashboard payload. */
	async overview( signal?: AbortSignal ): Promise< CommandCenterOverview | null > {
		const response = await fetch( `${ this.endpoint }/overview`, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			signal,
		} );
		if ( ! response.ok ) {
			throw new Error( __( 'Command center request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			const data = ( await response.json() ) as {
				success?: boolean;
				data?: CommandCenterOverview;
			};
			return data?.success === true && data.data ? data.data : null;
		} catch {
			return null;
		}
	}
}
