/**
 * MCP route integration tests — gateway + fake upstream NV oOS sites.
 *
 * Each fake upstream is an Express app replaying the plugin's
 * /wp-json/mcp-ai/v1/mcp surface (discovery, tools/list, tools/call) and
 * recording the Authorization header it receives, so the suite proves the
 * full proxy contract without real infrastructure.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import express from 'express';
import { createApp } from '../index.js';
import { startTestServer } from '../utils/test-server.js';

const KEY_MULTI = 'key-multi-1234567890abcdef';
const KEY_SINGLE = 'key-single-1234567890abcd';

/**
 * Start a fake upstream NV oOS site.
 *
 * @param {string} slug     Site slug.
 * @param {object} opts     `tools` (tool list), `fail` (500 on tools/list),
 *                          `token` (expected upstream bearer).
 * @return {Promise<{baseUrl: string, close: Function, requests: object[]}>}
 */
async function startFakeSite( slug, opts = {} ) {
	const requests = [];
	const app = express();
	app.use( express.json() );
	app.post( '/wp-json/mcp-ai/v1/mcp', ( req, res ) => {
		requests.push( { auth: req.get( 'Authorization' ), body: req.body } );
		const { id, method } = req.body;
		if ( opts.fail ) {
			return res.status( 500 ).json( { jsonrpc: '2.0', id, error: { code: -32603, message: 'boom' } } );
		}
		if ( 'tools/list' === method ) {
			return res.json( {
				jsonrpc: '2.0',
				id,
				result: {
					tools: opts.tools || [
						{ name: `make_${ slug.replace( /-/g, '_' ) }`, description: 'd', inputSchema: { type: 'object' } },
					],
				},
			} );
		}
		if ( 'tools/call' === method ) {
			return res.json( {
				jsonrpc: '2.0',
				id,
				result: {
					content: [ { type: 'text', text: `${ slug } ran ${ req.body.params.name }` } ],
				},
			} );
		}
		return res.json( { jsonrpc: '2.0', id, result: { echo: req.body } } );
	} );
	const { baseUrl, close } = await startTestServer( app );
	return {
		baseUrl: `${ baseUrl }/wp-json/mcp-ai/v1/mcp`,
		close,
		requests,
	};
}

/**
 * Build a gateway config over the fake sites.
 *
 * @param {object} fakes `{ 'site-a': {baseUrl, requests}, ... }`.
 * @return {object} Config for createApp.
 */
function gatewayConfig( fakes ) {
	const sites = new Map();
	for ( const [ slug, fake ] of Object.entries( fakes ) ) {
		sites.set( slug, { slug, url: fake.baseUrl, token: `op_${ slug.replace( /-/g, '_' ) }.SECRET` } );
	}
	return {
		keys: new Map( [
			[ KEY_MULTI, [ ...Object.keys( fakes ) ] ],
			[ KEY_SINGLE, [ Object.keys( fakes )[ 0 ] ] ],
		] ),
		previousKeys: new Map(),
		sites,
		errors: [],
	};
}

async function post( baseUrl, body, key ) {
	return fetch( `${ baseUrl }/mcp`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			...( key ? { Authorization: `Bearer ${ key }` } : {} ),
		},
		body: JSON.stringify( body ),
	} );
}

test( 'GET /mcp serves public discovery without auth', async () => {
	const siteA = await startFakeSite( 'site-a' );
	const { baseUrl, close } = await startTestServer( createApp( { config: gatewayConfig( { 'site-a': siteA } ) } ) );

	const res = await fetch( `${ baseUrl }/mcp` );
	assert.strictEqual( res.status, 200 );
	const body = await res.json();
	assert.strictEqual( body.name, 'NV oOS Gateway' );
	assert.strictEqual( body.protocolVersion, '2026-07-28' );
	assert.strictEqual( body.transports.streamable_http.default, true );
	assert.ok( ! JSON.stringify( body ).includes( 'site-a' ), 'discovery must not leak bound-site names' );

	await close();
	await siteA.close();
} );

