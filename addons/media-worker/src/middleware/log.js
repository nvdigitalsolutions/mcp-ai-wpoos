/**
 * Request logging with correlation IDs.
 *
 * Emits one structured JSON line per request (method, path, status,
 * duration, request id). Never logs headers that may carry secrets.
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
				method: req.method,
				path: req.originalUrl,
				status: res.statusCode,
				ms: Date.now() - started,
			} )
		);
	} );

	next();
}
