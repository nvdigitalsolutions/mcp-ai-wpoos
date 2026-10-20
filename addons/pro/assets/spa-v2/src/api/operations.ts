/**
 * Pro SPA v2 — Operations client (cron + dead-letter queue).
 *
 * Wraps mcp-ai/v1/operations/* for the OperationsPage (Proposal 084 Phase 3).
 * Mirrors the ToolsClient fetch discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface CronJobRecord {
	job_id: string;
	hook?: string;
	args?: unknown;
	schedule?: string;
	timestamp?: number;
	status?: string;
	user_id?: number;
	[ k: string ]: unknown;
}

export interface DlqItemRecord {
	item_id: string;
	type: string;
	identifier: string;
	failure_reason: string;
	dismissed: boolean;
	created_at: string;
}

/**
 * A single run summary from GET /operations/timeline.
 * The metric-store shape is guaranteed; the post-meta fallback adds
 * `trace` and may omit cost/latency fields, so keep them optional.
 */
export interface TimelineRunRecord {
	run_id: string;
	assistant_id: number;
	started_at?: number;
	total_tokens?: number;
	cost_usd?: number;
	latency_ms?: number;
	trace?: unknown;
	[ k: string ]: unknown;
}

export interface TimelinePage {
	runs: TimelineRunRecord[];
	total: number;
	page: number;
	per_page: number;
}

export interface OperationsClientOptions {
	endpoint: string;
	nonce: string;
}

export class OperationsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: OperationsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** GET /operations/cron — tracked plugin cron jobs. */
	async cronJobs( signal?: AbortSignal ): Promise< CronJobRecord[] > {
		const data = await this.request< { success?: boolean; data?: { jobs?: CronJobRecord[] } } >(
			'GET',
			`${ this.endpoint }/cron`,
			undefined,
			signal
		);
		return Array.isArray( data?.data?.jobs ) ? data.data.jobs : [];
	}

	/** DELETE /operations/cron/{job_id} — remove + unschedule. */
	async removeCronJob( jobId: string, signal?: AbortSignal ): Promise< void > {
		await this.request( 'DELETE', `${ this.endpoint }/cron/${ encodeURIComponent( jobId ) }`, undefined, signal );
	}

	/** GET /operations/dlq — dead-letter items. */
	async dlqItems( dismissed = false, signal?: AbortSignal ): Promise< DlqItemRecord[] > {
		const url = new URL( `${ this.endpoint }/dlq`, window.location.origin );
		url.searchParams.set( 'dismissed', dismissed ? '1' : '0' );
		const data = await this.request< { success?: boolean; data?: { items?: DlqItemRecord[] } } >(
			'GET',
			url.toString(),
			undefined,
			signal
		);
		return Array.isArray( data?.data?.items ) ? data.data.items : [];
	}

	/** POST /operations/dlq/{id}/retry — reprocess a dead letter. */
	async retryDlq( itemId: string, signal?: AbortSignal ): Promise< void > {
		await this.request( 'POST', `${ this.endpoint }/dlq/${ encodeURIComponent( itemId ) }/retry`, {}, signal );
	}

	/** POST /operations/dlq/{id}/dismiss — mark dismissed. */
	async dismissDlq( itemId: string, signal?: AbortSignal ): Promise< void > {
		await this.request( 'POST', `${ this.endpoint }/dlq/${ encodeURIComponent( itemId ) }/dismiss`, {}, signal );
	}

	/** GET /operations/timeline — paged run summaries. */
	async timeline( page = 1, assistantId = 0, signal?: AbortSignal ): Promise< TimelinePage > {
		const url = new URL( `${ this.endpoint }/timeline`, window.location.origin );
		url.searchParams.set( 'page', String( Math.max( 1, page ) ) );
		if ( assistantId > 0 ) {
			url.searchParams.set( 'assistant_id', String( assistantId ) );
		}
		const data = await this.request< { success?: boolean; data?: TimelinePage } >(
			'GET',
			url.toString(),
			undefined,
			signal
		);
		return (
			data?.data ?? { runs: [], total: 0, page: 1, per_page: 20 }
		);
	}

	private async request< T >(
		method: 'GET' | 'POST' | 'DELETE',
		url: string,
		body?: unknown,
		signal?: AbortSignal
	): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
		};
		if ( body !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
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
			throw new Error( detail || __( 'Operations request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
