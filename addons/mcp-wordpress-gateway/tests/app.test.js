/**
 * Integration tests for the gateway HTTP surface: health, auth rejection,
 * body limits, pass-through, and unavailable-handler behavior. Tool policy
 * is enforced at MCP registration time (see tool-policy.test.js and the
 * end-to-end smoke test).
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { once } from 'node:events';
import { createApp } from '../src/app.js';

const TOKEN = 't'.repeat( 40 );
const OTHER_TOKEN = 'o'.repeat( 40 );

/**
 * Start the gateway app with a mock MCP handler.
 *
 * @param {object} [overrides] Config overrides.
 * @param {Function} [handle]  Mock handleMcpRequest.
 * @returns {Promise<{ app: import('http').Server, port: number }>}
 */
async function startGateway( overrides = {}, handle ) {
	const config = {
		valid: true,
		token: TOKEN,
		previousToken: '',
		port: 0,
		allow: [ '*' ],
		deny: [],
		logLevel: 'none',
		...overrides,
	};

	const mockHandler =
		handle ??
		( async ( req, res ) => {
			res.status( 200 ).json( { jsonrpc: '2.0', id: 1, result: { handled: true } } );
		} );

	const app = createApp( config, { handleMcpRequest: mockHandler } );
	const listener = app.listen( 0, '127.0.0.1' );
	await once( listener, 'listening' );

	return { app: listener, port: listener.address().port };
}

async function post( port, path, body, headers = {} ) {
	const response = await fetch( `http://127.0.0.1:${ port }${ path }`, {
		method: 'POST',
		headers: { 'content-type': 'application/json', ...headers },
		body: JSON.stringify( body ),
	} );

	return {
		status: response.status,
		body: await response.json().catch( () => null ),
	};
}

test( 'healthz is public and minimal', async () => {
	const { app, port } = await startGateway();

	const response = await fetch( `http://127.0.0.1:${ port }/healthz` );
	assert.equal( response.status, 200 );

	const body = await response.json();
	assert.equal( body.status, 'ok' );
	assert.equal( body.service, 'mcp-wordpress-gateway' );
	assert.equal( 'token' in body, false );

	await new Promise( ( resolve ) => app.close( resolve ) );
} );

test( 'mcp rejects missing and wrong tokens with 401', async () => {
	const { app, port } = await startGateway();

	const missing = await post( port, '/mcp', { jsonrpc: '2.0', id: 1, method: 'tools/list' } );
	assert.equal( missing.status, 401 );

	const wrong = await post( port, '/mcp', { jsonrpc: '2.0', id: 1, method: 'tools/list' }, { 'X-MCP-Token': OTHER_TOKEN } );
	assert.equal( wrong.status, 401 );

	await new Promise( ( resolve ) => app.close( resolve ) );
} );

test( 'the rotation-window previous token is accepted', async () => {
	const { app, port } = await startGateway( { previousToken: OTHER_TOKEN } );

	const result = await post(
		port,
		'/mcp',
		{ jsonrpc: '2.0', id: 2, method: 'tools/list' },
		{ 'X-MCP-Token': OTHER_TOKEN }
	);
	assert.equal( result.status, 200 );

	await new Promise( ( resolve ) => app.close( resolve ) );
} );

test( 'authenticated requests pass through to the MCP handler', async () => {
	const { app, port } = await startGateway();

	const result = await post(
		port,
		'/mcp',
		{ jsonrpc: '2.0', id: 3, method: 'tools/list' },
		{ 'X-MCP-Token': TOKEN }
	);
	assert.equal( result.status, 200 );
	assert.deepEqual( result.body, { jsonrpc: '2.0', id: 1, result: { handled: true } } );

	await new Promise( ( resolve ) => app.close( resolve ) );
} );

test( 'returns 502 when no MCP handler is wired', async () => {
	const config = { valid: true, token: TOKEN, previousToken: '', port: 0, allow: [ '*' ], deny: [], logLevel: 'none' };
	const app = createApp( config );
	const listener = app.listen( 0, '127.0.0.1' );
	await once( listener, 'listening' );

	const result = await post(
		listener.address().port,
		'/mcp',
		{ jsonrpc: '2.0', id: 4, method: 'tools/list' },
		{ 'X-MCP-Token': TOKEN }
	);
	assert.equal( result.status, 502 );
	assert.equal( result.body.error, 'gateway_unavailable' );

	await new Promise( ( resolve ) => listener.close( resolve ) );
} );

test( 'oversized bodies are rejected with 413', async () => {
	const { app, port } = await startGateway();

	const response = await fetch( `http://127.0.0.1:${ port }/mcp`, {
		method: 'POST',
		headers: { 'content-type': 'application/json', 'X-MCP-Token': TOKEN },
		body: JSON.stringify( { jsonrpc: '2.0', id: 5, method: 'tools/call', params: { padding: 'x'.repeat( 1024 * 1024 + 1 ) } } ),
	} );
	assert.equal( response.status, 413 );

	await new Promise( ( resolve ) => app.close( resolve ) );
} );
