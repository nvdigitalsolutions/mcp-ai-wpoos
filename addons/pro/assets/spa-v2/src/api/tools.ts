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
	/** Capability flags (read-only, write, data-destruction, …) — powers severity colour coding. */
	flags?: string[];
	parameters?: Record< string, unknown >;
	enabled?: boolean;
}

export interface ToolsListResponse {
	tools: ToolDefinition[];
	total: number;
}

/**
 * Server-side tool entry served by GET mcp-ai/v1/tools — the MCP-facing
 * shape (name/description/inputSchema) with additive catalogue fields.
 */
interface ServerToolListItem {
	slug?: string;
	name: string;
	description: string;
	category?: string;
	required_capability?: string;
	inputSchema?: Record< string, unknown >;
	[ k: string ]: unknown;
}

/**
 * Slug prefixes that are verbs/core actions, not toolkit namespaces — tools
 * with these prefixes fall back to the 'Core' group instead of a bogus
 * category like 'Get' or 'Create'.
 */
const CORE_VERB_PREFIXES = new Set( [
	'get',
	'set',
	'create',
	'update',
	'delete',
	'list',
	'run',
	'search',
	'fetch',
	'add',
	'remove',
	'check',
	'convert',
	'import',
	'export',
	'generate',
	'analyze',
	'read',
	'write',
	'post',
	'send',
	'start',
	'stop',
	'pause',
	'resume',
	'enable',
	'disable',
	'install',
	'activate',
	'deactivate',
	'register',
	'unregister',
	'duplicate',
	'copy',
	'move',
	'rename',
	'upload',
	'download',
	'count',
	'compare',
	'schedule',
	'queue',
	'execute',
	'parse',
	'extract',
	'merge',
	'split',
	'format',
	'validate',
	'test',
	'sync',
	'toggle',
	'bulk',
	'restore',
	'publish',
	'translate',
	'resize',
	'optimize',
	'compress',
	'detect',
	'scan',
	'track',
	'log',
] );

/**
 * Derive a display category from a tool slug when the server envelope
 * carries none: the toolkit namespace prefix, or 'Core' for verb-prefixed
 * and prefix-less slugs.
 */
export function deriveToolCategory( slug: string ): string {
	const prefix = ( slug.split( '_' )[ 0 ] ?? '' ).toLowerCase();
	if ( ! prefix || CORE_VERB_PREFIXES.has( prefix ) ) {
		return 'Core';
	}
	return prefix.charAt( 0 ).toUpperCase() + prefix.slice( 1 );
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
	 *
	 * The REST route returns a top-level envelope — `{ tools: [...], total }`
	 * — where each entry is the MCP-facing shape (`name`, `description`,
	 * `inputSchema`) plus catalogue fields. Map it onto the page's
	 * ToolDefinition and fall back to the legacy
	 * `{ success, data: { tools } }` envelope for older installs.
	 *
	 * @param assistantId — Optional assistant post ID; the server scopes the
	 * list to that assistant's selected tools. Omitted, the server resolves
	 * the site/token DEFAULT assistant (MCP tools/list contract).
	 * @param signal      — AbortSignal for cancellation.
	 * @param scope       — Pass 'all' for the explicit full-registry opt-out
	 * (assistant tool picker); any other value keeps the default scoping.
	 */
	async list(
		assistantId?: number,
		signal?: AbortSignal,
		scope?: 'all',
	): Promise< ToolsListResponse > {
		const url = new URL( this.endpoint, window.location.origin );
		url.searchParams.set( 'per_page', '200' );
		if ( scope === 'all' ) {
			url.searchParams.set( 'scope', 'all' );
		} else if ( assistantId && assistantId > 0 ) {
			url.searchParams.set( 'assistant_id', String( assistantId ) );
		}
		const data = await this.request< {
			success?: boolean;
			tools?: ServerToolListItem[];
			total?: number;
			data?: {
				tools?: ServerToolListItem[];
				total?: number;
			};
		} >( { method: 'GET', url: url.toString(), signal } );

		const raw = Array.isArray( data?.tools )
			? data.tools
			: Array.isArray( data?.data?.tools )
			? data.data.tools
			: [];

		return {
			tools: raw.map( ( t ) => {
				const slug = typeof t?.slug === 'string' && t.slug ? t.slug : ( t?.name ?? '' );
				return {
					slug,
					name: t?.name ?? '',
					description: t?.description ?? '',
					// The modern envelope carries no category — derive one from the
					// toolkit slug prefix so the page's filter and badges work.
					category:
						typeof t?.category === 'string' && t.category
							? t.category
							: deriveToolCategory( slug ),
					required_capability:
						typeof t?.required_capability === 'string' ? t.required_capability : '',
					flags: Array.isArray( t?.flags ) ? t.flags : [],
					parameters: t?.inputSchema ?? {},
				};
			} ),
			total:
				typeof data?.total === 'number'
					? data.total
					: typeof data?.data?.total === 'number'
					? data.data.total
					: raw.length,
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
