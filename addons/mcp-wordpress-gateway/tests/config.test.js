/**
 * Config module tests: fail-closed validation, timing-safe auth, list parsing.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { loadConfig, authorize, parseCsvList } from '../src/config.js';

const LONG_TOKEN = 'a'.repeat( 40 );
const OTHER_TOKEN = 'b'.repeat( 40 );

test( 'loadConfig rejects a missing token', () => {
	const config = loadConfig( {} );
	assert.equal( config.valid, false );
	assert.ok( config.errors.some( ( error ) => error.includes( 'MCP_GATEWAY_TOKEN' ) ) );
} );

test( 'loadConfig rejects a short token', () => {
	const config = loadConfig( { MCP_GATEWAY_TOKEN: 'short' } );
	assert.equal( config.valid, false );
} );

test( 'loadConfig accepts a valid token and applies defaults', () => {
	const config = loadConfig( { MCP_GATEWAY_TOKEN: LONG_TOKEN } );
	assert.equal( config.valid, true );
	assert.equal( config.port, 3000 );
	assert.equal( config.internalPort, 8000 );
	assert.equal( config.upstreamUrl, 'http://127.0.0.1:8000/mcp' );
	assert.deepEqual( config.allow, [ '*' ] );
	assert.deepEqual( config.deny, [] );
} );

test( 'loadConfig parses allow and deny policy lists', () => {
	const config = loadConfig( {
		MCP_GATEWAY_TOKEN: LONG_TOKEN,
		MCP_TOOLS_ALLOW: 'wp_list_*, wp_get_post',
		MCP_TOOLS_DENY: ' wp_delete_* ,wp_create_user',
	} );
	assert.deepEqual( config.allow, [ 'wp_list_*', 'wp_get_post' ] );
	assert.deepEqual( config.deny, [ 'wp_delete_*', 'wp_create_user' ] );
} );

test( 'authorize accepts the exact token and rejects others', () => {
	const config = loadConfig( { MCP_GATEWAY_TOKEN: LONG_TOKEN } );
	assert.equal( authorize( config, LONG_TOKEN ), true );
	assert.equal( authorize( config, OTHER_TOKEN ), false );
	assert.equal( authorize( config, '' ), false );
	assert.equal( authorize( config, LONG_TOKEN + 'x' ), false );
} );

test( 'authorize honours the rotation-window previous token', () => {
	const config = loadConfig( { MCP_GATEWAY_TOKEN: LONG_TOKEN, MCP_GATEWAY_TOKEN_PREVIOUS: OTHER_TOKEN } );
	assert.equal( authorize( config, OTHER_TOKEN ), true );
} );

test( 'authorize fails closed on an invalid config', () => {
	const config = loadConfig( {} );
	assert.equal( authorize( config, 'anything' ), false );
} );

test( 'parseCsvList trims and drops empties', () => {
	assert.deepEqual( parseCsvList( ' a, , b ,' ), [ 'a', 'b' ] );
	assert.deepEqual( parseCsvList( '' ), [] );
} );
