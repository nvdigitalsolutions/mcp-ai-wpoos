/**
 * dryRun + shadow-policy integration tests (Phase 4) — gateway + fake site.
 *
 * Proves the inspector contract: dryRun evaluates policy and quota without
 * executing or consuming, and shadow policies log without enforcing.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import express from 'express';
import { createApp } from '../index.js';
import { startTestServer } from '../utils/test-server.js';
import { parseToolQuotas } from '../quota/config.js';
import { parsePolicy } from '../policy/evaluator.js';

const KEY_A = 'key-a-1234567890abcdefgh';

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
				result: { tools: [ { name: 'delete_post', description: 'd', inputSchema: {} } ] },
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

function dryRunConfig( fake ) {
	return {
		keys: new Map( [ [ KEY_A, [ 'site-a' ] ] ] ),
		previousKeys: new Map(),
		sites: new Map( [ [ 'site-a', { slug: 'site-a', url: fake.baseUrl, token: 'op_t.SECRET' } ] ] ),
		quotas: parseToolQuotas( 'delete_post:2/1m:2' ),
		policy: parsePolicy(
			JSON.stringify( { rules: [ { id: 'no-deletes', effect: 'deny', tool: { match: 'delete_*' } } ] } ),
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

test( 'dryRun evaluates policy and quota without executing or consuming', async ( t ) => {
	const site = await startFakeSite();
	const { baseUrl, close } = await startTestServer( createApp( { config: dryRunConfig( site ) } ) );
	t.after( async () => {
		await close();
		await site.close();
	} );

	const dry = await post(
		baseUrl,
		{
			jsonrpc: '2.0',
			id: 1,
			method: 'tools/call',
			params: { name: 'delete_post', arguments: { id: 1 }, dryRun: true },
		},
		KEY_A
	);
	assert.strictEqual( dry.status, 200 );
	const body = await dry.json();
	assert.strictEqual( body.result.dryRun, true );
	assert.strictEqual( body.result.would_execute, false );
	assert.strictEqual( body.result.policy.ruleId, 'no-deletes' );
	assert.strictEqual( body.result.quota.limit, 2 );
	assert.strictEqual( body.result.quota.remaining, 2, 'dryRun never consumes tokens' );

	// Nothing reached the upstream site.
	assert.strictEqual( site.calls.filter( ( c ) => 'tools/call' === c.method ).length, 0 );

	// The quota is untouched: a real call still has the full budget.
	const real = await post(
		baseUrl,
		{ jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name: 'delete_post', arguments: {} } },
		KEY_A
	);
	assert.strictEqual( real.status, 403, 'policy still denies the real call' );

	await close();
	await site.close();
} );

test( 'shadow policies log without enforcing', async ( t ) => {
	const site = await startFakeSite();
	const config = dryRunConfig( site );
	config.shadowPolicy = parsePolicy(
		JSON.stringify( { rules: [ { id: 'shadow-denies-everything', effect: 'deny', tool: { match: '*' } } ] } ),
		[]
	);
	// Relax the live policy so the call should execute: shadow must not block it.
	config.policy = null;

	const warnings = [];
	const originalWarn = console.warn;
	console.warn = ( line ) => warnings.push( String( line ) );
	t.after( async () => {
		console.warn = originalWarn;
		await site.close();
	} );

	const { baseUrl, close } = await startTestServer( createApp( { config } ) );
	t.after( async () => close() );

	const res = await post(
		baseUrl,
		{ jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'delete_post', arguments: {} } },
		KEY_A
	);
	assert.strictEqual( res.status, 200, 'shadow policy must never enforce' );
	assert.ok( warnings.some( ( line ) => line.includes( 'shadow_policy' ) ), 'shadow evaluation is logged' );
	assert.ok( warnings.some( ( line ) => line.includes( 'shadow-denies-everything' ) ) );

	await close();
	await site.close();
} );
