// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview @fileoverview Pro SPA v2 — scoped database toolkit console client (Proposal 167 P3).
 *
 * Wraps `/mcp-ai-pro/v1/database` (WordPress). The Laravel runtime keeps
 * connections and scopes in config files and reports the endpoint key
 * `runtime-adaptable`, so the toolkit page there renders its settings screen
 * without this console.
 *
 * Envelope: `{ success: true, data: … }`, errors
 * `{ success: false, error: { code, message } }` — the toolkit contract.
 *
 * ## What writes, and what does not
 *
 * Observation is GET-only. `testConnection()` opens a socket because a human
 * asked it to. `saveScope()` / `deleteScope()` write the *grant* record — the
 * same option the wp-admin page writes, through the same registry that owns the
 * validation and the version bump. No method here ever writes a database row:
 * `previewScope()` compiles a statement and stops before `prepare()`.
 *
 * @package NV_oOS_Pro_Spa
 * @since   3.2.0
 */

import { __ } from '@wordpress/i18n';

/** Stored probe result, as the record keeps it. */
export interface DatabaseProbe {
	reachable?: boolean;
	role?: string;
	server_version?: string;
	transaction_read_only?: boolean;
	can_write?: boolean;
	can_create_schema?: boolean;
	bypasses_rls?: boolean;
	checked_at?: number;
	error?: string | null;
}

/** One declared connection, credential removed. */
export interface DatabaseConnection {
	id: string;
	label: string;
	engine: string;
	kind: string;
	queryable: boolean;
	probe?: DatabaseProbe | null;
	host?: string;
	port?: number;
	database?: string;
	sslmode?: string;
	role?: string;
	allow_site_db?: boolean;
	has_credential?: boolean;
	/** Present when the stored record could not be validated. */
	error?: string;
}

/** Per-relation column rules on a scope. */
export interface DatabaseColumnRules {
	exclude?: string[];
	sensitive?: string[];
	searchable?: string[];
}

/** A mandatory predicate a scope applies to a relation. */
export interface DatabaseRowFilter {
	column: string;
	op: string;
	value_from?: string;
	value?: unknown;
	relation?: string;
}

/** One grant. `error` is set when the stored record could not be validated. */
export interface DatabaseScope {
	id: string;
	label?: string;
	connection?: string;
	schemas?: string[];
	tables?: string[];
	columns?: Record< string, DatabaseColumnRules >;
	row_filter?: DatabaseRowFilter[];
	tiers?: string[];
	limits?: Record< string, number >;
	principals?: string[];
	version?: number;
	error?: string;
}

/** One audit record. Values are never stored — only the statement template. */
export interface DatabaseAuditRecord {
	id: string;
	ts: number;
	connection: string;
	scope: string;
	scope_version: number;
	principal: string;
	action: string;
	tool: string;
	relation: string;
	refusal_code: string | null;
	sql_template: string;
	fingerprint: string;
	row_count: number;
	elapsed_ms: number;
	truncated: string;
	detail: string;
}

/** One sparkline bucket. */
export interface DatabaseAuditBucket {
	ts: number;
	calls: number;
	refusals: number;
}

/** Audit totals for the health and activity panels. */
export interface DatabaseAuditSnapshot {
	retained: number;
	total_24h: number;
	refusals: Record< string, number >;
	buckets: DatabaseAuditBucket[];
}

/** Runtime availability. */
export interface DatabaseRuntime {
	available: boolean;
	reason: string;
	reason_label: string;
	php: string;
}

/** The first-paint payload. */
export interface DatabaseOverview {
	runtime: DatabaseRuntime;
	enabled: boolean;
	write_path: boolean;
	connections: DatabaseConnection[];
	scopes: DatabaseScope[];
	pdo: Record< string, boolean >;
	limits: Record< string, number >;
	audit: DatabaseAuditSnapshot;
	admin_url: string;
}

/** One page of audit records plus the window summary. */
export interface DatabaseAuditPage {
	records: DatabaseAuditRecord[];
	filters: Record< string, string >;
	retained: number;
	summary: Record< string, number >;
}

/** Health snapshot. */
export interface DatabaseHealth {
	runtime: DatabaseRuntime;
	pdo: Record< string, boolean >;
	connections: DatabaseConnection[];
	audit: DatabaseAuditSnapshot;
}

/** Query filters for the audit tail. */
export interface DatabaseAuditQuery {
	connection?: string;
	scope?: string;
	tool?: string;
	action?: string;
	refusal_code?: string;
	limit?: number;
	signal?: AbortSignal;
}

