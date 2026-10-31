'use strict';

/**
 * Session-bootstrap tests for @nvdigitalsolutions/nvoos-mcp-bridge.
 *
 * Covers the opt-in manager-session support added for sites whose write
 * tools require a session cookie (e.g. the NV Astro storefront /mcp
 * contract writes):
 *
 *   - MCP_AI_COOKIE (static cookie) forwarded verbatim
 *   - MCP_AI_LOGIN_URL + email/password bootstrap: login POST body shape,
 *     captured Set-Cookie values forwarded upstream
 *   - upstream 401 → exactly one re-login + one retry
 *   - login failure degrades gracefully (relay stays transparent, no hang)
 *   - parseSetCookies/parseCookieExpiry unit behaviour
 *
 * Hermetic: spawns the relay against an in-process fake site, no network.
 *
 * Run: node --test test/session.test.js
 */

const assert = require( 'node:assert' );
const http = require( 'node:http' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const readline = require( 'node:readline' );
const { spawn } = require( 'node:child_process' );
const { test } = require( 'node:test' );

const RELAY = path.join( __dirname, '..', 'bin', 'mcp-bridge.js' );

// Hermeticity guard: never let a developer's real ~/.nvoos-bridge.env leak
// into a test spawn.
const NO_ENV_FILE = path.join( os.tmpdir(), 'nvoos-mcp-bridge-test-no-env-file.env' );

/**
 * Start a fake site that answers /auth/login with session cookies and / with
 * an echo of the JSON-RPC payload. Records every request (path, headers,
 * body).
 *
 * @param {object} opts  `loginStatus`, `cookies` (string[]), `mcp401Once`
 *                       (first MCP request answers 401).
 * @returns {Promise<{port:number, requests:object[], close:Function}>}
 */
function startFakeSite( opts = {} ) {
	const requests = [];
	let mcp401Sent = false;

	const server = http.createServer( ( req, res ) => {
		let raw = '';
		req.on( 'data', ( chunk ) => { raw += chunk; } );
		req.on( 'end', () => {
			requests.push( { path: req.url, headers: req.headers, body: raw } );

			if ( '/auth/login' === req.url ) {
				if ( opts.loginStatus && opts.loginStatus >= 400 ) {
					res.writeHead( opts.loginStatus, { 'Content-Type': 'application/json' } );
					res.end( JSON.stringify( { error: 'login failed' } ) );
					return;
				}
				const cookies = opts.cookies || [ 'session=abc123; Path=/; HttpOnly' ];
				res.writeHead( 200, { 'Content-Type': 'application/json', 'Set-Cookie': cookies } );
				res.end( JSON.stringify( { ok: true } ) );
				return;
			}

			// MCP endpoint (base URL = server root).
			let payload = null;
			try {
				payload = JSON.parse( raw );
			} catch ( e ) {
				res.writeHead( 400 );
				res.end( 'not json' );
				return;
			}
			if ( opts.mcp401Once && ! mcp401Sent ) {
				mcp401Sent = true;
				res.writeHead( 401, { 'Content-Type': 'application/json' } );
				res.end( JSON.stringify( {
					jsonrpc: '2.0',
					id: payload.id,
					error: { code: -32001, message: 'unauthorized' },
				} ) );
				return;
			}
			res.writeHead( 200, { 'Content-Type': 'application/json' } );
			res.end( JSON.stringify( {
				jsonrpc: '2.0',
				id: payload.id,
				result: { echo: payload },
			} ) );
		} );
	} );

	return new Promise( ( resolve ) => {
		server.listen( 0, '127.0.0.1', () => resolve( {
			port: server.address().port,
			requests,
			close: () => new Promise( ( r ) => server.close( r ) ),
		} ) );
	} );
}

/**
 * Launch a relay child, collecting stdout lines and stderr.
 *
 * @param {object} env  Extra env vars.
 * @returns {{child:object, lines:string[], stderr:string[], exit:Promise<object>}}
 */
function startBridge( env = {} ) {
	const child = spawn( process.execPath, [ RELAY ], {
		env: { ...process.env, MCP_AI_ENV_FILE: NO_ENV_FILE, ...env },
		stdio: [ 'pipe', 'pipe', 'pipe' ],
		windowsHide: true,
	} );

	const lines = [];
	const stderr = [];
	readline.createInterface( { input: child.stdout } ).on( 'line', ( l ) => lines.push( l ) );
	readline.createInterface( { input: child.stderr } ).on( 'line', ( l ) => stderr.push( l ) );

	const exit = new Promise( ( resolve ) => {
		child.once( 'exit', ( code, signal ) => resolve( { code, signal } ) );
	} );

	return { child, lines, stderr, exit };
}

/**
 * Wait until a predicate matches one of the collected stdout lines.
 *
 * @param {string[]} lines  Collected lines.
 * @param {Function} pred   Line predicate.
 * @param {number} timeoutMs Budget.
 * @returns {Promise<string>} Matching line.
 */
async function waitForLine( lines, pred, timeoutMs = 10000 ) {
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		const found = lines.find( pred );
		if ( found ) {
			return found;
		}
		await new Promise( ( r ) => setTimeout( r, 50 ) );
	}
	throw new Error( 'Timed out waiting for expected stdout line.' );
}

