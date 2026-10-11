/**
 * Tests for STATUS_EXTERNAL_TARGETS parsing (config.js).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseExternalTargets, siteEnvKey } from './config.js';

test( 'parses one slug=url pair', () => {
	assert.deepEqual( parseExternalTargets( 'gateway=https://mcp.nvoos.pro/health' ), [
		{ slug: 'gateway', url: 'https://mcp.nvoos.pro/health' },
	] );
} );

test( 'parses multiple semicolon-separated pairs with whitespace', () => {
	const targets = parseExternalTargets(
		' gateway = https://a.example/health ; registry=https://b.example/health '
	);
	assert.deepEqual( targets, [
		{ slug: 'gateway', url: 'https://a.example/health' },
		{ slug: 'registry', url: 'https://b.example/health' },
	] );
} );

test( 'normalizes slugs to lowercase hyphenated tokens capped at 64 chars', () => {
	const long = 'x'.repeat( 80 );
	const targets = parseExternalTargets( 'My_Gateway!!' + long + '=https://c.example/h' );
	assert.equal( targets.length, 1 );
	assert.equal( targets[ 0 ].slug, ( 'my-gateway-' + long ).slice( 0, 64 ) );
	assert.equal( targets[ 0 ].url, 'https://c.example/h' );
} );

test( 'drops malformed entries: no equals, empty slug, empty url', () => {
	assert.deepEqual( parseExternalTargets( 'nonsense;=https://a.example/h;slug=' ), [] );
} );

test( 'drops non-http(s) and unparsable urls', () => {
	assert.deepEqual( parseExternalTargets( 'a=ftp://host/x;b=not a url;c=javascript:alert(1)' ), [] );
} );

test( 'empty or non-string input yields no targets', () => {
	assert.deepEqual( parseExternalTargets( '' ), [] );
	assert.deepEqual( parseExternalTargets( undefined ), [] );
	assert.deepEqual( parseExternalTargets( null ), [] );
} );

test( 'siteEnvKey maps slug characters to env-safe names', () => {
	assert.equal( siteEnvKey( 'SYNTHETIC_URL', 'mcp-gateway' ), 'STATUS_MCP_GATEWAY_SYNTHETIC_URL' );
} );
