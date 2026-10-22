/**
 * Pro SPA v2 — Analytics client.
 *
 * Wraps the site-wide cost REST surface (mcp-ai/v1/cost, manage_options-
 * gated) for the console AnalyticsPage (Proposal 084 Phase 5b): dashboard
 * summary, cost trend, and provider breakdown — the data behind the wp-admin
 * cost-breakdown / usage-forecast dashboard widgets. Mirrors the
 * DiagnosticsClient fetch discipline (same-origin, X-WP-Nonce).
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

/** One provider's cost slice (keyed map: provider slug → cost float). */
export type CostByProviderMap = Record< string, number >;

/** Enriched per-user cost row from the dashboard summary. */
export interface CostTopUser {
	name: string;
	cost: number;
}

/** Chart-ready dataset (labels + one series) — the wp-admin Chart.js shape. */
export interface CostChartData {
	labels: string[];
	datasets: Array< {
		label?: string;
		data: number[];
		backgroundColor?: string | string[];
		borderColor?: string;
		borderWidth?: number;
		fill?: boolean;
	} >;
}

/** GET /cost/dashboard-summary — the widget-parity envelope. */
export interface CostDashboardSummary {
	total_cost: number;
	total_tokens: number;
	by_provider: CostByProviderMap;
	by_model: Record<
		string,
		{ provider: string; model: string; total_cost: number; total_tokens: number }
	>;
	by_tool: Record< string, number >;
	period_start: string;
	period_end: string;
	top_tools: Record< string, number >;
	top_users: Record< string, CostTopUser >;
}

export interface AnalyticsClientOptions {
	/** mcp-ai/v1/cost base URL (empty for non-admin sessions). */
	endpoint: string;
	nonce: string;
}

export class AnalyticsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: AnalyticsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** GET with the shared header discipline; throws a readable error. */
	private async get< T >( path: string, signal?: AbortSignal ): Promise< T > {
		const response = await fetch( `${ this.endpoint }${ path }`, {
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
				detail = err?.message ?? '';
			} catch {
				// Not JSON — keep the generic message.
			}
			throw new Error( detail || __( 'Analytics request failed.', 'nvoos-pro-spa' ) );
		}
		return ( await response.json() ) as T;
	}

	/** GET /cost/dashboard-summary?days= — widget-parity cost summary. */
	async summary( days: number, signal?: AbortSignal ): Promise< CostDashboardSummary > {
		const data = await this.get< { summary: CostDashboardSummary } >(
			`/dashboard-summary?days=${ days }`,
			signal
		);
		return data.summary;
	}

	/** GET /cost/trend?days= — chart-ready daily cost series. */
	async trend( days: number, signal?: AbortSignal ): Promise< CostChartData > {
		const data = await this.get< { data: CostChartData } >(
			`/trend?days=${ days }`,
			signal
		);
		return data.data;
	}

	/** GET /cost/by-provider?days= — chart-ready provider distribution. */
	async byProvider( days: number, signal?: AbortSignal ): Promise< CostChartData > {
		const data = await this.get< { data: CostChartData } >(
			`/by-provider?days=${ days }`,
			signal
		);
		return data.data;
	}
}

/**
 * Daily cost trend → nvoos-chart `line` fence (schema-safe: ≤40 labels).
 * Returns '' when there is nothing to chart.
 */
export function costTrendSpec( title: string, trend: CostChartData | null ): string {
	const series = trend?.datasets?.[ 0 ];
	if ( ! trend || ! series || series.data.length === 0 ) {
		return '';
	}
	return JSON.stringify( {
		type: 'line',
		title,
		labels: trend.labels.slice( -40 ),
		values: series.data.slice( -40 ),
		unit: '$',
		sort: 'none',
	} );
}

/**
 * Provider distribution → nvoos-chart `donut` fence.
 *
 * The schema caps donut slices at 6, so the top 5 providers render and the
 * remainder aggregates into an "Other" slice. Returns '' for empty data.
 */
export function providerDonutSpec( title: string, byProvider: CostChartData | null ): string {
	const labels = byProvider?.labels ?? [];
	const values = byProvider?.datasets?.[ 0 ]?.data ?? [];
	const entries = labels
		.map( ( label, index ) => ( { label, value: values[ index ] ?? 0 } ) )
		.filter( ( entry ) => typeof entry.value === 'number' && entry.value > 0 )
		.sort( ( a, b ) => b.value - a.value );
	if ( entries.length === 0 ) {
		return '';
	}
	const top = entries.slice( 0, 5 );
	const rest = entries.slice( 5 );
	const items = rest.length
		? [
				...top,
				{
					label: __( 'Other', 'nvoos-pro-spa' ),
					value: rest.reduce( ( sum, entry ) => sum + entry.value, 0 ),
				},
		  ]
		: top;
	return JSON.stringify( {
		type: 'donut',
		title,
		data: {
			items: items.map( ( entry ) => ( {
				label: entry.label,
				value: Number( entry.value.toFixed( 2 ) ),
			} ) ),
		},
	} );
}
