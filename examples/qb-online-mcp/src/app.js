// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * HTTP application assembly: inbound auth gate, health probe, and the
 * dual-era MCP handler.
 *
 * Auth and guards live OUTSIDE the SDK entry — the MCP handler is
 * deliberately validation-free, so anything it receives is trusted.
 */

import { createServer } from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import { McpServer, createMcpHandler } from '@modelcontextprotocol/server';
import { toNodeHandler } from '@modelcontextprotocol/node';
import { IntuitClient } from './intuit.js';
import { registerTools } from './tools.js';

/**
 * Constant-time bearer check.
 *
 * @param {Object} req    Incoming request.
 * @param {string} token  Expected token.
 * @return {boolean} Whether the request is authorised.
 */
function isAuthorized( req, token ) {
	const header = req.headers.authorization || '';
	const match = /^Bearer\s+(.+)$/i.exec( header );

	if ( ! match ) {
		return false;
	}

	const given = Buffer.from( match[ 1 ] );
	const expected = Buffer.from( token );

	return given.length === expected.length && timingSafeEqual( given, expected );
}

/**
 * Build the application without binding a port.
 *
 * @param {Object} config Configuration from loadConfig().
 * @return {Promise<{ server: import('node:http').Server, intuit: IntuitClient }>} App pieces.
 */
export async function createApp( config ) {
	const intuit = new IntuitClient( config );
	await intuit.init();

	/**
	 * Fresh server per request — the stateless contract. Return the server
	 * itself: `registerTool` returns a tool record, so chaining would hand the
	 * handler the wrong object and every request would 500.
	 */
	const buildServer = () => {
		const server = new McpServer( {
			name: config.serverName,
			version: config.serverVersion,
		} );

		registerTools( server, intuit );

		return server;
	};

	const handler = createMcpHandler( buildServer, {
		legacy: 'stateless',
		onerror: ( error ) => console.error( '[mcp]', error?.message || error ),
	} );

	const nodeHandler = toNodeHandler( handler );

	const server = createServer( async ( req, res ) => {
		if ( '/health' === req.url && ( 'GET' === req.method || 'HEAD' === req.method ) ) {
			res.writeHead( 200, { 'Content-Type': 'application/json' } );
			res.end(
				JSON.stringify( {
					ok: true,
					name: config.serverName,
					version: config.serverVersion,
					environment: config.environment,
				} )
			);
			return;
		}

		if ( ! config.allowAnonymous && ! isAuthorized( req, config.inboundToken ) ) {
			res.writeHead( 401, {
				'Content-Type': 'application/json',
				'WWW-Authenticate': 'Bearer realm="quickbooks-online-mcp"',
			} );
			res.end(
				JSON.stringify( {
					jsonrpc: '2.0',
					id: null,
					error: { code: -32001, message: 'Unauthorized.' },
				} )
			);
			return;
		}

		try {
			await nodeHandler( req, res );
		} catch ( error ) {
			console.error( '[http]', error );

			if ( ! res.headersSent ) {
				res.writeHead( 500, { 'Content-Type': 'application/json' } );
			}

			res.end(
				JSON.stringify( {
					jsonrpc: '2.0',
					id: null,
					error: { code: -32603, message: 'Internal server error.' },
				} )
			);
		}
	} );

	return { server, intuit };
}
