/**
 * Pro SPA v2 — Tools execution client.
 *
 * Wraps `mcp-ai/v1/tools` for executing WordPress tools (speech,
 * transcribe, etc.) from the chat surface.  Mirrors chat-spa's
 * ToolsClient so that speech playback and audio recorder buttons
 * share one well-tested fetch path.
 *
 * @package NV_oOS_Pro_Spa
 * @since   1.0.0
 */

import { __ } from '@wordpress/i18n';

export interface ToolDefinition {
	slug: string;
	name: string;
	description: string;
	category: string;
	required_capability: string;
	parameters?: Record< string, unknown >;
	enabled?: boolean;
}

export interface ToolsListResponse {
	tools: ToolDefinition[];
	total: number;
}

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
	 * List registered tools (GET with pagination).
	 */
	async list( signal?: AbortSignal ): Promise< ToolsListResponse > {
		const url = new URL( this.endpoint, window.location.origin );
		url.searchParams.set( 'per_page', '200' );
		const data = await this.request< {
			success: boolean;
			data: ToolsListResponse;
		} >( { method: 'GET', url: url.toString(), signal } );
		return {
			tools: Array.isArray( data?.data?.tools ) ? data.data.tools : [],
			total: typeof data?.data?.total === 'number' ? data.data.total : 0,
		};
	}

	/**
	 * Execute a WordPress tool synchronously.
	 *
	 * POSTs to `mcp-ai/v1/tools` with `tool`, `arguments`, and optional
	 * `assistant_id` — the same envelope that the legacy chat, chat-spa,
	 * and REST API tests use.
	 *
	 * @param tool        — Tool slug (e.g. "generate_openai_speech", "transcribe_openai_audio").
	 * @param args        — Tool arguments.
	 * @param assistantId — Optional assistant post ID.
	 * @param signal      — AbortSignal for cancellation.
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
					__( 'Tool execution failed.', 'nvoos-pro-spa' )
			);
		}

		try {
			return ( await response.json() ) as ToolExecutionResult;
		} catch {
			throw new Error( __( 'Invalid tool response.', 'nvoos-pro-spa' ) );
		}
	}

	/**
	 * Upload a file to the WordPress media library.
	 *
	 * Uses `FormData` multipart POST to `wp/v2/media`.
	 *
	 * @param uploadEndpoint — WordPress media REST endpoint.
	 * @param nonce          — WP REST nonce.
	 * @param file           — The File/Blob to upload.
	 * @param fileName       — Suggested filename.
	 * @param signal         — AbortSignal for cancellation.
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
				detail || __( 'Media upload failed.', 'nvoos-pro-spa' )
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

	/* ── Private helpers ──────────────────────────────────────────── */

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
				__( 'Tools request failed.', 'nvoos-pro-spa' )
			);
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
