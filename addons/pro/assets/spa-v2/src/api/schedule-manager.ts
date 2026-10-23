/**
 * Pro SPA v2 — Schedule Manager client.
 *
 * Wraps the existing mcp-ai-pro/v1 schedule + SA-preset REST surface
 * (Proposal 084 Phase 3b, gap P9). The controllers predate this port, so
 * the client speaks their `{ ok, ... }` envelope — not the newer
 * `{ success, data }` shape used by the Phase 3 controllers.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface ScheduleItem {
	id: string;
	name: string;
	description?: string;
	schedule_type?: string;
	schedule?: string;
	enabled?: boolean;
	next_run?: string | null;
	last_run?: string | null;
	priority?: number;
	tags?: string[];
	run_count?: number;
	[ k: string ]: unknown;
}

export interface SchedulePreset {
	id: string;
	name: string;
	description?: string;
	category?: string;
	toolkit?: string;
	[ k: string ]: unknown;
}

export interface ScheduleHistoryRow {
	run_id?: string;
	status?: string;
	started_at?: string;
	finished_at?: string;
	result?: string;
	[ k: string ]: unknown;
}

interface OkEnvelope {
	ok?: boolean;
	message?: string;
}

export interface ScheduleManagerClientOptions {
	/** REST base — mcp-ai-pro/v1 (schedules + presets share it). */
	endpoint: string;
	nonce: string;
}

export class ScheduleManagerClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: ScheduleManagerClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	private async request< T >( path: string, init?: RequestInit ): Promise< T | null > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
			...( init?.body ? { 'Content-Type': 'application/json' } : {} ),
		};
		const response = await fetch( `${ this.endpoint }${ path }`, {
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
			throw new Error( detail || __( 'Schedule manager request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			const body = ( await response.json() ) as T & OkEnvelope;
			if ( body?.ok === false ) {
				throw new Error( body.message || __( 'Schedule manager request failed.', 'nvoos-pro-spa' ) );
			}
			return body;
		} catch ( err ) {
			if ( err instanceof Error && err.message ) {
				throw err;
			}
			return null;
		}
	}

	/** GET /schedules — redacted schedule list. */
	async list( signal?: AbortSignal ): Promise< ScheduleItem[] > {
		const body = await this.request< { schedules?: ScheduleItem[]; total?: number } >(
			'/schedules',
			{ signal }
		);
		return Array.isArray( body?.schedules ) ? body.schedules : [];
	}

	/** GET /schedules/{id} */
	async get( id: string ): Promise< ScheduleItem | null > {
		const body = await this.request< { schedule?: ScheduleItem } >(
			`/schedules/${ encodeURIComponent( id ) }`
		);
		return body?.schedule ?? null;
	}

	/** POST /schedules — create a schedule. */
	async create( payload: Record< string, unknown > ): Promise< void > {
		await this.request( '/schedules', {
			method: 'POST',
			body: JSON.stringify( payload ),
		} );
	}

	/** POST /schedules/{id} — update a schedule. */
	async update( id: string, payload: Record< string, unknown > ): Promise< void > {
		await this.request( `/schedules/${ encodeURIComponent( id ) }`, {
			method: 'POST',
			body: JSON.stringify( payload ),
		} );
	}

	/** DELETE /schedules/{id} */
	async remove( id: string ): Promise< void > {
		await this.request( `/schedules/${ encodeURIComponent( id ) }`, {
			method: 'DELETE',
		} );
	}

	/** POST /schedules/{id}/trigger — run the schedule now. */
	async trigger( id: string ): Promise< void > {
		await this.request( `/schedules/${ encodeURIComponent( id ) }/trigger`, {
			method: 'POST',
		} );
	}

	/** POST /schedules/{id}/toggle — flip enabled state. */
	async toggle( id: string ): Promise< void > {
		await this.request( `/schedules/${ encodeURIComponent( id ) }/toggle`, {
			method: 'POST',
		} );
	}

	/** GET /schedules/{id}/history */
	async history( id: string ): Promise< ScheduleHistoryRow[] > {
		const body = await this.request< { history?: ScheduleHistoryRow[] } >(
			`/schedules/${ encodeURIComponent( id ) }/history`
		);
		return Array.isArray( body?.history ) ? body.history : [];
	}

	/** GET /presets — SA schedule presets (filters optional). */
	async presets( params: Record< string, string > = {} ): Promise< SchedulePreset[] > {
		const query = new URLSearchParams( params ).toString();
		const body = await this.request< { presets?: SchedulePreset[]; total?: number } >(
			`/presets${ query ? `?${ query }` : '' }`
		);
		return Array.isArray( body?.presets ) ? body.presets : [];
	}

	/** POST /presets/{id}/install */
	async installPreset( id: string ): Promise< void > {
		await this.request( `/presets/${ encodeURIComponent( id ) }/install`, {
			method: 'POST',
		} );
	}
}