/**
 * Wait until the fake site has recorded a request matching the predicate.
 *
 * @param {object[]} requests  Recorded requests.
 * @param {Function} pred      Request predicate.
 * @param {number} timeoutMs   Budget.
 * @returns {Promise<object>} Matching request.
 */
async function waitForRequest( requests, pred, timeoutMs = 10000 ) {
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		const found = requests.find( pred );
		if ( found ) {
			return found;
		}
		await new Promise( ( r ) => setTimeout( r, 50 ) );
	}
	throw new Error( 'Timed out waiting for the fake site to record a request.' );
}

/**
 * Write one JSON-RPC object to the child's stdin.
 *
 * @param {object} child  Spawned child.
 * @param {object} obj    Payload.
 */
function send( child, obj ) {
	child.stdin.write( JSON.stringify( obj ) + '\n' );
}

/**
 * Best-effort cleanup so a failed assertion can never hang the runner.
 *
 * @param {object} bridge  startBridge() result.
 * @param {object} site    startFakeSite() result.
 */
async function cleanup( bridge, site ) {
	try {
		bridge.child.stdin.end();
	} catch ( e ) {
		// Pipe already gone.
	}
	try {
		bridge.child.kill();
	} catch ( e ) {
		// Already exited.
	}
	await bridge.exit.catch( () => ( {} ) );
	if ( site ) {
		await site.close().catch( () => {} );
	}
}

// ── Integration tests ────────────────────────────────────────────────────────

test( 'session: static MCP_AI_COOKIE is forwarded verbatim', async () => {
	const site = await startFakeSite();
	const bridge = startBridge( {
		MCP_AI_BASE_URL: `http://127.0.0.1:${ site.port }`,
		MCP_AI_COOKIE: 'wordpress_logged_in_abc=STATIC',
	} );
	try {
		send( bridge.child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 1 } );
		const line = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":1' ) ) );
		assert.strictEqual( line.result.echo.method, 'tools/list' );
		assert.strictEqual( site.requests[ 0 ].headers.cookie, 'wordpress_logged_in_abc=STATIC' );
		assert.strictEqual( site.requests.length, 1, 'no login request may be sent with a static cookie' );
	} finally {
		await cleanup( bridge, site );
	}
} );

test( 'session: login bootstrap captures cookies and forwards them upstream', async () => {
	const site = await startFakeSite( {
		cookies: [
			'wordpress_logged_in_abc=VAL1; Path=/; HttpOnly; Max-Age=3600',
			'wordpress_test_cookie=VAL2; Path=/',
		],
	} );
	const bridge = startBridge( {
		MCP_AI_BASE_URL: `http://127.0.0.1:${ site.port }`,
		MCP_AI_LOGIN_URL: `http://127.0.0.1:${ site.port }/auth/login`,
		MCP_AI_LOGIN_EMAIL: 'manager@example.com',
		MCP_AI_LOGIN_PASSWORD: 'hunter2',
	} );
	try {
		// Deterministic: wait for the startup login before the first call.
		const login = await waitForRequest( site.requests, ( r ) => '/auth/login' === r.path );
		assert.deepStrictEqual(
			JSON.parse( login.body ),
			{ email: 'manager@example.com', password: 'hunter2' }
		);

		send( bridge.child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 2 } );
		await waitForLine( bridge.lines, ( l ) => l.includes( '"id":2' ) );

		const mcp = site.requests.find( ( r ) => '/' === r.path );
		assert.ok( mcp, 'the MCP request must reach the fake site' );
		assert.ok(
			mcp.headers.cookie.includes( 'wordpress_logged_in_abc=VAL1' ),
			`first cookie forwarded, got: ${ mcp.headers.cookie }`
		);
		assert.ok(
			mcp.headers.cookie.includes( 'wordpress_test_cookie=VAL2' ),
			`second cookie forwarded, got: ${ mcp.headers.cookie }`
		);
	} finally {
		await cleanup( bridge, site );
	}
} );

