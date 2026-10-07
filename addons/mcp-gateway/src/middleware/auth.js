/**
 * Public API-key authentication middleware.
 *
 * The gateway is a PUBLIC endpoint (directory-listed), so every /mcp
 * request must carry a valid public key. `GATEWAY_PUBLIC_KEYS` maps a key
 * to the site slugs it may reach (config in src/utils/config.js); the
 * middleware verifies the key timing-safely and attaches the resolved
 * identity for downstream namespacing and per-key rate limiting.
 *
 * Rotation: GATEWAY_PUBLIC_KEYS_PREVIOUS is accepted during rotation
 * windows with a one-time warning (the media-worker *_PREVIOUS pattern).
 *
 * When OAuth 2.1 is configured (GATEWAY_OAUTH_ISSUER), JWT bearer tokens
 * issued by the authorization server are accepted alongside static keys:
 * the middleware validates signature/issuer/audience/expiry, derives site
 * bindings from `site:<slug>` scopes, and emits RFC 9728 WWW-Authenticate
 * challenges so MCP clients can run the OAuth flow.
 *
 * Upstream op_ tokens are NOT accepted here and NEVER appear in responses.
 */

import { timingSafeEqual, createHash } from 'crypto';
import { oauthEnabled, buildWwwAuthenticate, sitesFromScopes, supportedScopes } from '../oauth/resource-server.js';
import { looksLikeJwt, validateAccessToken, tokenFingerprint } from '../oauth/jwt.js';

const MIN_KEY_LENGTH = 16;

let warnedPrevious = false;

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
 * Verify a provided key against a configured key, timing-safely.
 *
 * @param {string} provided The key from the Authorization header.
 * @param {string} expected The configured key.
 * @return {boolean} True on match.
 */
export function keyMatches( provided, expected ) {
	if ( 'string' !== typeof provided || ! provided ) {
		return false;
	}
	return timingSafeEqual( digest( provided ), digest( expected ) );
}

/**
 * Extract the bearer token from an Authorization header.
 *
 * @param {string} header Raw header value.
 * @return {string} Token, or ''.
 */
export function bearerToken( header ) {
	const match = /^Bearer\s+(.+)$/i.exec( String( header || '' ).trim() );
	return match ? match[ 1 ].trim() : '';
}

/**
 * Resolve a provided key to its key id (and site slugs) across current and
 * previous key maps, timing-safely across every configured key.
 *
 * @param {string} provided Key from the request.
 * @param {Map<string, string[]>} keys Current key map.
 * @param {Map<string, string[]>} previousKeys Rotation map.
 * @return {{keyId: string, slugs: string[]}|null}
 */
export function resolveKey( provided, keys, previousKeys ) {
	for ( const [ key, slugs ] of keys ) {
		if ( keyMatches( provided, key ) ) {
			return { keyId: key, slugs };
		}
	}
	for ( const [ key, slugs ] of previousKeys ) {
		if ( keyMatches( provided, key ) ) {
			if ( ! warnedPrevious ) {
				warnedPrevious = true;
				console.warn(
					'[Auth] Request authenticated with GATEWAY_PUBLIC_KEYS_PREVIOUS — remove it once rotation is complete.'
				);
			}
			return { keyId: key, slugs };
		}
	}
	return null;
}

/**
 * Express middleware enforcing a public API key.
 *
 * @param {import('express').Request}  req  Request.
 * @param {import('express').Response} res  Response.
 * @param {Function}                   next Next middleware.
 */
export function authMiddleware( req, res, next ) {
	const cfg = req.app.get( 'gatewayConfig' );
	const provided = bearerToken( req.get( 'Authorization' ) );

	if ( ! provided ) {
		// OAuth-configured gateways challenge with RFC 9728 metadata so MCP
		// clients can discover the authorization server and link accounts.
		res.setHeader(
			'WWW-Authenticate',
			oauthEnabled( cfg ) ? buildWwwAuthenticate( cfg ) : 'Bearer realm="nvoos-mcp-gateway"'
		);
		return res.status( 401 ).json( {
			error: 'unauthorized',
			message: oauthEnabled( cfg )
				? 'Authentication required — send a gateway API key or an OAuth access token.'
				: 'Missing bearer key. Send the public API key as "Authorization: Bearer <key>".',
		} );
	}

	const resolved = resolveKey( provided, cfg.keys, cfg.previousKeys );
	if ( ! resolved ) {
		// Static key miss — try OAuth access tokens when configured.
		if ( oauthEnabled( cfg ) && looksLikeJwt( provided ) ) {
			return handleOAuthToken( req, res, next, provided );
		}
		res.setHeader(
			'WWW-Authenticate',
			oauthEnabled( cfg ) ? buildWwwAuthenticate( cfg ) : 'Bearer realm="nvoos-mcp-gateway"'
		);
		return res.status( 401 ).json( { error: 'unauthorized', message: 'Unknown API key.' } );
	}

	// Fail closed: a key bound to sites that are not fully configured cannot
	// be served (config validation already prevents this at boot; this is the
	// runtime backstop for keys added via *_PREVIOUS).
	const sites = resolved.slugs.filter( ( slug ) => cfg.sites.has( slug ) );
	if ( 0 === sites.length ) {
		return res.status( 503 ).json( {
			error: 'no_sites_bound',
			message: 'This key is not bound to any configured site.',
		} );
	}

	req.gatewayKeyId = resolved.keyId;
	req.gatewaySites = sites;

	if ( resolved.keyId.length < MIN_KEY_LENGTH ) {
		console.warn(
			'[Auth] A public key shorter than 16 characters authenticated a request — rotate it to a strong random key.'
		);
	}

	return next();
}

/**
 * Validate an OAuth access token and attach its identity.
 *
 * @param {import('express').Request} req   Request.
 * @param {import('express').Response} res   Response.
 * @param {Function}                   next  Next middleware.
 * @param {string}                     token Bearer token (JWT-shaped).
 * @return {Promise<void>}
 */
async function handleOAuthToken( req, res, next, token ) {
	const cfg = req.app.get( 'gatewayConfig' );
	const result = await validateAccessToken( token, cfg.oauth );

	if ( ! result.ok ) {
		console.warn(
			`[Auth] OAuth token rejected (${ result.error }) — ${ tokenFingerprint( token ) }`
		);
		res.setHeader( 'WWW-Authenticate', buildWwwAuthenticate( cfg ) );
		return res.status( 401 ).json( { error: 'unauthorized', message: `Invalid OAuth access token (${ result.error }).` } );
	}

	const sites = sitesFromScopes( result.scopes, cfg );
	if ( 0 === sites.length ) {
		res.setHeader(
			'WWW-Authenticate',
			buildWwwAuthenticate( cfg, 'insufficient_scope', supportedScopes( cfg ).join( ' ' ) )
		);
		return res.status( 403 ).json( {
			error: 'insufficient_scope',
			message: 'The token grants no site access — request a site:<slug> scope during authorization.',
		} );
	}

	const subject = result.claims.sub || result.claims.client_id || tokenFingerprint( token );
	req.gatewayKeyId = `oauth:${ subject }`;
	req.gatewaySites = sites;
	req.gatewayAuth = 'oauth';
	return next();
}
