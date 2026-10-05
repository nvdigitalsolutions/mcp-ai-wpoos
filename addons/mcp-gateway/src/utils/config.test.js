/**
 * Config parser tests.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import { loadConfig, assertConfig, parseKeyMap, envSuffix } from './config.js';

const KEY = 'key-1234567890abcdef';
const GOOD_ENV = {
	GATEWAY_PUBLIC_KEYS: `${ KEY }=site-a`,
	NVOOS_SITE_SITE_A_URL: 'https://example.com/wp-json/mcp-ai/v1/mcp',
	NVOOS_SITE_SITE_A_TOKEN: 'op_test.SECRET',
};

test( 'parseKeyMap accepts the key=slugs form', () => {
	const map = parseKeyMap( 'k1=a,b; k2=c ' );
	assert.deepStrictEqual( [ ...map ], [
		[ 'k1', [ 'a', 'b' ] ],
		[ 'k2', [ 'c' ] ],
	] );
} );

test( 'parseKeyMap accepts the JSON object form', () => {
	const map = parseKeyMap( '{"k1":["a","b"],"k2":"c"}' );
	assert.deepStrictEqual( [ ...map ], [
		[ 'k1', [ 'a', 'b' ] ],
		[ 'k2', [ 'c' ] ],
	] );
} );

test( 'parseKeyMap returns null for empty or malformed input', () => {
	assert.strictEqual( parseKeyMap( '' ), null );
	assert.strictEqual( parseKeyMap( '   ' ), null );
	assert.strictEqual( parseKeyMap( 'no-equals-here' ), null );
} );

test( 'envSuffix uppercases and swaps hyphens for underscores', () => {
	assert.strictEqual( envSuffix( 'site-a' ), 'SITE_A' );
	assert.strictEqual( envSuffix( 'my-site-2' ), 'MY_SITE_2' );
} );

test( 'loadConfig resolves a valid single-site configuration', () => {
	const cfg = loadConfig( GOOD_ENV );
	assert.deepStrictEqual( cfg.errors, [] );
	assert.strictEqual( cfg.keys.size, 1 );
	assert.deepStrictEqual( cfg.keys.get( KEY ), [ 'site-a' ] );
	assert.strictEqual( cfg.sites.get( 'site-a' ).token, 'op_test.SECRET' );
} );

test( 'loadConfig separates previous keys into their own map', () => {
	const cfg = loadConfig( {
		...GOOD_ENV,
		GATEWAY_PUBLIC_KEYS_PREVIOUS: 'old-key-1234567890=site-a',
	} );
	assert.strictEqual( cfg.previousKeys.size, 1 );
	assert.strictEqual( cfg.keys.size, 1 );
} );

test( 'loadConfig reports a missing site token', () => {
	const cfg = loadConfig( {
		GATEWAY_PUBLIC_KEYS: `${ KEY }=site-a`,
		NVOOS_SITE_SITE_A_URL: 'https://example.com/wp-json/mcp-ai/v1/mcp',
	} );
	assert.ok( cfg.errors.some( ( e ) => e.includes( 'NVOOS_SITE_SITE_A_TOKEN' ) ) );
} );

test( 'loadConfig rejects invalid slugs and URLs', () => {
	const cfg = loadConfig( {
		GATEWAY_PUBLIC_KEYS: `${ KEY }=BAD SLUG!,site-b`,
		NVOOS_SITE_BAD_SLUG__URL: 'https://example.com/x',
		NVOOS_SITE_BAD_SLUG__TOKEN: 'op_test.SECRET',
		NVOOS_SITE_SITE_B_URL: 'not-a-url',
		NVOOS_SITE_SITE_B_TOKEN: 'op_test.SECRET',
	} );
	assert.ok( cfg.errors.some( ( e ) => e.includes( 'BAD SLUG!' ) ) );
	assert.ok( cfg.errors.some( ( e ) => e.includes( 'invalid upstream URL' ) ) );
} );

test( 'loadConfig rejects keys referencing unknown sites', () => {
	const cfg = loadConfig( {
		GATEWAY_PUBLIC_KEYS: `${ KEY }=ghost-site`,
	} );
	assert.ok( cfg.errors.some( ( e ) => e.includes( 'ghost-site' ) ) );
} );

test( 'loadConfig fails closed with no keys in strict mode', () => {
	const cfg = loadConfig( { NVOOS_SITE_SITE_A_URL: 'https://example.com' } );
	assert.ok( cfg.errors.some( ( e ) => e.includes( 'GATEWAY_PUBLIC_KEYS' ) ) );
} );

test( 'assertConfig throws with all errors joined', () => {
	const cfg = loadConfig( {} );
	assert.throws( () => assertConfig( cfg ), /\[config\]/ );
} );
