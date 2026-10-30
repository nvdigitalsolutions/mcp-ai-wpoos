/**
 * Per-tool quota integration tests (Phase 1) — gateway + fake upstream.
 *
 * Proves the HTTP contract: a scoped quota breach returns HTTP 429 with
 * RFC 9558 RateLimit-* headers and a -32099 JSON-RPC error, and that quotas
 * are per-key (one noisy key cannot exhaust another's tool budget).
 */

import { test } from 'node:test';
import assert from 'node:assert';
import express from 'express';
import { createApp } from '../index.js';
import { startTestServer } from '../utils/test-server.js';
import { parseToolQuotas } from './config.js';

const KEY_A = 'key-a-1234567890abcdefgh';
const KEY_B = 'key-b-1234567890abcdefgh';

/**
 * Start a fake upstream site exposing one tool.
 *
 * @param {string} slug Site slug.
 * @return {Promise<{baseUrl: string, close: Function, calls: object[]}>}
 */
async function startFakeSite( slug ) {
	const calls = [];
	const app = express();
	app.use( express.json() );
	app.post( '/wp-json/mcp-ai/v1/mcp', ( req, res ) => {
		calls.push( req.body );
		const { id } = req.body;
		if ( 'tools/list' === req.body.method ) {
			return res.json( {
				jsonrpc: '2.0',
				id,
				result: { tools: [ { name: 'slow_tool', description: 'd', inputSchema: {} } ] },
			} );
		}
		return res.json( {
			jsonrpc: '2.0',
			id,
			result: { content: [ { type: 'text', text: `${ slug } ran ${ req.body.params?.name }` } ] },
		} );
	} );
	const { baseUrl, close } = await startTestServer( app );
	return { baseUrl: `${ baseUrl }/wp-json/mcp-ai/v1/mcp`, close, calls };
}

/**
 * Gateway config: two keys bound to one site, a 2/1m quota on slow_tool.
 *
 * @param {object} fake Fake site.
 * @return {object} Config.
 */
function quotaConfig( fake ) {
	return {
		keys: new Map( [
			[ KEY_A, [ 'site-a' ] ],
			[ KEY_B, [ 'site-a' ] ],
		] ),
		previousKeys: new Map(),
		sites: new Map( [ [ 'site-a', { slug: 'site-a', url: fake.baseUrl, token: 'op_test.SECRET' } ] ] ),
		quotas: parseToolQuotas( 'slow_tool:2/1m:2' ),
		errors: [],
	};
}

async function post( baseUrl, body, key ) {
	return fetch( `${ baseUrl }/mcp`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${ key }` },
		body: JSON.stringify( body ),
	} );
}

test( 'a scoped quota breach returns 429, RFC 9558 headers, and -32099', async ( t ) => {
	const site = await startFakeSite( 'site-a' );
	const { baseUrl, close } = await startTestServer( createApp( { config: quotaConfig( site ) } ) );
	t.after( async () => {
		await close();
		await site.close();
	} );

	// Single-site mode: bare names are accepted.
	const call = { jsonrpc: '2.0', id: 7, method: 'tools/call', params: { name: 'slow_tool', arguments: {} } };
	const first = await post( baseUrl, call, KEY_A );
	assert.strictEqual( first.status, 200 );
	const second = await post( baseUrl, call, KEY_A );
	assert.strictEqual( second.status, 200 );

	const third = await post( baseUrl, call, KEY_A );
	assert.strictEqual( third.status, 429 );
	// Wire names are lowercased by Node's HTTP layer (`RateLimit-Limit` →
	// `ratelimit-limit`); fetch's Headers.get is case-insensitive.
	assert.strictEqual( third.headers.get( 'ratelimit-limit' ), '2' );
	assert.strictEqual( third.headers.get( 'ratelimit-remaining' ), '0' );
	assert.strictEqual( third.headers.get( 'ratelimit-policy' ), '2;w=60' );
	assert.ok( Number( third.headers.get( 'ratelimit-reset' ) ) >= 1 );
	assert.ok( Number( third.headers.get( 'retry-after' ) ) >= 1 );

	const body = await third.json();
	assert.strictEqual( body.error.code, -32099 );
	assert.strictEqual( body.error.message, 'Rate limit exceeded' );
	assert.strictEqual( body.error.data.requests_per_window, 2 );
	assert.strictEqual( body.error.data.window_seconds, 60 );

	// The breached call never reached the upstream site.
	assert.strictEqual( site.calls.filter( ( c ) => 'tools/call' === c.method ).length, 2 );
} );

test( 'quotas are per-key — one noisy key cannot exhaust another', async ( t ) => {
	const site = await startFakeSite( 'site-a' );
	const { baseUrl, close } = await startTestServer( createApp( { config: quotaConfig( site ) } ) );
	t.after( async () => {
		await close();
		await site.close();
	} );

	const call = { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'slow_tool', arguments: {} } };
	await post( baseUrl, call, KEY_A );
	await post( baseUrl, call, KEY_A );
	const keyABreach = await post( baseUrl, call, KEY_A );
	assert.strictEqual( keyABreach.status, 429 );

	const keyB = await post( baseUrl, call, KEY_B );
	assert.strictEqual( keyB.status, 200, 'KEY_B has its own bucket' );
} );

test( 'unscoped tools are unaffected by other tool quotas', async ( t ) => {
	const site = await startFakeSite( 'site-a' );
	const { baseUrl, close } = await startTestServer( createApp( { config: quotaConfig( site ) } ) );
	t.after( async () => {
		await close();
		await site.close();
	} );

	const call = { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'fast_tool', arguments: {} } };
	for ( let i = 0; i < 5; i++ ) {
		const res = await post( baseUrl, call, KEY_A );
		assert.strictEqual( res.status, 200, `call ${ i + 1 } must pass (no rule matches fast_tool)` );
	}
} );
