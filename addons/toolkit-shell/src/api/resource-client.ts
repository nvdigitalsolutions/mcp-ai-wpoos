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

export async function fetchResource(
	namespace: string,
	resource: Resource
): Promise<Array<Record<string, unknown>>> {
	const { nonce } = getBootstrap();
	const base = urlFor( namespace );
	const url = `${ base.replace( /\/$/, '' ) }${ resource.endpoint }`;
	const res = await fetch( url, {
		headers: {
			'X-WP-Nonce': nonce,
			Accept: 'application/json',
		},
		credentials: 'same-origin',
	} );
	if ( ! res.ok ) {
		throw new Error( `Resource fetch failed: HTTP ${ res.status }` );
	}
	const body = await res.json();
	if ( Array.isArray( body ) ) {
		return body as Array<Record<string, unknown>>;
	}
	if ( body && typeof body === 'object' && Array.isArray( ( body as { items?: unknown } ).items ) ) {
		return ( body as { items: Array<Record<string, unknown>> } ).items;
	}
	return [];
}
