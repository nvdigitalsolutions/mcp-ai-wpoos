/**
 * NV oOS Chat SPA — typed wrappers around `mcp-ai/v1/chat-memory`.
 *
 * Routes exposed by `WP_MCP_AI_REST_Chat_Memory_Controller`:
 *
 *   GET  /preferences                    — { enabled, autosummarize }
 *   POST /preferences                    — update toggles, returns same shape
 *   GET  /recall                         — { contexts: MemoryContext[] }
 *   GET  /wake-up                        — (internal, not used in drawer)
 *   POST /store                          — create a memory
 *   PUT  /{context_id}                   — partial update
 *   DELETE /{context_id}                 — delete
 *   GET  /audit                          — { entries: AuditEntry[] }
 *
 * The controller always wraps success in `{ success: true, ...payload }`.
 * We normalise the shapes here so the UI doesn't need to deal with
 * envelope variations.
 */

import { __ } from '@wordpress/i18n';

export interface MemoryPreferences {
	enabled: boolean;
	autosummarize: boolean;
}

export interface MemoryContext {
	context_id?: string;
	id?: string;
	uuid?: string;
	title?: string;
	content?: string;
	importance?: string;
	tags?: string[];
	wing?: string;
	room?: string;
	context_type?: string;
	context_data?: {
		title?: string;
		content?: string;
		importance?: string;
		tags?: string[];
		[ k: string ]: unknown;
	};
	created_at?: string;
	updated_at?: string;
	[ k: string ]: unknown;
}

export interface AuditEntry {
	action?: string;
	context_id?: string;
	timestamp?: string;
	agent_id?: string | number;
	details?: Record< string, unknown >;
	[ k: string ]: unknown;
}

export interface MemoryClientOptions {
	endpoint: string;
	nonce: string;
	assistantId: number | string;
}

/**
 * localStorage key for wing/room scope persisted per-assistant.
 */
export function memoryScopeStorageKey( assistantId: number | string ): string {
	return `nvoos-chat-spa.memory-scope.${ assistantId || 0 }`;
}

export function readPersistedScope( assistantId: number | string ): { wing: string; room: string } {
	if ( typeof window === 'undefined' ) {
		return { wing: '', room: '' };
	}
	try {
		const raw = window.localStorage.getItem( memoryScopeStorageKey( assistantId ) );
		if ( raw ) {
			const parsed = JSON.parse( raw ) as { wing?: string; room?: string };
			return {
				wing: typeof parsed.wing === 'string' ? parsed.wing : '',
				room: typeof parsed.room === 'string' ? parsed.room : '',
			};
		}
	} catch {
		// Ignore.
	}
	return { wing: '', room: '' };
}

export function persistScope(
	assistantId: number | string,
	wing: string,
	room: string
): void {
	if ( typeof window === 'undefined' ) {
		return;
	}
	try {
		window.localStorage.setItem(
			memoryScopeStorageKey( assistantId ),
			JSON.stringify( { wing, room } )
		);
	} catch {
		// Ignore quota failures.
	}
}

export class MemoryClient {
	private readonly base: string;
	private readonly nonce: string;
	private readonly assistantId: number | string;

	constructor( opts: MemoryClientOptions ) {
		this.base = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
		this.assistantId = opts.assistantId;
	}

	async getPreferences( signal?: AbortSignal ): Promise< MemoryPreferences > {
		const data = await this.request< MemoryPreferences & { success?: boolean } >(
			'GET',
			`${ this.base }/preferences`,
			undefined,
			signal
		);
		return {
			enabled: typeof data?.enabled === 'boolean' ? data.enabled : true,
			autosummarize: typeof data?.autosummarize === 'boolean' ? data.autosummarize : false,
		};
	}

	async updatePreferences(
		prefs: Partial< MemoryPreferences >,
		signal?: AbortSignal
	): Promise< MemoryPreferences > {
		const data = await this.request< MemoryPreferences & { success?: boolean } >(
			'POST',
			`${ this.base }/preferences`,
			prefs,
			signal
		);
		return {
			enabled: typeof data?.enabled === 'boolean' ? data.enabled : true,
			autosummarize: typeof data?.autosummarize === 'boolean' ? data.autosummarize : false,
		};
	}

	async recall(
		opts: { wing?: string; room?: string; query?: string; limit?: number },
		signal?: AbortSignal
	): Promise< MemoryContext[] > {
		const url = new URL( `${ this.base }/recall`, window.location.origin );
		if ( this.assistantId ) {
			url.searchParams.set( 'agent_id', String( this.assistantId ) );
		}
		if ( opts.wing ) url.searchParams.set( 'wing', opts.wing );
		if ( opts.room ) url.searchParams.set( 'room', opts.room );
		if ( opts.query ) url.searchParams.set( 'query', opts.query );
		if ( opts.limit ) url.searchParams.set( 'limit', String( opts.limit ) );

		const data = await this.request< Record< string, unknown > >(
			'GET',
			url.toString(),
			undefined,
			signal
		);
		return extractContexts( data );
	}

