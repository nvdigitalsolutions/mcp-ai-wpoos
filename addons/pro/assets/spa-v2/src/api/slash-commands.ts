/**
 * Pro SPA v2 — Slash commands client.
 *
 * Wraps mcp-ai-pro/v1/slash-commands (catalogue) and
 * /slash-commands/execute (executor) for the SlashCommandsPage. Mirrors
 * the ToolsClient fetch discipline.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface SlashCommandSummary {
	command: string;
	description: string;
	usage: string;
	parameters?: string[];
	category?: string;
	[ k: string ]: unknown;
}

export interface SlashCommandsClientOptions {
	endpoint: string;
	nonce: string;
}

export class SlashCommandsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: SlashCommandsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/**
	 * List the commands visible to the current user (capability-filtered
	 * server-side).
	 */
	async list( signal?: AbortSignal ): Promise< SlashCommandSummary[] > {
		const data = await this.request< { commands?: SlashCommandSummary[] } | SlashCommandSummary[] >(
			'GET',
			this.endpoint,
			undefined,
			signal
		);
		if ( Array.isArray( data ) ) {
			return data;
		}
		return Array.isArray( data?.commands ) ? data.commands : [];
	}

	/**
	 * Execute a slash command (e.g. "/research topic").
	 */
	async execute(
		command: string,
		signal?: AbortSignal
	): Promise< { success?: boolean; message?: string; [ k: string ]: unknown } > {
		return this.request( 'POST', `${ this.endpoint }/execute`, { command }, signal );
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
			throw new Error( detail || __( 'Slash command request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
