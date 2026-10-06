/**
 * Rate limiting middleware (OWASP Node.js guidance: limit every endpoint).
 *
 * The gateway is public, so limits are per-identity: the /mcp limiter keys
 * on the authenticated public key (set by the auth middleware), so one noisy
 * key cannot exhaust another's budget. The global limiter keys on client IP
 * (behind NGINX on Velocity, X-Forwarded-For via TRUST_PROXY=1).
 *
 * Env-tunable: RATE_LIMIT_GLOBAL, RATE_LIMIT_MCP, RATE_LIMIT_HEALTH.
 */

import { rateLimit } from 'express-rate-limit';

/**
 * Shared 429 responder.
 *
 * @param {import('express').Request}  _req Request.
 * @param {import('express').Response} res  Response.
 */
function json429( _req, res ) {
	res.status( 429 ).json( { error: 'too_many_requests', message: 'Too many requests. Please retry later.' } );
}

/** Route-group budgets (window, default limit, env key). */
const GROUPS = {
	global: { windowMs: 5 * 60 * 1000, limit: 600, envKey: 'GLOBAL' },
	mcp: { windowMs: 60 * 1000, limit: 120, envKey: 'MCP' },
	health: { windowMs: 60 * 1000, limit: 60, envKey: 'HEALTH' },
};

export { GROUPS };

/**
 * Effective limit for a group: env override or the default.
 *
 * @param {object} cfg Group config.
 * @return {number} Limit.
 */
function groupLimit( cfg ) {
	const envValue = Number( process.env[ `RATE_LIMIT_${ cfg.envKey }` ] );
	return Number.isFinite( envValue ) && envValue > 0 ? envValue : cfg.limit;
}

/**
 * Build a limiter.
 *
 * @param {object} cfg        Group config.
 * @param {Function} keyFn    Key generator (req → string).
 * @return {import('express-rate-limit').RateLimitRequestHandler} Limiter.
 */
function makeLimiter( cfg, keyFn ) {
	return rateLimit( {
		windowMs: cfg.windowMs,
		limit: groupLimit( cfg ),
		standardHeaders: true,
		legacyHeaders: false,
		keyGenerator: keyFn,
		handler: json429,
	} );
}

/**
 * Client IP when TRUST_PROXY is configured; Express resolves req.ip from
 * X-Forwarded-For.
 *
 * @param {import('express').Request} req Request.
 * @return {string} IP string.
 */
function clientIp( req ) {
	return req.ip || 'unknown';
}

/**
 * Per-identity key for the MCP limiter: the authenticated public key id
 * (falling back to the IP before the auth middleware has run).
 *
 * @param {import('express').Request} req Request.
 * @return {string} Limiter key.
 */
function mcpKey( req ) {
	return req.gatewayKeyId ? `key:${ req.gatewayKeyId }` : `ip:${ clientIp( req ) }`;
}

/** Global limiter (all routes, per IP). */
export const globalLimiter = makeLimiter( GROUPS.global, clientIp );

export { makeLimiter };

/** MCP route limiter (per public key). */
export const mcpLimiter = makeLimiter( GROUPS.mcp, mcpKey );

/** Health route limiter (per IP). */
export const healthLimiter = makeLimiter( GROUPS.health, clientIp );
