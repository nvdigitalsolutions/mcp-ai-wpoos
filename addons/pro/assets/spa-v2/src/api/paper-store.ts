/**
 * Pro SPA v2 — Paper Store client.
 *
 * Wraps mcp-ai/v1/paper-store/* (collections, records CRUD, search) for the
 * PaperStorePage. Mirrors the RecordsClient fetch discipline (X-WP-Nonce,
 * same-origin cookies, non-JSON-safe error extraction).
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export type PaperRecordStatus = 'published' | 'draft' | 'archived';

export interface PaperRecord {
	id: string;
	title: string;
	description?: string;
	tags?: string[];
	status?: PaperRecordStatus;
	type?: string;
	/** Free-form body — any JSON-serialisable value. */
	body?: unknown;
	meta?: Record< string, unknown >;
	updated_at?: string;
}

export interface PaperRecordInput {
	id?: string;
	title: string;
	description?: string;
	tags?: string[];
	status?: PaperRecordStatus;
	body?: unknown;
	meta?: Record< string, unknown >;
}

export interface PaperRecordPage {
	items: PaperRecord[];
	/** Total records in the collection for the active filters (not the page length). */
	total: number;
	page: number;
	perPage: number;
}

export interface PaperRecordsOptions {
	tag?: string;
	status?: PaperRecordStatus;
	/** 1-based page (default 1). */
	page?: number;
	/** Rows per page (default 25, capped at the API's 200). */
	perPage?: number;
	signal?: AbortSignal;
}

/** Default page size for Paper Store record lists. */
export const PAPER_STORE_PER_PAGE = 25;

export interface PaperStoreClientOptions {
	endpoint: string;
	nonce: string;
}

export class PaperStoreClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: PaperStoreClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** List all collection names. */
	async collections( signal?: AbortSignal ): Promise< string[] > {
		const data = await this.request< { collections?: string[] } >(
			'GET',
			this.endpoint,
			undefined,
			signal
		);
		return Array.isArray( data?.collections ) ? data.collections : [];
	}

	/**
	 * List one page of records in a collection (optional tag/status filters).
	 *
	 * The REST envelope carries `total` counted before the `limit`/`offset`
	 * slice, so the console pager can show the true collection size.
	 */
	async records(
		collection: string,
		options: PaperRecordsOptions = {}
	): Promise< PaperRecordPage > {
		const page = Math.max( 1, Math.floor( options.page ?? 1 ) );
		const perPage = Math.min(
			200,
			Math.max( 1, Math.floor( options.perPage ?? PAPER_STORE_PER_PAGE ) )
		);
		const params = new URLSearchParams( {
			limit: String( perPage ),
			offset: String( ( page - 1 ) * perPage ),
		} );
		if ( options.tag ) {
			params.set( 'tag', options.tag );
		}
		if ( options.status ) {
			params.set( 'status', options.status );
		}
		const data = await this.request< { records?: PaperRecord[]; total?: number } >(
			'GET',
			`${ this.endpoint }/${ encodeURIComponent( collection ) }?${ params.toString() }`,
			undefined,
			options.signal
		);
		const items = Array.isArray( data?.records ) ? data.records : [];
		const reportedTotal = data?.total;

		return {
			items,
			total:
				typeof reportedTotal === 'number' && reportedTotal >= items.length
					? reportedTotal
					: items.length,
			page,
			perPage,
		};
	}

	/** Cross-collection search. */
	async search( q: string, collection?: string, signal?: AbortSignal ): Promise< PaperRecord[] > {
		const params = new URLSearchParams( { q, limit: '50' } );
		if ( collection ) {
			params.set( 'collection', collection );
		}
		const data = await this.request< { records?: PaperRecord[] } >(
			'GET',
			`${ this.endpoint }/search?${ params.toString() }`,
			undefined,
			signal
		);
		return Array.isArray( data?.records ) ? data.records : [];
	}

	/** Create a record. */
	async create(
		collection: string,
		input: PaperRecordInput,
		signal?: AbortSignal
	): Promise< PaperRecord | null > {
		return this.request< PaperRecord | null >(
			'POST',
			`${ this.endpoint }/${ encodeURIComponent( collection ) }`,
			input,
			signal
		);
	}

	/** Update a record. */
	async update(
		collection: string,
		id: string,
		input: PaperRecordInput,
		signal?: AbortSignal
	): Promise< PaperRecord | null > {
		return this.request< PaperRecord | null >(
			'PUT',
			`${ this.endpoint }/${ encodeURIComponent( collection ) }/${ encodeURIComponent( id ) }`,
			input,
			signal
		);
	}

	/** Delete a record. */
	async remove( collection: string, id: string, signal?: AbortSignal ): Promise< boolean > {
		const data = await this.request< { ok?: boolean } >(
			'DELETE',
			`${ this.endpoint }/${ encodeURIComponent( collection ) }/${ encodeURIComponent( id ) }`,
			undefined,
			signal
		);
		return data?.ok === true;
	}

	private async request< T >(
		method: 'GET' | 'POST' | 'PUT' | 'DELETE',
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
			throw new Error( detail || __( 'Paper Store request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
