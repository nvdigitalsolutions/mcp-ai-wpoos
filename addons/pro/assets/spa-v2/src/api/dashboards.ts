/**
 * Pro SPA v2 — Dashboards client (Proposal 084 Phase 4, W13).
 *
 * Consumes the console's repeating-dashboard surface:
 *   GET /mcp-ai-pro/v1/dashboards/schema   — dashboard catalogue
 *   GET /mcp-ai-pro/v1/dashboards/{id}/data — filtered metric payload
 *
 * Mirrors the RecordsClient fetch discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface DashboardFilterOption {
	[ key: string ]: string;
}

export interface DashboardFilter {
	key: string;
	label: string;
	type: 'taxonomy' | 'select';
	taxonomy?: string;
	options?: DashboardFilterOption;
}

export interface DashboardMetric {
	key: string;
	label: string;
	format: 'number' | 'money' | 'percent';
}

export interface DashboardChart {
	key: string;
	title: string;
	type: 'bar' | 'donut';
}

export interface DashboardListColumn {
	key: string;
	label: string;
	format?: 'number' | 'money' | 'percent';
}

export interface DashboardList {
	key: string;
	title: string;
	columns: DashboardListColumn[];
}

export interface DashboardConfig {
	id: string;
	label: string;
	description: string;
	icon: string;
	filters: DashboardFilter[];
	metrics: DashboardMetric[];
	charts?: DashboardChart[];
	lists?: DashboardList[];
}

export interface DashboardsSchemaResponse {
	dashboards?: Record< string, DashboardConfig >;
	[ k: string ]: unknown;
}

export interface DashboardLoanRow {
	id: number;
	title: string;
	balance: number;
	rate: number;
	dscr: number;
	ltv: number;
	dy: number;
	status: string;
	type: string;
	maturity: string;
}

export interface DashboardData {
	total_loans: number;
	total_balance: number;
	avg_rate: number;
	avg_dscr: number;
	avg_ltv: number;
	avg_debt_yield: number;
	by_status: Record< string, number >;
	by_loan_type: Record< string, number >;
	by_property_type: Record< string, number >;
	maturity_schedule: Record< string, number >;
	rate_distribution: Record< string, number >;
	loan_list: DashboardLoanRow[];
	[ k: string ]: unknown;
}

export interface DashboardsClientOptions {
	schemaEndpoint: string;
	nonce: string;
}

export class DashboardsClient {
	private readonly schemaBase: string;
	private readonly nonce: string;

	constructor( opts: DashboardsClientOptions ) {
		this.schemaBase = opts.schemaEndpoint.replace( /\/schema\/?$/, '' );
		this.nonce = opts.nonce;
	}

	/** Fetch the dashboard catalogue. */
	async schema( signal?: AbortSignal ): Promise< DashboardConfig[] > {
		const data = await this.request< DashboardsSchemaResponse >(
			'GET',
			this.schemaBase + '/schema',
			undefined,
			signal
		);
		const map = data?.dashboards ?? {};
		return Object.values( map );
	}

	/** Fetch filtered data for one dashboard. */
	async data(
		id: string,
		filters: Record< string, string >,
		signal?: AbortSignal
	): Promise< DashboardData > {
		const query = Object.keys( filters ).length
			? '?' + new URLSearchParams( { filters: JSON.stringify( filters ) } ).toString()
			: '';
		return this.request< { data?: DashboardData } >(
			'GET',
			`${ this.schemaBase }/${ id }/data${ query }`,
			undefined,
			signal
		).then( ( payload ) => ( payload?.data ?? {} ) as DashboardData );
	}

	private async request< T >(
		method: 'GET',
		url: string,
		body?: unknown,
		signal?: AbortSignal
	): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
		};
		const response = await fetch( url, {
			method,
			credentials: 'same-origin',
			headers,
			body: body !== undefined ? JSON.stringify( body ) : undefined,
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
			throw new Error( detail || __( 'Dashboards request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}

/** Format a metric value for its card. */
export function formatMetric( value: unknown, format: DashboardMetric[ 'format' ] ): string {
	const numeric = typeof value === 'number' && isFinite( value ) ? value : 0;
	if ( format === 'money' ) {
		return new Intl.NumberFormat( 'en-US', {
			style: 'currency',
			currency: 'USD',
			maximumFractionDigits: 0,
		} ).format( numeric );
	}
	if ( format === 'percent' ) {
		return `${ numeric.toFixed( 2 ) }%`;
	}
	return new Intl.NumberFormat( 'en-US' ).format( numeric );
}

/**
 * Build an `nvoos-chart`-recipe JSON spec string for a keyed distribution
 * (by_status / by_loan_type / maturity_schedule …). Sorted descending so
 * the dominant buckets lead; an empty distribution yields an empty string
 * (the page renders nothing instead of an empty chart).
 */
export function distributionChartSpec(
	title: string,
	distribution: Record< string, number > | undefined,
	type: 'bar' | 'donut' = 'bar'
): string {
	const entries = Object.entries( distribution ?? {} ).filter(
		( [ , value ] ) => typeof value === 'number' && value > 0
	);
	if ( entries.length === 0 ) {
		return '';
	}
	entries.sort( ( a, b ) => b[ 1 ] - a[ 1 ] );
	return JSON.stringify( {
		type,
		title,
		labels: entries.map( ( [ key ] ) => key ),
		values: entries.map( ( [ , value ] ) => value ),
		sort: 'none',
	} );
}
