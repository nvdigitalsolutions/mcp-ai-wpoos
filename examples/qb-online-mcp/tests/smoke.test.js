// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * End-to-end smoke tests over a real socket: inbound auth, health, and the
 * legacy (claim-less) MCP handshake + tools/list.
 *
 * Intuit is never called — these cover the wire contract and tool registry
 * only. Token rotation is covered separately in oauth.test.js.
 */

import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { createApp } from '../src/app.js';

const TOKEN = 'test-token-0123456789';

const config = {
	clientId: 'test-client-id',
	clientSecret: 'test-client-secret',
	realmId: '193514562011054',
	refreshToken: 'test-refresh-token',
	environment: 'sandbox',
	baseUrl: 'https://sandbox-quickbooks.api.intuit.com/v3',
	stateFile: join( tmpdir(), `qbo-state-${ randomUUID() }.json` ),
	inboundToken: TOKEN,
	allowAnonymous: false,
	port: 0,
	serverName: 'quickbooks-online-mcp',
	serverVersion: '0.1.0',
};

let server;
let base;

before( async () => {
	const app = await createApp( config );
	server = app.server;

	await new Promise( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
	base = `http://127.0.0.1:${ server.address().port }`;
} );

after( () => new Promise( ( resolve ) => server.close( resolve ) ) );

/**
 * Collapse a single-event SSE body to its JSON payload.
 *
 * @param {string} text Response body.
 * @return {Object} Parsed payload.
 */
function parseBody( text ) {
	const data = text
		.split( '\n' )
		.filter( ( line ) => line.startsWith( 'data: ' ) )
		.map( ( line ) => line.slice( 6 ) )
		.join( '\n' );

	return JSON.parse( data || text );
}

/**
 * POST a JSON-RPC request.
 *
 * @param {Object} body           JSON-RPC body.
 * @param {Object} [options]      Options.
 * @param {string} [options.token] Bearer token, or null to omit.
 * @return {Promise<{status:number, text:string, contentType:string}>} Response pieces.
 */
async function rpc( body, { token = TOKEN } = {} ) {
	const headers = {
		'Content-Type': 'application/json',
		Accept: 'application/json, text/event-stream',
	};

	if ( token ) {
		headers.Authorization = `Bearer ${ token }`;
	}

	const response = await fetch( base, {
		method: 'POST',
		headers,
		body: JSON.stringify( body ),
	} );

	return {
		status: response.status,
		contentType: response.headers.get( 'content-type' ) || '',
		text: await response.text(),
	};
}

test( 'health is public', async () => {
	const response = await fetch( `${ base }/health` );
	const body = await response.json();

	assert.equal( response.status, 200 );
	assert.equal( body.ok, true );
	assert.equal( body.environment, 'sandbox' );
} );

test( 'missing token returns 401 with a JSON-RPC error', async () => {
	const { status, text } = await rpc(
		{ jsonrpc: '2.0', id: 1, method: 'tools/list' },
		{ token: null }
	);
	const payload = JSON.parse( text );

	assert.equal( status, 401 );
	assert.equal( payload.error.code, -32001 );
} );

test( 'wrong token returns 401', async () => {
	const { status } = await rpc(
		{ jsonrpc: '2.0', id: 1, method: 'tools/list' },
		{ token: 'not-the-token' }
	);

	assert.equal( status, 401 );
} );

test( 'legacy initialize negotiates and returns serverInfo', async () => {
	const { status, contentType, text } = await rpc( {
		jsonrpc: '2.0',
		id: 1,
		method: 'initialize',
		params: {
			protocolVersion: '2025-06-18',
			capabilities: {},
			clientInfo: { name: 'smoke-test', version: '0.0.0' },
		},
	} );
	const payload = parseBody( text );

	assert.equal( status, 200 );
	assert.match( contentType, /text\/event-stream/ );
	assert.equal( payload.result.serverInfo.name, 'quickbooks-online-mcp' );
	assert.ok( payload.result.protocolVersion );
} );

test( 'tools/list exposes the read and create families', async () => {
	const { status, text } = await rpc( { jsonrpc: '2.0', id: 2, method: 'tools/list' } );
	const payload = parseBody( text );
	const names = payload.result.tools.map( ( tool ) => tool.name ).sort();

	assert.equal( status, 200 );
	assert.deepEqual( names, [
		'create_bill',
		'create_customer',
		'create_estimate',
		'create_invoice',
		'create_payment',
		'delete_invoice',
		'delete_payment',
		'get_company_info',
		'get_invoice',
		'get_report',
		'search_accounts',
		'search_customers',
		'search_invoices',
		'search_items',
		'search_payments',
		'search_vendors',
		'update_invoice',
	] );
} );

test( 'read tools advertise readOnlyHint; create tools do not', async () => {
	const { text } = await rpc( { jsonrpc: '2.0', id: 3, method: 'tools/list' } );
	const tools = parseBody( text ).result.tools;
	const byName = Object.fromEntries( tools.map( ( tool ) => [ tool.name, tool ] ) );

	assert.equal( byName.get_report.annotations.readOnlyHint, true );
	assert.equal( byName.create_invoice.annotations.readOnlyHint, false );
	assert.equal( byName.create_invoice.annotations.destructiveHint, false );
	assert.equal( byName.update_invoice.annotations.destructiveHint, false );
	assert.equal( byName.delete_invoice.annotations.destructiveHint, true );
	assert.equal( byName.delete_payment.annotations.destructiveHint, true );
	assert.equal( byName.search_payments.annotations.readOnlyHint, true );
} );
