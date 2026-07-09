/**
 * Slash Commands API Client
 *
 * Fetches registered slash commands for the Pro SPA v2 Commands drawer.
 *
 * @since 2.1.0
 */

export interface SlashCommand {
	command: string;
	description: string;
	usage: string;
	parameters: string[];
	category: string;
}

export interface SlashCommandsResponse {
	commands: SlashCommand[];
	categories: string[];
}

export interface SlashCommandsClientOptions {
	endpoint: string;
	nonce: string;
}

export class SlashCommandsClient {
	private readonly base: string;
	private readonly nonce: string;

	constructor( opts: SlashCommandsClientOptions ) {
		this.base = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/**
	 * List all slash commands, optionally filtered by search.
	 */
	async list(
		search?: string,
		signal?: AbortSignal
	): Promise< SlashCommandsResponse > {
		const url = new URL( this.base, window.location.origin );

		if ( search ) {
			url.searchParams.set( 'search', search );
		}

		return this.request< SlashCommandsResponse >( url.toString(), signal );
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

		if ( this.nonce ) {
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
