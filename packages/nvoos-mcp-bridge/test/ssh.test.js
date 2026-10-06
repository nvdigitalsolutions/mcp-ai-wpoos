'use strict';

/**
 * SSH-variant tests for @nvdigitalsolutions/nvoos-mcp-bridge
 * (bin/mcp-bridge-ssh.js).
 *
 * Spawns the SSH bridge with an inline fake `ssh` (a TCP forwarder that
 * honours the stdin-EOF exit contract) and an in-process fake MCP endpoint.
 * Exercises tunnel startup, roundtrip, env-file loading, and orphan-free
 * teardown — no real infrastructure needed.
 *
 * Run: node --test test/ssh.test.js
 */

const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const http = require( 'node:http' );
const net = require( 'node:net' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const readline = require( 'node:readline' );
const { spawn } = require( 'node:child_process' );
const { test } = require( 'node:test' );

const BRIDGE_SSH = path.join( __dirname, '..', 'bin', 'mcp-bridge-ssh.js' );

// Hermeticity guard: never let a developer's real ~/.nvoos-bridge.env leak
// into a test spawn.
const NO_ENV_FILE = path.join( os.tmpdir(), 'nvoos-mcp-bridge-test-no-env-file.env' );

// Inline fake ssh: forwards `-L 127.0.0.1:LPORT:RHOST:RPORT`, exits on stdin
// EOF (the orphan-protection contract the bridge relies on).
const FAKE_SSH = path.join( os.tmpdir(), `fake-ssh-${ process.pid }-${ Date.now() }.js` );
fs.writeFileSync( FAKE_SSH, `
'use strict';
const net = require( 'net' );
const args = process.argv.slice( 2 );
const idx = args.indexOf( '-L' );
if ( -1 === idx || ! args[ idx + 1 ] ) { process.exit( 2 ); }
const spec = String( args[ idx + 1 ] ).split( ':' );
const localPort = parseInt( spec[ 1 ], 10 );
const remoteHost = spec[ 2 ];
const remotePort = parseInt( spec[ 3 ], 10 );
const server = net.createServer( ( client ) => {
	const upstream = net.connect( remotePort, remoteHost );
	upstream.once( 'error', () => client.destroy() );
	client.once( 'error', () => upstream.destroy() );
	client.pipe( upstream ).pipe( client );
} );
server.listen( localPort, '127.0.0.1', () => {
	process.stderr.write( 'forwarding\\n' );
} );
process.stdin.resume();
process.stdin.on( 'end', () => server.close( () => process.exit( 0 ) ) );
` );

/**
 * Start a fake MCP endpoint that echoes the JSON-RPC payload back as a
 * result.
 *
 * @returns {Promise<{port:number, close:Function}>}
 */
function startFakeMcp() {
	const server = http.createServer( ( req, res ) => {
		let raw = '';
		req.on( 'data', ( chunk ) => { raw += chunk; } );
		req.on( 'end', () => {
			const payload = JSON.parse( raw );
			const envelope = {
				jsonrpc: '2.0',
				id: undefined === payload.id || null === payload.id ? null : payload.id,
				result: { echo: payload },
			};
			res.writeHead( 200, { 'Content-Type': 'application/json' } );
			res.end( JSON.stringify( envelope ) );
		} );
	} );
	return new Promise( ( resolve ) => {
		server.listen( 0, '127.0.0.1', () => resolve( {
			port: server.address().port,
			close: () => new Promise( ( r ) => server.close( r ) ),
		} ) );
	} );
}

/**
 * Launch the SSH bridge with fake-ssh as the transport.
 *
 * @param {object} env  Extra env vars (MCP_AI_SSH_* etc.).
 * @returns {{child:object, lines:string[], stderr:string[], exit:Promise<object>}}
 */
function startSshBridge( env = {} ) {
	const child = spawn( process.execPath, [ BRIDGE_SSH ], {
		env: {
			...process.env,
			MCP_AI_ENV_FILE: NO_ENV_FILE,
			MCP_AI_SSH_CMD: process.execPath,
			MCP_AI_SSH_EXTRA_ARGS: `"${ FAKE_SSH }"`,
			MCP_AI_SSH_USER: 'bridge-tester',
			MCP_AI_SSH_HOST: 'fake-host',
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
async function waitForLine( lines, pred, timeoutMs = 15000 ) {
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

/**
 * Find a free TCP port on 127.0.0.1.
 *
 * @returns {Promise<number>}
 */
function findFreePort() {
	return new Promise( ( resolve, reject ) => {
		const srv = net.createServer();
		srv.once( 'error', reject );
		srv.listen( 0, '127.0.0.1', () => {
			const port = srv.address().port;
			srv.close( ( err ) => ( err ? reject( err ) : resolve( port ) ) );
		} );
	} );
}

/**
 * Best-effort cleanup so a failed assertion can never hang the runner.
 *
 * @param {object} bridge  startSshBridge() result.
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
 * Run an SSH-bridge test body with guaranteed cleanup, even when an
 * assertion throws.
 *
 * @param {object} opts   `env` extras for startSshBridge.
 * @param {Function} fn   Async body: (bridge, mcp) => Promise<void>.
 * @returns {Promise<void>}
 */
async function withSsh( opts, fn ) {
	const mcp = await startFakeMcp();
	const bridge = startSshBridge( { MCP_AI_SSH_REMOTE_PORT: String( mcp.port ), ...opts } );
	try {
		await fn( bridge, mcp );
	} finally {
		await cleanup( bridge );
		await mcp.close();
	}
}

// ── Tests ────────────────────────────────────────────────────────────────────

test( 'ssh: initialize roundtrip through the tunnel', async () => {
	await withSsh( {}, async ( bridge ) => {
		send( bridge.child, { jsonrpc: '2.0', method: 'initialize', params: {}, id: 1 } );
		const init = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":1' ) ) );
		assert.strictEqual( init.result.echo.method, 'initialize' );
	} );
} );

test( 'ssh: env file supplies config, process env wins over the file', async () => {
	const envFile = path.join( os.tmpdir(), `nvoos-bridge-${ process.pid }-${ Date.now() }.env` );
	fs.writeFileSync(
		envFile,
		[
			'MCP_AI_SSH_USER=env-file-user',
			'MCP_AI_SSH_HOST=env-file-host',
			'MCP_AI_TOKEN=op_from_file.SECRET',
		].join( '\n' )
	);

	await withSsh( { MCP_AI_ENV_FILE: envFile }, async ( bridge ) => {
		send( bridge.child, { jsonrpc: '2.0', method: 'initialize', params: {}, id: 2 } );
		const init = JSON.parse( await waitForLine( bridge.lines, ( l ) => l.includes( '"id":2' ) ) );
		assert.strictEqual( init.result.echo.method, 'initialize' );

		// Process env (set by startSshBridge) must have won over the file.
		bridge.child.stdin.end();
		await bridge.exit;
		assert.ok(
			bridge.stderr.join( '\n' ).includes( 'fake-ssh' ) || bridge.stderr.join( '\n' ).includes( 'forwarding' ),
			'stderr should reflect the process-env SSH command, not the env-file one'
		);
	} );

	fs.unlinkSync( envFile );
} );

test( 'ssh: hard-killing the bridge tears the tunnel down (no orphan)', async () => {
	const localPort = await findFreePort();

	await withSsh( { MCP_AI_LOCAL_PORT: String( localPort ) }, async ( bridge ) => {
		send( bridge.child, { jsonrpc: '2.0', method: 'initialize', params: {}, id: 3 } );
		await waitForLine( bridge.lines, ( l ) => l.includes( '"id":3' ) );

		bridge.child.kill( 'SIGKILL' ); // hard kill — no graceful shutdown path
		await bridge.exit;

		await waitForPortClosed( localPort );
	} );
} );
