/**
 * Pro SPA v2 — Security Center client.
 *
 * Wraps mcp-ai/v1/security/* for the SecurityCenterPage: posture report,
 * header preview, snapshots (list/save/restore) and the self-test runner.
 * Mirrors the ToolsClient fetch discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface SecurityCenterClientOptions {
	endpoint: string;
	nonce: string;
}

export class SecurityCenterClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: SecurityCenterClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** GET /security/posture — the 21-signal posture report. */
	async posture( signal?: AbortSignal ): Promise< Record< string, unknown > > {
		return this.request( 'GET', `${ this.endpoint }/posture`, undefined, signal );
	}

	/** GET /security/preview-headers — headers the plugin would emit. */
	async previewHeaders( signal?: AbortSignal ): Promise< {
		security_headers_enabled?: boolean;
		hsts_enabled?: boolean;
		headers?: Record< string, string >;
	} > {
		return this.request( 'GET', `${ this.endpoint }/preview-headers`, undefined, signal );
	}

	/** GET /security/snapshots — saved settings snapshots. */
	async snapshots( signal?: AbortSignal ): Promise< { snapshots?: Record< string, unknown >[] } > {
		return this.request( 'GET', `${ this.endpoint }/snapshots`, undefined, signal );
	}

	/** POST /security/snapshot — save the current settings state. */
	async saveSnapshot( label = '', signal?: AbortSignal ): Promise< Record< string, unknown > > {
		return this.request( 'POST', `${ this.endpoint }/snapshot`, { label }, signal );
	}

	/** POST /security/restore — restore a snapshot by id. */
	async restoreSnapshot( snapshotId: string, signal?: AbortSignal ): Promise< Record< string, unknown > > {
		return this.request( 'POST', `${ this.endpoint }/restore`, { snapshot_id: snapshotId }, signal );
	}

	/** POST /security/self-test — run the on-demand self test. */
	async selfTest( signal?: AbortSignal ): Promise< Record< string, unknown > > {
		return this.request( 'POST', `${ this.endpoint }/self-test`, {}, signal );
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
			throw new Error( detail || __( 'Security request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
