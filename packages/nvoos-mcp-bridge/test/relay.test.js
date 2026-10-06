'use strict';

/**
 * Relay tests for @nvdigitalsolutions/nvoos-mcp-bridge (bin/mcp-bridge.js).
 *
 * Spawns the relay against an in-process fake MCP endpoint (no real site, no
 * SSH) and exercises the transport contract: framing, id correlation,
 * notification suppression, HTTP pass-through vs transport-error mapping,
 * Host-header override, empty-body handling, stdin-EOF draining, and the
 * dispatcher's variant selection.
 *
 * Relay contract: the bridge is a transparent transport. HTTP responses
 * (any status) pass through with their body; only transport-level failures
 * (network error, timeout, non-JSON body) map to JSON-RPC -32603.
 *
 * Run: node --test test/relay.test.js
 */

const assert = require( 'node:assert' );
const http = require( 'node:http' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const readline = require( 'node:readline' );
const { spawn } = require( 'node:child_process' );
const { test } = require( 'node:test' );

const RELAY = path.join( __dirname, '..', 'bin', 'mcp-bridge.js' );
const DISPATCHER = path.join( __dirname, '..', 'bin', 'nvoos-mcp.js' );

// Hermeticity guard: never let a developer's real ~/.nvoos-bridge.env leak
// into a test spawn.
const NO_ENV_FILE = path.join( os.tmpdir(), 'nvoos-mcp-bridge-test-no-env-file.env' );

/**
 * Start a fake MCP endpoint that echoes the JSON-RPC payload back as a
 * result and records every request.
 *
 * @param {object} opts  `status` (HTTP code), `empty` (no body),
 *                       `badJson` (non-JSON body), `stallDelay` (respond
 *                       after N ms).
 * @returns {Promise<{port:number, requests:object[], close:Function}>}
 */
function startFakeMcp( opts = {} ) {
	const requests = [];
	const server = http.createServer( ( req, res ) => {
		let raw = '';
		req.on( 'data', ( chunk ) => { raw += chunk; } );
		req.on( 'end', () => {
			let payload = null;
			try {
				payload = JSON.parse( raw );
			} catch ( e ) {
				res.writeHead( 400 );
				res.end( 'not json' );
				return;
			}
			requests.push( { headers: req.headers, payload } );
			const envelope = {
				jsonrpc: '2.0',
				id: undefined === payload.id || null === payload.id ? null : payload.id,
				result: { echo: payload },
			};
			const send = () => {
				res.writeHead( opts.status || 200, { 'Content-Type': 'application/json' } );
				if ( opts.empty ) {
					res.end();
				} else if ( opts.badJson ) {
					res.end( 'this is not json' );
				} else {
					res.end( JSON.stringify( envelope ) );
				}
			};
			if ( opts.stallDelay ) {
				setTimeout( send, opts.stallDelay );
			} else {
				send();
			}
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
 * @param {string} script  Absolute path to the script to run.
 * @param {object} env     Extra env vars.
 * @param {string[]} args  Extra CLI arguments.
 * @returns {{child:object, lines:string[], stderr:string[], exit:Promise<object>}}
 */
function startBridge( script, env = {}, args = [] ) {
	const child = spawn( process.execPath, [ script, ...args ], {
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
 */
async function cleanup( bridge ) {
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
}

/**
 * Run a relay test body with guaranteed cleanup of the bridge and fake
 * endpoint, even when an assertion throws.
 *
 * @param {object} opts   `mcp` (fake endpoint opts), `env`, `args`, `script`.
 * @param {Function} fn   Async body: (bridge, mcp) => Promise<void>.
 * @returns {Promise<void>}
 */
async function withRelay( opts, fn ) {
	const mcp = await startFakeMcp( opts.mcp || {} );
	const bridge = startBridge(
		opts.script || RELAY,
		{ MCP_AI_BASE_URL: `http://127.0.0.1:${ mcp.port }`, MCP_AI_TOKEN: 'cred_test.SECRET', ...opts.env },
		opts.args || []
	);
	try {
		await fn( bridge, mcp );
	} finally {
		await cleanup( bridge );
		await mcp.close();
	}
}

// ── Tests ────────────────────────────────────────────────────────────────────

test( 'relay: initialize + tools/call roundtrip with correct ids', async () => {
	await withRelay( {}, async ( bridge, mcp ) => {
		send( bridge.child, { jsonrpc: '2.0', method: 'initialize', params: {}, id: 1 } );
		const init = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":1' ) ) );
		assert.strictEqual( init.result.echo.method, 'initialize' );

		send( bridge.child, { jsonrpc: '2.0', method: 'tools/call', params: { name: 'x' }, id: 2 } );
		const call = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":2' ) ) );
		assert.strictEqual( call.result.echo.method, 'tools/call' );

		// Bearer token must travel as a header on every request.
		assert.strictEqual( mcp.requests[ 0 ].headers.authorization, 'Bearer cred_test.SECRET' );
		assert.strictEqual( mcp.requests[ 1 ].headers.authorization, 'Bearer cred_test.SECRET' );
	} );
} );

test( 'relay: notifications get no stdout response, even though the server echoes one', async () => {
	await withRelay( {}, async ( bridge ) => {
		send( bridge.child, { jsonrpc: '2.0', method: 'notifications/initialized' } );
		// The next response line must be the request below, not an echo of the
		// notification — deterministic without relying on timeouts.
		send( bridge.child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 7 } );
		const line = await waitForLine( bridge.lines, ( l ) => l.includes( '"id":7' ) );
		assert.ok( line.includes( 'tools/list' ) );
		assert.strictEqual( bridge.lines.length, 1, 'only the request response may be emitted' );
	} );
} );

test( 'relay: malformed input answers with a JSON-RPC parse error', async () => {
	await withRelay( {}, async ( bridge, mcp ) => {
		bridge.child.stdin.write( 'this is not json\n' );
		const line = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '-32700' ) ) );
		assert.strictEqual( line.error.code, -32700 );
		assert.strictEqual( line.id, null );
		assert.strictEqual( mcp.requests.length, 0, 'no HTTP request may leave the relay for garbage input' );
	} );
} );

test( 'relay: HTTP 500 with a JSON body passes through unchanged (transparent transport)', async () => {
	await withRelay( { mcp: { status: 500 } }, async ( bridge ) => {
		send( bridge.child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 11 } );
		const line = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":11' ) ) );
		assert.strictEqual( line.id, 11 );
		assert.strictEqual( line.result.echo.method, 'tools/list' );
	} );
} );

test( 'relay: non-JSON HTTP body maps to a JSON-RPC internal error', async () => {
	await withRelay( { mcp: { badJson: true } }, async ( bridge ) => {
		send( bridge.child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 12 } );
		const line = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":12' ) ) );
		assert.strictEqual( line.error.code, -32603 );
		assert.ok( line.error.message.includes( 'Non-JSON' ) );
	} );
} );

test( 'relay: connection refused maps to a JSON-RPC internal error', async () => {
	// Grab a port that is (very likely) closed right now and point the relay
	// at it — the transport failure must surface as -32603.
	const probe = http.createServer( () => {} );
	await new Promise( ( resolve ) => probe.listen( 0, '127.0.0.1', resolve ) );
	const deadPort = probe.address().port;
	await new Promise( ( resolve ) => probe.close( resolve ) );

	const bridge = startBridge( RELAY, {
		MCP_AI_BASE_URL: `http://127.0.0.1:${ deadPort }`,
		MCP_AI_TOKEN: 'cred_test.SECRET',
	} );
	try {
		send( bridge.child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 15 } );
		const line = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":15' ) ) );
		assert.strictEqual( line.error.code, -32603 );
	} finally {
		await cleanup( bridge );
	}
} );

test( 'relay: empty HTTP body (e.g. 202) becomes an empty result', async () => {
	await withRelay( { mcp: { status: 202, empty: true } }, async ( bridge ) => {
		send( bridge.child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 13 } );
		const line = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":13' ) ) );
		assert.deepStrictEqual( line.result, {} );
	} );
} );

test( 'relay: MCP_AI_HOST_HEADER overrides the HTTP Host header', async () => {
	await withRelay( { env: { MCP_AI_HOST_HEADER: 'canonical.example.com' } }, async ( bridge, mcp ) => {
		send( bridge.child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 14 } );
		await waitForLine( bridge.lines, ( l ) => l.includes( '"id":14' ) );
		assert.strictEqual( mcp.requests[ 0 ].headers.host, 'canonical.example.com' );
	} );
} );

