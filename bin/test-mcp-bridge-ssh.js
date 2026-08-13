#!/usr/bin/env node
/**
 * Tests for the SSH MCP bridge (bin/mcp-bridge-ssh.js + bin/mcp-bridge.js).
 *
 * Uses bin/utils/fake-ssh.js as a stand-in for OpenSSH and an in-process
 * HTTP server as a stand-in for the NV oOS MCP endpoint, so the whole
 * orchestration path — tunnel startup, port probing, relay delegation,
 * notification suppression, timeout errors, and tunnel teardown — is
 * exercised without any real infrastructure.
 *
 * Run:  node bin/test-mcp-bridge-ssh.js
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const http = require( 'node:http' );
const net = require( 'node:net' );
const path = require( 'node:path' );
const readline = require( 'node:readline' );
const { spawn } = require( 'node:child_process' );

const BRIDGE_SSH = path.join( __dirname, 'mcp-bridge-ssh.js' );
const BRIDGE = path.join( __dirname, 'mcp-bridge.js' );
const FAKE_SSH = path.join( __dirname, 'utils', 'fake-ssh.js' );
const { parseEnvFile, splitArgs } = require( BRIDGE_SSH );

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Start a fake MCP endpoint that echoes the JSON-RPC payload back as a
 * result, records every request, and can be told to stall or reply empty.
 *
 * @param {object} opts          `stall` (never respond), `empty` (202, no
 *                               body), `stallDelay` (respond after N ms).
 * @returns {Promise<{port:number, requests:object[], close:Function}>}
 */
