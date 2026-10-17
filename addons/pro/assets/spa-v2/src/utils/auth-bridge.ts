/**
 * Auth bridge — hand a headless credential from the Pro SPA host to a framed
 * child app (Media Studio / Document Editor).
 *
 * The hosted/embedded Pro SPA authenticates its own `fetch`/`$.ajax` traffic
 * with a WordPress application password (`Authorization: Basic`) or an
 * assistant credential (`Authorization: Bearer cred_...`). Those headers
 * cannot cross an `<iframe>` boundary: a framed app is a fresh page load that
 * sends no custom auth headers, so it would render as a logged-out visitor.
 *
 * This module defines the parent side of a small `postMessage` handshake:
 * the host reads the credential it already holds (in the non-persisted
 * `window.__NVOOS_AUTH__` slot) and forwards it, in-memory only, to a child
 * iframe once that iframe has loaded. The child validates the message origin
 * and attaches the resulting `Authorization` header value to its own requests.
 *
 * Secrets never touch a URL, query string, cookie, `localStorage`, or the
 * localized `NVOOS_PRO_SPA` global dumped to the page by `wp_localize_script`.
 */

/**
 * Source-origin scoped, single-use message type shared with the child apps.
 * Keep in sync with the equivalent constant in each framed app.
 */
export const NVOOS_AUTH_MESSAGE_TYPE = 'nvoos-auth';

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

/**
 * Forward the stored credential to a child iframe via `postMessage`.
 *
 * Origin-scoped: the credential is only sent when the iframe's resolved origin
 * matches the credential's `siteOrigin` (so the app can never leak a WordPress
 * secret to an arbitrary third-party frame). Safe to call on every iframe
 * `load` — the child ignores duplicate messages.
 *
 * @param iframe The child iframe element.
 * @param siteOrigin Optional explicit origin to target (defaults to the
 *                   credential's own `siteOrigin`).
 */
export function postAuthToChild( iframe: HTMLIFrameElement | null, siteOrigin?: string ): void {
	if ( ! iframe?.contentWindow ) {
		return;
	}
	const credential = getAuthCredential();
	if ( ! credential ) {
		return;
	}

	const targetOrigin = siteOrigin ?? credential.siteOrigin;
	if ( ! targetOrigin ) {
		return;
	}

	// Only forward when the frame actually lives on the trusted origin.
	try {
		const frameUrl = iframe.src || iframe.contentWindow.location.href;
		if ( new URL( frameUrl, window.location.origin ).origin !== targetOrigin ) {
			return;
		}
	} catch {
		// Cross-origin frame: cannot read location.href; fall back to the URL the
		// parent itself set (iframe.src), which is already origin-checked above.
		const frameOrigin = ( () => {
			try {
				return new URL( iframe.src, window.location.origin ).origin;
			} catch {
				return '';
			}
		} )();
		if ( frameOrigin !== targetOrigin ) {
			return;
		}
	}

	iframe.contentWindow.postMessage(
		{ type: NVOOS_AUTH_MESSAGE_TYPE, authorization: credential.authorization },
		targetOrigin,
	);
}
