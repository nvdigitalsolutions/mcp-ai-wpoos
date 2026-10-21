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

	/** List one entity's records (wp/v2 with search + edit context). */
	async list(
		entity: RecordEntityConfig,
		search: string,
		signal?: AbortSignal
	): Promise< RecordSummary[] > {
		const params = new URLSearchParams( {
			per_page: '50',
			context: 'edit',
			_fields: WP_FIELDS,
			orderby: 'date',
			order: 'desc',
		} );
		if ( search.trim() ) {
			params.set( 'search', search.trim() );
		}
		const data = await this.request< unknown >(
			'GET',
			`${ this.wpBase }/${ entity.restBase }?${ params.toString() }`,
			undefined,
			signal
		);
		return Array.isArray( data ) ? data.map( mapRecord ) : [];
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
			throw new Error( detail || __( 'Records request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
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
