/**
 * Policy enforcement integration tests (Phase 2) — gateway + fake upstream.
 *
 * Proves: tools/list catalog filtering (denied tools never reach the model
 * context), deny short-circuits tools/call with 403 + -32001, and
 * require_approval returns 428 + -32002 with a preview payload.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import express from 'express';
import { createApp } from '../index.js';
import { startTestServer } from '../utils/test-server.js';
import { parsePolicy } from './evaluator.js';

const KEY_A = 'key-a-1234567890abcdefgh';

/**
 * Start a fake upstream site exposing three tools.
 *
 * @return {Promise<{baseUrl: string, close: Function, calls: object[]}>}
 */
async function startFakeSite() {
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
				result: {
					tools: [
						{ name: 'delete_post', description: 'dangerous', inputSchema: {} },
						{ name: 'publish_post', description: 'needs approval', inputSchema: {} },
						{ name: 'get_post', description: 'read-only', inputSchema: {} },
					],
				},
			} );
		}
		return res.json( {
			jsonrpc: '2.0',
			id,
			result: { content: [ { type: 'text', text: `ran ${ req.body.params?.name }` } ] },
		} );
	} );
	const { baseUrl, close } = await startTestServer( app );
	return { baseUrl: `${ baseUrl }/wp-json/mcp-ai/v1/mcp`, close, calls };
}

/**
 * Gateway config with a two-rule policy: deny delete_*, approve publish_*.
 *
 * @param {object} fake Fake site.
 * @return {object} Config.
 */
function policyConfig( fake ) {
	return {
		keys: new Map( [ [ KEY_A, [ 'site-a' ] ] ] ),
		previousKeys: new Map(),
		sites: new Map( [ [ 'site-a', { slug: 'site-a', url: fake.baseUrl, token: 'op_t.SECRET' } ] ] ),
		policy: parsePolicy(
			JSON.stringify( {
				rules: [
					{ id: 'no-deletes', effect: 'deny', tool: { match: 'delete_*' } },
					{ id: 'approve-publishing', effect: 'require_approval', tool: { match: 'publish_*' } },
				],
			} ),
			[]
		),
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

test( 'tools/list filters denied tools out of the catalog', async ( t ) => {
	const site = await startFakeSite();
	const { baseUrl, close } = await startTestServer( createApp( { config: policyConfig( site ) } ) );
	t.after( async () => {
		await close();
		await site.close();
	} );

	const res = await post( baseUrl, { jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} }, KEY_A );
	const body = await res.json();
	const names = body.result.tools.map( ( tool ) => tool.name );
	assert.deepStrictEqual( names, [ 'site-a_publish_post', 'site-a_get_post' ] );

	await close();
	await site.close();
} );

test( 'deny short-circuits tools/call with 403 + -32001, upstream untouched', async ( t ) => {
	const site = await startFakeSite();
	const { baseUrl, close } = await startTestServer( createApp( { config: policyConfig( site ) } ) );
	t.after( async () => {
		await close();
		await site.close();
	} );

	const res = await post(
		baseUrl,
		{ jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name: 'delete_post', arguments: { id: 1 } } },
		KEY_A
	);
	assert.strictEqual( res.status, 403 );
	const body = await res.json();
	assert.strictEqual( body.error.code, -32001 );
	assert.strictEqual( body.error.data.policy_id, 'no-deletes' );
	assert.strictEqual( site.calls.filter( ( c ) => 'tools/call' === c.method ).length, 0 );

	await close();
	await site.close();
} );

test( 'require_approval returns 428 + -32002 with a redacted preview', async ( t ) => {
	const site = await startFakeSite();
	const { baseUrl, close } = await startTestServer( createApp( { config: policyConfig( site ) } ) );
	t.after( async () => {
		await close();
		await site.close();
	} );

	const res = await post(
		baseUrl,
		{
			jsonrpc: '2.0',
			id: 3,
			method: 'tools/call',
			params: { name: 'publish_post', arguments: { id: 9, api_key: 'secret' } },
		},
		KEY_A
	);
	assert.strictEqual( res.status, 428 );
	const body = await res.json();
	assert.strictEqual( body.error.code, -32002 );
	assert.strictEqual( body.error.data.policy_id, 'approve-publishing' );
	// Preview carries argument KEYS only — never values.
	assert.deepStrictEqual( body.error.data.preview.argument_keys, [ 'id', 'api_key' ] );
	assert.ok( ! JSON.stringify( body ).includes( 'secret' ), 'preview must not leak argument values' );
	assert.strictEqual( site.calls.filter( ( c ) => 'tools/call' === c.method ).length, 0 );

	await close();
	await site.close();
} );

test( 'allowed tools still pass through unchanged', async ( t ) => {
	const site = await startFakeSite();
	const { baseUrl, close } = await startTestServer( createApp( { config: policyConfig( site ) } ) );
	t.after( async () => {
		await close();
		await site.close();
	} );

	const res = await post(
		baseUrl,
		{ jsonrpc: '2.0', id: 4, method: 'tools/call', params: { name: 'get_post', arguments: { id: 5 } } },
		KEY_A
	);
	assert.strictEqual( res.status, 200 );
	const body = await res.json();
	assert.ok( body.result.content );

	await close();
	await site.close();
} );