test( 'initialize is answered gateway-level', async () => {
	const siteA = await startFakeSite( 'site-a' );
	const { baseUrl, close } = await startTestServer( createApp( { config: gatewayConfig( { 'site-a': siteA } ) } ) );

	const res = await post( baseUrl, { jsonrpc: '2.0', id: 1, method: 'initialize', params: {} }, KEY_MULTI );
	const body = await res.json();
	assert.strictEqual( body.result.serverInfo.name, 'NV oOS Gateway' );
	assert.strictEqual( body.id, 1 );
	assert.strictEqual( body.result.protocolVersion, '2024-11-05', 'no client version → oldest supported dialect' );
	assert.strictEqual( siteA.requests.length, 0, 'initialize must not hit upstream' );

	await close();
	await siteA.close();
} );

test( 'initialize negotiates the protocol version with the client', async () => {
	const siteA = await startFakeSite( 'site-a' );
	const { baseUrl, close } = await startTestServer( createApp( { config: gatewayConfig( { 'site-a': siteA } ) } ) );

	const cases = [
		{ params: { protocolVersion: '2026-07-28' }, expect: '2026-07-28' },
		{ params: { protocolVersion: '2025-06-18' }, expect: '2025-06-18' },
		{ params: { protocolVersion: '2025-03-26' }, expect: '2025-03-26' },
		{ params: { protocolVersion: '2024-11-05' }, expect: '2024-11-05' },
		{ params: { protocolVersion: '2027-01-01' }, expect: '2024-11-05' },
		{ params: { supportedProtocolVersions: [ '2025-03-26', '2025-06-18' ] }, expect: '2025-06-18' },
		{
			params: { protocolVersion: '2027-01-01', supportedProtocolVersions: [ '2025-03-26' ] },
			expect: '2025-03-26',
		},
	];

	for ( const { params, expect } of cases ) {
		const res = await post(
			baseUrl,
			{ jsonrpc: '2.0', id: 1, method: 'initialize', params },
			KEY_MULTI
		);
		const body = await res.json();
		assert.strictEqual(
			body.result.protocolVersion,
			expect,
			`initialize ${ JSON.stringify( params ) } → ${ expect }`
		);
	}
	assert.strictEqual( siteA.requests.length, 0, 'initialize must not hit upstream' );

	await close();
	await siteA.close();
} );

test( 'multi-site tools/list merges and prefixes; upstream token is header-only', async () => {
	const siteA = await startFakeSite( 'site-a' );
	const siteB = await startFakeSite( 'site-b' );
	const { baseUrl, close } = await startTestServer(
		createApp( { config: gatewayConfig( { 'site-a': siteA, 'site-b': siteB } ) } )
	);

	const res = await post( baseUrl, { jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} }, KEY_MULTI );
	const body = await res.json();
	const names = body.result.tools.map( ( t ) => t.name ).sort();
	assert.deepStrictEqual( names, [ 'site-a.make_site_a', 'site-b.make_site_b' ] );
	assert.deepStrictEqual( body.result._meta.gateway.sites, [ 'site-a', 'site-b' ] );

	assert.strictEqual( siteA.requests[ 0 ].auth, 'Bearer op_site_a.SECRET' );
	assert.strictEqual( siteB.requests[ 0 ].auth, 'Bearer op_site_b.SECRET' );
	assert.ok( ! JSON.stringify( body ).includes( 'SECRET' ), 'upstream tokens must never reach responses' );

	await close();
	await siteA.close();
	await siteB.close();
} );

test( 'tools/call reverse-maps the prefix and passes the result through', async () => {
	const siteA = await startFakeSite( 'site-a' );
	const { baseUrl, close } = await startTestServer( createApp( { config: gatewayConfig( { 'site-a': siteA } ) } ) );

	const res = await post(
		baseUrl,
		{ jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'site-a.make_site_a', arguments: {} } },
		KEY_MULTI
	);
	const body = await res.json();
	assert.strictEqual( body.id, 3 );
	assert.strictEqual( body.result.content[ 0 ].text, 'site-a ran make_site_a' );
	assert.strictEqual( siteA.requests[ 0 ].body.params.name, 'make_site_a' );

	await close();
	await siteA.close();
} );

test( 'multi-site key rejects unprefixed tool names (no smuggling)', async () => {
	const siteA = await startFakeSite( 'site-a' );
	const siteB = await startFakeSite( 'site-b' );
	const { baseUrl, close } = await startTestServer(
		createApp( { config: gatewayConfig( { 'site-a': siteA, 'site-b': siteB } ) } )
	);

	const res = await post(
		baseUrl,
		{ jsonrpc: '2.0', id: 4, method: 'tools/call', params: { name: 'make_site_a', arguments: {} } },
		KEY_MULTI
	);
	const body = await res.json();
	assert.strictEqual( body.error.code, -32602 );
	assert.match( body.error.message, /site-slug/ );

	await close();
	await siteA.close();
	await siteB.close();
} );

