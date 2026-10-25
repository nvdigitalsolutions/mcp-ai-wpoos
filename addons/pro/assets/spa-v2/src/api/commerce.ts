/**
 * Commerce toolkit console API (Proposal 089 Phase 3c).
 *
 * Typed wrapper around the read-only sync-runs bridge
 * (GET /mcp-ai-pro/v1/commerce/sync-runs).
 */

import { __ } from '@wordpress/i18n';

export interface SyncRunCounters {
	items_updated: number;
	items_skipped: number;
	items_errored: number;
}

export interface SyncRunSummary {
	toolkit: string;
	run_id: string;
	connection_id: string;
	dry_run: boolean;
	started_at: string;
	finished_at: string;
	status: string;
	summary: SyncRunCounters;
}

export interface CommerceClientOptions {
	/** mcp-ai-pro/v1 base URL (runtime.proApi). */
	base: string;
	nonce: string;
}

export class CommerceClient {
	private readonly base: string;
	private readonly nonce: string;

	constructor( opts: CommerceClientOptions ) {
		this.base = opts.base.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** GET /commerce/sync-runs?toolkit={toolkit} — newest first. */
	async syncRuns( toolkit: string, limit = 20, signal?: AbortSignal ): Promise< SyncRunSummary[] > {
		const url = new URL( `${ this.base }/commerce/sync-runs`, window.location.origin );
		url.searchParams.set( 'toolkit', toolkit );
		url.searchParams.set( 'limit', String( Math.min( Math.max( limit, 1 ), 50 ) ) );
		const data = await this.request< { success: boolean; data: { runs: SyncRunSummary[] } } >( {
			url: url.toString(),
			signal,
		} );
		return data?.data?.runs ?? [];
	}

	private async request< T >( req: { url: string; signal?: AbortSignal } ): Promise< T | null > {
		const response = await fetch( req.url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			signal: req.signal,
		} );
		if ( ! response.ok ) {
			throw new Error( __( 'Commerce sync data request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return null;
		}
	}
}