/**
 * What a draft scope compiles to, before a single row is read.
 *
 * `statement` carries `:pN` placeholders only — bound values are counted
 * (`bindings`), never returned, so a preview can be pasted into a ticket.
 */
export interface DatabasePreview {
	connection: string;
	scope: string;
	relation: string;
	engine: string;
	statement: string;
	bindings: number;
	projected: string[];
	masked: string[];
	excluded: string[];
	searchable: string[];
	row_limit: number;
	limits: Record< string, number >;
	tenant_bound: boolean;
}

/** A read to compile. Never carries SQL or a bound value. */
export interface DatabasePreviewQuery {
	relation: string;
	columns?: string[];
	limit?: number;
}

/** Typed API failure. */
export class DatabaseApiError extends Error {
	readonly code: string;
	readonly status: number;

	constructor( message: string, code = 'request_failed', status = 0 ) {
		super( message );
		this.name = 'DatabaseApiError';
		this.code = code;
		this.status = status;
	}
}

export interface DatabaseClientOptions {
	/** `…/database` base (empty disables the surface). */
	endpoint: string;
	nonce: string;
}

export class DatabaseClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: DatabaseClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** Whether the client is wired to a backend at all. */
	get available(): boolean {
		return this.endpoint !== '';
	}

	/** Connections, scopes, limits, extensions and audit totals. */
	async overview( signal?: AbortSignal ): Promise< DatabaseOverview > {
		const data = await this.request< Partial< DatabaseOverview > >( 'GET', '/overview', undefined, signal );

		return {
			runtime: normalizeRuntime( data?.runtime ),
			enabled: data?.enabled === true,
			write_path: data?.write_path === true,
			connections: Array.isArray( data?.connections ) ? data.connections : [],
			scopes: Array.isArray( data?.scopes ) ? data.scopes : [],
			pdo: isRecord( data?.pdo ) ? ( data.pdo as Record< string, boolean > ) : {},
			limits: isRecord( data?.limits ) ? ( data.limits as Record< string, number > ) : {},
			audit: normalizeAudit( data?.audit ),
			admin_url: typeof data?.admin_url === 'string' ? data.admin_url : '',
		};
	}

	/** The audit tail, newest first. */
	async audit( query: DatabaseAuditQuery = {} ): Promise< DatabaseAuditPage > {
		const params = new URLSearchParams();

		for ( const key of [ 'connection', 'scope', 'tool', 'action', 'refusal_code' ] as const ) {
			const value = ( query[ key ] ?? '' ).trim();

			if ( value !== '' ) {
				params.set( key, value );
			}
		}

		params.set( 'limit', String( Math.min( 200, Math.max( 1, query.limit ?? 50 ) ) ) );

		const data = await this.request< Partial< DatabaseAuditPage > >(
			'GET',
			`/audit?${ params.toString() }`,
			undefined,
			query.signal
		);

		return {
			records: Array.isArray( data?.records ) ? data.records : [],
			filters: isRecord( data?.filters ) ? ( data.filters as Record< string, string > ) : {},
			retained: typeof data?.retained === 'number' ? data.retained : 0,
			summary: isRecord( data?.summary ) ? ( data.summary as Record< string, number > ) : {},
		};
	}

	/** Runtime, extensions, stored probes and the refusal series. */
	async health( signal?: AbortSignal ): Promise< DatabaseHealth > {
		const data = await this.request< Partial< DatabaseHealth > >( 'GET', '/health', undefined, signal );

		return {
			runtime: normalizeRuntime( data?.runtime ),
			pdo: isRecord( data?.pdo ) ? ( data.pdo as Record< string, boolean > ) : {},
			connections: Array.isArray( data?.connections ) ? data.connections : [],
			audit: normalizeAudit( data?.audit ),
		};
	}

	/**
	 * Probe one connection and record the result.
	 *
	 * The only call that opens a socket, and only because a human clicked Test.
	 */
	async testConnection( id: string ): Promise< DatabaseProbe > {
		const data = await this.request< { probe?: DatabaseProbe } >(
			'POST',
			`/connections/${ encodeURIComponent( id ) }/test`
		);

		return isRecord( data?.probe ) ? ( data.probe as DatabaseProbe ) : {};
	}

	/**
	 * Create or replace a scope.
	 *
	 * The registry is the validator: a wildcard schema, an empty principal list
	 * or an unknown tier is refused server-side with a machine code the editor
	 * maps onto the offending field. An unchanged save is a successful no-op and
	 * does not move the grant version.
	 */
	async saveScope( scope: DatabaseScope ): Promise< DatabaseScope > {
		const data = await this.request< { scope?: DatabaseScope } >( 'POST', '/scopes', { scope } );

		return isRecord( data?.scope ) ? ( data.scope as DatabaseScope ) : { id: scope.id };
	}

	/** Delete a scope. A missing id is a 404 the caller surfaces. */
	async deleteScope( id: string ): Promise< void > {
		await this.request< unknown >( 'DELETE', `/scopes/${ encodeURIComponent( id ) }` );
	}

	/**
	 * Compile a draft (or saved) scope against a relation.
	 *
	 * Executes nothing: the executor resolves, tiers, admits and renders the
	 * statement, then stops. The catalogue read does touch the connection, so the
	 * connection must hold a successful probe.
	 */
	async previewScope(
		connection: string,
		scope: DatabaseScope,
		query: DatabasePreviewQuery,
		signal?: AbortSignal
	): Promise< DatabasePreview > {
		const data = await this.request< { preview?: Partial< DatabasePreview > } >(
			'POST',
			'/preview',
			{ connection, scope, query },
			signal
		);

		const preview = isRecord( data?.preview ) ? data.preview : {};
		const strings = ( value: unknown ): string[] =>
			Array.isArray( value ) ? value.filter( ( item ): item is string => typeof item === 'string' ) : [];

		return {
			connection: typeof preview.connection === 'string' ? preview.connection : '',
			scope: typeof preview.scope === 'string' ? preview.scope : '',
			relation: typeof preview.relation === 'string' ? preview.relation : '',
			engine: typeof preview.engine === 'string' ? preview.engine : '',
			statement: typeof preview.statement === 'string' ? preview.statement : '',
			bindings: typeof preview.bindings === 'number' ? preview.bindings : 0,
			projected: strings( preview.projected ),
			masked: strings( preview.masked ),
			excluded: strings( preview.excluded ),
			searchable: strings( preview.searchable ),
			row_limit: typeof preview.row_limit === 'number' ? preview.row_limit : 0,
			limits: isRecord( preview.limits ) ? ( preview.limits as Record< string, number > ) : {},
			tenant_bound: preview.tenant_bound === true,
		};
	}

	private async request< T >(
		method: 'GET' | 'POST' | 'DELETE',
		path: string,
		body?: unknown,
		signal?: AbortSignal
	): Promise< T | null > {
		if ( ! this.available ) {
			throw new DatabaseApiError(
				__( 'The database console is not available on this runtime.', 'nvoos-pro-spa' ),
				'unavailable'
			);
		}

		const headers: Record< string, string > = { Accept: 'application/json' };

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

			throw new DatabaseApiError(
				error instanceof Error ? error.message : __( 'Network request failed.', 'nvoos-pro-spa' ),
				'network_error'
			);
		}

		const payload = await parseJson( response );

		if ( ! response.ok ) {
			const envelope = payload as {
				error?: { code?: string; message?: string };
				message?: string;
			} | null;

			throw new DatabaseApiError(
				envelope?.error?.message ?? envelope?.message ?? __( 'Database request failed.', 'nvoos-pro-spa' ),
				envelope?.error?.code ?? 'request_failed',
				response.status
			);
		}

		const envelope = payload as { success?: boolean; data?: T } | null;

		return envelope?.data ?? null;
	}
}

function isRecord( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null && ! Array.isArray( value );
}

function normalizeRuntime( value: unknown ): DatabaseRuntime {
	const record = isRecord( value ) ? value : {};

	return {
		available: record.available === true,
		reason: typeof record.reason === 'string' ? record.reason : '',
		reason_label: typeof record.reason_label === 'string' ? record.reason_label : '',
		php: typeof record.php === 'string' ? record.php : '',
	};
}

function normalizeAudit( value: unknown ): DatabaseAuditSnapshot {
	const record = isRecord( value ) ? value : {};
	const refusals = isRecord( record.refusals ) ? ( record.refusals as Record< string, number > ) : {};

	return {
		retained: typeof record.retained === 'number' ? record.retained : 0,
		total_24h: typeof record.total_24h === 'number' ? record.total_24h : 0,
		refusals,
		buckets: Array.isArray( record.buckets ) ? ( record.buckets as DatabaseAuditBucket[] ) : [],
	};
}

async function parseJson( response: Response ): Promise< unknown > {
	try {
		return await response.json();
	} catch {
		return null;
	}
}
