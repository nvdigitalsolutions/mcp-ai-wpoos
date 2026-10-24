/**
 * Auth bridge — read the headless credential the standalone app placed in
 * the in-memory auth slot.
 *
 * The hosted/embedded Pro SPA authenticates its own `fetch`/`$.ajax` traffic
 * with a WordPress application password (`Authorization: Basic`) or an
 * assistant credential (`Authorization: Bearer cred_...`). Island apps
 * (Media Studio / Document Editor) run same-origin and receive the credential
 * through their injected config globals — the pages pass it on directly, so
 * no `postMessage` handshake is needed anymore (Proposal 087 Phase 3).
 *
 * Secrets never touch a URL, query string, cookie, `localStorage`, or the
 * localized `NVOOS_PRO_SPA` global dumped to the page by `wp_localize_script`.
 */

/**
 * Name of the in-memory (non-persisted) slot the standalone app and the
 * embedded shortcode loader populate with the credential. Deliberately not
 * part of `NVOOS_PRO_SPA` so it is never serialized to the page.
 */
export const NVOOS_AUTH_SLOT = '__NVOOS_AUTH__';

/** Credential shape held in the auth slot. */
export interface NvoosAuthCredential {
	/**
	 * Pre-built `Authorization` header value to attach, e.g.
	 * `Bearer cred_xxxxx.SECRET` or `Basic base64(user:app-password)`.
	 */
	authorization: string;
	/**
	 * Origin of the WordPress site the credential belongs to. The parent only
	 * forwards the credential to a child iframe on this origin (origin check),
	 * and the child only accepts a message from this same origin.
	 */
	siteOrigin?: string;
}

/**
 * Read the headless credential the standalone app placed in the auth slot.
 * Returns null when none is present (cookie-auth embedded surface, or the
 * credential was not supplied).
 */
export function getAuthCredential(): NvoosAuthCredential | null {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const slot = ( window as unknown as Record< string, unknown > )[ NVOOS_AUTH_SLOT ];
	if ( ! slot || typeof slot !== 'object' ) {
		return null;
	}
	const auth = slot as NvoosAuthCredential;
	if ( typeof auth.authorization !== 'string' || ! auth.authorization ) {
		return null;
	}
	return {
		authorization: auth.authorization,
		siteOrigin: typeof auth.siteOrigin === 'string' ? auth.siteOrigin : undefined,
	};
}
