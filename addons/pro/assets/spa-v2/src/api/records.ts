/**
 * Pro SPA v2 — Records client (Proposal 084 Phase 4, W11).
 *
 * Consumes the generic Records surface for the CRM command center:
 *   GET /mcp-ai-pro/v1/records/schema   — entity configs (labels, columns)
 *   GET /wp/v2/{post_type}              — the audit's wp-core storage
 *                                          strategy (meta exposed in REST by
 *                                          the Pro Records controller).
 *
 * Mirrors the TeamsClient fetch discipline (X-WP-Nonce, same-origin).
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface RecordColumn {
	key: string;
	label: string;
	type: string;
	enum?: string[];
}

export interface RecordEntityConfig {
	key: string;
	postType: string;
	restBase: string;
	label: string;
	singular: string;
	icon: string;
	/** Command-center partition (crm | pm | professions) — routes filter by it. */
	group?: string;
	columns: RecordColumn[];
}

export interface RecordsSchemaResponse {
	entities?: Record< string, RecordEntityConfig >;
	[ k: string ]: unknown;
}

export interface RecordSummary {
	id: number;
	title: string;
	date: string;
	status: string;
	meta: Record< string, unknown >;
}

export interface RecordsClientOptions {
	schemaEndpoint: string;
	wpBase: string;
	nonce: string;
}

/**
 * One page of records (server-side pagination).
 *
 * `total`/`totalPages` come from WordPress' `X-WP-Total` and
 * `X-WP-TotalPages` response headers, so the pager can show the true result
 * count instead of the length of the current slice.
 */
export interface RecordListPage {
	items: RecordSummary[];
	total: number;
	totalPages: number;
	page: number;
	perPage: number;
}

/** Arguments for a paginated record list request. */
export interface RecordListOptions {
	/** Free-text search term (WordPress `search` argument). */
	search?: string;
	/** 1-based page number (default 1). */
	page?: number;
	/** Rows per page (default 50, capped at WordPress' 100). */
	perPage?: number;
	signal?: AbortSignal;
}

/** Default page size for record lists (WordPress caps per_page at 100). */
export const RECORDS_PER_PAGE = 50;

/** Fields to request from wp/v2 — keeps payloads lean. */
const WP_FIELDS = 'id,date,status,title,meta';

export class RecordsClient {
	private readonly schemaEndpoint: string;
	private readonly wpBase: string;
	private readonly nonce: string;

