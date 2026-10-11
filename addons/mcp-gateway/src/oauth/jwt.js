/**
 * OAuth 2.1 access-token (JWT) validation for the gateway resource server.
 *
 * Zero runtime dependencies: signatures verify with node:crypto against the
 * authorization server's JWKS (fetched over HTTPS and cached in-memory with
 * a TTL); claims are checked exactly per OAuth 2.1 §5.2 and RFC 9068 —
 * issuer, audience (RFC 8707 resource binding), expiry, and scope.
 *
 * Fail closed: any fetch, parse, or verification failure rejects the token.
 */

import { createHash, createPublicKey, verify } from 'crypto';

/** Signature algorithms accepted from the authorization server. */
const ALLOWED_ALGS = new Set( [ 'RS256', 'RS384', 'RS512', 'ES256', 'ES384' ] );

/** Clock skew tolerated on exp/nbf comparisons (seconds). */
const CLOCK_SKEW_S = 60;

/** JWKS cache TTL (ms). */
const DEFAULT_JWKS_TTL_MS = 5 * 60 * 1000;

/** JWKS fetch budget (ms). */
const JWKS_FETCH_TIMEOUT_MS = 10000;

/** `alg` → node:crypto hash name. */
function algToHash( alg ) {
	if ( 'ES256' === alg || 'ES384' === alg ) {
		return 'ES384' === alg ? 'sha384' : 'sha256';
	}
	return ( { RS256: 'sha256', RS384: 'sha384', RS512: 'sha512' } )[ alg ] || 'sha256';
}

/**
 * Whether a bearer token is JWT-shaped (header.payload.signature).
 *
 * @param {string} token Raw token.
 * @return {boolean}
 */
export function looksLikeJwt( token ) {
	return /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test( String( token || '' ) );
}

/**
 * Base64url-decode a JWT part to an object.
 *
 * @param {string} part Encoded part.
 * @return {object|null} Parsed object, or null.
 */
export function decodePart( part ) {
	try {
		return JSON.parse( Buffer.from( part, 'base64url' ).toString( 'utf8' ) );
	} catch {
		return null;
	}
}

/**
 * Split and decode a JWT.
 *
 * @param {string} token Compact JWT.
 * @return {{header: object, payload: object, signature: string}|null}
 */
export function decodeJwt( token ) {
	const parts = String( token || '' ).split( '.' );
	if ( 3 !== parts.length ) {
		return null;
	}
	const header = decodePart( parts[ 0 ] );
	const payload = decodePart( parts[ 1 ] );
	if ( ! header || ! payload || ! parts[ 2 ] ) {
		return null;
	}
	return { header, payload, signature: parts[ 2 ], signingInput: `${ parts[ 0 ] }.${ parts[ 1 ] }` };
}

/**
 * Convert a JWK (from the AS JWKS) into a KeyObject, or null.
 *
 * @param {object} jwk JSON Web Key.
 * @return {object|null} crypto KeyObject.
 */
export function keyObjectFromJwk( jwk ) {
	try {
		return createPublicKey( { key: jwk, format: 'jwk' } );
	} catch {
		return null;
	}
}

/**
 * Verify a JWT signature against one JWK.
 *
 * @param {object} decoded decodeJwt() result.
 * @param {object} jwk     Candidate key from the JWKS.
 * @return {boolean}
 */
export function verifySignature( decoded, jwk ) {
	const alg = decoded.header.alg;
	if ( ! ALLOWED_ALGS.has( alg ) ) {
		return false;
	}
	if ( jwk.alg && jwk.alg !== alg ) {
		return false;
	}
	const key = keyObjectFromJwk( jwk );
	if ( ! key ) {
		return false;
	}
	try {
		const signature = Buffer.from( decoded.signature, 'base64url' );
		return verify( algToHash( alg ), Buffer.from( decoded.signingInput ), key, signature );
	} catch {
		return false;
	}
}

/**
 * Fetch the AS JWKS document.
 *
 * @param {string}   jwksUri    JWKS URL.
 * @param {Function} [fetchImpl] Injectable transport (tests; defaults to global fetch).
 * @return {Promise<Map<string, object>|null>} kid → JWK map, or null.
 */
export async function fetchJwks( jwksUri, fetchImpl ) {
	const doFetch = fetchImpl || ( ( url, opts ) => fetch( url, opts ) );
	try {
		const res = await doFetch( jwksUri, {
			headers: { Accept: 'application/json' },
			signal: AbortSignal.timeout( JWKS_FETCH_TIMEOUT_MS ),
		} );
		if ( ! res.ok ) {
			return null;
		}
		const doc = await res.json();
		if ( ! doc || ! Array.isArray( doc.keys ) ) {
			return null;
		}
		const keys = new Map();
		for ( const jwk of doc.keys ) {
			if ( jwk && jwk.kid ) {
				keys.set( jwk.kid, jwk );
			}
		}
		return keys.size ? keys : null;
	} catch {
		return null;
	}
}

