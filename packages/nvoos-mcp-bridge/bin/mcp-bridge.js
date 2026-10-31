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
 *   MCP_AI_BRIDGE_NAME  — Declares this bridge's identity to the site. It is
 *                         stamped as the X-MCP-Bridge-Name header and
 *                         surfaced by the site's mcp_server_info tool and
 *                         the list_mcp_tools _meta block. Purely
 *                         informational — never used for authorization.
 *                         In Zed, set it to the context_servers key, e.g.
 *                         "nvoos-ideabits-gateway-basic".
 *
 * Session support (optional — for sites whose write tools require a
 * manager session cookie, e.g. the NV Astro storefront's /mcp contract
 * writes):
 *   MCP_AI_COOKIE           — Static Cookie header sent on every upstream
 *                             request. Escape hatch for a manually captured
 *                             session; overrides the login bootstrap below.
 *   MCP_AI_LOGIN_URL        — Full URL of a login endpoint that answers
 *                             with Set-Cookie session headers, e.g.
 *                             https://astro.nvoos.cloud/api/auth/login.
 *                             Must be same-origin with MCP_AI_BASE_URL.
 *   MCP_AI_LOGIN_EMAIL      — Login credential (email/username).
 *   MCP_AI_LOGIN_PASSWORD   — Login credential (password).
 *   MCP_AI_LOGIN_BODY       — Raw JSON request body override for the login
 *                             POST. When set, it wins over the email/password
 *                             pair (whose default body is { email, password }).
 *
 * The bridge logs in once at startup, attaches the captured cookies to
 * every upstream request, and re-establishes the session once when an
 * upstream request answers 401/403 or when the cookies near their expiry.
 * Without session env vars the relay stays the transparent transport it
 * always was (HTTP statuses pass through untouched).
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
const BRIDGE_NAME = process.env.MCP_AI_BRIDGE_NAME || '';

const LOGIN_URL = ( process.env.MCP_AI_LOGIN_URL || '' ).trim();
const LOGIN_EMAIL = process.env.MCP_AI_LOGIN_EMAIL || '';
const LOGIN_PASSWORD = process.env.MCP_AI_LOGIN_PASSWORD || '';
const LOGIN_BODY = ( process.env.MCP_AI_LOGIN_BODY || '' ).trim();
const STATIC_COOKIE = process.env.MCP_AI_COOKIE || '';

// Live session state: { cookie, expiresAt } | null. Replaced by the login
// bootstrap, the 401/403 retry path, or the static-cookie escape hatch.
let session = null;
// Single-flight guard so concurrent callers share one login attempt.
let sessionPromise = null;

// Unparseable cookies get this fallback lifetime before a re-login attempt.
const SESSION_TTL_FALLBACK_MS = 30 * 60 * 1000;
// Re-login this many milliseconds before the cookies expire.
const SESSION_EXPIRY_SLACK_MS = 30 * 1000;
// Upper bound for the startup bootstrap so a dead login endpoint can never
// hang the bridge before it serves initialize.
const SESSION_BOOTSTRAP_TIMEOUT_MS = 10000;

const timeoutRaw = parseInt( process.env.MCP_AI_HTTP_TIMEOUT || '', 10 );
const HTTP_TIMEOUT_MS = Number.isFinite( timeoutRaw ) && timeoutRaw >= 100
	? timeoutRaw
	: 120000;

// ── HTTP helpers ─────────────────────────────────────────────────────────────

/**
 * Parse a JSON or SSE-framed response body into the JSON payload it
 * carries. SDK-v2 servers reply with `text/event-stream` bodies
 * (`event: message` + `data: <json>`) whenever the client advertises
 * `text/event-stream` in Accept — the per-event `data:` blocks are the
 * JSON-RPC messages. When several events carry JSON, the last one wins
 * (keeps the final message of a stream). Keep-alive comments and empty
 * events are ignored.
 *
 * @param {string} raw          Raw response body.
 * @param {string} contentType  Response Content-Type header.
 * @returns {object|null} Parsed JSON payload, or null when unparseable.
 */
function parseResponseBody( raw, contentType = '' ) {
	const isSse =
		/text\/event-stream/i.test( contentType || '' ) ||
		/^\s*(event:|data:)/m.test( raw );

	if ( ! isSse ) {
		try {
			return JSON.parse( raw );
		} catch ( e ) {
			return null;
		}
	}

	const events = [];
	let current = [];
	for ( const line of raw.split( /\r?\n/ ) ) {
		if ( '' === line.trim() ) {
			if ( current.length ) {
				events.push( current.join( '\n' ) );
				current = [];
			}
			continue;
		}
		if ( line.startsWith( 'data:' ) ) {
			current.push( line.slice( 5 ).replace( /^ /, '' ) );
		}
		// event:, id:, retry:, and comment (:) lines carry no payload.
	}
	if ( current.length ) {
		events.push( current.join( '\n' ) );
	}

	let parsed = null;
	for ( const event of events ) {
		if ( '' === event.trim() ) continue;
		try {
			parsed = JSON.parse( event );
		} catch ( e ) {
			// Non-JSON event (should not happen) — skip it.
		}
	}
	return parsed;
}

