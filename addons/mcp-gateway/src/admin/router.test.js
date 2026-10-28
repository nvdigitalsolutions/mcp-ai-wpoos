/**
 * Admin router integration tests (Phases 4–5).
 *
 * Proves the operator surface: auth gating (fail-closed when unconfigured),
 * the read-only policy view, policy-sim replay against the recorded call
 * buffer, and the fleet catalog with lifecycle states.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import { createApp } from '../index.js';
import { startTestServer } from '../utils/test-server.js';
import { parseToolQuotas } from '../quota/config.js';
import { parsePolicy } from '../policy/evaluator.js';
import { recorder } from './recorder.js';

const ADMIN = 'op-admin-1234567890abcdef';

function adminConfig( extra = {} ) {
	return {
		keys: new Map( [ [ 'key-a-1234567890abcdefgh', [ 'site-a' ] ] ] ),
		previousKeys: new Map(),
		sites: new Map( [
			[ 'site-a', { slug: 'site-a', url: 'https://example.com/wp-json/mcp-ai/v1/mcp', token: 'op_site.SECRET' } ],
		] ),
		adminToken: ADMIN,
		quotas: parseToolQuotas( 'delete_post:5/1h:2' ),
		policy: parsePolicy(
			JSON.stringify( { rules: [ { id: 'no-deletes', effect: 'deny', tool: { match: 'delete_*' } } ] } ),
			[]
		),
		siteLifecycle: new Map( [ [ 'site-a', 'draft' ] ] ),
		errors: [],
		...extra,
	};
}

async function start( config ) {
	return startTestServer( createApp( { config } ) );
}

test( 'the admin surface is inert (401) without GATEWAY_ADMIN_TOKEN', async ( t ) => {
	const { baseUrl, close } = await start( adminConfig( { adminToken: '' } ) );
	t.after( async () => close() );
	const res = await fetch( `${ baseUrl }/admin/policy` );
	assert.strictEqual( res.status, 401 );
	assert.strictEqual( ( await res.json() ).error, 'admin_disabled' );
} );

test( 'a bad operator token is rejected timing-safely', async ( t ) => {
	const { baseUrl, close } = await start( adminConfig() );
	t.after( async () => close() );
	const res = await fetch( `${ baseUrl }/admin/policy`, {
		headers: { Authorization: 'Bearer wrong-token-1234567890' },
	} );
	assert.strictEqual( res.status, 401 );
} );

test( 'GET /admin/policy exposes the effective policy and quota table', async ( t ) => {
	const { baseUrl, close } = await start( adminConfig() );
	t.after( async () => close() );
	const res = await fetch( `${ baseUrl }/admin/policy`, {
		headers: { Authorization: `Bearer ${ ADMIN }` },
	} );
	assert.strictEqual( res.status, 200 );
	const body = await res.json();
	assert.strictEqual( body.quotas[ 0 ].pattern, 'delete_post' );
	assert.strictEqual( body.quotas[ 0 ].rate, 5 );
	assert.strictEqual( body.policy.rules[ 0 ].id, 'no-deletes' );
} );

test( 'POST /admin/policy-sim replays recorded calls and diffs against live', async ( t ) => {
	const { baseUrl, close } = await start( adminConfig() );
	t.after( async () => close() );

	// Record traffic like the MCP route does (redacted entries).
	recorder.record( {
		ts: new Date().toISOString(),
		tool: 'delete_post',
		site: 'site-a',
		key: 'key-a-1234567890abcdefgh',
		country: null,
		argsKeys: [ 'id' ],
		traceId: 't1',
	} );
	recorder.record( {
		ts: new Date().toISOString(),
		tool: 'get_post',
		site: 'site-a',
		key: 'key-a-1234567890abcdefgh',
		country: null,
		argsKeys: [],
		traceId: 't2',
	} );

	const candidate = JSON.stringify( {
		rules: [ { id: 'block-everything', effect: 'deny', tool: { match: '*' } } ],
	} );
	const res = await fetch( `${ baseUrl }/admin/policy-sim`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${ ADMIN }` },
		body: JSON.stringify( { policy: candidate } ),
	} );
	assert.strictEqual( res.status, 200 );
	const body = await res.json();
	assert.strictEqual( body.summary.replayed, 2 );
	assert.strictEqual( body.summary.simulated.deny, 2 );
	// Both calls change: get_post flips allow→deny, delete_post keeps the
	// deny but from a different rule (rule-id swap counts as changed).
	assert.strictEqual( body.summary.changed, 2 );
	assert.strictEqual( body.decisions.find( ( d ) => 'get_post' === d.tool ).changed, true );
	assert.strictEqual( body.decisions.find( ( d ) => 'get_post' === d.tool ).live.effect, 'allow' );
} );

test( 'POST /admin/policy-sim rejects malformed candidate policies', async ( t ) => {
	const { baseUrl, close } = await start( adminConfig() );
	t.after( async () => close() );
	const res = await fetch( `${ baseUrl }/admin/policy-sim`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${ ADMIN }` },
		body: JSON.stringify( { policy: '{not json' } ),
	} );
	assert.strictEqual( res.status, 400 );
	assert.strictEqual( ( await res.json() ).error, 'invalid_policy' );
} );

test( 'GET /admin/catalog aggregates sites with lifecycle states', async ( t ) => {
	const { baseUrl, close } = await start( adminConfig() );
	t.after( async () => close() );
	const res = await fetch( `${ baseUrl }/admin/catalog`, {
		headers: { Authorization: `Bearer ${ ADMIN }` },
	} );
	assert.strictEqual( res.status, 200 );
	const body = await res.json();
	assert.strictEqual( body.gateway.registryId, 'io.github.nvdigitalsolutions/nvoos-mcp-gateway' );
	assert.strictEqual( body.gateway.lifecycle, 'published' );
	assert.strictEqual( body.sites.length, 1 );
	assert.strictEqual( body.sites[ 0 ].slug, 'site-a' );
	assert.strictEqual( body.sites[ 0 ].lifecycle, 'draft' );
	assert.strictEqual( body.sites[ 0 ].health, 'ok' );
} );
