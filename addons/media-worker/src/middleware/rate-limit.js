/**
 * Rate limiting middleware (OWASP Node.js guidance: limit every endpoint,
 * with stricter limits on expensive routes).
 *
 * Limits are env-tunable: RATE_LIMIT_GLOBAL, RATE_LIMIT_IMAGE,
 * RATE_LIMIT_VIDEO, RATE_LIMIT_BROWSER, RATE_LIMIT_WORKFLOW.
 *
 * The WordPress plugin is the only legitimate caller, so traffic is low by
 * design; these limits are sized to catch abuse without affecting it.
 */

import { rateLimit } from 'express-rate-limit';

/**
 * Shared 429 responder.
 *
 * @param {import('express').Request}  req Request.
 * @param {import('express').Response} res Response.
 */
function json429( req, res ) {
	res.status( 429 ).json( { error: 'Too many requests. Please retry later.' } );
}

/**
 * Build a limiter with env overrides.
 *
 * @param {Object} cfg        Configuration.
 * @param {number} cfg.windowMs Window in milliseconds.
 * @param {number} cfg.limit    Default request limit.
 * @param {string} cfg.envKey   Env var suffix (e.g. IMAGE).
 * @return {import('express-rate-limit').RateLimitRequestHandler} Limiter.
 */
function makeLimiter( { windowMs, limit, envKey } ) {
	const envValue = Number( process.env[ `RATE_LIMIT_${ envKey }` ] );
	return rateLimit( {
		windowMs,
		limit: Number.isFinite( envValue ) && envValue > 0 ? envValue : limit,
		standardHeaders: true,
		legacyHeaders: false,
		handler: json429,
	} );
}

/** Global limiter applied to every request. */
export const globalLimiter = makeLimiter( {
	windowMs: 5 * 60 * 1000,
	limit: 300,
	envKey: 'GLOBAL',
} );

/** Image generation — expensive (external API credits). */
export const imageLimiter = makeLimiter( {
	windowMs: 10 * 60 * 1000,
	limit: 30,
	envKey: 'IMAGE',
} );

/** Video processing — expensive (CPU + provider credits). */
export const videoLimiter = makeLimiter( {
	windowMs: 10 * 60 * 1000,
	limit: 20,
	envKey: 'VIDEO',
} );

/** Browser rendering — expensive (memory). */
export const browserLimiter = makeLimiter( {
	windowMs: 10 * 60 * 1000,
	limit: 30,
	envKey: 'BROWSER',
} );

/** Workflow orchestration — multi-step pipelines. */
export const workflowLimiter = makeLimiter( {
	windowMs: 10 * 60 * 1000,
	limit: 30,
	envKey: 'WORKFLOW',
} );