/**
 * In-memory JWKS cache shared by every validation (module-scoped).
 *
 * @type {Map<string, {fetchedAt: number, keys: Map<string, object>}>}
 */
export const jwksCache = new Map();

/**
 * Resolve the JWK for a token's `kid`, fetching (and re-fetching on unknown
 * kids) as needed.
 *
 * @param {string}   jwksUri  JWKS URL.
 * @param {string}   kid      Key id from the token header.
 * @param {object}   deps     `{ now, fetchImpl, cache, ttlMs }` (tests).
 * @return {Promise<object|null>} JWK or null.
 */
export async function resolveJwk(
	jwksUri,
	kid,
	{ now = Date.now, fetchImpl, cache = jwksCache, ttlMs = DEFAULT_JWKS_TTL_MS } = {}
) {
	const cached = cache.get( jwksUri );
	if ( cached && cached.fetchedAt + ttlMs > now() && cached.keys.has( kid ) ) {
		return cached.keys.get( kid );
	}

	// Stale or missing: fetch fresh, then re-check for unknown kids once.
	let keys = await fetchJwks( jwksUri, fetchImpl );
	if ( cached && keys && ! keys.has( kid ) ) {
		cache.delete( jwksUri );
		keys = await fetchJwks( jwksUri, fetchImpl );
	}
	if ( ! keys ) {
		return null;
	}
	cache.set( jwksUri, { fetchedAt: now(), keys } );
	return keys.get( kid ) || null;
}

/**
 * Whether a token audience includes the configured resource.
 *
 * Accepts the canonical resource, its trailing-slash variants, and the
 * `/mcp` endpoint form (tokens minted against the path-insertion metadata
 * document carry that audience).
 *
 * @param {string|string[]} aud      Decoded `aud` claim.
 * @param {string}          resource Configured resource identifier.
 * @return {boolean}
 */
export function audienceMatches( aud, resource ) {
	const base = String( resource || '' ).replace( /\/$/, '' );
	const candidates = new Set( [ base, `${ base }/`, `${ base }/mcp` ] );
	const list = Array.isArray( aud ) ? aud : [ aud ];
	return list.some( ( value ) => 'string' === typeof value && candidates.has( value ) );
}

/**
 * Validate an OAuth access token and return its claims.
 *
 * @param {string} token    Bearer token.
 * @param {object} oauthCfg `{ enabled, issuer, jwksUri, resource }`.
 * @param {object} [deps]   `{ now, fetchImpl, cache, ttlMs }` (tests).
 * @return {Promise<{ok: boolean, claims?: object, scopes?: string[], error?: string}>}
 */
export async function validateAccessToken( token, oauthCfg, deps = {} ) {
	const now = deps.now || Date.now;

	if ( ! oauthCfg || ! oauthCfg.enabled ) {
		return { ok: false, error: 'oauth_disabled' };
	}

	const decoded = decodeJwt( token );
	if ( ! decoded ) {
		return { ok: false, error: 'invalid_token' };
	}

	const { header, payload } = decoded;

	if ( ! ALLOWED_ALGS.has( header.alg ) || ! header.kid ) {
		return { ok: false, error: 'invalid_token' };
	}

	const jwk = await resolveJwk( oauthCfg.jwksUri, header.kid, deps );
	if ( ! jwk ) {
		return { ok: false, error: 'unknown_kid' };
	}

	if ( ! verifySignature( decoded, jwk ) ) {
		return { ok: false, error: 'bad_signature' };
	}

	// Issuer: exact match, tolerating the trailing-slash convention.
	const issuer = oauthCfg.issuer || '';
	if ( payload.iss !== issuer && payload.iss !== issuer.replace( /\/$/, '' ) ) {
		return { ok: false, error: 'invalid_issuer' };
	}

	// Expiry / not-before (seconds since epoch, ± skew).
	const nowS = now() / 1000;
	if ( ! Number.isFinite( payload.exp ) || payload.exp <= nowS - CLOCK_SKEW_S ) {
		return { ok: false, error: 'expired_token' };
	}
	if ( Number.isFinite( payload.nbf ) && payload.nbf > nowS + CLOCK_SKEW_S ) {
		return { ok: false, error: 'invalid_token' };
	}

	if ( ! audienceMatches( payload.aud, oauthCfg.resource ) ) {
		return { ok: false, error: 'invalid_audience' };
	}

	const scopes = 'string' === typeof payload.scope ? payload.scope.split( /\s+/ ).filter( Boolean ) : [];

	return { ok: true, claims: payload, scopes };
}

/**
 * Hash a token for safe logging (never log the token itself).
 *
 * @param {string} token Raw token.
 * @return {string} Truncated digest.
 */
export function tokenFingerprint( token ) {
	return createHash( 'sha256' ).update( String( token ) ).digest( 'hex' ).slice( 0, 12 );
}
