/**
 * HTTP surface of the gateway: health endpoint plus the token-gated MCP
 * endpoint. Tool policy is enforced at MCP registration time
 * (src/gateway-server.js) — this layer owns auth, body limits, and
 * pass-through to the MCP server.
 */

import express from 'express';
import { authorize } from './config.js';

const MAX_REQUEST_BODY = 1024 * 1024; // 1 MB.
const STARTED_AT = Date.now();

/**
 * Buffer the raw request body (capped) and opportunistically parse JSON.
 *
 * @param {import('express').Request}  req  Request.
 * @param {import('express').Response} res  Response.
 * @param {Function}                   next Next handler.
 */
function bufferBody( req, res, next ) {
	const chunks = [];
	let size = 0;
	let aborted = false;

	req.on( 'data', ( chunk ) => {
		size += chunk.length;
		if ( size > MAX_REQUEST_BODY ) {
			aborted = true;
			res.status( 413 ).json( { error: 'payload_too_large' } );
			req.destroy();
			return;
		}
		chunks.push( chunk );
	} );

	req.on( 'error', () => {
		if ( ! res.headersSent ) {
			res.status( 400 ).json( { error: 'request_body_error' } );
		}
	} );

	req.on( 'end', () => {
		if ( aborted ) {
			return;
		}

		req.rawBody = Buffer.concat( chunks );
		req.parsedBody = undefined;

		const contentType = String( req.get( 'content-type' ) ?? '' );
		if ( req.rawBody.length > 0 && contentType.includes( 'application/json' ) ) {
			try {
				req.parsedBody = JSON.parse( req.rawBody.toString( 'utf8' ) );
			} catch {
				// Leave unparsed; the MCP server rejects malformed JSON-RPC.
			}
		}

		next();
	} );
}

/**
 * Build the gateway HTTP application.
 *
 * @param {object} config Loaded config (see src/config.js).
 * @param {object} deps   { handleMcpRequest } — injectable for tests.
 * @returns {import('express').Express} Express app.
 */
export function createApp( config, deps = {} ) {
	const app = express();
	app.disable( 'x-powered-by' );

	app.use( ( _req, res, next ) => {
		res.setHeader( 'X-Content-Type-Options', 'nosniff' );
		next();
	} );

	// Minimal, auth-free health endpoint (never leaks configuration).
	app.get( '/healthz', ( _req, res ) => {
		res.json( {
			status: 'ok',
			service: 'mcp-wordpress-gateway',
			version: '0.1.0',
			uptime_seconds: Math.floor( ( Date.now() - STARTED_AT ) / 1000 ),
		} );
	} );

	app.use( '/mcp', bufferBody, async ( req, res ) => {
		const presented = String( req.get( 'X-MCP-Token' ) ?? '' );

		if ( ! authorize( config, presented ) ) {
			if ( config.logLevel !== 'none' ) {
				console.log( JSON.stringify( { event: 'auth_rejected', method: req.method, path: req.path } ) );
			}
			res.status( 401 ).json( { error: 'unauthorized' } );
			return;
		}

		const handle = deps.handleMcpRequest;

		if ( typeof handle !== 'function' ) {
			res.status( 502 ).json( { error: 'gateway_unavailable' } );
			return;
		}

		try {
			await handle( req, res, req.parsedBody );
		} catch {
			if ( ! res.headersSent ) {
				res.status( 502 ).json( { error: 'gateway_unavailable' } );
			}
		}
	} );

	return app;
}
