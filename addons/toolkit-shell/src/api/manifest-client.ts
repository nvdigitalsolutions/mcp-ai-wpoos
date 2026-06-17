/**
 * Manifest fetch client.
 *
 * @since 0.1.0
 */

import type { Manifest } from './types';

function getBootstrap() {
	const g = window.NVOOS_TOOLKIT_SHELL;
	if ( ! g ) {
		throw new Error( 'NV oOS Toolkit Shell bootstrap is missing.' );
	}
	return g;
}

export async function fetchManifest( toolkit: string ): Promise<Manifest> {
	const { apiUrl, nonce } = getBootstrap();
	const url = `${ apiUrl }/manifests/${ encodeURIComponent( toolkit ) }`;
	const res = await fetch( url, {
		headers: {
			'X-WP-Nonce': nonce,
			Accept: 'application/json',
		},
		credentials: 'same-origin',
	} );
	if ( ! res.ok ) {
		throw new Error( `Manifest fetch failed: HTTP ${ res.status }` );
	}
	const body = ( await res.json() ) as Manifest;
	return body;
}
