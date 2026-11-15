#!/usr/bin/env node
/**
 * Tests for the plain MCP bridge (bin/mcp-bridge.js).
 *
 * Uses an in-process HTTP server as a stand-in for the NV oOS MCP endpoint
 * so the config-loading contract is exercised without real infrastructure:
 * config must come from MCP_AI_ENV_FILE when the process env is empty
 * (env-file-only launches are exactly how Zed spawns this server), explicit
 * process env must win, and a missing config must exit 1 with a clear error.
 *
 * Run:  node bin/test-mcp-bridge.js
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const http = require( 'node:http' );
const os = require( 'node:os' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const readline = require( 'node:readline' );
const { spawn } = require( 'node:child_process' );

const BRIDGE = path.join( __dirname, 'mcp-bridge.js' );

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Start a fake MCP endpoint that records every request and echoes the
 * JSON-RPC payload back as a result.
 *
 * @returns {Promise<{port:number, requests:object[], close:Function}>}
 */
function startFakeMcp() {
	const requests = [];
	const server = http.createServer( ( req, res ) => {
		let raw = '';
		req.on( 'data', ( chunk ) => { raw += chunk; } );
		req.on( 'end', () => {
			requests.push( { headers: req.headers, body: JSON.parse( raw || '{}' ) } );
			const payload = requests[ requests.length - 1 ].body;
			res.writeHead( 200, { 'Content-Type': 'application/json' } );
			res.end( JSON.stringify( {
				jsonrpc: '2.0',
				id: undefined === payload.id || null === payload.id ? null : payload.id,
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
 * Launch the plain bridge with the MCP_AI_* vars scrubbed from the inherited
 * environment (so the env file / explicit overrides are the only source).
 *
 * @param {object} env   Extra env vars (MCP_AI_*).
 * @returns {object}     { child, lines, stderr, exit }
 */
function startBridge( env = {} ) {
	const scrubbed = {};
	for ( const [ key, value ] of Object.entries( process.env ) ) {
		if ( ! /^MCP_AI_/i.test( key ) ) {
			scrubbed[ key ] = value;
		}
	}
	const child = spawn( process.execPath, [ BRIDGE ], {
		env: { ...scrubbed, ...env },
		stdio: [ 'pipe', 'pipe', 'pipe' ],
		windowsHide: true,
	} );
	const lines = [];
	const stderr = [];
	const rl = readline.createInterface( { input: child.stdout } );
	rl.on( 'line', ( line ) => lines.push( line ) );
	child.stderr.on( 'data', ( chunk ) => stderr.push( String( chunk ) ) );
	const exit = new Promise( ( resolve ) => child.on( 'close', ( code, signal ) => resolve( { code, signal } ) ) );
	return { child, lines, stderr, exit };
}

/** Feed one JSON-RPC request line and wait for the matching response line. */
function exchange( bridge, id, payload ) {
	bridge.child.stdin.write( JSON.stringify( payload ) + '\n' );
	return new Promise( ( resolve, reject ) => {
		const timer = setTimeout( () => reject( new Error( 'timed out waiting for response' ) ), 10000 );
		const poll = setInterval( () => {
			for ( const line of bridge.lines ) {
				try {
					const parsed = JSON.parse( line );
					if ( parsed.id === id || ( null === id && null === parsed.id ) ) {
						clearTimeout( timer );
						clearInterval( poll );
						resolve( parsed );
						return;
					}
				} catch ( e ) {
					// Not JSON yet — keep polling.
				}
			}
		}, 25 );
	} );
}

// ── Tests ────────────────────────────────────────────────────────────────────

test( 'config comes from the env file when process env is empty', async () => {
	// Regression: config used to be read at module scope, before
	// loadEnvFile() ran — env-file-only launches (how Zed spawns us) failed.
	const fake = await startFakeMcp();
	const envFile = path.join( os.tmpdir(), `mcp-bridge-test-${ process.pid }.env` );
	fs.writeFileSync( envFile, [
		`MCP_AI_BASE_URL=http://127.0.0.1:${ fake.port }/mcp`,
		'MCP_AI_TOKEN=op_file.SECRET',
	].join( '\n' ) );

	const bridge = startBridge( { MCP_AI_ENV_FILE: envFile } );
	const payload = {
		jsonrpc: '2.0',
		id: 1,
		method: 'initialize',
		params: { protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 't', version: '1' } },
	};
	const response = await exchange( bridge, 1, payload );

	assert.strictEqual( response.result.echo.method, 'initialize' );
	assert.strictEqual( fake.requests.length, 1 );
	assert.strictEqual( fake.requests[ 0 ].headers.authorization, 'Bearer op_file.SECRET' );

	bridge.child.stdin.end();
	await bridge.exit;
	await fake.close();
	fs.unlinkSync( envFile );
} );

test( 'explicit process env wins over the env file', async () => {
	const fake = await startFakeMcp();
	const envFile = path.join( os.tmpdir(), `mcp-bridge-test-${ process.pid }.env` );
	fs.writeFileSync( envFile, [
		`MCP_AI_BASE_URL=http://127.0.0.1:${ fake.port }/mcp`,
		'MCP_AI_TOKEN=op_file.SECRET',
	].join( '\n' ) );

	const bridge = startBridge( {
		MCP_AI_ENV_FILE: envFile,
		MCP_AI_BASE_URL: `http://127.0.0.1:${ fake.port }/mcp`,
		MCP_AI_TOKEN: 'op_env.SECRET',
	} );
	await exchange( bridge, 1, {
		jsonrpc: '2.0',
		id: 1,
		method: 'initialize',
		params: { protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 't', version: '1' } },
	} );

	assert.strictEqual( fake.requests[ 0 ].headers.authorization, 'Bearer op_env.SECRET' );

	bridge.child.stdin.end();
	await bridge.exit;
	await fake.close();
	fs.unlinkSync( envFile );
} );

test( 'missing config exits 1 with a clear error', async () => {
	const bridge = startBridge();
	const { code } = await bridge.exit;
	assert.strictEqual( code, 1 );
	assert.ok( bridge.stderr.join( '' ).includes( 'MCP_AI_BASE_URL is not set' ) );
} );