test( 'relay: drains in-flight request after stdin closes', async () => {
	await withRelay( { mcp: { stallDelay: 800 } }, async ( bridge ) => {
		send( bridge.child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 16 } );
		bridge.child.stdin.end(); // EOF while the request is still in flight

		const line = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":16' ) ) );
		assert.strictEqual( line.result.echo.id, 16 );
		const { code } = await bridge.exit;
		assert.strictEqual( code, 0 );
	} );
} );

test( 'dispatcher: no args selects the HTTPS relay, "ssh" selects the SSH variant', async () => {
	await withRelay( { script: DISPATCHER }, async ( bridge ) => {
		send( bridge.child, { jsonrpc: '2.0', method: 'initialize', params: {}, id: 20 } );
		const init = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":20' ) ) );
		assert.strictEqual( init.result.echo.method, 'initialize' );
	} );

	// "ssh" with no SSH env (and no reachable env file) must fail fast with a
	// clear diagnostic on stderr instead of touching a real ssh binary.
	const ssh = startBridge( DISPATCHER, {}, [ 'ssh' ] );
	try {
		const { code } = await ssh.exit;
		assert.notStrictEqual( code, 0, 'ssh variant without MCP_AI_SSH_USER must exit non-zero' );
		assert.ok(
			ssh.stderr.join( '\n' ).includes( 'MCP_AI_SSH_USER' ),
			'stderr must name the missing SSH configuration'
		);
	} finally {
		await cleanup( ssh );
	}
} );