function startFakeMcp( opts = {} ) {
	const requests = [];
	const server = http.createServer( ( req, res ) => {
		let raw = '';
		req.on( 'data', ( chunk ) => { raw += chunk; } );
		req.on( 'end', () => {
			requests.push( { headers: req.headers, body: JSON.parse( raw || '{}' ) } );
			if ( opts.stall ) {
				return; // Never respond — exercises the relay timeout.
			}
			if ( opts.empty ) {
				res.writeHead( 202, { 'Content-Type': 'application/json' } );
				res.end();
				return;
			}
			const payload = requests[ requests.length - 1 ].body;
			const envelope = {
				jsonrpc: '2.0',
				id: undefined === payload.id || null === payload.id ? null : payload.id,
				result: { echo: payload },
			};
			const send = () => {
				res.writeHead( 200, { 'Content-Type': 'application/json' } );
				res.end( JSON.stringify( envelope ) );
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
 * Launch the SSH bridge with fake-ssh as the transport.
 *
 * @param {object} env   Extra env vars (MCP_AI_SSH_* etc.).
 * @returns {Promise<{child:object, lines:string[], stderr:string[], exit:Promise<{code,signal}>}>}
 */
function startBridge( env = {} ) {
	const child = spawn( process.execPath, [ BRIDGE_SSH ], {
		env: {
			...process.env,
			MCP_AI_SSH_CMD: process.execPath,
			MCP_AI_SSH_EXTRA_ARGS: `"${ FAKE_SSH }"`,
			MCP_AI_SSH_USER: 'bridge-tester',
			MCP_AI_SSH_HOST: 'fake-host',
			MCP_AI_SSH_REMOTE_PORT: String( env.MCP_AI_SSH_REMOTE_PORT || '' ),
			MCP_AI_TOKEN: 'op_test.SECRET',
			...env,
		},
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

function send( child, obj ) {
	child.stdin.write( JSON.stringify( obj ) + '\n' );
}

function canConnect( port ) {
	return new Promise( ( resolve ) => {
		const sock = net.connect( { host: '127.0.0.1', port } );
		sock.once( 'connect', () => {
			sock.destroy();
			resolve( true );
		} );
		sock.once( 'error', () => resolve( false ) );
	} );
}

async function waitForPortClosed( port, timeoutMs = 8000 ) {
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		if ( ! ( await canConnect( port ) ) ) {
			return;
		}
		await new Promise( ( r ) => setTimeout( r, 100 ) );
	}
	throw new Error( `Port ${ port } is still open after ${ timeoutMs }ms.` );
}

// ── Unit tests ───────────────────────────────────────────────────────────────

test( 'parseEnvFile skips comments/blanks and strips quotes', () => {
	const parsed = parseEnvFile( [
		'# comment',
		'',
		'MCP_AI_TOKEN=op_abc.SECRET',
		'MCP_AI_SSH_PORT="2222"',
		"KEY_WITH_SPACE='a b c'",
		'no_equals_sign',
	].join( '\n' ) );
	assert.deepStrictEqual( parsed, {
		MCP_AI_TOKEN: 'op_abc.SECRET',
		MCP_AI_SSH_PORT: '2222',
		KEY_WITH_SPACE: 'a b c',
	} );
} );

test( 'splitArgs honours quoted values with spaces', () => {
	assert.deepStrictEqual(
		splitArgs( '-i "C:/my keys/id_ed25519" -o ProxyJump=bastion' ),
		[ '-i', 'C:/my keys/id_ed25519', '-o', 'ProxyJump=bastion' ]
	);
	assert.deepStrictEqual( splitArgs( '' ), [] );
} );

// ── Integration tests ────────────────────────────────────────────────────────

test( 'end-to-end: initialize + tools/call roundtrip through the tunnel', async () => {
	const mcp = await startFakeMcp();
	const { child, lines, exit } = startBridge( { MCP_AI_SSH_REMOTE_PORT: String( mcp.port ) } );

	send( child, { jsonrpc: '2.0', method: 'initialize', params: {}, id: 1 } );
	const init = JSON.parse( await waitForLine( lines, ( l ) => l.includes( '"id":1' ) ) );
	assert.strictEqual( init.result.echo.method, 'initialize' );

	send( child, { jsonrpc: '2.0', method: 'tools/call', params: { name: 'x' }, id: 2 } );
	const call = JSON.parse( await waitForLine( lines, ( l ) => l.includes( '"id":2' ) ) );
	assert.strictEqual( call.result.echo.method, 'tools/call' );

	// Authorization header travelled end-to-end.
	assert.ok( mcp.requests.every( ( r ) => 'Bearer op_test.SECRET' === r.headers.authorization ) );

	child.stdin.end();
	const { code } = await exit;
	assert.strictEqual( code, 0 );
	await mcp.close();
} );

test( 'notifications get no stdout response, even though the server echoes one', async () => {
	const mcp = await startFakeMcp();
	const { child, lines, exit } = startBridge( { MCP_AI_SSH_REMOTE_PORT: String( mcp.port ) } );

	send( child, { jsonrpc: '2.0', method: 'notifications/initialized' } );
	// The next response line must be the request below, not an echo of the
	// notification — deterministic without relying on timeouts.
	send( child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 7 } );
	const line = await waitForLine( lines, ( l ) => l.includes( '"id":7' ) );
	assert.ok( line.includes( 'tools/list' ) );
	assert.strictEqual( lines.length, 1, 'only the request response may be emitted' );

	child.stdin.end();
	await exit;
	await mcp.close();
} );

test( 'hard-killing the bridge tears the tunnel down (no orphan)', async () => {
	const mcp = await startFakeMcp();
	const { child, stderr, exit } = startBridge( { MCP_AI_SSH_REMOTE_PORT: String( mcp.port ) } );

	// Wait until the tunnel is up, then recover the forwarded port from the
	// orchestrator's own log line.
	const readyDeadline = Date.now() + 10000;
	let port = null;
	while ( Date.now() < readyDeadline && null === port ) {
		const match = stderr.find( ( l ) => /tunnel ready on 127\.0\.0\.1:(\d+)/.test( l ) );
		if ( match ) {
			port = parseInt( /tunnel ready on 127\.0\.0\.1:(\d+)/.exec( match )[ 1 ], 10 );
		} else {
			await new Promise( ( r ) => setTimeout( r, 50 ) );
		}
	}
	assert.ok( port, 'bridge should log the forwarded port' );
	assert.ok( await canConnect( port ), 'tunnel should be up before the kill' );

	process.kill( child.pid, 'SIGKILL' );
	await exit;

	// The fake ssh should have received EOF on its stdin (the pipe's write
	// end died with the bridge) and closed the forward.
	await waitForPortClosed( port );
	await mcp.close();
} );

test( 'relay surfaces timeouts as JSON-RPC errors instead of hanging', async () => {
	const mcp = await startFakeMcp( { stall: true } );
	const child = spawn( process.execPath, [ BRIDGE ], {
		env: {
			...process.env,
			MCP_AI_BASE_URL: `http://127.0.0.1:${ mcp.port }/wp-json/mcp-ai/v1/mcp`,
			MCP_AI_TOKEN: 'op_test.SECRET',
			MCP_AI_HTTP_TIMEOUT: '300',
		},
		stdio: [ 'pipe', 'pipe', 'pipe' ],
		windowsHide: true,
	} );
	const lines = [];
	readline.createInterface( { input: child.stdout } ).on( 'line', ( l ) => lines.push( l ) );
	const exit = new Promise( ( resolve ) => child.once( 'exit', ( code, signal ) => resolve( { code, signal } ) ) );

	send( child, { jsonrpc: '2.0', method: 'tools/call', params: {}, id: 5 } );
	const line = await waitForLine( lines, ( l ) => l.includes( '"id":5' ) );
	const envelope = JSON.parse( line );
	assert.strictEqual( envelope.error.code, -32603 );
	assert.ok( envelope.error.message.includes( 'timed out' ) );

	child.stdin.end();
	await exit;
	await mcp.close();
} );

test( 'relay drains in-flight request after stdin closes', async () => {
	// Stall the endpoint so the response lands after stdin EOF.
	const mcp = await startFakeMcp( { stallDelay: 400 } );
	const child = spawn( process.execPath, [ BRIDGE ], {
		env: {
			...process.env,
			MCP_AI_BASE_URL: `http://127.0.0.1:${ mcp.port }/wp-json/mcp-ai/v1/mcp`,
			MCP_AI_TOKEN: 'op_test.SECRET',
		},
		stdio: [ 'pipe', 'pipe', 'pipe' ],
		windowsHide: true,
	} );
	const lines = [];
	readline.createInterface( { input: child.stdout } ).on( 'line', ( l ) => lines.push( l ) );
	const exit = new Promise( ( resolve ) => child.once( 'exit', ( code, signal ) => resolve( { code, signal } ) ) );

	send( child, { jsonrpc: '2.0', method: 'tools/call', params: {}, id: 8 } );
	child.stdin.end();

	const line = await waitForLine( lines, ( l ) => l.includes( '"id":8' ) );
	assert.strictEqual( JSON.parse( line ).result.echo.id, 8 );
	const { code } = await exit;
	assert.strictEqual( code, 0 );
	await mcp.close();
} );

test( 'relay maps empty HTTP bodies to empty results', async () => {
	const mcp = await startFakeMcp( { empty: true } );
	const child = spawn( process.execPath, [ BRIDGE ], {
		env: {
			...process.env,
			MCP_AI_BASE_URL: `http://127.0.0.1:${ mcp.port }/wp-json/mcp-ai/v1/mcp`,
			MCP_AI_TOKEN: 'op_test.SECRET',
		},
		stdio: [ 'pipe', 'pipe', 'pipe' ],
		windowsHide: true,
	} );
	const lines = [];
	readline.createInterface( { input: child.stdout } ).on( 'line', ( l ) => lines.push( l ) );
	const exit = new Promise( ( resolve ) => child.once( 'exit', ( code, signal ) => resolve( { code, signal } ) ) );

	send( child, { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 6 } );
	const line = await waitForLine( lines, ( l ) => l.includes( '"id":6' ) );
	assert.deepStrictEqual( JSON.parse( line ), { jsonrpc: '2.0', id: 6, result: {} } );

	child.stdin.end();
	await exit;
	await mcp.close();
} );
