/**
 * Pro SPA v2 — Pro Ops client.
 *
 * Wraps mcp-ai-pro/v1/ops/overview for the ProOpsPage
 * (Proposal 084 Phase 3, gap P11). Mirrors the DiagnosticsClient fetch
 * discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface ProOpsComponent {
	slug: string;
	name: string;
	group: string;
	public: boolean;
	status?: string;
	[ k: string ]: unknown;
}

export interface ProOpsStatus {
	overall: string;
	components: ProOpsComponent[];
	last_checked: number;
}

export interface ProOpsIncident {
	id: number;
	title: string;
	phase: string;
	severity: string;
	services: string[];
	resolved_at: string;
	date: string;
}

export interface ProOpsMaintenance {
	id: number;
	title: string;
	status: string;
	start: string;
	end: string;
	banner_enabled: boolean;
}

export interface ProOpsWebhook {
	id: string;
	type: string;
	label: string;
	expected_url: string;
	webhook_set: boolean;
	last_checked_text: string;
}

export interface ProOpsOverview {
	status: ProOpsStatus;
	incidents: ProOpsIncident[];
	maintenance: ProOpsMaintenance[];
	webhooks: ProOpsWebhook[];
}

export interface ProOpsClientOptions {
	endpoint: string;
	nonce: string;
}

export class ProOpsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: ProOpsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** GET /ops/overview — the consolidated Pro ops payload. */
	async overview( signal?: AbortSignal ): Promise< ProOpsOverview | null > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
		};
		const response = await fetch( `${ this.endpoint }/overview`, {
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
			throw new Error( detail || __( 'Pro ops request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			const data = ( await response.json() ) as { success?: boolean; data?: ProOpsOverview };
			return data?.data ?? null;
		} catch {
			return null;
		}
	}
}
