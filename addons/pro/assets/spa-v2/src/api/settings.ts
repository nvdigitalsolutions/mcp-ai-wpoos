/**
 * Pro SPA v2 — typed wrappers around `mcp-ai/v1/settings`.
 */

import { __ } from '@wordpress/i18n';

export interface ProviderSettings {
	provider: string;
	api_key: string;
	enabled: boolean;
}

export interface PluginSettings {
	openai_api_key?: string;
	google_api_key?: string;
	anthropic_api_key?: string;
	ollama_endpoint?: string;
	default_model?: string;
	default_provider?: string;
	max_tokens?: number;
	temperature?: number;
	enable_logging?: boolean;
	providers?: ProviderSettings[];
}

export interface SettingsClientOptions {
	endpoint: string;
	nonce: string;
}

/** One schema-driven settings field (2.4.0, Proposal 084 Phase 2). */
export interface SettingsField {
	key: string;
	type: 'checkbox' | 'number' | 'select' | 'text' | 'password' | 'url' | 'email' | 'textarea' | 'hidden';
	section_id: string;
	section: string;
	tab: string;
	label: string;
	description: string;
	sensitive: boolean;
	options?: Record< string, string >;
	default?: unknown;
	min?: number;
	max?: number;
	step?: number;
}

export interface SettingsSchema {
	tabs: Record< string, string >;
	fields: SettingsField[];
}

export type SettingsValues = Record< string, unknown >;

export const MASKED_PLACEHOLDER = '**************';

export class SettingsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: SettingsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	async get( signal?: AbortSignal ): Promise< PluginSettings > {
		const url = new URL( this.endpoint, window.location.origin );
		const data = await this.request< { success: boolean; data: PluginSettings } >( {
			method: 'GET',
			url: url.toString(),
			signal,
		} );
		return data?.data ?? {};
	}

	async update(
		changes: Partial< PluginSettings >,
		signal?: AbortSignal
	): Promise< PluginSettings > {
		const data = await this.request< { success: boolean; data: PluginSettings } >( {
			method: 'POST',
			url: this.endpoint,
			body: changes,
			signal,
		} );
		return data?.data ?? {};
	}

	/** GET /settings/schema — the SPA-renderable settings catalogue. */
	async schema( signal?: AbortSignal ): Promise< SettingsSchema > {
		const url = new URL( `${ this.endpoint }/schema`, window.location.origin );
		const data = await this.request< { success: boolean; data: SettingsSchema } >( {
			method: 'GET',
			url: url.toString(),
			signal,
		} );
		return data?.data ?? { tabs: {}, fields: [] };
	}

	/** GET /settings/values — every schema key, secrets masked. */
	async values( signal?: AbortSignal ): Promise< SettingsValues > {
		const url = new URL( `${ this.endpoint }/values`, window.location.origin );
		const data = await this.request< { success: boolean; data: { values: SettingsValues } } >( {
			method: 'GET',
			url: url.toString(),
			signal,
		} );
		return data?.data?.values ?? {};
	}

	/** POST /settings/values — partial update against the schema. */
	async updateValues(
		changes: SettingsValues,
		signal?: AbortSignal
	): Promise< SettingsValues > {
		const url = new URL( `${ this.endpoint }/values`, window.location.origin );
		const data = await this.request< { success: boolean; data: { values: SettingsValues } } >( {
			method: 'POST',
			url: url.toString(),
			body: changes,
			signal,
		} );
		return data?.data?.values ?? {};
	}

	private async request< T = unknown >( req: {
		method: 'GET' | 'POST';
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
		if ( ! response.ok ) {
			throw new Error(
				__( 'Settings request failed.', 'nvoos-pro-spa' )
			);
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
