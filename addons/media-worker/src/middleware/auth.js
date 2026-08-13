/**
 * Shared-secret authentication middleware.
 *
 * The WordPress plugin (trait WP_MCP_AI_Media_Worker_Client) already sends an
 * `X-Site-Token` header on every sidecar request. This middleware verifies it
 * against the WORKER_API_TOKEN environment variable using a timing-safe
 * comparison so that timing side-channels cannot leak the secret.
 *
 * Auth modes:
 *   - Token configured  -> strict: every /api/* request must carry the token.
 *   - Token not set     -> lenient: requests pass (local Docker development).
 * Set AUTH_MODE=strict to *fail closed* when no token is configured.
 *
 * Token rotation: during a rotation window the previous token may be kept as
 * WORKER_API_TOKEN_PREVIOUS. Requests carrying it are accepted (with a
 * one-time warning) so WordPress and the worker can switch tokens without a
 * window of 401s. Remove the variable once rotation is complete.
 */

import { timingSafeEqual, createHash } from 'crypto';

const MIN_TOKEN_LENGTH = 16;

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
 * Verify a provided token against the configured secret.
 *
 * @param {string} provided The token from the request header.
 * @param {string} expected The configured WORKER_API_TOKEN value.
 * @return {boolean} True when the token matches.
 */
export function tokenMatches( provided, expected ) {
	if ( typeof provided !== 'string' || ! provided ) {
		return false;
	}
	return timingSafeEqual( digest( provided ), digest( expected ) );
}

/**
 * Express middleware enforcing the shared-secret token.
 *
 * @param {import('express').Request}  req  Request object.
 * @param {import('express').Response} res  Response object.
 * @param {Function}                   next Next middleware.
 */
export function authMiddleware( req, res, next ) {
	const expected = process.env.WORKER_API_TOKEN;

	// Lenient mode: no token configured (local development).
	if ( ! expected ) {
		if ( 'strict' === ( process.env.AUTH_MODE || '' ).toLowerCase() ) {
			return res.status( 503 ).json( {
				error: 'auth_not_configured',
				message: 'WORKER_API_TOKEN is not set but AUTH_MODE=strict.',
			} );
		}
		return next();
	}

	if ( expected.length < MIN_TOKEN_LENGTH ) {
		console.warn( '[Auth] WORKER_API_TOKEN is shorter than 16 characters; use a strong random secret.' );
	}

	const provided = req.get( 'X-Site-Token' ) || '';
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
