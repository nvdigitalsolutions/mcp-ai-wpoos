/**
 * Typed WordPress REST API helpers for the NV oOS TypeScript layer.
 *
 * Provides thin wrappers around the WP REST API and the plugin's
 * AJAX endpoints so that callers get type-checked requests/responses
 * without reaching for `any`.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── Type-only imports (erased at compile-time) ───────────────────────
import type { GlobalChatConfig } from './types';

// ── Base REST helpers ────────────────────────────────────────────────

/**
 * Build request headers for an authenticated WP REST call.
 *
 * Sets `X-WP-Nonce` from the plugin config (or falls back to
 * `window.wpApiSettings?.nonce`), `Content-Type`, and `Accept`.
 */
export function buildAuthHeaders( config: Pick< GlobalChatConfig, 'nonce' > ): Record< string, string > {
	const headers: Record< string, string > = {
		'Content-Type': 'application/json',
		Accept: 'application/json',
	};

	if ( config.nonce ) {
		headers[ 'X-WP-Nonce' ] = config.nonce;
	} else if ( typeof window.wp?.apiSettings !== 'undefined' ) {
		const apiSettings = window.wp.apiSettings as Record< string, string > | undefined;
		if ( apiSettings?.nonce ) {
			headers[ 'X-WP-Nonce' ] = apiSettings.nonce;
		}
	}

	return headers;
}

/**
 * Build request headers for guest (unauthenticated) WP REST calls.
 *
 * Uses the `X-WP-MCP-AI-Guest` header instead of a nonce so the
 * server can apply guest-specific rate limiting and tool scope.
 */
export function buildGuestHeaders(): Record< string, string > {
	return {
		'Content-Type': 'application/json',
		Accept: 'application/json',
		'X-WP-MCP-AI-Guest': '1',
	};
}

/**
 * Perform a typed `fetch` GET to a WordPress REST endpoint.
 */
export async function wpGet< T >(
	url: string,
	headers: Record< string, string >,
	signal?: AbortSignal,
): Promise< T > {
	const response = await fetch( url, {
		method: 'GET',
		headers,
		credentials: 'same-origin',
		signal,
	} );

	if ( ! response.ok ) {
		const errorBody: unknown = await response.json().catch( () => null );
		const message =
			( errorBody as Record< string, string > | null )?.message ??
			response.statusText;
		throw new Error( `WP REST GET ${ url } ${ response.status }: ${ message }` );
	}

	return response.json() as Promise< T >;
}

/**
 * Perform a typed `fetch` POST to a WordPress REST endpoint.
 */
export async function wpPost< T >(
	url: string,
	body: unknown,
	headers: Record< string, string >,
	signal?: AbortSignal,
): Promise< T > {
	const response = await fetch( url, {
		method: 'POST',
		headers,
		credentials: 'same-origin',
		body: JSON.stringify( body ),
		signal,
	} );

	if ( ! response.ok ) {
		const errorBody: unknown = await response.json().catch( () => null );
		const message =
			( errorBody as Record< string, string > | null )?.message ??
			response.statusText;
		throw new Error( `WP REST POST ${ url } ${ response.status }: ${ message }` );
	}

	return response.json() as Promise< T >;
}

/**
 * Upload a file to a WordPress REST endpoint via multipart POST.
 *
 * Returns the parsed JSON response.  Does **not** set `Content-Type`
 * — the browser will fill in the correct `multipart/form-data` boundary.
 */
export async function wpUpload< T >(
	url: string,
	file: File,
	headers: Record< string, string >,
	signal?: AbortSignal,
): Promise< T > {
	// Remove Content-Type so the browser sets multipart with boundary.
	const uploadHeaders: Record< string, string > = {};
	for ( const [ key, value ] of Object.entries( headers ) ) {
		if ( key.toLowerCase() !== 'content-type' ) {
			uploadHeaders[ key ] = value;
		}
	}

	const response = await fetch( url, {
		method: 'POST',
		headers: uploadHeaders,
		credentials: 'same-origin',
		body: file,
		signal,
	} );

	if ( ! response.ok ) {
		const errorBody: unknown = await response.json().catch( () => null );
		const message =
			( errorBody as Record< string, string > | null )?.message ??
			response.statusText;
		throw new Error( `WP REST upload ${ url } ${ response.status }: ${ message }` );
	}

	return response.json() as Promise< T >;
}

// ── Utility ──────────────────────────────────────────────────────────

/**
 * Sanitise a string for safe use as a session / storage key.
 *
 * Replaces any character that is not `[0-9a-zA-Z_-]` with `_`.
 */
export function sanitizeSessionKey( raw: string ): string {
	return raw.replace( /[^0-9a-zA-Z_-]/g, '_' );
}

/**
 * Format a number of bytes into a human-readable string (e.g. "1.5 MB").
 */
export function formatBytes( bytes: number, decimals = 1 ): string {
	if ( bytes === 0 ) {
		return '0 B';
	}
	const k = 1024;
	const sizes = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
	const value = bytes / Math.pow( k, i );
	return `${ parseFloat( value.toFixed( decimals ) ) } ${ sizes[ i ] }`;
}
