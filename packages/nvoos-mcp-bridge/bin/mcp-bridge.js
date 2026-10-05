#!/usr/bin/env node
/**
 * MCP Bridge — stdio ↔ HTTP relay for NV oOS.
 *
 * Reads JSON-RPC 2.0 messages from stdin (one object per line — the
 * newline-delimited framing the MCP stdio transport specifies, which is
 * what Zed's context server client sends) and forwards them as POST
 * requests to the NV oOS MCP endpoint, then writes the HTTP response JSON
 * back to stdout. Diagnostic messages go to stderr only.
 *
 * Required environment variables:
 *   MCP_AI_BASE_URL  — Full URL of the MCP endpoint, e.g.
 *                      https://example.com/wp-json/mcp-ai/v1/mcp
 *   MCP_AI_TOKEN     — Bearer credential token (cred_xxxxx.SECRET or
 *                      op_xxxxx.SECRET)
 *
 * Optional environment variables:
 *   MCP_AI_HOST_HEADER  — Override the HTTP Host header. Useful when the
 *                         endpoint is reached through a tunnel/proxy whose
 *                         address is not the canonical site host and the
 *                         web server canonical-redirects on Host.
 *   MCP_AI_HTTP_TIMEOUT — Request timeout in milliseconds (default 120000).
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
 *
 * For SSH-only sites, use bin/mcp-bridge-ssh.js, which starts the tunnel
 * for you and then delegates to this relay over 127.0.0.1.
 */

'use strict';

const http = require( 'http' );
const https = require( 'https' );
const readline = require( 'readline' );

// ── Configuration ────────────────────────────────────────────────────────────

const BASE_URL = ( process.env.MCP_AI_BASE_URL || '' ).replace( /\/+$/, '' );
const TOKEN = process.env.MCP_AI_TOKEN || '';
const HOST_HEADER = process.env.MCP_AI_HOST_HEADER || '';

const timeoutRaw = parseInt( process.env.MCP_AI_HTTP_TIMEOUT || '', 10 );
const HTTP_TIMEOUT_MS = Number.isFinite( timeoutRaw ) && timeoutRaw >= 100
	? timeoutRaw
	: 120000;

// ── HTTP helper ──────────────────────────────────────────────────────────────

/**
 * Post a JSON body to the MCP endpoint and resolve with the status code and
 * parsed response body. An empty response body resolves with `data: null`
 * (the plugin answers notifications with no content).
 *
 * @param {object} payload  JSON-RPC 2.0 request object.
 * @returns {Promise<{statusCode: number, data: object|null}>}
 */
function postToMcp( payload ) {
	return new Promise( ( resolve, reject ) => {
		const body = JSON.stringify( payload );
		const parsed = new URL( BASE_URL );
		const isHttps = parsed.protocol === 'https:';
		const lib = isHttps ? https : http;

		const headers = {
			'Content-Type': 'application/json',
			'Content-Length': Buffer.byteLength( body ),
			'Authorization': `Bearer ${ TOKEN }`,
			'Accept': 'application/json',
		};
		if ( HOST_HEADER ) {
			headers.Host = HOST_HEADER;
		}

		const options = {
			hostname: parsed.hostname,
			port: parsed.port || ( isHttps ? 443 : 80 ),
			path: parsed.pathname + parsed.search,
			method: 'POST',
			headers,
			// Accept self-signed certs for local development.
			rejectUnauthorized: ! parsed.hostname.endsWith( '.local' ),
		};

		const req = lib.request( options, ( res ) => {
			let raw = '';
			res.on( 'data', ( chunk ) => { raw += chunk; } );
			res.on( 'end', () => {
				const trimmed = raw.trim();
				if ( '' === trimmed ) {
					resolve( { statusCode: res.statusCode, data: null } );
					return;
				}
				try {
					resolve( { statusCode: res.statusCode, data: JSON.parse( trimmed ) } );
				} catch ( e ) {
					reject( new Error( `Non-JSON response (${ res.statusCode }): ${ trimmed.slice( 0, 200 ) }` ) );
				}
			} );
		} );

		req.setTimeout( HTTP_TIMEOUT_MS, () => {
			req.destroy( new Error( `Request timed out after ${ HTTP_TIMEOUT_MS }ms` ) );
		} );
		req.on( 'error', reject );
		req.write( body );
		req.end();
	} );
}

// ── Main loop ────────────────────────────────────────────────────────────────

/**
 * Write a JSON-RPC response to stdout (newline-delimited).
 *
 * @param {object} envelope  Full JSON-RPC response object.
 */
function writeResponse( envelope ) {
	process.stdout.write( JSON.stringify( envelope ) + '\n' );
}

function main() {
	if ( ! BASE_URL ) {
		process.stderr.write( '[mcp-bridge] ERROR: MCP_AI_BASE_URL is not set.\n' );
		process.exit( 1 );
	}

	if ( ! TOKEN ) {
		process.stderr.write( '[mcp-bridge] WARNING: MCP_AI_TOKEN is not set — requests will be unauthenticated.\n' );
	}

	process.stderr.write( `[mcp-bridge] Bridging stdio → ${ BASE_URL }\n` );

	const rl = readline.createInterface( { input: process.stdin, crlfDelay: Infinity } );

	// Track in-flight requests: stdin EOF must not kill a request that is
	// still awaiting its HTTP response (some clients half-close stdin right
	// after writing the final request).
	let inFlight = 0;

	rl.on( 'line', async ( line ) => {
		const trimmed = line.trim();
		if ( ! trimmed ) return;

		let request;
		try {
			request = JSON.parse( trimmed );
		} catch ( e ) {
			writeResponse( {
				jsonrpc: '2.0',
				id: null,
				error: { code: -32700, message: `Parse error: ${ e.message }` },
			} );
			return;
		}

		const isNotification = undefined === request.id || null === request.id;

		inFlight += 1;
		try {
			const { statusCode, data } = await postToMcp( request );

			if ( isNotification ) {
				// JSON-RPC notifications get no response, ever.
				if ( 'number' === typeof statusCode && statusCode >= 400 ) {
					process.stderr.write( `[mcp-bridge] notification returned HTTP ${ statusCode }\n` );
				}
				return;
			}

			// Empty body (e.g. HTTP 202 without content) → empty result.
			writeResponse( null === data
				? { jsonrpc: '2.0', id: request.id, result: {} }
				: data
			);
		} catch ( e ) {
			process.stderr.write( `[mcp-bridge] HTTP error: ${ e.message }\n` );
			if ( isNotification ) {
				return;
			}
			writeResponse( {
				jsonrpc: '2.0',
				id: request.id,
				error: { code: -32603, message: `Internal error: ${ e.message }` },
			} );
		} finally {
			inFlight -= 1;
		}
	} );

	rl.on( 'close', () => {
		process.stderr.write( '[mcp-bridge] stdin closed, draining in-flight requests.\n' );
		const drain = () => {
			if ( inFlight > 0 ) {
				setTimeout( drain, 50 );
				return;
			}
			process.exit( 0 );
		};
		drain();
	} );
}

if ( require.main === module ) {
	main();
}

module.exports = { postToMcp, main };
