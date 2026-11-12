/**
 * Pro SPA v2 — Toolkit engine client (Proposal 090-B §8).
 *
 * Wraps `/mcp-ai-pro/v1/toolkits` (WordPress) and `/api/v1/pro/toolkits`
 * (Laravel) — the manifest-driven Pro surface. Every family is described by
 * one manifest document; this client is the only place that knows the wire
 * shape, so the five generic screens stay declarative.
 *
 * Envelope: `{ success: true, data: … }`, errors
 * `{ success: false, error: { code, message, fields? } }`.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import { __ } from '@wordpress/i18n';

/** A column the entity workbench renders, sorts and validates. */
export interface ToolkitColumn {
	key: string;
	label: string;
	type: 'string' | 'number' | 'currency' | 'boolean' | 'date' | 'enum';
	enum?: string[];
	required?: boolean;
}

/** One entity type declared by a manifest. */
export interface ToolkitEntity {
	key: string;
	label: string;
	singular: string;
	icon: string;
	id_field: string;
	columns: ToolkitColumn[];
}

/** A setting field the settings screen renders. */
export interface ToolkitSettingField {
	key: string;
	label: string;
	type: 'text' | 'number' | 'boolean' | 'select' | 'secret';
	default?: unknown;
	description?: string;
	options?: string[] | Record< string, string >;
}

/** A dashboard card the engine aggregates server-side. */
export interface ToolkitDashboardCard {
	key: string;
	label: string;
	source: string;
	aggregate: 'count' | 'sum' | 'avg' | 'min' | 'max';
	column?: string;
	filter?: { column: string; op: string; value?: unknown };
}

/** The full manifest document. */
export interface ToolkitManifest {
	slug: string;
	name: string;
	version: string;
	description?: string;
	icon?: string;
	feature: string;
	screens: string[];
	settings: ToolkitSettingField[];
	entities: ToolkitEntity[];
	dashboard: { cards: ToolkitDashboardCard[] };
}

/** Row shape in the toolkit index. */
export interface ToolkitSummary {
	slug: string;
	name: string;
	version: string;
	description: string;
	icon: string;
	feature: string;
	screens: string[];
	entities: Array< { key: string; label: string; icon: string } >;
}

/** One stored record. `id` is the manifest's business key. */
export interface ToolkitRecord {
	id: string;
	entity: string;
	data: Record< string, unknown >;
	created_at?: string | null;
	updated_at?: string | null;
}

/** One page of records. */
export interface ToolkitRecordsPage {
	items: ToolkitRecord[];
	total: number;
	page: number;
	perPage: number;
}

/** Settings schema + current values. */
export interface ToolkitSettingsState {
	schema: ToolkitSettingField[];
	values: Record< string, unknown >;
}

/** One computed dashboard card. */
export interface ToolkitDashboardCardResult {
	key: string;
	label: string;
	aggregate: string;
	value: number;
}

/** Typed API failure — `fields` carries per-field validation messages. */
export class ToolkitsApiError extends Error {
	readonly code: string;
	readonly fields: Record< string, string >;

	constructor( message: string, code = 'request_failed', fields: Record< string, string > = {} ) {
		super( message );
		this.name = 'ToolkitsApiError';
		this.code = code;
		this.fields = fields;
	}
}

export interface ToolkitsClientOptions {
	/** `…/toolkits` base (empty disables the surface). */
	endpoint: string;
	nonce: string;
}

export interface RecordQuery {
	page?: number;
	perPage?: number;
	search?: string;
	filter?: Record< string, string >;
	signal?: AbortSignal;
}

