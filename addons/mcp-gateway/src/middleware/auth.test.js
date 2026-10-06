/**
 * Auth middleware tests.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import express from 'express';
import { authMiddleware, bearerToken, keyMatches, resolveKey } from './auth.js';
import { startTestServer } from '../utils/test-server.js';

const KEY_A = 'key-a-1234567890abcdef';
const KEY_B = 'key-b-1234567890abcdef';
const PREV = 'key-prev-1234567890ab';

/**
 * Build a minimal config object for the middleware.
 *
 * @param {object} overrides Map overrides.
 * @return {object} Config.
 */
function makeConfig( overrides = {} ) {
	return {
		keys: new Map( [ [ KEY_A, [ 'site-a' ] ], [ KEY_B, [ 'site-b' ] ] ] ),
		previousKeys: new Map(),
		sites: new Map( [
			[ 'site-a', { slug: 'site-a', url: 'https://a.example.com', token: 'op_a.SECRET' } ],
			[ 'site-b', { slug: 'site-b', url: 'https://b.example.com', token: 'op_b.SECRET' } ],
		] ),
		errors: [],
		...overrides,
	};
}

/**
 * Build an app exposing the authed identity.
 *
 * @param {object} cfg Config.
 * @return {Promise<{baseUrl: string, close: Function}>}
 */
async function startAuthedApp( cfg ) {
	const app = express();
	app.set( 'gatewayConfig', cfg );
	app.use( '/mcp', authMiddleware );
	app.get( '/mcp', ( req, res ) => res.json( { key: req.gatewayKeyId, sites: req.gatewaySites } ) );
	return startTestServer( app );
}

test( 'bearerToken extracts and validates the header shape', () => {
	assert.strictEqual( bearerToken( 'Bearer abc' ), 'abc' );
	assert.strictEqual( bearerToken( 'bearer  abc ' ), 'abc' );
	assert.strictEqual( bearerToken( 'Basic abc' ), '' );
	assert.strictEqual( bearerToken( '' ), '' );
} );

test( 'keyMatches is timing-safe and case-sensitive', () => {
	assert.ok( keyMatches( 'abc', 'abc' ) );
	assert.ok( ! keyMatches( 'abc', 'ABC' ) );
	assert.ok( ! keyMatches( '', 'abc' ) );
	assert.ok( ! keyMatches( null, 'abc' ) );
} );

test( 'resolveKey finds current and previous keys', () => {
	const cfg = makeConfig( { previousKeys: new Map( [ [ PREV, [ 'site-a' ] ] ] ) } );
	assert.strictEqual( resolveKey( KEY_B, cfg.keys, cfg.previousKeys ).keyId, KEY_B );
	assert.strictEqual( resolveKey( PREV, cfg.keys, cfg.previousKeys ).keyId, PREV );
	assert.strictEqual( resolveKey( 'nope', cfg.keys, cfg.previousKeys ), null );
} );

test( 'authMiddleware: missing key → 401 with WWW-Authenticate', async () => {
	const { baseUrl, close } = await startAuthedApp( makeConfig() );
	const res = await fetch( `${ baseUrl }/mcp` );
	assert.strictEqual( res.status, 401 );
	assert.match( res.headers.get( 'www-authenticate' ), /^Bearer / );
	const body = await res.json();
	assert.strictEqual( body.error, 'unauthorized' );
	assert.strictEqual( typeof body.message, 'string' );
	await close();
} );

test( 'authMiddleware: wrong key → 401', async () => {
	const { baseUrl, close } = await startAuthedApp( makeConfig() );
	const res = await fetch( `${ baseUrl }/mcp`, {
		headers: { Authorization: `Bearer ${ 'wrong'.repeat( 8 ) }` },
	} );
	assert.strictEqual( res.status, 401 );
	await close();
} );

test( 'authMiddleware: valid key resolves identity and bindings', async () => {
	const { baseUrl, close } = await startAuthedApp( makeConfig() );
	const res = await fetch( `${ baseUrl }/mcp`, { headers: { Authorization: `Bearer ${ KEY_A }` } } );
	assert.strictEqual( res.status, 200 );
	assert.deepStrictEqual( await res.json(), { key: KEY_A, sites: [ 'site-a' ] } );
	await close();
} );

test( 'authMiddleware: previous key is accepted during rotation', async () => {
	const cfg = makeConfig( { previousKeys: new Map( [ [ PREV, [ 'site-a' ] ] ] ) } );
	const { baseUrl, close } = await startAuthedApp( cfg );
	const res = await fetch( `${ baseUrl }/mcp`, { headers: { Authorization: `Bearer ${ PREV }` } } );
	assert.strictEqual( res.status, 200 );
	await close();
} );

test( 'authMiddleware: key bound to no configured site → 503 (runtime backstop)', async () => {
	const cfg = makeConfig( {
		keys: new Map( [ [ KEY_A, [ 'ghost-site' ] ] ] ),
	} );
	const { baseUrl, close } = await startAuthedApp( cfg );
	const res = await fetch( `${ baseUrl }/mcp`, { headers: { Authorization: `Bearer ${ KEY_A }` } } );
	assert.strictEqual( res.status, 503 );
	assert.deepStrictEqual( ( await res.json() ).error, 'no_sites_bound' );
	await close();
} );
