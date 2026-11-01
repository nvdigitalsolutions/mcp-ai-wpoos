/**
 * Health route tests — public shape, degraded registry, auth-gated full.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { createApp } from '../index.js';
import { recordSiteHealth } from './health.js';
import { startTestServer } from '../utils/test-server.js';

// The runbook contract (`docs/operations/deployment/mcp-gateway-velocity-setup.md`
// §7): `/health` must report the version in package.json — enforce it so a
// future bump can never drift the marker again.
const pkg = JSON.parse(
	readFileSync( join( dirname( fileURLToPath( import.meta.url ) ), '..', '..', 'package.json' ), 'utf8' )
);

const KEY = 'key-1234567890abcdef';

function makeConfig() {
	return {
		keys: new Map( [ [ KEY, [ 'site-a' ] ] ] ),
		previousKeys: new Map(),
		sites: new Map( [
			[ 'site-a', { slug: 'site-a', url: 'https://a.example.com/wp-json/mcp-ai/v1/mcp', token: 'op_a.SECRET' } ],
		] ),
		errors: [],
	};
}

test( 'GET /health is public and reports the site registry', async () => {
	const { baseUrl, close } = await startTestServer( createApp( { config: makeConfig() } ) );
	const res = await fetch( `${ baseUrl }/health` );
	assert.strictEqual( res.status, 200 );
	const body = await res.json();
	assert.strictEqual( body.status, 'ok' );
	assert.strictEqual( body.service, 'design-mcp-gateway' );
	assert.strictEqual( body.version, pkg.version );
	assert.deepStrictEqual( body.sites, { 'site-a': 'ok' } );
	await close();
} );

test( 'degraded sites flip the public status and appear in the full view', async () => {
	recordSiteHealth( 'site-a', false, 'upstream timeout' );
	const { baseUrl, close } = await startTestServer( createApp( { config: makeConfig() } ) );

	const publicBody = await ( await fetch( `${ baseUrl }/health` ) ).json();
	assert.strictEqual( publicBody.status, 'degraded' );
	assert.deepStrictEqual( publicBody.sites, { 'site-a': 'degraded' } );

	const fullRes = await fetch( `${ baseUrl }/health/full`, {
		headers: { Authorization: `Bearer ${ KEY }` },
	} );
	assert.strictEqual( fullRes.status, 200 );
	const full = await fullRes.json();
	assert.strictEqual( full.sites[ 'site-a' ].status, 'degraded' );
	assert.strictEqual( full.sites[ 'site-a' ].lastError, 'upstream timeout' );
	assert.strictEqual( full.sites[ 'site-a' ].url, 'a.example.com' );
	assert.strictEqual( full.keys, 1 );
	assert.deepStrictEqual( full.bindings, [ 'site-a' ] );
	await close();

	// Restore for other tests.
	recordSiteHealth( 'site-a', true );
} );

test( 'GET /health/full requires auth', async () => {
	const { baseUrl, close } = await startTestServer( createApp( { config: makeConfig() } ) );
	const res = await fetch( `${ baseUrl }/health/full` );
	assert.strictEqual( res.status, 401 );
	await close();
} );

test( 'PUBLIC_SITE_SLUGS hides private sites from public /health', async () => {
	const config = makeConfig();
	config.sites = new Map( [
		[ 'site-a', { slug: 'site-a', url: 'https://a.example.com/wp-json/mcp-ai/v1/mcp', token: 'op_a.SECRET' } ],
		[ 'site-b', { slug: 'site-b', url: 'https://b.example.com/wp-json/mcp-ai/v1/mcp', token: 'op_b.SECRET' } ],
	] );
	config.publicSlugs = new Set( [ 'site-a' ] );
	recordSiteHealth( 'site-b', false, 'upstream timeout' );

	const { baseUrl, close } = await startTestServer( createApp( { config } ) );

	// Public view: site-b is invisible, and its degradation must not flip
	// the public status.
	const publicBody = await ( await fetch( `${ baseUrl }/health` ) ).json();
	assert.strictEqual( publicBody.status, 'ok' );
	assert.deepStrictEqual( publicBody.sites, { 'site-a': 'ok' } );

	// Keyed full view: every site, including the private one.
	const fullBody = await ( await fetch( `${ baseUrl }/health/full`, {
		headers: { Authorization: `Bearer ${ KEY }` },
	} ) ).json();
	assert.strictEqual( fullBody.sites[ 'site-a' ].status, 'ok' );
	assert.strictEqual( fullBody.sites[ 'site-b' ].status, 'degraded' );
	await close();

	// Restore for other tests.
	recordSiteHealth( 'site-b', true );
} );
