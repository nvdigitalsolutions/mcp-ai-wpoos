/**
 * NV oOS Chat SPA — Tools execution client.
 *
 * Wraps `mcp-ai/v1/tools` for executing WordPress tools (speech,
 * transcribe, translate, etc.) from the chat surface.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { __ } from '@wordpress/i18n';

export interface ToolExecutionResult {
	success: boolean;
	data?: {
		url?: string;
		attachment_id?: number;
		format?: string;
		mime_type?: string;
		text?: string;
		message?: string;
		[ k: string ]: unknown;
	};
	message?: string;
	[ k: string ]: unknown;
}

export interface ToolsClientOptions {
	endpoint: string;
	nonce: string;
}

export class ToolsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: ToolsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/**
	 * Execute a WordPress tool synchronously.
	 *
	 * @param tool       — Tool slug (e.g. "speech", "transcribe").
	 * @param args       — Tool arguments.
	 * @param assistantId — Optional assistant post ID.
	 * @param signal     — AbortSignal for cancellation.
	 */
	async execute(
		tool: string,
		args: Record< string, unknown >,
		assistantId?: number,
		signal?: AbortSignal
	): Promise< ToolExecutionResult > {
		const body: Record< string, unknown > = {
			tool,
			arguments: args,
		};
		if ( assistantId ) {
			body.assistant_id = assistantId;
		}

		const headers: Record< string, string > = {
			'Content-Type': 'application/json',
			Accept: 'application/json',
		};
		if ( this.nonce ) {
			headers[ 'X-WP-Nonce' ] = this.nonce;
		}

		const response = await fetch( this.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers,
			body: JSON.stringify( body ),
			signal,
		} );

		if ( ! response.ok ) {
			let detail = '';
			try {
				const err = ( await response.json() ) as { message?: string };
				detail = err?.message ?? '';
			} catch {
				// Not JSON.
			}
			throw new Error(
				detail ||
					__( 'Tool execution failed.', 'nvoos-chat-spa' )
			);
		}

		try {
			return ( await response.json() ) as ToolExecutionResult;
		} catch {
			throw new Error( __( 'Invalid tool response.', 'nvoos-chat-spa' ) );
		}
	}

	/**
	 * Upload a file to the WordPress media library.
	 *
	 * Uses `FormData` multipart POST to `wp/v2/media`.
	 *
	 * @param file     — The File/Blob to upload.
	 * @param fileName — Suggested filename.
	 * @param signal   — AbortSignal for cancellation.
	 * @returns The media attachment record (id, source_url, etc.).
	 */
	static async uploadMedia(
		uploadEndpoint: string,
		nonce: string,
		file: Blob,
		fileName: string,
		signal?: AbortSignal
	): Promise< { id: number; source_url: string; mime_type: string } > {
		const formData = new FormData();
		formData.append( 'file', file, fileName );

		const headers: Record< string, string > = {
			Accept: 'application/json',
		};
		if ( nonce ) {
			headers[ 'X-WP-Nonce' ] = nonce;
		}
		// Don't set Content-Type — the browser sets it with the boundary.

		const response = await fetch( uploadEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers,
			body: formData,
			signal,
		} );

		if ( ! response.ok ) {
			let detail = '';
			try {
				const err = ( await response.json() ) as { message?: string };
				detail = err?.message ?? '';
			} catch {
				// Not JSON.
			}
			throw new Error(
				detail || __( 'Media upload failed.', 'nvoos-chat-spa' )
			);
		}

		const data = ( await response.json() ) as {
			id: number;
			source_url: string;
			mime_type: string;
		};
		return {
			id: data.id,
			source_url: data.source_url,
			mime_type: data.mime_type,
		};
	}
}