	async store(
		payload: {
			content: string;
			title?: string;
			importance?: string;
			tags?: string[];
			wing?: string;
			room?: string;
		},
		signal?: AbortSignal
	): Promise< MemoryContext > {
		const body: Record< string, unknown > = {
			agent_id: this.assistantId,
			content: payload.content,
		};
		if ( payload.title ) body.title = payload.title;
		if ( payload.importance ) body.importance = payload.importance;
		if ( Array.isArray( payload.tags ) ) body.tags = payload.tags;
		if ( payload.wing ) body.wing = payload.wing;
		if ( payload.room ) body.room = payload.room;

		const data = await this.request< Record< string, unknown > >(
			'POST',
			`${ this.base }/store`,
			body,
			signal
		);
		return ( data as MemoryContext ) ?? {};
	}

	async update(
		contextId: string,
		changes: Partial< Pick< MemoryContext, 'title' | 'content' | 'importance' | 'tags' > >,
		signal?: AbortSignal
	): Promise< void > {
		await this.request(
			'PUT',
			`${ this.base }/${ encodeURIComponent( contextId ) }`,
			{ agent_id: this.assistantId, ...changes },
			signal
		);
	}

	async delete( contextId: string, signal?: AbortSignal ): Promise< void > {
		await this.request(
			'DELETE',
			`${ this.base }/${ encodeURIComponent( contextId ) }?agent_id=${ encodeURIComponent( String( this.assistantId ) ) }`,
			undefined,
			signal
		);
	}

	async audit(
		opts?: { limit?: number; action_type?: string },
		signal?: AbortSignal
	): Promise< AuditEntry[] > {
		const url = new URL( `${ this.base }/audit`, window.location.origin );
		if ( this.assistantId ) {
			url.searchParams.set( 'agent_id', String( this.assistantId ) );
		}
		if ( opts?.limit ) url.searchParams.set( 'limit', String( opts.limit ) );
		if ( opts?.action_type ) url.searchParams.set( 'action_type', opts.action_type );

		const data = await this.request< Record< string, unknown > >(
			'GET',
			url.toString(),
			undefined,
			signal
		);
		return extractAuditEntries( data );
	}

	private async request< T = unknown >(
		method: 'GET' | 'POST' | 'PUT' | 'DELETE',
		url: string,
		body?: unknown,
		signal?: AbortSignal
	): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
		};
		if ( this.nonce ) {
			headers[ 'X-WP-Nonce' ] = this.nonce;
		}
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
				const errBody = ( await response.json() ) as { message?: string; code?: string };
				detail = errBody?.message ?? errBody?.code ?? '';
			} catch {
				// Not JSON.
			}
			throw new Error(
				detail ||
					/* translators: %d: HTTP status code. */
					__( 'Memory request failed (status %d).', 'nvoos-chat-spa' ).replace(
						'%d',
						String( response.status )
					)
			);
		}

		if ( response.status === 204 ) {
			return undefined as unknown as T;
		}

		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}

/**
 * Extract contexts from the dispatch_tool success wrapper.
 * The REST controller returns `{ success: true, contexts: [...] }` (or nested
 * under `data`), mirroring what the legacy chat-memory-drawer.js does.
 */
function extractContexts( data: Record< string, unknown > ): MemoryContext[] {
	if ( ! data || typeof data !== 'object' ) return [];
	if ( Array.isArray( data.contexts ) ) return data.contexts as MemoryContext[];
	if ( Array.isArray( data.results ) ) return data.results as MemoryContext[];
	if ( Array.isArray( data.memories ) ) return data.memories as MemoryContext[];
	const inner = data.data as Record< string, unknown > | undefined;
	if ( inner ) {
		if ( Array.isArray( inner.contexts ) ) return inner.contexts as MemoryContext[];
		if ( Array.isArray( inner.results ) ) return inner.results as MemoryContext[];
		if ( Array.isArray( inner.memories ) ) return inner.memories as MemoryContext[];
	}
	return [];
}

function extractAuditEntries( data: Record< string, unknown > ): AuditEntry[] {
	if ( ! data || typeof data !== 'object' ) return [];
	if ( Array.isArray( data.entries ) ) return data.entries as AuditEntry[];
	const inner = data.data as Record< string, unknown > | undefined;
	if ( inner && Array.isArray( inner.entries ) ) return inner.entries as AuditEntry[];
	return [];
}
