/**
 * Pro SPA v2 — assistant MCP Apps client (Pro surface).
 *
 * `GET  mcp-ai/v1/mcp-apps/{assistant_id}` — assigned apps, secrets masked.
 * `PUT  mcp-ai/v1/mcp-apps/{assistant_id}` — replace the list; the server
 * restores stored secrets by identity, so callers must not send tokens.
 *
 * Secret-bearing fields (`token`, `oauth_data`) are read-only by contract:
 * the write payload is built from the non-secret fields only.
 */

import { __ } from '@wordpress/i18n';

/** One MCP app entry (secrets masked on read). */
export interface McpAppRecord {
	label: string;
	server_url: string;
	connection_ref: string;
	auth_type: 'none' | 'bearer' | 'basic' | 'header' | 'oauth' | string;
	header_name?: string;
	enabled: boolean;
	timeout?: number;
	verify_ssl?: boolean;
	/** Masked on read (`••••••••abcd`); never send this back. */
	token?: string;
	oauth_data?: Record< string, unknown >;
}

export interface McpAppsResponse {
	success: boolean;
	assistant_id: number;
	apps: McpAppRecord[];
	app_count: number;
}

export interface McpAppsClientOptions {
	/** REST base, e.g. `…/wp-json/mcp-ai/v1/mcp-apps`. */
	endpoint: string;
	nonce: string;
}

/** The write payload: every field except the secret-bearing ones. */
export function toWritableApp( app: McpAppRecord ): McpAppRecord {
	return {
		label: app.label ?? '',
		server_url: app.server_url ?? '',
		connection_ref: app.connection_ref ?? '',
		auth_type: app.auth_type ?? 'none',
		header_name: app.header_name ?? '',
		enabled: app.enabled !== false,
		timeout: app.timeout ?? 30,
		verify_ssl: app.verify_ssl !== false,
	};
}

export class McpAppsClient {
	private readonly endpoint: string;

	private readonly nonce: string;

	constructor( opts: McpAppsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	async list(
		assistantId: number,
		signal?: AbortSignal
	): Promise< McpAppsResponse > {
		return this.request< McpAppsResponse >( {
			method: 'GET',
			url: `${ this.endpoint }/${ assistantId }`,
			signal,
		} );
	}

	async save(
		assistantId: number,
		apps: McpAppRecord[],
		signal?: AbortSignal
	): Promise< McpAppsResponse > {
		return this.request< McpAppsResponse >( {
			method: 'PUT',
			url: `${ this.endpoint }/${ assistantId }`,
			body: { apps: apps.map( toWritableApp ) },
			signal,
		} );
	}

	private async request< T >( req: {
		method: 'GET' | 'PUT';
		url: string;
		body?: unknown;
		signal?: AbortSignal;
	} ): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
		};

		if ( req.body !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		const response = await fetch( req.url, {
			method: req.method,
			credentials: 'same-origin',
			headers,
			body: req.body !== undefined ? JSON.stringify( req.body ) : undefined,
			signal: req.signal,
		} );

		const data = ( await response.json().catch( () => ( {} ) ) ) as
			| ( T & { message?: string } )
			| undefined;

		if ( ! response.ok ) {
			throw new Error(
				data?.message ||
					__( 'MCP apps request failed.', 'nvoos-pro-spa' )
			);
		}

		return ( data ?? ( {} as T ) ) as T;
	}
}