test( 'session: upstream 401 triggers exactly one re-login and one retry', async () => {
	const site = await startFakeSite( {
		cookies: [ 'session=fresh; Path=/; Max-Age=3600' ],
		mcp401Once: true,
	} );
	const bridge = startBridge( {
		MCP_AI_BASE_URL: `http://127.0.0.1:${ site.port }`,
		MCP_AI_LOGIN_URL: `http://127.0.0.1:${ site.port }/auth/login`,
		MCP_AI_LOGIN_EMAIL: 'manager@example.com',
		MCP_AI_LOGIN_PASSWORD: 'hunter2',
	} );
	try {
		await waitForRequest( site.requests, ( r ) => '/auth/login' === r.path );

		send( bridge.child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 5 } );
		const line = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":5' ) ) );
		assert.strictEqual( line.result.echo.method, 'tools/list' );

		const mcpCalls = site.requests.filter( ( r ) => '/' === r.path );
		const logins = site.requests.filter( ( r ) => '/auth/login' === r.path );
		assert.strictEqual( mcpCalls.length, 2, '401 + exactly one retry' );
		assert.strictEqual( logins.length, 2, 'startup login + exactly one re-login' );
		assert.ok(
			mcpCalls[ 1 ].headers.cookie.includes( 'session=fresh' ),
			`retry must carry the re-established cookie, got: ${ mcpCalls[ 1 ].headers.cookie }`
		);
	} finally {
		await cleanup( bridge, site );
	}
} );

test( 'session: login failure degrades gracefully — relay stays transparent', async () => {
	const site = await startFakeSite( { loginStatus: 500 } );
	const bridge = startBridge( {
		MCP_AI_BASE_URL: `http://127.0.0.1:${ site.port }`,
		MCP_AI_LOGIN_URL: `http://127.0.0.1:${ site.port }/auth/login`,
		MCP_AI_LOGIN_EMAIL: 'manager@example.com',
		MCP_AI_LOGIN_PASSWORD: 'hunter2',
	} );
	try {
		send( bridge.child, { jsonrpc: '2.0', method: 'initialize', params: {}, id: 9 } );
		const line = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":9' ) ) );
		assert.strictEqual( line.result.echo.method, 'initialize' );

		const mcp = site.requests.find( ( r ) => '/' === r.path );
		assert.strictEqual( mcp.headers.cookie, undefined, 'no cookie may be attached' );
		assert.ok(
			bridge.stderr.join( '\n' ).includes( 'login returned HTTP 500' ),
			'stderr must surface the failed login'
		);
	} finally {
		await cleanup( bridge, site );
	}
} );

// ── Unit tests (direct require — safe: main() only runs as entry point) ─────

const { parseCookieExpiry, parseSetCookies } = require( RELAY );

test( 'session: parseSetCookies joins every cookie and keeps the earliest expiry', () => {
	const parsed = parseSetCookies( {
		'set-cookie': [
			'first=1; Path=/; Max-Age=600',
			'second=2; Path=/; Max-Age=60',
		],
	} );
	assert.ok( parsed, 'cookies must parse' );
	assert.strictEqual( parsed.cookie, 'first=1; second=2' );
	const now = Date.now();
	assert.ok( parsed.expiresAt >= now + 55 * 1000, 'earliest expiry wins (60s)' );
	assert.ok( parsed.expiresAt <= now + 65 * 1000, 'earliest expiry wins (60s)' );
} );

test( 'session: parseSetCookies falls back to a TTL when expiry attributes are absent', () => {
	const parsed = parseSetCookies( { 'set-cookie': 'bare=1; Path=/' } );
	assert.ok( parsed, 'cookies must parse' );
	const now = Date.now();
	assert.ok( parsed.expiresAt > now + 29 * 60 * 1000, 'fallback TTL applies' );
} );

test( 'session: parseSetCookies returns null when no cookies are set', () => {
	assert.strictEqual( parseSetCookies( {} ), null );
	assert.strictEqual( parseSetCookies( { 'set-cookie': [] } ), null );
} );

test( 'session: parseCookieExpiry reads max-age and expires', () => {
	const maxAge = parseCookieExpiry( 'a=1; Path=/; Max-Age=120' );
	assert.ok( Math.abs( maxAge - ( Date.now() + 120 * 1000 ) ) < 5000, 'max-age respected' );

	const expires = parseCookieExpiry( 'a=1; Expires=Wed, 21 Oct 2037 07:28:00 GMT' );
	assert.strictEqual( expires, new Date( '2037-10-21T07:28:00Z' ).getTime(), 'expires respected' );

	assert.strictEqual( parseCookieExpiry( 'a=1; Path=/' ), null, 'no expiry attribute → null' );
} );
