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
 * Collect per-site token env vars (Phase 3 W3): every SITE_TOKEN_<SLUG>
 * (slug uppercased, hyphens -> underscores) with a non-empty value. These
 * merge OVER the SITE_TOKENS JSON so platform env-size limits can be
 * worked around one site at a time.
 *
 * @return {Object} Map of site slug -> token.
 */
export function collectSiteTokenEnv() {
	const map = {};
	for ( const [ key, value ] of Object.entries( process.env ) ) {
		const match = /^SITE_TOKEN_([A-Z0-9_]+)$/.exec( key );
		if ( match && 'string' === typeof value && value ) {
			map[ match[ 1 ].toLowerCase().replace( /_/g, '-' ) ] = value;
		}
	}
	return map;
}

/**
 * Effective site -> token map: SITE_TOKENS JSON merged with the per-site
 * SITE_TOKEN_<SLUG> env vars (env wins).
 *
 * @return {Object} Map of site slug -> token.
 */
export function siteTokenMap() {
	return { ...( parseTokenMap( process.env.SITE_TOKENS ) || {} ), ...collectSiteTokenEnv() };
}

/**
 * Whether multi-tenant mode is active.
 *
 * @return {boolean} True when any site token is configured.
 */
export function isMultiTenantMode() {
	return Object.keys( siteTokenMap() ).length > 0;
}

/**
 * Site slugs configured for multi-tenant mode.
 *
 * @return {string[]} Slugs (empty in single-tenant mode).
 */
export function configuredSites() {
	return Object.keys( siteTokenMap() );
}

/**
 * Reconstruct the configured token for a site slug. Used by queue
 * processors to make authenticated self-calls outside a request context
 * (the worker already knows every token via SITE_TOKENS / WORKER_API_TOKEN,
 * so secrets never need to be stored inside queued job data).
 *
 * @param {string} site Site slug ('default' in single-tenant mode).
 * @return {string} Token, or '' when none is configured (lenient mode).
 */
export function tokenForSite( site ) {
	if ( isMultiTenantMode() ) {
		const map = siteTokenMap();
		return 'string' === typeof map[ site ] ? map[ site ] : '';
	}
	return process.env.WORKER_API_TOKEN || '';
}

/**
 * Resolve the site slug for a provided token. Timing-safe across all
 * configured tokens; also accepts SITE_TOKENS_PREVIOUS during rotation.
 *
 * @param {string} provided Token from the request header.
 * @return {string|null} Site slug, or null when unknown.
 */
export function resolveSite( provided ) {
	for ( const [ slug, token ] of Object.entries( siteTokenMap() ) ) {
		if ( typeof token === 'string' && tokenMatches( provided, token ) ) {
			return slug;
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
