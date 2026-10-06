/**
 * Request logging with correlation ids.
 *
 * Emits one structured JSON line per request. Never logs headers that may
 * carry secrets (Authorization / tokens); the public key is referenced by
 * an id only. Tool-call arguments are excluded by default (LOG_TOOL_ARGS=1
 * opts in, and still passes through credential redaction).
 */

import crypto from 'crypto';

/**
 * Express middleware assigning a request id and logging completion.
 *
 * @param {import('express').Request}  req  Request.
 * @param {import('express').Response} res  Response.
 * @param {Function}                   next Next middleware.
 */
export function requestLogger( req, res, next ) {
	const started = Date.now();
	req.id = req.get( 'X-Request-Id' ) || crypto.randomUUID();
	res.setHeader( 'X-Request-Id', req.id );

	res.on( 'finish', () => {
		console.log(
			JSON.stringify( {
				ts: new Date().toISOString(),
				id: req.id,
				key: req.gatewayKeyId || null, // set by the auth middleware
				sites: req.gatewaySites || null,
				method: req.method,
				path: req.originalUrl,
				status: res.statusCode,
				ms: Date.now() - started,
			} )
		);
	} );

	next();
}
