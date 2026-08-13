/**
 * Rate limiting middleware (OWASP Node.js guidance: limit every endpoint,
 * with stricter limits on expensive routes).
 *
 * Limits are env-tunable: RATE_LIMIT_GLOBAL, RATE_LIMIT_IMAGE,
 * RATE_LIMIT_VIDEO, RATE_LIMIT_BROWSER, RATE_LIMIT_WORKFLOW.
 *
 * Multi-tenant mode (SITE_TOKENS set): each site gets its own limiter
 * instances keyed `site:<slug>:<ip>` so one noisy site cannot exhaust
 * another's budget. Per-site overrides use the env var pattern
 * RATE_LIMIT_IMAGE_SITE-A (slug uppercased, hyphens -> underscores).
 *
 * The WordPress plugin is the only legitimate caller, so traffic is low by
 * design; these limits are sized to catch abuse without affecting it.
 */

import { rateLimit } from 'express-rate-limit';
import { parseTokenMap } from './auth.js';

/**
 * Shared 429 responder.
 *
 * @param {import('express').Request}  req Request.
 * @param {import('express').Response} res Response.
 */
function json429( req, res ) {
	res.status( 429 ).json( { error: 'Too many requests. Please retry later.' } );
}

/** Route-group budgets (window, default limit, env key). */
const GROUPS = {
	global: { windowMs: 5 * 60 * 1000, limit: 300, envKey: 'GLOBAL' },
	image: { windowMs: 10 * 60 * 1000, limit: 30, envKey: 'IMAGE' },
	video: { windowMs: 10 * 60 * 1000, limit: 20, envKey: 'VIDEO' },
	browser: { windowMs: 10 * 60 * 1000, limit: 30, envKey: 'BROWSER' },
	workflow: { windowMs: 10 * 60 * 1000, limit: 30, envKey: 'WORKFLOW' },
};

/**
 * Build a limiter.
 *
 * @param {Object} cfg              Configuration.
 * @param {number} cfg.windowMs     Window in milliseconds.
 * @param {number} cfg.limit        Request limit.
 * @param {string} [cfg.keyPrefix]  Optional key prefix (site namespace).
 * @return {import('express-rate-limit').RateLimitRequestHandler} Limiter.
 */
function makeLimiter( { windowMs, limit, keyPrefix } ) {
	return rateLimit( {
		windowMs,
		limit,
		standardHeaders: true,
		legacyHeaders: false,
		keyGenerator: ( req ) => ( keyPrefix ? `${ keyPrefix }:${ req.ip }` : req.ip ),
		handler: json429,
	} );
}

/**
 * Effective limit for a group: env override or the default.
 *
 * @param {Object} cfg Group config.
 * @return {number} Limit.
 */
function groupLimit( cfg ) {
	const envValue = Number( process.env[ `RATE_LIMIT_${ cfg.envKey }` ] );
	return Number.isFinite( envValue ) && envValue > 0 ? envValue : cfg.limit;
}

/** Env var name for a per-site override: RATE_LIMIT_IMAGE_SITE-A. */
function siteEnvKey( envKey, slug ) {
	return `RATE_LIMIT_${ envKey }_${ String( slug ).toUpperCase().replace( /-/g, '_' ) }`;
}

/** Default (shared) limiters — single-tenant mode and fallback. */
const DEFAULT_LIMITERS = Object.fromEntries(
	Object.entries( GROUPS ).map( ( [ name, cfg ] ) => [
		name,
		makeLimiter( { windowMs: cfg.windowMs, limit: groupLimit( cfg ) } ),
	] )
);

/** Per-site limiters built once at boot from SITE_TOKENS. */
const SITE_LIMITERS = new Map();
(function buildSiteLimiters() {
	const map = parseTokenMap( process.env.SITE_TOKENS );
	if ( ! map ) {
		return;
	}
	for ( const slug of Object.keys( map ) ) {
		const set = {};
		for ( const name of Object.keys( GROUPS ) ) {
			if ( 'global' === name ) {
				continue; // The global limiter stays global.
			}
			const cfg = GROUPS[ name ];
			const envValue = Number( process.env[ siteEnvKey( cfg.envKey, slug ) ] );
			set[ name ] = makeLimiter( {
				windowMs: cfg.windowMs,
				limit: Number.isFinite( envValue ) && envValue > 0 ? envValue : groupLimit( cfg ),
				keyPrefix: `site:${ slug }`,
			} );
		}
		SITE_LIMITERS.set( slug, set );
	}
})();

/**
 * Select the limiter for a request: the site's own instance in
 * multi-tenant mode, otherwise the shared default.
 *
 * @param {Object} req   Request (auth middleware set req.site).
 * @param {string} group Group name.
 * @return {import('express-rate-limit').RateLimitRequestHandler} Limiter.
 */
function limiterFor( req, group ) {
	const site = SITE_LIMITERS.get( req.site );
	return ( site && site[ group ] ) || DEFAULT_LIMITERS[ group ];
}

/** Global limiter applied to every request. */
export const globalLimiter = DEFAULT_LIMITERS.global;

/** Image generation — expensive (external API credits). */
export const imageLimiter = ( req, res, next ) => limiterFor( req, 'image' )( req, res, next );

/** Video processing — expensive (CPU + provider credits). */
export const videoLimiter = ( req, res, next ) => limiterFor( req, 'video' )( req, res, next );

/** Browser rendering — expensive (memory). */
export const browserLimiter = ( req, res, next ) => limiterFor( req, 'browser' )( req, res, next );

/** Workflow orchestration — multi-step pipelines. */
export const workflowLimiter = ( req, res, next ) => limiterFor( req, 'workflow' )( req, res, next );
