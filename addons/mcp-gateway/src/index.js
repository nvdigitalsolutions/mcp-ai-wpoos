/**
 * NV oOS MCP Gateway — entry point.
 *
 * Composes the app (helmet, CORS allowlist, request logging, rate limits,
 * health, landing, and the auth-gated /mcp router) and starts the server.
 * `createApp()` is exported so tests can build the app with an injected
 * configuration and bind port 0.
 *
 * Env reference: see README.md and src/utils/config.js.
 */

import express from 'express';
import helmet from 'helmet';
import cors from 'cors';
import { loadConfig, assertConfig } from './utils/config.js';
import { requestLogger } from './middleware/log.js';
import { globalLimiter, mcpLimiter, healthLimiter } from './middleware/rate-limit.js';
import { authMiddleware } from './middleware/auth.js';
import { healthRouter } from './routes/health.js';
import { landingRouter } from './routes/landing.js';
import { mcpRouter, discoveryHandler } from './routes/mcp.js';

export const VERSION = '0.1.1';

/**
 * Build the gateway app.
 *
 * @param {object} overrides        Test overrides.
 * @param {object} [overrides.config]  Injected config (skips env parsing).
 * @return {import('express').Express} App.
 */
export function createApp( overrides = {} ) {
	const cfg = assertConfig( overrides.config || loadConfig() );

	const app = express();
	app.set( 'gatewayConfig', cfg );
	app.set( 'gatewayVersion', VERSION );

	// Behind NGINX on managed hosts (Cloudways Velocity), X-Forwarded-For is
	// the only client-IP signal. TRUST_PROXY=1 enables it for rate limiting.
	if ( '1' === process.env.TRUST_PROXY ) {
		app.set( 'trust proxy', 1 );
	}

	app.disable( 'x-powered-by' );
	app.use( helmet( { crossOriginResourcePolicy: { policy: 'cross-origin' } } ) );
	app.use( requestLogger );
	app.use( globalLimiter );

	// CORS: server-to-server traffic needs none. When ALLOWED_ORIGINS is set
	// (comma-separated), browser-based MCP clients are restricted to it.
	const allowedOrigins = ( process.env.ALLOWED_ORIGINS || '' )
		.split( ',' )
		.map( ( origin ) => origin.trim() )
		.filter( Boolean );
	if ( allowedOrigins.length ) {
		app.use( cors( { origin: allowedOrigins } ) );
	}

	// OWASP: keep the JSON body small — MCP payloads are tiny; 1 MB default.
	app.use( express.json( { limit: process.env.MAX_JSON_BODY || '1mb' } ) );
	app.use( express.urlencoded( { extended: true, limit: '1mb' } ) );

	// ── Public surfaces (no auth) ─────────────────────────────────
	app.use( '/', landingRouter() );
	app.use( '/', healthLimiter, healthRouter() );

	// MCP discovery is public (directory reviewers read it); the JSON-RPC
	// POST surface below it is auth-gated.
	app.get( '/mcp', discoveryHandler );

	// ── Auth-gated MCP surface ────────────────────────────────────
	app.use( '/mcp', authMiddleware, mcpLimiter, mcpRouter() );

	// ── 404 + error handling (JSON, never stack traces in prod) ──
	app.use( ( req, res ) => {
		res.status( 404 ).json( { error: 'not_found', path: req.path } );
	} );

	// eslint-disable-next-line no-unused-vars
	app.use( ( err, req, res, _next ) => {
		const isJsonParse = 'entity.parse.failed' === err.type;
		const status = isJsonParse ? 400 : 500;
		if ( isJsonParse && '/mcp' === req.path ) {
			return res.status( 400 ).json( {
				jsonrpc: '2.0',
				id: null,
				error: { code: -32700, message: 'Parse error: invalid JSON body.' },
			} );
		}
		if ( ! isJsonParse ) {
			console.error( `[gateway] ${ err.message }` );
		}
		return res.status( status ).json( {
			error: isJsonParse ? 'invalid_json' : 'internal_error',
			message: isJsonParse ? 'Invalid JSON body.' : 'Internal error.',
		} );
	} );

	return app;
}

// Start only when run directly (tests import createApp and bind their own).
if ( process.argv[ 1 ] && import.meta.url === new URL( `file://${ process.argv[ 1 ] }` ).href ) {
	const app = createApp();
	const port = Number( process.env.PORT ) || 8080;
	app.listen( port, () => {
		console.log(
			JSON.stringify( {
				ts: new Date().toISOString(),
				service: 'design-mcp-gateway',
				version: VERSION,
				node: process.version,
				port,
				sites: [ ...app.get( 'gatewayConfig' ).sites.keys() ],
				keys: app.get( 'gatewayConfig' ).keys.size,
			} )
		);
	} );
}
