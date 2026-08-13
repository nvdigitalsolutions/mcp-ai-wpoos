/**
 * Shared-secret authentication middleware.
 *
 * The WordPress plugin (trait WP_MCP_AI_Media_Worker_Client) already sends an
 * `X-Site-Token` header (and `X-Site-Url`) on every sidecar request. This
 * middleware verifies the token using a timing-safe comparison so that timing
 * side-channels cannot leak the secret, and attaches the caller's site
 * identity (`req.site`) for namespacing downstream.
 *
 * Auth modes:
 *   - Single-tenant (default): WORKER_API_TOKEN (+ WORKER_API_TOKEN_PREVIOUS
 *     for rotation). Token configured -> strict; not set -> lenient (local
 *     Docker development). AUTH_MODE=strict fails closed when no token is
 *     configured.
 *   - Multi-tenant (SITE_TOKENS set): JSON map of site slug -> token. Every
 *     /api/* request must carry a token belonging to a known site (always
 *     fail-closed — there is no lenient fallback). SITE_TOKENS_PREVIOUS
 *     accepts the previous token during rotation windows.
 */

import { timingSafeEqual, createHash } from 'crypto';

const MIN_TOKEN_LENGTH = 16;

let warnedPrevious = false;
const seenSiteUrls = new Map();

/**
 * Constant-time SHA-256 digest used to normalise secret lengths before
 * timingSafeEqual (which requires equal-length buffers).
 *
 * @param {string} value Value to hash.
 * @return {Buffer} 32-byte digest.
 */
function digest( value ) {
	return createHash( 'sha256' ).update( String( value ) ).digest();
}

/**
 * Verify a provided token against the configured secret.
 *
 * @param {string} provided The token from the request header.
 * @param {string} expected The configured token value.
 * @return {boolean} True when the token matches.
 */
export function tokenMatches( provided, expected ) {
	if ( typeof provided !== 'string' || ! provided ) {
		return false;
	}
	return timingSafeEqual( digest( provided ), digest( expected ) );
}

/**
 * Parse a token map env var (JSON object of slug -> token).
 *
 * @param {string} raw Raw env value.
 * @return {Object|null} Map, or null when unset/invalid.
 */
export function parseTokenMap( raw ) {
	if ( ! raw ) {
		return null;
	}
	try {
		const parsed = JSON.parse( raw );
		if ( parsed && 'object' === typeof parsed && ! Array.isArray( parsed ) ) {
			return parsed;
		}
	} catch {
		// Fall through to null.
	}
	return null;
}

/**
 * Whether multi-tenant mode is active.
 *
 * @return {boolean} True when SITE_TOKENS is set.
 */
export function isMultiTenantMode() {
	return Boolean( process.env.SITE_TOKENS );
}

/**
 * Site slugs configured for multi-tenant mode.
 *
 * @return {string[]} Slugs (empty in single-tenant mode).
 */
export function configuredSites() {
	const map = parseTokenMap( process.env.SITE_TOKENS );
	return map ? Object.keys( map ) : [];
}

/**
 * Resolve the site slug for a provided token. Timing-safe across all
 * configured tokens; also accepts SITE_TOKENS_PREVIOUS during rotation.
 *
 * @param {string} provided Token from the request header.
 * @return {string|null} Site slug, or null when unknown.
 */
export function resolveSite( provided ) {
	const map = parseTokenMap( process.env.SITE_TOKENS );
	if ( map ) {
		for ( const [ slug, token ] of Object.entries( map ) ) {
			if ( typeof token === 'string' && tokenMatches( provided, token ) ) {
				return slug;
			}
		}
	}
	const previousMap = parseTokenMap( process.env.SITE_TOKENS_PREVIOUS );
	if ( previousMap ) {
		for ( const [ slug, token ] of Object.entries( previousMap ) ) {
			if ( typeof token === 'string' && tokenMatches( provided, token ) ) {
				if ( ! warnedPrevious ) {
					warnedPrevious = true;
					console.warn(
						'[Auth] Request authenticated with SITE_TOKENS_PREVIOUS — remove it once rotation is complete.'
					);
				}
				return slug;
			}
		}
	}
	return null;
}

/**
 * Host-only form of a URL (audit logging never records full URLs).
 *
 * @param {string} url Raw URL.
 * @return {string} Hostname, or the raw value when unparseable.
 */
function hostOf( url ) {
	try {
		return new URL( url ).host;
	} catch {
		return String( url ).slice( 0, 128 );
	}
}

/**
 * Audit-only check: warn when a site's X-Site-Url changes between requests
 * (may indicate a stolen token pointed at a different domain). Never an auth
 * input.
 *
 * @param {string} slug Site slug.
 * @param {string|null} url  Raw X-Site-Url header.
 */
function auditSiteUrl( slug, url ) {
	if ( ! url ) {
		return;
	}
	const last = seenSiteUrls.get( slug );
	if ( last && last !== url ) {
		console.warn(
			`[Auth] X-Site-Url changed for site "${ slug }": ${ hostOf( last ) } -> ${ hostOf( url ) }`
		);
	}
	seenSiteUrls.set( slug, url );
}

/**
 * Express middleware enforcing the shared-secret token.
 *
 * @param {import('express').Request}  req  Request object.
 * @param {import('express').Response} res  Response object.
 * @param {Function}                   next Next middleware.
 */
export function authMiddleware( req, res, next ) {
	const provided = req.get( 'X-Site-Token' ) || '';

	// ── Multi-tenant mode (fail-closed) ───────────────────────
	if ( isMultiTenantMode() ) {
		const slug = resolveSite( provided );
		if ( ! slug ) {
			return res.status( 401 ).json( { error: 'Unauthorized' } );
		}
		req.site = slug;
		req.siteUrl = req.get( 'X-Site-Url' ) || null;
		auditSiteUrl( slug, req.siteUrl );
		return next();
	}

	// ── Single-tenant mode ────────────────────────────────────
	const expected = process.env.WORKER_API_TOKEN;

	// Lenient mode: no token configured (local development).
	if ( ! expected ) {
		if ( 'strict' === ( process.env.AUTH_MODE || '' ).toLowerCase() ) {
			return res.status( 503 ).json( {
				error: 'auth_not_configured',
				message: 'WORKER_API_TOKEN is not set but AUTH_MODE=strict.',
			} );
		}
		req.site = 'default';
		req.siteUrl = req.get( 'X-Site-Url' ) || null;
		return next();
	}

	if ( expected.length < MIN_TOKEN_LENGTH ) {
		console.warn( '[Auth] WORKER_API_TOKEN is shorter than 16 characters; use a strong random secret.' );
	}

	req.site = 'default';
	req.siteUrl = req.get( 'X-Site-Url' ) || null;

	if ( tokenMatches( provided, expected ) ) {
		return next();
	}

	// Rotation overlap: accept the previous token as well (timing-safe),
	// with a one-time warning so operators remember to remove it.
	const previous = process.env.WORKER_API_TOKEN_PREVIOUS;
	if ( previous && tokenMatches( provided, previous ) ) {
		if ( ! warnedPrevious ) {
			warnedPrevious = true;
			console.warn(
				'[Auth] Request authenticated with WORKER_API_TOKEN_PREVIOUS — remove it once rotation is complete.'
			);
		}
		return next();
	}

	return res.status( 401 ).json( { error: 'Unauthorized' } );
}
