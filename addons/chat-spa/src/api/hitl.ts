/**
 * NV oOS Chat SPA — typed wrappers around `mcp-ai/v1/approvals`.
 *
 * The HITL approval REST controller (`WP_MCP_AI_REST_Approval_Controller`)
 * exposes:
 *
 *   GET  /approvals                 — list pending (manage_options only)
 *   GET  /approvals/{id}            — get single
 *   POST /approvals/{id}/approve    — approve (manage_options)
 *   POST /approvals/{id}/deny       — deny (manage_options)
 *
 * Non-admin users receive a 403 from the server; the SPA only renders the
 * approval bar when `endpoints.approvals` is non-empty (the shortcode PHP
 * only populates it for manage_options users).
 */

import { __ } from '@wordpress/i18n';

export interface ApprovalRecord {
	id: number;
	status: 'pending' | 'approved' | 'denied' | 'expired';
	tool: string;
	arguments: Record< string, unknown >;
	assistant_id: number;
	requester_id: number;
	session_id: string;
	reason: string;
	created_at: number;
	expires_at: number;
	resolved_by: number;
	resolved_at: number;
	note: string;
}

export interface HitlClientOptions {
	endpoint: string;
	nonce: string;
}

export class HitlClient {
	private readonly base: string;
	private readonly nonce: string;

	constructor( opts: HitlClientOptions ) {
		this.base = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** List pending approvals, optionally filtered by assistantId / sessionId. */
	async listPending(
		opts: { assistantId?: number; sessionId?: string },
		signal?: AbortSignal
	): Promise< ApprovalRecord[] > {
		const url = new URL( this.base, window.location.origin );
		if ( opts.assistantId ) url.searchParams.set( 'assistant_id', String( opts.assistantId ) );
		if ( opts.sessionId ) url.searchParams.set( 'session_id', opts.sessionId );

		const data = await this.request< unknown >( 'GET', url.toString(), undefined, signal );
		// GET /approvals returns an array at the top level.
		if ( Array.isArray( data ) ) return data as ApprovalRecord[];
		// Safety fallback — wrapped envelope.
		const env = data as Record< string, unknown >;
		if ( Array.isArray( env?.approvals ) ) return env.approvals as ApprovalRecord[];
		return [];
	}

	async approve( id: number, note?: string, signal?: AbortSignal ): Promise< void > {
		await this.request(
			'POST',
			`${ this.base }/${ id }/approve`,
			note ? { note } : {},
			signal
		);
	}

	async deny( id: number, note?: string, signal?: AbortSignal ): Promise< void > {
		await this.request(
			'POST',
			`${ this.base }/${ id }/deny`,
			note ? { note } : {},
			signal
		);
	}

	private async request< T = unknown >(
		method: 'GET' | 'POST',
		url: string,
		body?: unknown,
		signal?: AbortSignal
	): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
		};
		if ( body !== undefined && method !== 'GET' ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		const response = await fetch( url, {
			method,
			credentials: 'same-origin',
			headers,
			body: body !== undefined && method !== 'GET' ? JSON.stringify( body ) : undefined,
			signal,
		} );

		if ( ! response.ok ) {
			let detail = '';
			try {
				const errBody = ( await response.json() ) as { message?: string; code?: string };
				detail = errBody?.message ?? errBody?.code ?? '';
			} catch {
				// Not JSON.
			}
			throw new Error(
				detail ||
					__( 'Approval request failed.', 'nvoos-chat-spa' )
			);
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
