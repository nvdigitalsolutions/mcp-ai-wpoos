/**
 * Pro SPA v2 — Media Ops client (Proposal 084 Phase 4, W14).
 *
 * Consumes the media command-center's health snapshot and sweep trigger:
 *   GET  /mcp-ai-pro/v1/media-cc/health             — snapshot metrics
 *   POST /mcp-ai-pro/v1/media-cc/compression-sweep  — queue a sweep
 *
 * Mirrors the DashboardsClient fetch discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface MediaQueueStatus {
	pending: number;
	completed_today: number;
	failed: number;
	total_processed: number;
	recent_failures: unknown[];
}

export interface MediaScheduleSummary {
	active: number;
	ran_today: number;
	overdue: number;
	next_run: string;
}

export interface MediaHealthSnapshot {
	attachments: {
		total: number;
		image_count: number;
		with_alt: number;
		descriptive_filename_count: number;
	};
	storage: number;
	alt_pct: number;
	nextgen_pct: number;
	compression: number;
	queue: MediaQueueStatus;
	schedule: MediaScheduleSummary;
	[ k: string ]: unknown;
}

export interface MediaSweepResult {
	queued: boolean;
	message: string;
}

export interface MediaOpsClientOptions {
	endpoint: string;
	nonce: string;
}

export class MediaOpsClient {
	private readonly base: string;
	private readonly nonce: string;

	constructor( opts: MediaOpsClientOptions ) {
		this.base = opts.endpoint.replace( /\/$/, '' );
		this.nonce = opts.nonce;
	}

	/** Fetch the media library health snapshot. */
	async health( signal?: AbortSignal ): Promise< MediaHealthSnapshot > {
		return this.request< MediaHealthSnapshot >( 'GET', `${ this.base }/health`, undefined, signal );
	}

	/** Queue a library-wide compression sweep. */
	async runCompressionSweep( signal?: AbortSignal ): Promise< MediaSweepResult > {
		return this.request< MediaSweepResult >( 'POST', `${ this.base }/compression-sweep`, {}, signal );
	}

	private async request< T >(
		method: 'GET' | 'POST',
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
			throw new Error( detail || __( 'Media ops request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}

/** Format a byte count as a human-readable size. */
export function formatBytes( bytes: unknown ): string {
	const numeric = typeof bytes === 'number' && isFinite( bytes ) ? bytes : 0;
	if ( numeric < 1024 ) {
		return `${ numeric } B`;
	}
	const units = [ 'KB', 'MB', 'GB', 'TB' ];
	let value = numeric;
	let unit = -1;
	do {
		value /= 1024;
		unit++;
	} while ( value >= 1024 && unit < units.length - 1 );
	return `${ value.toFixed( 1 ) } ${ units[ unit ] }`;
}

/** Format a 0-100 coverage percentage with a fallback for non-numeric data. */
export function formatPercent( value: unknown ): string {
	const numeric = typeof value === 'number' && isFinite( value ) ? value : 0;
	return `${ numeric }%`;
}