test( 'single-site key allows bare tool names (passthrough mode)', async () => {
	const siteA = await startFakeSite( 'site-a' );
	const { baseUrl, close } = await startTestServer( createApp( { config: gatewayConfig( { 'site-a': siteA } ) } ) );

	const res = await post(
		baseUrl,
		{ jsonrpc: '2.0', id: 5, method: 'tools/call', params: { name: 'make_site_a', arguments: {} } },
		KEY_SINGLE
	);
	const body = await res.json();
	assert.strictEqual( body.result.content[ 0 ].text, 'site-a ran make_site_a' );

	await close();
	await siteA.close();
} );

test( 'a failing upstream degrades tools/list instead of failing it', async () => {
	const siteA = await startFakeSite( 'site-a' );
	const siteB = await startFakeSite( 'site-b', { fail: true } );
	const { baseUrl, close } = await startTestServer(
		createApp( { config: gatewayConfig( { 'site-a': siteA, 'site-b': siteB } ) } )
	);

	const res = await post( baseUrl, { jsonrpc: '2.0', id: 6, method: 'tools/list', params: {} }, KEY_MULTI );
	const body = await res.json();
	assert.deepStrictEqual( body.result.tools.map( ( t ) => t.name ), [ 'site-a.make_site_a' ] );
	assert.ok( body.result._meta.gateway.errors[ 'site-b' ], 'failed site must be reported in _meta' );

	// The health registry must now show the degraded site.
	const health = await ( await fetch( `${ baseUrl }/health` ) ).json();
	assert.strictEqual( health.sites[ 'site-b' ], 'degraded' );

	await close();
	await siteA.close();
	await siteB.close();
} );

test( 'notifications get 202 and no body', async () => {
	const siteA = await startFakeSite( 'site-a' );
	const { baseUrl, close } = await startTestServer( createApp( { config: gatewayConfig( { 'site-a': siteA } ) } ) );

	const res = await post( baseUrl, { jsonrpc: '2.0', method: 'notifications/initialized' }, KEY_MULTI );
	assert.strictEqual( res.status, 202 );
	assert.strictEqual( await res.text(), '' );
	assert.strictEqual( siteA.requests.length, 1, 'notification forwarded upstream' );

	await close();
	await siteA.close();
} );

test( 'batch requests return an array of responses', async () => {
	const siteA = await startFakeSite( 'site-a' );
	const { baseUrl, close } = await startTestServer( createApp( { config: gatewayConfig( { 'site-a': siteA } ) } ) );

	const res = await post(
		baseUrl,
		[
			{ jsonrpc: '2.0', id: 7, method: 'ping', params: {} },
			{ jsonrpc: '2.0', method: 'notifications/initialized' },
			{ jsonrpc: '2.0', id: 8, method: 'tools/list', params: {} },
		],
		KEY_SINGLE
	);
	const body = await res.json();
	assert.strictEqual( body.length, 2, 'the notification contributes no response' );
	assert.strictEqual( body[ 0 ].result && Object.keys( body[ 0 ].result ).length, 0 );
	assert.strictEqual( body[ 1 ].result.tools[ 0 ].name, 'site-a.make_site_a' );

	await close();
	await siteA.close();
} );

test( 'unknown methods → -32601, invalid json → -32700, no key → 401', async () => {
	const siteA = await startFakeSite( 'site-a' );
	const { baseUrl, close } = await startTestServer( createApp( { config: gatewayConfig( { 'site-a': siteA } ) } ) );

	const unknown = await ( await post( baseUrl, { jsonrpc: '2.0', id: 9, method: 'tools/explode', params: {} }, KEY_MULTI ) ).json();
	assert.strictEqual( unknown.error.code, -32601 );

	const badJson = await fetch( `${ baseUrl }/mcp`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${ KEY_MULTI }` },
		body: '{not json',
	} );
	assert.strictEqual( badJson.status, 400 );
	assert.strictEqual( ( await badJson.json() ).error.code, -32700 );

	const noKey = await post( baseUrl, { jsonrpc: '2.0', id: 10, method: 'ping', params: {} } );
	assert.strictEqual( noKey.status, 401 );

	await close();
	await siteA.close();
} );
