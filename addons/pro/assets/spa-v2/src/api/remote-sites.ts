/**
 * Pro SPA v2 — Remote Sites (Connections) client (console Connections page).
 *
 * Consumes the mcp-ai-pro/v1/remote-sites surface added alongside the
 * console port of the wp-admin Remote Sites page:
 *   GET    /remote-sites           — list (secrets redacted)
 *   GET    /remote-sites/types     — connection-type catalogue
 *   POST   /remote-sites           — create
 *   POST   /remote-sites/{id}      — update (blank secret = keep)
 *   DELETE /remote-sites/{id}      — delete
 *   POST   /remote-sites/{id}/test — verify against the provider
 *
 * Secrets are write-only by contract: the list never echoes credentials,
 * only `has_credentials`. The client mirrors that shape.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

/** A single connection as served by the list endpoint (redacted). */
export interface RemoteSiteConnection {
	id: string;
	/** 'pro' (Remote Sites) or 'base' (plugin settings, e.g. base Gmail). */
	source: 'pro' | 'base';
	name: string;
	connection_type: string;
	url: string;
	enabled: boolean;
	/** True when at least one credential field is stored (never echoes secrets). */
	has_credentials: boolean;
	user_email?: string;
	bot_username?: string;
	[k: string]: unknown;
}

export interface RemoteSitesEnvelope {
	items: RemoteSiteConnection[];
	total: number;
}

/** One field in a connection type's schema (drives the add/edit form). */
export interface RemoteSiteTypeField {
	key: string;
	label: string;
	secret?: boolean;
	required?: boolean;
	help?: string;
	placeholder?: string;
}

export interface RemoteSiteType {
	id: string;
	label: string;
	category: string;
	help?: string;
	fields: RemoteSiteTypeField[];
}

export interface RemoteSitesTypesEnvelope {
	items: RemoteSiteType[];
}

export interface RemoteSiteTestResult {
	success: boolean;
	message: string;
}

export interface RemoteSitesClientOptions {
	endpoint: string;
	nonce: string;
}

/**
 * Build the wp-admin settings URL for the base-plugin connections from a
 * WordPress REST base URL.
 *
 * `apiUrl` is either `{origin}/wp-json` or `{origin}/wp-json/{ns}/{v}` —
 * both must collapse to `{origin}/wp-admin/options-general.php?page=…`.
 * Empty input (no runtime) yields an empty link.
 */
export function buildWpAdminSettingsUrl( apiUrl: string ): string {
	const origin = apiUrl.replace( /\/wp-json(\/.*)?$/, '' );
	return origin
		? `${ origin }/wp-admin/options-general.php?page=wp-mcp-ai-settings`
		: '';
}

export class RemoteSitesClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: RemoteSitesClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** List every connection (Remote Sites + base plugin settings). */
	async list( signal?: AbortSignal ): Promise< RemoteSitesEnvelope > {
		const data = await this.request< Partial< RemoteSitesEnvelope > >(
			'GET',
			this.endpoint,
			undefined,
			signal
		);
		return {
			items: Array.isArray( data?.items ) ? data.items : [],
			total: typeof data?.total === 'number' ? data.total : 0,
		};
	}

	/** The connection-type catalogue for the add-connection wizard. */
	async types( signal?: AbortSignal ): Promise< RemoteSiteType[] > {
		const data = await this.request< { items?: RemoteSiteType[] } >(
			'GET',
			`${ this.endpoint }/types`,
			undefined,
			signal
		);
		return Array.isArray( data?.items ) ? data.items : [];
	}

	/** Create a connection from the wizard payload. */
	async create(
		payload: Record< string, unknown >,
		signal?: AbortSignal
	): Promise< RemoteSiteConnection > {
		const data = await this.request< { item?: RemoteSiteConnection } >(
			'POST',
			this.endpoint,
			payload,
			signal
		);
		if ( ! data?.item ) {
			throw new Error( __( 'The server did not return the saved connection.', 'nvoos-pro-spa' ) );
		}
		return data.item;
	}

	/** Update a connection (partial — blank secrets keep the stored value). */
	async update(
		id: string,
		payload: Record< string, unknown >,
		signal?: AbortSignal
	): Promise< RemoteSiteConnection > {
		const data = await this.request< { item?: RemoteSiteConnection } >(
			'POST',
			`${ this.endpoint }/${ id }`,
			payload,
			signal
		);
		if ( ! data?.item ) {
			throw new Error( __( 'The server did not return the saved connection.', 'nvoos-pro-spa' ) );
		}
		return data.item;
	}

	/** Delete a connection (destructive). */
	async delete( id: string, signal?: AbortSignal ): Promise< void > {
		await this.request< unknown >( 'DELETE', `${ this.endpoint }/${ id }`, undefined, signal );
	}

	/** Verify a connection against its provider. */
	async test( id: string, signal?: AbortSignal ): Promise< RemoteSiteTestResult > {
		const data = await this.request< Partial< RemoteSiteTestResult > >(
			'POST',
			`${ this.endpoint }/${ id }/test`,
			{},
			signal
		);
		return {
			success: Boolean( data?.success ),
			message:
				typeof data?.message === 'string'
					? data.message
					: __( 'Connection test completed.', 'nvoos-pro-spa' ),
		};
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
			throw new Error( detail || __( 'Connections request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
