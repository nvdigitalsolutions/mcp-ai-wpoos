/**
 * Pro SPA v2 — Approvals client (HITL approval queue).
 *
 * Wraps the mcp-ai/v1/approvals routes so the ApprovalsPage can list the
 * pending queue and resolve (approve/deny) items. Mirrors the ToolsClient
 * fetch discipline (nonce header, structured errors).
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface ApprovalRecord {
	id: number;
	status: string;
	tool: string;
	arguments?: Record< string, unknown >;
	assistant_id?: number;
	requester_id?: number;
	session_id?: string;
	reason?: string;
	created_at?: number;
	expires_at?: number;
	[ k: string ]: unknown;
}

export interface ApprovalsListResponse {
	approvals: ApprovalRecord[];
	total: number;
}

export interface ApprovalsClientOptions {
	endpoint: string;
	nonce: string;
}

export class ApprovalsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: ApprovalsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/**
	 * List the pending approval queue (up to 100 items, newest first).
	 */
	async list( signal?: AbortSignal ): Promise< ApprovalsListResponse > {
		const url = new URL( this.endpoint, window.location.origin );
		url.searchParams.set( 'limit', '100' );
		const data = await this.request< {
			approvals?: ApprovalRecord[];
			total?: number;
		} >( 'GET', url.toString(), undefined, signal );

		return {
			approvals: Array.isArray( data.approvals ) ? data.approvals : [],
			total: typeof data.total === 'number' ? data.total : 0,
		};
	}

	/**
	 * Approve or deny a pending approval.
	 *
	 * @param id     — Approval post ID.
	 * @param action — 'approve' | 'deny'.
	 * @param note   — Optional resolution note.
	 */
	async resolve(
		id: number,
		action: 'approve' | 'deny',
		note = '',
		signal?: AbortSignal
	): Promise< { success?: boolean } > {
		return this.request< { success?: boolean } >(
			'POST',
			`${ this.endpoint }/${ id }/${ action }`,
			{ note },
			signal
		);
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
			throw new Error( detail || __( 'Approvals request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