/**
 * Send a JSON request and resolve with the status code, the parsed response
 * body, and the response headers. An empty response body resolves with
 * `data: null` (the plugin answers notifications with no content).
 *
 * The Accept header advertises both Streamable HTTP media types
 * (application/json and text/event-stream) — MCP clients MUST accept both,
 * and strict SDK-v2 servers reject the handshake otherwise.
 *
 * @param {string} url        Absolute URL to request.
 * @param {object} headers    Extra request headers.
 * @param {object|null} body  JSON-serializable body (null = no body).
 * @returns {Promise<{statusCode: number, data: object|null, headers: object}>}
 */
function requestJson( url, headers = {}, body = null ) {
	return new Promise( ( resolve, reject ) => {
		const parsed = new URL( url );
		const isHttps = parsed.protocol === 'https:';
		const lib = isHttps ? https : http;
		const payload = null === body ? null : JSON.stringify( body );

		const outHeaders = {
			Accept: 'application/json, text/event-stream',
			...headers,
		};
		if ( null !== payload ) {
			outHeaders[ 'Content-Type' ] = 'application/json';
			outHeaders[ 'Content-Length' ] = Buffer.byteLength( payload );
		}

		const options = {
			hostname: parsed.hostname,
			port: parsed.port || ( isHttps ? 443 : 80 ),
			path: parsed.pathname + parsed.search,
			method: null === payload ? 'GET' : 'POST',
			headers: outHeaders,
			// Accept self-signed certs for local development.
			rejectUnauthorized: ! parsed.hostname.endsWith( '.local' ),
		};

		const req = lib.request( options, ( res ) => {
			let raw = '';
			res.on( 'data', ( chunk ) => { raw += chunk; } );
			res.on( 'end', () => {
				const trimmed = raw.trim();
				if ( '' === trimmed ) {
					resolve( { statusCode: res.statusCode, data: null, headers: res.headers } );
					return;
				}
				const data = parseResponseBody( trimmed, res.headers[ 'content-type' ] );
				if ( null === data ) {
					reject( new Error( `Non-JSON response (${ res.statusCode }): ${ trimmed.slice( 0, 200 ) }` ) );
					return;
				}
				resolve( { statusCode: res.statusCode, data, headers: res.headers } );
			} );
		} );

		req.setTimeout( HTTP_TIMEOUT_MS, () => {
			req.destroy( new Error( `Request timed out after ${ HTTP_TIMEOUT_MS }ms` ) );
		} );
		req.on( 'error', reject );
		if ( null !== payload ) {
			req.write( payload );
		}
		req.end();
	} );
}

/**
 * Post a JSON-RPC payload to the MCP endpoint (transparent transport).
 *
 * @param {object} payload  JSON-RPC 2.0 request object.
 * @returns {Promise<{statusCode: number, data: object|null, headers: object}>}
 */
function postToMcp( payload ) {
	const headers = { Authorization: `Bearer ${ TOKEN }` };
	if ( HOST_HEADER ) {
		headers.Host = HOST_HEADER;
	}
	if ( BRIDGE_NAME ) {
		headers[ 'X-MCP-Bridge-Name' ] = BRIDGE_NAME;
	}
	const cookie = currentCookie();
	if ( cookie ) {
		headers.Cookie = cookie;
	}
	return requestJson( BASE_URL, headers, payload );
}

/**
 * Post a JSON-RPC payload, re-establishing the session once when the
 * upstream answers 401/403. Without session configuration this behaves
 * exactly like postToMcp (the transparent-transport contract).
 *
 * @param {object} payload  JSON-RPC 2.0 request object.
 * @returns {Promise<{statusCode: number, data: object|null, headers: object}>}
 */
async function postWithSessionRetry( payload ) {
	let res = await postToMcp( payload );
	if ( 401 !== res.statusCode && 403 !== res.statusCode ) {
		return res;
	}
	if ( ! LOGIN_URL && ! STATIC_COOKIE && ! session ) {
		return res;
	}
	process.stderr.write(
		`[mcp-bridge] upstream answered HTTP ${ res.statusCode } — re-establishing session, retrying once.\n`
	);
	session = null;
	await ensureSession();
	return postToMcp( payload );
}

// ── Session bootstrap ────────────────────────────────────────────────────────

/**
 * Parse the expiry of a single Set-Cookie header string.
 *
 * @param {string} setCookie  Full Set-Cookie header value.
 * @returns {number|null} Epoch milliseconds, or null when unparseable.
 */
function parseCookieExpiry( setCookie ) {
	let maxAge = null;
	let expires = null;
	for ( const attr of setCookie.split( ';' ).slice( 1 ) ) {
		const eq = attr.indexOf( '=' );
		if ( -1 === eq ) continue;
		const key = attr.slice( 0, eq ).trim().toLowerCase();
		const value = attr.slice( eq + 1 ).trim();
		if ( 'max-age' === key ) {
			const n = parseInt( value, 10 );
			if ( Number.isFinite( n ) ) maxAge = n;
		} else if ( 'expires' === key ) {
			const d = new Date( value ).getTime();
			if ( Number.isFinite( d ) ) expires = d;
		}
	}
	if ( null !== maxAge ) {
		return Date.now() + maxAge * 1000;
	}
	return expires;
}

