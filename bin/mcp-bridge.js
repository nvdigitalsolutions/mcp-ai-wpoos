#!/usr/bin/env node
/**
 * MCP Bridge — stdio ↔ HTTP relay for NV oOS.
 *
 * Reads JSON-RPC 2.0 messages from stdin (one object per line) and forwards
 * them as POST requests to the NV oOS MCP endpoint, then writes the HTTP
 * response JSON back to stdout.  Diagnostic messages go to stderr only.
 *
 * Required environment variables:
 *   MCP_AI_BASE_URL  — Full URL of the MCP endpoint, e.g.
 *                      https://nixenv.local/wp-json/mcp-ai/v1/mcp
 *                      or for a pro toolkit server:
 *                      https://nixenv.local/wp-json/mcp-ai-pro/v1/mcp/image-production
 *   MCP_AI_TOKEN     — Bearer credential token (cred_xxxxx.SECRET)
 *
 * Usage in Zed / Claude Desktop / Cursor:
 *   {
 *     "command": "node",
 *     "args":    ["bin/mcp-bridge.js"],
 *     "env": {
 *       "MCP_AI_BASE_URL": "https://your-site/wp-json/mcp-ai/v1/mcp",
 *       "MCP_AI_TOKEN":    "cred_xxxxx.SECRET"
 *     }
 *   }
 */

'use strict';

const http  = require( 'http' );
const https = require( 'https' );
const readline = require( 'readline' );

// ── Configuration ────────────────────────────────────────────────────────────

const BASE_URL = ( process.env.MCP_AI_BASE_URL || '' ).replace( /\/+$/, '' );
const TOKEN    = process.env.MCP_AI_TOKEN || '';

if ( ! BASE_URL ) {
	process.stderr.write( '[mcp-bridge] ERROR: MCP_AI_BASE_URL is not set.\n' );
	process.exit( 1 );
}

if ( ! TOKEN ) {
	process.stderr.write( '[mcp-bridge] WARNING: MCP_AI_TOKEN is not set — requests will be unauthenticated.\n' );
}

process.stderr.write( `[mcp-bridge] Bridging stdio → ${ BASE_URL }\n` );

// ── HTTP helper ──────────────────────────────────────────────────────────────

/**
 * Post a JSON body to the MCP endpoint and resolve with the parsed response.
 *
 * @param {object} payload  JSON-RPC 2.0 request object.
 * @returns {Promise<object>}
 */
function postToMcp( payload ) {
	return new Promise( ( resolve, reject ) => {
		const body    = JSON.stringify( payload );
		const parsed  = new URL( BASE_URL );
		const isHttps = parsed.protocol === 'https:';
		const lib     = isHttps ? https : http;

		const options = {
			hostname: parsed.hostname,
			port    : parsed.port || ( isHttps ? 443 : 80 ),
			path    : parsed.pathname + parsed.search,
			method  : 'POST',
			headers : {
				'Content-Type'  : 'application/json',
				'Content-Length': Buffer.byteLength( body ),
				'Authorization' : `Bearer ${ TOKEN }`,
				'Accept'        : 'application/json',
			},
			// Accept self-signed certs for local development.
			rejectUnauthorized: ! parsed.hostname.endsWith( '.local' ),
		};

		const req = lib.request( options, ( res ) => {
			let raw = '';
			res.on( 'data', ( chunk ) => { raw += chunk; } );
			res.on( 'end', () => {
				try {
					resolve( JSON.parse( raw ) );
				} catch ( e ) {
					reject( new Error( `Non-JSON response (${ res.statusCode }): ${ raw.slice( 0, 200 ) }` ) );
				}
			} );
		} );

		req.on( 'error', reject );
		req.write( body );
		req.end();
	} );
}

// ── Main loop ────────────────────────────────────────────────────────────────

const rl = readline.createInterface( { input: process.stdin, crlfDelay: Infinity } );

rl.on( 'line', async ( line ) => {
	const trimmed = line.trim();
	if ( ! trimmed ) return;

	let request;
	try {
		request = JSON.parse( trimmed );
	} catch ( e ) {
		const errResponse = {
			jsonrpc : '2.0',
			id      : null,
			error   : { code: -32700, message: `Parse error: ${ e.message }` },
		};
		process.stdout.write( JSON.stringify( errResponse ) + '\n' );
		return;
	}

	try {
		const response = await postToMcp( request );
		process.stdout.write( JSON.stringify( response ) + '\n' );
	} catch ( e ) {
		process.stderr.write( `[mcp-bridge] HTTP error: ${ e.message }\n` );
		const errResponse = {
			jsonrpc : '2.0',
			id      : request.id ?? null,
			error   : { code: -32603, message: `Internal error: ${ e.message }` },
		};
		process.stdout.write( JSON.stringify( errResponse ) + '\n' );
	}
} );

rl.on( 'close', () => {
	process.stderr.write( '[mcp-bridge] stdin closed, exiting.\n' );
	process.exit( 0 );
} );
