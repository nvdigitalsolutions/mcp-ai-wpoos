/**
 * Resource (data) client.
 *
 * Talks to whichever REST namespace the manifest declares — typically
 * `mcp-ai-pro/v1`. Domain data flows through the existing Pro endpoints,
 * never through the Toolkit Shell's own namespace.
 *
 * @since 0.1.0
 */

import type { Resource } from './types';

export interface ListParams {
	page?: number;
	per_page?: number;
	search?: string;
	orderby?: string;
	order?: 'asc' | 'desc';
}

export interface ListResult {
	items: Array<Record<string, unknown>>;
	total?: number;
	totalPages?: number;
}

function getBootstrap() {
	const g = window.NVOOS_TOOLKIT_SHELL;
	if ( ! g ) {
		throw new Error( 'NV oOS Toolkit Shell bootstrap is missing.' );
	}
	return g;
}

/**
 * Build a base URL for a given REST namespace declared in a manifest.
 */
function urlFor( namespace: string ): string {
	const g = getBootstrap();
	if ( namespace === 'mcp-ai-pro/v1' ) {
		return g.proApi;
	}
	if ( namespace === 'mcp-ai/v1' ) {
		return g.baseApi;
	}
	// Custom namespace — derive from apiUrl by replacing the namespace segment.
	// `apiUrl` is `…/wp-json/nvoos-toolkit-shell/v1`; strip that suffix.
	return g.apiUrl.replace( /\/nvoos-toolkit-shell\/v1$/, '/' + namespace );
}

function endpointUrl( namespace: string, resource: Resource, suffix = '' ): string {
	const base = urlFor( namespace ).replace( /\/$/, '' );
	const path = resource.endpoint.replace( /\/$/, '' );
	return `${ base }${ path }${ suffix }`;
}

function buildHeaders(): HeadersInit {
	const { nonce } = getBootstrap();
	return {
		'X-WP-Nonce': nonce,
		Accept: 'application/json',
		'Content-Type': 'application/json',
	};
}

/**
 * Normalize a JSON response into { items, total, totalPages }.
 *
 * Accepts WP-REST-style arrays (with X-WP-Total / X-WP-TotalPages headers),
 * `{ items: [...] }` envelopes, and bare arrays.
 */
function normalizeListBody(
	body: unknown,
	headers: Headers
): ListResult {
	const totalHeader = headers.get( 'X-WP-Total' );
	const totalPagesHeader = headers.get( 'X-WP-TotalPages' );
	const total = totalHeader ? Number( totalHeader ) : undefined;
	const totalPages = totalPagesHeader ? Number( totalPagesHeader ) : undefined;
	if ( Array.isArray( body ) ) {
		return {
			items: body as Array<Record<string, unknown>>,
			total,
			totalPages,
		};
	}
	if ( body && typeof body === 'object' ) {
		const obj = body as Record<string, unknown>;
		if ( Array.isArray( obj.items ) ) {
			return {
				items: obj.items as Array<Record<string, unknown>>,
				total:
					typeof obj.total === 'number' ? obj.total : total,
				totalPages:
					typeof obj.total_pages === 'number'
						? obj.total_pages
						: totalPages,
			};
		}
	}
	return { items: [], total, totalPages };
}

export async function listResource(
	namespace: string,
	resource: Resource,
	params: ListParams = {}
): Promise<ListResult> {
	const search = new URLSearchParams();
	if ( params.page ) {
		search.set( 'page', String( params.page ) );
	}
	if ( params.per_page ) {
		search.set( 'per_page', String( params.per_page ) );
	}
	if ( params.search ) {
		search.set( 'search', params.search );
	}
	if ( params.orderby ) {
		search.set( 'orderby', params.orderby );
	}
	if ( params.order ) {
		search.set( 'order', params.order );
	}
	const qs = search.toString();
	const url = `${ endpointUrl( namespace, resource ) }${ qs ? `?${ qs }` : '' }`;
	const res = await fetch( url, {
		method: 'GET',
		headers: buildHeaders(),
		credentials: 'same-origin',
	} );
	if ( ! res.ok ) {
		throw new Error( `Resource fetch failed: HTTP ${ res.status }` );
	}
	const body = await res.json();
	return normalizeListBody( body, res.headers );
}

/**
 * Backwards-compatible thin wrapper used by Phase-0 callers.
 */
export async function fetchResource(
	namespace: string,
	resource: Resource
): Promise<Array<Record<string, unknown>>> {
	const { items } = await listResource( namespace, resource, { per_page: 50 } );
	return items;
}

export async function getResource(
	namespace: string,
	resource: Resource,
	id: string | number
): Promise<Record<string, unknown>> {
	const url = endpointUrl( namespace, resource, `/${ encodeURIComponent( String( id ) ) }` );
	const res = await fetch( url, {
		method: 'GET',
		headers: buildHeaders(),
		credentials: 'same-origin',
	} );
	if ( ! res.ok ) {
		throw new Error( `Resource get failed: HTTP ${ res.status }` );
	}
	return ( await res.json() ) as Record<string, unknown>;
}

export async function createResource(
	namespace: string,
	resource: Resource,
	values: Record<string, unknown>
): Promise<Record<string, unknown>> {
	const url = endpointUrl( namespace, resource );
	const res = await fetch( url, {
		method: 'POST',
		headers: buildHeaders(),
		credentials: 'same-origin',
		body: JSON.stringify( values ),
	} );
	if ( ! res.ok ) {
		throw new Error( `Resource create failed: HTTP ${ res.status }` );
	}
	return ( await res.json() ) as Record<string, unknown>;
}

export async function updateResource(
	namespace: string,
	resource: Resource,
	id: string | number,
	values: Record<string, unknown>
): Promise<Record<string, unknown>> {
	const url = endpointUrl( namespace, resource, `/${ encodeURIComponent( String( id ) ) }` );
	const res = await fetch( url, {
		method: 'PUT',
		headers: buildHeaders(),
		credentials: 'same-origin',
		body: JSON.stringify( values ),
	} );
	if ( ! res.ok ) {
		throw new Error( `Resource update failed: HTTP ${ res.status }` );
	}
	return ( await res.json() ) as Record<string, unknown>;
}

export async function deleteResource(
	namespace: string,
	resource: Resource,
	id: string | number
): Promise<void> {
	const url = endpointUrl( namespace, resource, `/${ encodeURIComponent( String( id ) ) }` );
	const res = await fetch( url, {
		method: 'DELETE',
		headers: buildHeaders(),
		credentials: 'same-origin',
	} );
	if ( ! res.ok ) {
		throw new Error( `Resource delete failed: HTTP ${ res.status }` );
	}
}
