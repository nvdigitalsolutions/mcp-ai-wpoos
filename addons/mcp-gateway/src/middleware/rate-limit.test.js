/**
 * Rate limiter tests — per-key budgets, 429 shape, Retry-After header.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import express from 'express';
import { makeLimiter } from './rate-limit.js';
import { startTestServer } from '../utils/test-server.js';

/**
 * Build a limiter with a tiny budget for the test.
 *
 * @return {import('express-rate-limit').RateLimitRequestHandler}
 */
function tinyLimiter() {
	return makeLimiter( { windowMs: 60 * 1000, limit: 2, envKey: 'TEST_NOPE' }, ( req ) => `key:${ req.get( 'X-Test-Key' ) || 'ip' }` );
}

test( 'limiter allows the budget then answers 429 with Retry-After', async () => {
	const app = express();
	app.use( tinyLimiter() );
	app.get( '/x', ( _req, res ) => res.json( { ok: true } ) );
	const { baseUrl, close } = await startTestServer( app );

	const first = await fetch( `${ baseUrl }/x` );
	const second = await fetch( `${ baseUrl }/x` );
	const third = await fetch( `${ baseUrl }/x` );
	assert.strictEqual( first.status, 200 );
	assert.strictEqual( second.status, 200 );
	assert.strictEqual( third.status, 429 );
	assert.ok( third.headers.has( 'retry-after' ) );
	assert.deepStrictEqual( ( await third.json() ).error, 'too_many_requests' );
	await close();
} );

test( 'limiter budgets are isolated per key', async () => {
	const app = express();
	app.use( tinyLimiter() );
	app.get( '/x', ( _req, res ) => res.json( { ok: true } ) );
	const { baseUrl, close } = await startTestServer( app );

	for ( let i = 0; i < 3; i += 1 ) {
		await fetch( `${ baseUrl }/x`, { headers: { 'X-Test-Key': 'a' } } );
	}
	const other = await fetch( `${ baseUrl }/x`, { headers: { 'X-Test-Key': 'b' } } );
	assert.strictEqual( other.status, 200, 'key b keeps its own budget while key a is exhausted' );
	await close();
} );