export class ToolkitsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: ToolkitsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** Whether the client is wired to a backend at all. */
	get available(): boolean {
		return this.endpoint !== '';
	}

	/** List the manifests this session is entitled to. */
	async list( signal?: AbortSignal ): Promise< {
		toolkits: ToolkitSummary[];
		invalid: Record< string, string[] >;
	} > {
		const data = await this.request< {
			toolkits?: ToolkitSummary[];
			invalid?: Record< string, string[] >;
		} >( 'GET', '', undefined, signal );

		return {
			toolkits: Array.isArray( data?.toolkits ) ? data.toolkits : [],
			invalid: data?.invalid && typeof data.invalid === 'object' ? data.invalid : {},
		};
	}

	/** Fetch one manifest. */
	async show( slug: string, signal?: AbortSignal ): Promise< ToolkitManifest > {
		const data = await this.request< { toolkit?: ToolkitManifest } >(
			'GET',
			`/${ encodeURIComponent( slug ) }`,
			undefined,
			signal
		);

		if ( ! data?.toolkit ) {
			throw new ToolkitsApiError( __( 'Toolkit manifest was empty.', 'nvoos-pro-spa' ), 'empty_manifest' );
		}

		return data.toolkit;
	}

	/** Read settings schema + values. */
	async settings( slug: string, signal?: AbortSignal ): Promise< ToolkitSettingsState > {
		const data = await this.request< { schema?: ToolkitSettingField[]; values?: Record< string, unknown > } >(
			'GET',
			`/${ encodeURIComponent( slug ) }/settings`,
			undefined,
			signal
		);

		return {
			schema: Array.isArray( data?.schema ) ? data.schema : [],
			values: data?.values && typeof data.values === 'object' ? data.values : {},
		};
	}

	/** Persist settings values; returns the stored values. */
	async saveSettings( slug: string, values: Record< string, unknown > ): Promise< Record< string, unknown > > {
		const data = await this.request< { values?: Record< string, unknown > } >(
			'POST',
			`/${ encodeURIComponent( slug ) }/settings`,
			{ values }
		);

		return data?.values && typeof data.values === 'object' ? data.values : {};
	}

	/** Aggregated dashboard cards. */
	async dashboard( slug: string, signal?: AbortSignal ): Promise< ToolkitDashboardCardResult[] > {
		const data = await this.request< { cards?: ToolkitDashboardCardResult[] } >(
			'GET',
			`/${ encodeURIComponent( slug ) }/dashboard`,
			undefined,
			signal
		);

		return Array.isArray( data?.cards ) ? data.cards : [];
	}

	/** One page of records for an entity. */
	async records( slug: string, entity: string, query: RecordQuery = {} ): Promise< ToolkitRecordsPage > {
		const page = Math.max( 1, Math.floor( query.page ?? 1 ) );
		const perPage = Math.min( 100, Math.max( 1, Math.floor( query.perPage ?? 25 ) ) );
		const params = new URLSearchParams( {
			page: String( page ),
			per_page: String( perPage ),
		} );

		const search = ( query.search ?? '' ).trim();
		if ( search ) {
			params.set( 'search', search );
		}

		for ( const [ column, value ] of Object.entries( query.filter ?? {} ) ) {
			if ( value !== '' ) {
				params.set( `filter[${ column }]`, value );
			}
		}

		const data = await this.request< {
			records?: ToolkitRecord[];
			total?: number;
			page?: number;
			per_page?: number;
		} >( 'GET', `/${ encodeURIComponent( slug ) }/entities/${ encodeURIComponent( entity ) }?${ params.toString() }`, undefined, query.signal );

		const items = Array.isArray( data?.records ) ? data.records : [];
		const total = typeof data?.total === 'number' && data.total >= 0 ? data.total : items.length;

		return {
			items,
			total,
			page: typeof data?.page === 'number' ? data.page : page,
			perPage: typeof data?.per_page === 'number' ? data.per_page : perPage,
		};
	}

	/** Create a record. */
	async createRecord( slug: string, entity: string, data: Record< string, unknown > ): Promise< ToolkitRecord > {
		const body = await this.request< { record?: ToolkitRecord } >(
			'POST',
			`/${ encodeURIComponent( slug ) }/entities/${ encodeURIComponent( entity ) }`,
			{ data }
		);

		if ( ! body?.record ) {
			throw new ToolkitsApiError( __( 'The server did not return the created record.', 'nvoos-pro-spa' ), 'empty_record' );
		}

		return body.record;
	}

	/** Partial update (the server merges into the stored payload). */
	async updateRecord(
		slug: string,
		entity: string,
		id: string,
		data: Record< string, unknown >
	): Promise< ToolkitRecord > {
		const body = await this.request< { record?: ToolkitRecord } >(
			'PUT',
			`/${ encodeURIComponent( slug ) }/entities/${ encodeURIComponent( entity ) }/${ encodeURIComponent( id ) }`,
			{ data }
		);

		if ( ! body?.record ) {
			throw new ToolkitsApiError( __( 'The server did not return the updated record.', 'nvoos-pro-spa' ), 'empty_record' );
		}

		return body.record;
	}

	/** Delete a record by business key. */
	async deleteRecord( slug: string, entity: string, id: string ): Promise< boolean > {
		const body = await this.request< { deleted?: boolean } >(
			'DELETE',
			`/${ encodeURIComponent( slug ) }/entities/${ encodeURIComponent( entity ) }/${ encodeURIComponent( id ) }`
		);

		return body?.deleted === true;
	}

	private async request< T >(
		method: 'GET' | 'POST' | 'PUT' | 'DELETE',
		path: string,
		body?: unknown,
		signal?: AbortSignal
	): Promise< T | null > {
		if ( ! this.available ) {
			throw new ToolkitsApiError(
				__( 'The toolkit engine is not available on this runtime.', 'nvoos-pro-spa' ),
				'unavailable'
			);
		}

		const headers: Record< string, string > = {
			Accept: 'application/json',
		};

		if ( this.nonce ) {
			headers[ 'X-WP-Nonce' ] = this.nonce;
		}

		if ( body !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		let response: Response;

		try {
			response = await fetch( `${ this.endpoint }${ path }`, {
				method,
				credentials: 'same-origin',
				headers,
				body: body !== undefined ? JSON.stringify( body ) : undefined,
				signal,
			} );
		} catch ( error ) {
			if ( error instanceof DOMException && error.name === 'AbortError' ) {
				throw error;
			}

			throw new ToolkitsApiError(
				error instanceof Error ? error.message : __( 'Network request failed.', 'nvoos-pro-spa' ),
				'network_error'
			);
		}

		const payload = await this.parseJson( response );

		if ( ! response.ok ) {
			const envelope = payload as {
				error?: { code?: string; message?: string; fields?: Record< string, string > };
				message?: string;
			} | null;

			throw new ToolkitsApiError(
				envelope?.error?.message ?? envelope?.message ?? __( 'Toolkit request failed.', 'nvoos-pro-spa' ),
				envelope?.error?.code ?? 'request_failed',
				envelope?.error?.fields ?? {}
			);
		}

		const envelope = payload as { success?: boolean; data?: T } | null;

		return envelope?.data ?? null;
	}

	private async parseJson( response: Response ): Promise< unknown > {
		try {
			return await response.json();
		} catch {
			return null;
		}
	}
}
