import type { Blueprint, CatalogueEntry, EstateDetail, EstateSummary } from '../types';

export class ApiClientError extends Error {
	constructor(
		public code: string,
		message: string,
		public status: number,
		public extra: Record<string, unknown> = {}
	) {
		super( message );
		this.name = 'ApiClientError';
	}
}

interface ApiEnvelope<T> {
	ok: boolean;
	data?: T;
	error?: { code?: string; message?: string } & Record<string, unknown>;
}

export interface ApiClientOptions {
	baseUrl?: string;
	token?: string | null;
	getToken?: () => string | null;
	fetchImpl?: typeof fetch;
}

export function createApiClient( options: ApiClientOptions = {} ) {
	const baseUrl = ( options.baseUrl ?? '' ).replace( /\/+$/, '' );
	const fetchImpl = options.fetchImpl ?? globalThis.fetch;
	const getToken =
		options.getToken ??
		( () =>
			typeof localStorage !== 'undefined'
				? localStorage.getItem( 'nvoos-exchange-admin-token' )
				: null );

	async function request<T>(
		path: string,
		init: { method?: string; body?: unknown } = {}
	): Promise<T> {
		const headers: Record<string, string> = { Accept: 'application/json' };
		if ( init.body !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		const token = options.token ?? getToken();
		if ( token ) {
			headers.Authorization = `Bearer ${ token }`;
		}

		const response = await fetchImpl( `${ baseUrl }/api/v1${ path }`, {
			method: init.method ?? 'GET',
			headers,
			body: init.body === undefined ? undefined : JSON.stringify( init.body ),
		} );

		let payload: ApiEnvelope<T> | null = null;
		try {
			payload = ( await response.json() ) as ApiEnvelope<T>;
		} catch {
			payload = null;
		}

		if ( ! response.ok || ! payload?.ok ) {
			throw new ApiClientError(
				String( payload?.error?.code ?? 'request_failed' ),
				String( payload?.error?.message ?? `Request failed (${ response.status })` ),
				response.status,
				( payload?.error ?? {} ) as Record<string, unknown>
			);
		}
		return payload.data as T;
	}

	return {
		health: () => request<{ service: string; version: string; mode: string }>( '/health' ),
		blueprints: () => request<Blueprint[]>( '/blueprints' ),
		estates: () => request<EstateSummary[]>( '/estates' ),
		estate: ( slug: string ) => request<EstateDetail>( `/estates/${ encodeURIComponent( slug ) }` ),
		createEstate: ( payload: { blueprint?: Blueprint; blueprint_slug?: string } ) =>
			request<EstateSummary>( '/estates', { method: 'POST', body: payload } ),
		advance: ( slug: string ) =>
			request<{ state: string; steps: EstateSummary['steps']; advanced: boolean }>(
				`/estates/${ encodeURIComponent( slug ) }/advance`,
				{ method: 'POST' }
			),
		linkCommerce: ( slug: string, stripePriceId: string ) =>
			request<EstateSummary>( `/estates/${ encodeURIComponent( slug ) }/commerce`, {
				method: 'POST',
				body: { stripe_price_id: stripePriceId },
			} ),
		teardown: ( slug: string ) =>
			request<{ slug: string; removed: boolean }>( `/estates/${ encodeURIComponent( slug ) }/teardown`, {
				method: 'POST',
				body: { confirm: true, slug },
			} ),
		catalogue: () => request<CatalogueEntry[]>( '/catalogue' ),
	};
}

export type ApiClient = ReturnType<typeof createApiClient>;
