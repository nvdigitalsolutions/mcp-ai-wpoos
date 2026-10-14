/**
 * Tool Shortcuts API Client
 *
 * Fetches assistant-scoped tool shortcuts from the Pro SPA REST endpoint.
 *
 * @since 2.1.0
 */

export interface ToolShortcut {
	id: string;
	label: string;
	payload: string;
	tool: string;
	description: string;
	category: string;
	icon: string;
}

export interface ToolShortcutsResponse {
	shortcuts: ToolShortcut[];
	categories: string[];
	assistant_id: number;
}

export interface ToolShortcutsClientOptions {
	endpoint: string;
	nonce: string;
	/** Guest mode — send the X-WP-MCP-AI-Guest header instead of a nonce. */
	guest?: boolean;
	guestToken?: string;
}

export class ToolShortcutsClient {
	private readonly base: string;
	private readonly nonce: string;
	private readonly guest: boolean;
	private readonly guestToken: string;

	constructor( opts: ToolShortcutsClientOptions ) {
		this.base = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
		this.guest = opts.guest ?? false;
		this.guestToken = opts.guestToken ?? '';
	}

	/**
	 * List tool shortcuts, optionally filtered by assistant_id and search.
	 */
	async list(
		assistantId: number | string,
		search?: string,
		signal?: AbortSignal
	): Promise< ToolShortcutsResponse > {
		const url = new URL( this.base, window.location.origin );

		if ( assistantId ) {
			url.searchParams.set( 'assistant_id', String( assistantId ) );
		}

		if ( search ) {
			url.searchParams.set( 'search', search );
		}

		return this.request< ToolShortcutsResponse >( url.toString(), signal );
	}

	/**
	 * Generic fetch wrapper with nonce and error handling.
	 */
	private async request< T >(
		url: string,
		signal?: AbortSignal
	): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
		};

		if ( this.guest ) {
			headers[ 'X-WP-MCP-AI-Guest' ] = this.guestToken || '1';
		} else if ( this.nonce ) {
			headers[ 'X-WP-Nonce' ] = this.nonce;
		}

		const response = await fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers,
			signal,
		} );

		if ( ! response.ok ) {
			let message = `Request failed (${ response.status })`;
			try {
				const body = await response.json();
				if ( body?.message ) {
					message = body.message;
				}
			} catch {
				// Use default message.
			}
			throw new Error( message );
		}

		return response.json() as Promise< T >;
	}
}
