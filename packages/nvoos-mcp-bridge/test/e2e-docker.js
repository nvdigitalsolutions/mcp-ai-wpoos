#!/usr/bin/env node
/**
 * Opt-in Docker E2E for @nvdigitalsolutions/nvoos-mcp-bridge.
 *
 * Skips (exit 0) unless both MCP_AI_BASE_URL and MCP_AI_TOKEN are set — point
 * them at a live NV oOS site (e.g. the docker compose dev site at
 * http://localhost:8000/wp-json/mcp-ai/v1/mcp) and this script completes an
 * initialize + tools/list handshake through the packaged relay.
 *
 * Run: npm run test:e2e
 */

'use strict';

const assert = require( 'node:assert' );
const path = require( 'node:path' );
const readline = require( 'node:readline' );
const { spawn } = require( 'node:child_process' );

const BASE_URL = ( process.env.MCP_AI_BASE_URL || '' ).trim();
const TOKEN = process.env.MCP_AI_TOKEN || '';

if ( ! BASE_URL || ! TOKEN ) {
	process.stderr.write( '[e2e] SKIP — set MCP_AI_BASE_URL and MCP_AI_TOKEN to run the Docker E2E.\n' );
	process.exit( 0 );
}

const RELAY = path.join( __dirname, '..', 'bin', 'mcp-bridge.js' );

/**
 * One-shot: send the two handshake requests, collect responses, assert.
 *
 * @returns {Promise<void>}
 */
async function main() {
	const child = spawn( process.execPath, [ RELAY ], {
		env: { ...process.env, MCP_AI_BASE_URL: BASE_URL, MCP_AI_TOKEN: TOKEN },
		stdio: [ 'pipe', 'pipe', 'pipe' ],
		windowsHide: true,
	} );

	const lines = [];
	const stderr = [];
	readline.createInterface( { input: child.stdout } ).on( 'line', ( l ) => lines.push( l ) );
	readline.createInterface( { input: child.stderr } ).on( 'line', ( l ) => stderr.push( l ) );

	child.stdin.write( JSON.stringify( {
		jsonrpc: '2.0',
		id: 1,
		method: 'initialize',
		params: { protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 'e2e-docker', version: '1.0' } },
	} ) + '\n' );
	child.stdin.write( JSON.stringify( {
		jsonrpc: '2.0',
		id: 2,
		method: 'tools/list',
		params: {},
	} ) + '\n' );
	child.stdin.end();

	const exit = new Promise( ( resolve ) => {
		child.once( 'exit', ( code, signal ) => resolve( { code, signal } ) );
	} );

	const deadline = Date.now() + 60000;
	while ( lines.length < 2 && Date.now() < deadline ) {
		await new Promise( ( r ) => setTimeout( r, 100 ) );
	}

	const init = lines.find( ( l ) => l.includes( '"id":1' ) );
	const list = lines.find( ( l ) => l.includes( '"id":2' ) );

	assert.ok( init, 'initialize response missing (stderr: ' + stderr.join( ' | ' ) + ')' );
	assert.ok( list, 'tools/list response missing (stderr: ' + stderr.join( ' | ' ) + ')' );

	const initBody = JSON.parse( init );
	const listBody = JSON.parse( list );

	assert.strictEqual( initBody.result.protocolVersion, '2025-03-26' );
	assert.ok( initBody.result.serverInfo && initBody.result.serverInfo.name, 'initialize must carry serverInfo.name' );
	assert.ok( Array.isArray( listBody.result.tools ), 'tools/list must return a tools array' );

	const { code } = await exit;
	assert.strictEqual( code, 0, 'relay must exit 0 after draining' );

	process.stderr.write(
		`[e2e] OK — ${ initBody.result.serverInfo.name } ${ initBody.result.serverInfo.version }: ` +
		`${ listBody.result.tools.length } tool(s) visible to this credential.\n`
	);
}

main().catch( ( err ) => {
	process.stderr.write( `[e2e] FAILED: ${ err.message }\n` );
	process.exit( 1 );
} );