/**
 * Collect the session cookies from response headers (all Set-Cookie values
 * joined) and the earliest expiry across them.
 *
 * @param {object} headers  Response headers from requestJson.
 * @returns {{cookie: string, expiresAt: number}|null}
 */
function parseSetCookies( headers ) {
	const raw = headers && headers[ 'set-cookie' ];
	const list = Array.isArray( raw ) ? raw : ( raw ? [ raw ] : [] );
	let cookie = '';
	let expiry = null;
	for ( const setCookie of list ) {
		const value = setCookie.split( ';' )[ 0 ].trim();
		if ( ! value ) continue;
		cookie += ( cookie ? '; ' : '' ) + value;
		const at = parseCookieExpiry( setCookie );
		if ( null !== at && ( null === expiry || at < expiry ) ) {
			expiry = at;
		}
	}
	if ( ! cookie ) return null;
	return {
		cookie,
		expiresAt: null === expiry ? Date.now() + SESSION_TTL_FALLBACK_MS : expiry,
	};
}

/**
 * The cookie string to attach to upstream requests, or null.
 *
 * @returns {string|null}
 */
function currentCookie() {
	if ( session && Date.now() < session.expiresAt - SESSION_EXPIRY_SLACK_MS ) {
		return session.cookie;
	}
	return null;
}

/**
 * POST the configured login body to MCP_AI_LOGIN_URL.
 *
 * @returns {Promise<{statusCode: number, data: object|null, headers: object}>}
 */
function loginRequest() {
	let body = null;
	if ( LOGIN_BODY ) {
		body = JSON.parse( LOGIN_BODY );
	} else if ( LOGIN_EMAIL || LOGIN_PASSWORD ) {
		body = { email: LOGIN_EMAIL, password: LOGIN_PASSWORD };
	}
	return requestJson( LOGIN_URL, {}, body );
}

/**
 * Ensure a live session cookie is available. The static MCP_AI_COOKIE wins;
 * otherwise the login bootstrap runs (once per call wave — single-flight).
 *
 * @returns {Promise<{cookie: string, expiresAt: number}|null>}
 */
async function ensureSession() {
	if ( currentCookie() ) {
		return session;
	}
	if ( STATIC_COOKIE ) {
		session = { cookie: STATIC_COOKIE, expiresAt: Number.MAX_SAFE_INTEGER };
		return session;
	}
	if ( ! LOGIN_URL ) {
		return null;
	}
	if ( sessionPromise ) {
		return sessionPromise;
	}
	sessionPromise = ( async () => {
		try {
			const res = await loginRequest();
			if ( res.statusCode >= 400 ) {
				process.stderr.write(
					`[mcp-bridge] login returned HTTP ${ res.statusCode } — writes requiring a session will fail.\n`
				);
				return null;
			}
			const parsed = parseSetCookies( res.headers );
			if ( ! parsed ) {
				process.stderr.write(
					'[mcp-bridge] login returned no session cookies — writes requiring a session will fail.\n'
				);
				return null;
			}
			session = parsed;
			process.stderr.write(
				`[mcp-bridge] session established (expires ${ new Date( parsed.expiresAt ).toISOString() })\n`
			);
			return session;
		} catch ( e ) {
			process.stderr.write( `[mcp-bridge] login failed: ${ e.message }\n` );
			return null;
		} finally {
			sessionPromise = null;
		}
	} )();
	return sessionPromise;
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

async function main() {
	if ( ! BASE_URL ) {
		process.stderr.write( '[mcp-bridge] ERROR: MCP_AI_BASE_URL is not set.\n' );
		process.exit( 1 );
	}

	if ( ! TOKEN ) {
		process.stderr.write( '[mcp-bridge] WARNING: MCP_AI_TOKEN is not set — requests will be unauthenticated.\n' );
	}

	process.stderr.write( `[mcp-bridge] Bridging stdio → ${ BASE_URL }\n` );

	// Bootstrap the session before serving requests so the very first
	// tools/call already carries the cookie. Capped so a dead login endpoint
	// can never hang the bridge; the 401/403 retry path covers the race.
	if ( LOGIN_URL || STATIC_COOKIE ) {
		await Promise.race( [
			ensureSession(),
			new Promise( ( resolve ) => setTimeout( resolve, SESSION_BOOTSTRAP_TIMEOUT_MS ) ),
		] );
	}

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
			const { statusCode, data } = await postWithSessionRetry( request );

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

module.exports = {
	postToMcp,
	main,
	requestJson,
	parseResponseBody,
	postWithSessionRetry,
	parseCookieExpiry,
	parseSetCookies,
	ensureSession,
	loginRequest,
};
