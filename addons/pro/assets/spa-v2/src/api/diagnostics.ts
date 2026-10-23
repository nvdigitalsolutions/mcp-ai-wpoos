/**
 * Pro SPA v2 — Diagnostics client.
 *
 * Wraps mcp-ai/v1/diagnostics/overview for the DiagnosticsPage
 * (Proposal 084 Phase 3). Mirrors the OperationsClient fetch discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface DiagnosticsHealth {
	status: string;
	timestamp?: number;
	version?: string;
	checks?: Record< string, unknown >;
	[ k: string ]: unknown;
}

export interface DiagnosticsSystem {
	wp_version: string;
	php_version: string;
	plugin_version: string;
	memory_limit: string;
	is_multisite: boolean;
}

export interface DiagnosticsSettingsDashboard {
	constants: Record< string, boolean >;
	classes: Record< string, boolean >;
	dashboard_global_set: boolean;
	admin_settings_global: boolean;
	settings_init_function: boolean;
	sections_total: number;
}

export interface DiagnosticsMcpServer {
	auth0_configured: boolean;
	federation_enabled: boolean;
	federation_directory_enabled: boolean;
	mesh_enabled: boolean;
	tool_count: number;
	mcp_routes_present: boolean;
}

export interface ProviderDiagnostic {
	slug: string;
	label: string;
	has_credentials: boolean;
	key_source: string;
}

export interface DiagnosticsOverview {
	generated_at: number;
	health: DiagnosticsHealth;
	system: DiagnosticsSystem;
	settings_dashboard: DiagnosticsSettingsDashboard;
	mcp_server: DiagnosticsMcpServer;
	providers: ProviderDiagnostic[];
	rest_context: Record< string, unknown >;
	pro_dashboard: Record< string, unknown >;
}

export interface DiagnosticsClientOptions {
	endpoint: string;
	nonce: string;
}

export class DiagnosticsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: DiagnosticsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** GET /diagnostics/overview — the consolidated diagnostics payload. */
	async overview( signal?: AbortSignal ): Promise< DiagnosticsOverview | null > {
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
			throw new Error( detail || __( 'Diagnostics request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			const data = ( await response.json() ) as { success?: boolean; data?: DiagnosticsOverview };
			return data?.data ?? null;
		} catch {
			return null;
		}
	}
}
