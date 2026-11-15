#!/usr/bin/env node
/**
 * Full-stack smoke test (mock mode).
 *
 * Boots the real HTTP server (static dist + API), then drives the complete
 * happy path: health → seeds → create estate → advance to live → catalogue.
 * Exits non-zero on any mismatch. Run with `npm run smoke`.
 */

import assert from 'node:assert/strict';
import { rm } from 'node:fs/promises';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createExchangeServer, loadSeeds } from './serve.mjs';
import { loadConfig } from '../server/config.js';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const dataDir = join( root, 'data-smoke' );

const env = {
	...process.env,
	EXCHANGE_MODE: 'mock',
	EXCHANGE_DATA_DIR: dataDir,
	EXCHANGE_JOBS_FILE: join( dataDir, 'jobs.jsonl' ),
	EXCHANGE_ENCRYPTION_KEY: 'smoke-key',
	PORT: '0',
};

const config = loadConfig( env );
const seeds = await loadSeeds( join( root, 'blueprints' ) );
assert.ok( seeds.length >= 5, 'expected at least five seed blueprints' );

const { server } = createExchangeServer( { config, seeds } );
await new Promise( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
const base = `http://127.0.0.1:${ server.address().port }`;

async function api( path, init = {} ) {
	const response = await fetch( `${ base }/api/v1${ path }`, {
		headers: init.body ? { 'Content-Type': 'application/json' } : undefined,
		...init,
	} );
	const payload = await response.json();
	return { status: response.status, payload };
}

try {
	const health = await api( '/health' );
	assert.equal( health.status, 200 );
	assert.equal( health.payload.data.mode, 'mock' );

	const list = await api( '/blueprints' );
	assert.equal( list.payload.data.length, seeds.length );

	const created = await api( '/estates', {
		method: 'POST',
		body: JSON.stringify( { blueprint_slug: 'clinic-ops' } ),
	} );
	assert.equal( created.status, 201 );
	assert.equal( created.payload.data.state, 'requested' );

	let state = 'requested';
	for ( let index = 0; index < 9 && state !== 'live'; index += 1 ) {
		const advanced = await api( '/estates/clinic-ops/advance', { method: 'POST' } );
		assert.equal( advanced.status, 200, JSON.stringify( advanced.payload ) );
		state = advanced.payload.data.state;
	}
	assert.equal( state, 'live', 'estate should reach live in mock mode' );

	const detail = await api( '/estates/clinic-ops' );
	assert.equal( detail.status, 200 );
	assert.ok( detail.payload.data.steps.every( ( step ) => step.ok ) );
	assert.equal( detail.payload.data.entitlement, 'trialing' );

	const catalogue = await api( '/catalogue' );
	assert.equal( catalogue.status, 200 );
	assert.equal( catalogue.payload.data.length, 1 );
	assert.equal( catalogue.payload.data[ 0 ].slug, 'clinic-ops' );

	const envblock = await api( '/estates/clinic-ops/envblock' );
	assert.equal( envblock.status, 409, 'mock estates have no minted credentials yet' );

	const missing = await api( '/nope' );
	assert.equal( missing.status, 404 );

	console.log( 'smoke ok: health → 5 seeds → create → 9 steps → live → catalogue' );
} finally {
	await new Promise( ( resolve ) => server.close( resolve ) );
	await rm( dataDir, { recursive: true, force: true } );
}