	constructor( opts: RecordsClientOptions ) {
		this.schemaEndpoint = opts.schemaEndpoint.replace( /\/+$/, '' );
		this.wpBase = opts.wpBase.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** Fetch the entity catalogue (labels + columns per entity). */
	async schema( signal?: AbortSignal ): Promise< Record< string, RecordEntityConfig > > {
		const data = await this.request< RecordsSchemaResponse >(
			'GET',
			this.schemaEndpoint,
			undefined,
			signal
		);
		return data && typeof data.entities === 'object' && data.entities !== null
			? data.entities
			: {};
	}

	/**
	 * List one entity's records (wp/v2 with search + edit context).
	 *
	 * Returns the page slice plus the collection total read from
	 * `X-WP-Total` / `X-WP-TotalPages`; both headers are exposed by WordPress
	 * core on every collection route, so pagination never has to guess.
	 */
	async list(
		entity: RecordEntityConfig,
		options: RecordListOptions = {}
	): Promise< RecordListPage > {
		const page = Math.max( 1, Math.floor( options.page ?? 1 ) );
		const perPage = clampPerPage( options.perPage );
		const params = new URLSearchParams( {
			per_page: String( perPage ),
			page: String( page ),
			context: 'edit',
			_fields: WP_FIELDS,
			orderby: 'date',
			order: 'desc',
		} );
		const search = ( options.search ?? '' ).trim();
		if ( search ) {
			params.set( 'search', search );
		}
		const { data, headers } = await this.requestWithMeta< unknown >(
			'GET',
			`${ this.wpBase }/${ entity.restBase }?${ params.toString() }`,
			undefined,
			options.signal
		);
		const items = Array.isArray( data ) ? data.map( mapRecord ) : [];
		const total = readIntHeader( headers, 'X-WP-Total', items.length );
		const totalPages = readIntHeader(
			headers,
			'X-WP-TotalPages',
			Math.max( 1, Math.ceil( total / perPage ) )
		);
		return { items, total, totalPages, page, perPage };
	}

	/** Fetch a single record (wp/v2). */
	async get(
		entity: RecordEntityConfig,
		id: number,
		signal?: AbortSignal
	): Promise< RecordSummary | null > {
		const data = await this.request< unknown >(
			'GET',
			`${ this.wpBase }/${ entity.restBase }/${ id }?context=edit&_fields=${ WP_FIELDS }`,
			undefined,
			signal
		);
		return data && typeof data === 'object' ? mapRecord( data ) : null;
	}

	/**
	 * List one entity's records on a remote WordPress site (Proposal 089
	 * Phase B — the read-only proxy; credentials stay server-side).
	 *
	 * The proxy forwards `page` to the remote site and returns the remote
	 * collection's `total` / `total_pages` alongside the slice, so remote
	 * browsing paginates exactly like the local list.
	 */
	async listRemote(
		entity: RecordEntityConfig,
		options: RecordListOptions,
		remoteSite: string
	): Promise< RecordListPage > {
		const recordsBase = this.schemaEndpoint.replace( /\/schema\/?$/, '' );
		const page = Math.max( 1, Math.floor( options.page ?? 1 ) );
		const perPage = clampPerPage( options.perPage );
		const params = new URLSearchParams( {
			per_page: String( perPage ),
			page: String( page ),
			remote_site: remoteSite,
		} );
		const search = ( options.search ?? '' ).trim();
		if ( search ) {
			params.set( 'search', search );
		}
		const data = await this.request< {
			data?: { items?: unknown[]; total?: number; total_pages?: number };
		} >(
			'GET',
			`${ recordsBase }/remote/${ entity.key }?${ params.toString() }`,
			undefined,
			options.signal
		);
		const items = Array.isArray( data?.data?.items ) ? data.data.items.map( mapRecord ) : [];
		const reportedTotal = data?.data?.total;
		const total =
			typeof reportedTotal === 'number' && reportedTotal >= items.length
				? reportedTotal
				: items.length;
		const reportedPages = data?.data?.total_pages;
		const totalPages =
			typeof reportedPages === 'number' && reportedPages >= 1
				? reportedPages
				: Math.max( 1, Math.ceil( total / perPage ) );
		return { items, total, totalPages, page, perPage };
	}

	/** Fetch a single record on a remote WordPress site (Phase B proxy). */
	async getRemote(
		entity: RecordEntityConfig,
		id: number,
		remoteSite: string,
		signal?: AbortSignal
	): Promise< RecordSummary | null > {
		const recordsBase = this.schemaEndpoint.replace( /\/schema\/?$/, '' );
		const params = new URLSearchParams( { remote_site: remoteSite } );
		const data = await this.request< { data?: { item?: unknown } } >(
			'GET',
			`${ recordsBase }/remote/${ entity.key }/${ id }?${ params.toString() }`,
			undefined,
			signal
		);
		return data?.data?.item && typeof data.data.item === 'object' ? mapRecord( data.data.item ) : null;
	}

	/**
	 * Research a query and map the report onto the entity's schema fields
	 * (POST /records/{entity}/research — the 4a-b create-from-research
	 * flow; the server returns suggested fields, nothing is created).
	 */
	async research(
		entity: RecordEntityConfig,
		query: string,
		signal?: AbortSignal
	): Promise< { title: string; fields: Record< string, string > } > {
		const recordsBase = this.schemaEndpoint.replace( /\/schema\/?$/, '' );
		const data = await this.request< { title?: string; fields?: Record< string, string > } >(
			'POST',
			`${ recordsBase }/${ entity.key }/research`,
			{ query },
			signal
		);
		return {
			title: typeof data?.title === 'string' ? data.title : '',
			fields:
				data?.fields && typeof data.fields === 'object' && ! Array.isArray( data.fields )
					? data.fields
					: {},
		};
	}

	/**
	 * Create a record from reviewed fields (POST wp/v2/{post_type}).
	 * The server's registered meta sanitizers apply on write.
	 */
	async create(
		entity: RecordEntityConfig,
		title: string,
		fields: Record< string, unknown >,
		signal?: AbortSignal
	): Promise< RecordSummary | null > {
		const data = await this.request< unknown >(
			'POST',
			`${ this.wpBase }/${ entity.restBase }`,
			{
				title,
				status: 'publish',
				meta: fields,
			},
			signal
		);
		return data && typeof data === 'object' ? mapRecord( data ) : null;
	}

	private async request< T >(
		method: 'GET' | 'POST',
		url: string,
		body?: unknown,
		signal?: AbortSignal
	): Promise< T > {
		const { data } = await this.requestWithMeta< T >( method, url, body, signal );
		return data;
	}

	/**
	 * Perform a request and keep the response headers.
	 *
	 * Collection routes return `X-WP-Total` / `X-WP-TotalPages`, which is the
	 * only reliable way for the pager to know the real result count.
	 */
	private async requestWithMeta< T >(
		method: 'GET' | 'POST',
		url: string,
		body?: unknown,
		signal?: AbortSignal
	): Promise< { data: T; headers: Headers } > {
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
			const error = new Error(
				detail || __( 'Records request failed.', 'nvoos-pro-spa' )
			) as Error & { status?: number };
			error.status = response.status;
			throw error;
		}
		let data: T;
		try {
			data = ( await response.json() ) as T;
		} catch {
			data = undefined as unknown as T;
		}
		// Some test doubles (and a few exotic fetch polyfills) omit `headers`;
		// fall back to an empty Headers so pagination degrades to item counts.
		return { data, headers: response.headers ?? new Headers() };
	}
}

/** Clamp a requested page size into WordPress' 1–100 range. */
function clampPerPage( perPage: number | undefined ): number {
	const value = Math.floor( perPage ?? RECORDS_PER_PAGE );
	if ( ! Number.isFinite( value ) || value < 1 ) {
		return RECORDS_PER_PAGE;
	}
	return Math.min( 100, value );
}

/** Read an integer response header, falling back when absent or malformed. */
function readIntHeader( headers: Headers, name: string, fallback: number ): number {
	const raw = headers.get( name );
	const parsed = raw === null ? NaN : parseInt( raw, 10 );
	return Number.isFinite( parsed ) && parsed >= 0 ? parsed : fallback;
}

/** Map a raw wp/v2 post onto the RecordSummary shape. */
function mapRecord( raw: unknown ): RecordSummary {
	const post = ( raw ?? {} ) as {
		id?: number;
		date?: string;
		status?: string;
		title?: { rendered?: string; raw?: string } | string;
		meta?: Record< string, unknown >;
	};
	const titleValue =
		typeof post.title === 'string'
			? post.title
			: post.title?.rendered ?? post.title?.raw ?? '';
	return {
		id: typeof post.id === 'number' ? post.id : 0,
		title: typeof titleValue === 'string' ? titleValue : '',
		date: typeof post.date === 'string' ? post.date : '',
		status: typeof post.status === 'string' ? post.status : '',
		meta:
			post.meta && typeof post.meta === 'object' && ! Array.isArray( post.meta )
				? post.meta
				: {},
	};
}
