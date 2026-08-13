/**
 * Tests for per-site provider credential resolution (proposal 027, Phase 2a).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	getCredential,
	configuredProviders,
	parseSiteProviderKeys,
	isProviderKeysStrict,
	resetProviderKeysCache,
} from './provider-keys.js';

const originalKeys = process.env.SITE_PROVIDER_KEYS;
const originalStrict = process.env.PROVIDER_KEYS_STRICT;
const originalOpenAI = process.env.OPENAI_API_KEY;
const originalFirefly = process.env.FIREFLY_CLIENT_SECRET;

function resetEnv() {
	if ( originalKeys ) {
		process.env.SITE_PROVIDER_KEYS = originalKeys;
	} else {
		delete process.env.SITE_PROVIDER_KEYS;
	}
	if ( originalStrict ) {
		process.env.PROVIDER_KEYS_STRICT = originalStrict;
	} else {
		delete process.env.PROVIDER_KEYS_STRICT;
	}
	if ( originalOpenAI ) {
		process.env.OPENAI_API_KEY = originalOpenAI;
	} else {
		delete process.env.OPENAI_API_KEY;
	}
	if ( originalFirefly ) {
		process.env.FIREFLY_CLIENT_SECRET = originalFirefly;
	} else {
		delete process.env.FIREFLY_CLIENT_SECRET;
	}
	resetProviderKeysCache();
}

test( 'parseSiteProviderKeys parses valid maps and rejects garbage', () => {
	process.env.SITE_PROVIDER_KEYS = '{"site-a":{"openai_api_key":"sk-A"},"site-b":{"gemini_api_key":"AIza-B"}}';
	assert.deepEqual( parseSiteProviderKeys(), { 'site-a': { openai_api_key: 'sk-A' }, 'site-b': { gemini_api_key: 'AIza-B' } } );

	resetProviderKeysCache();
	process.env.SITE_PROVIDER_KEYS = 'not json';
	assert.equal( parseSiteProviderKeys(), null );

	resetProviderKeysCache();
	process.env.SITE_PROVIDER_KEYS = '[1,2]';
	assert.equal( parseSiteProviderKeys(), null );

	resetProviderKeysCache();
	process.env.SITE_PROVIDER_KEYS = '{"site-a":{"openai_api_key":42}}';
	assert.equal( parseSiteProviderKeys(), null );

	resetEnv();
} );

test( 'getCredential prefers the per-site key over the shared pool', () => {
	process.env.OPENAI_API_KEY = 'sk-POOL';
	process.env.SITE_PROVIDER_KEYS = '{"site-a":{"openai_api_key":"sk-SITE"}}';
	assert.equal( getCredential( 'site-a', 'OPENAI_API_KEY' ), 'sk-SITE' );
	resetEnv();
} );

test( 'getCredential falls back to the pool when the site has no entry', () => {
	process.env.OPENAI_API_KEY = 'sk-POOL';
	process.env.SITE_PROVIDER_KEYS = '{"site-a":{"gemini_api_key":"AIza-A"}}';
	assert.equal( getCredential( 'site-a', 'OPENAI_API_KEY' ), 'sk-POOL' );
	resetEnv();
} );

test( 'getCredential returns null when nothing is configured', () => {
	delete process.env.OPENAI_API_KEY;
	process.env.SITE_PROVIDER_KEYS = '{"site-a":{"gemini_api_key":"AIza-A"}}';
	assert.equal( getCredential( 'site-a', 'OPENAI_API_KEY' ), null );
	resetEnv();
} );

test( 'PROVIDER_KEYS_STRICT disables the pool fallback in multi-tenant mode', () => {
	process.env.OPENAI_API_KEY = 'sk-POOL';
	process.env.PROVIDER_KEYS_STRICT = '1';
	process.env.SITE_PROVIDER_KEYS = '{"site-a":{"gemini_api_key":"AIza-A"}}';
	assert.equal( isProviderKeysStrict(), true );
	assert.equal( getCredential( 'site-a', 'OPENAI_API_KEY' ), null );
	assert.equal( getCredential( 'site-a', 'GEMINI_API_KEY' ), 'AIza-A' );
	resetEnv();
} );

test( 'single-tenant mode ignores SITE_PROVIDER_KEYS and uses the pool', () => {
	process.env.OPENAI_API_KEY = 'sk-POOL';
	process.env.SITE_PROVIDER_KEYS = '{"site-a":{"openai_api_key":"sk-SITE"}}';
	assert.equal( getCredential( 'default', 'OPENAI_API_KEY' ), 'sk-POOL' );
	resetEnv();
} );

test( 'multi-part providers resolve as independent flat entries', () => {
	process.env.FIREFLY_CLIENT_SECRET = 'sec-POOL';
	process.env.SITE_PROVIDER_KEYS = '{"site-a":{"firefly_client_id":"id-SITE"}}';
	assert.equal( getCredential( 'site-a', 'FIREFLY_CLIENT_ID' ), 'id-SITE' );
	assert.equal( getCredential( 'site-a', 'FIREFLY_CLIENT_SECRET' ), 'sec-POOL' );
	resetEnv();
} );

test( 'configuredProviders reports booleans per site without values', () => {
	process.env.OPENAI_API_KEY = 'sk-POOL';
	process.env.SITE_PROVIDER_KEYS = '{"site-a":{"gemini_api_key":"AIza-A"}}';
	const providers = configuredProviders( 'site-a' );
	assert.equal( providers.OPENAI_API_KEY, true );
	assert.equal( providers.GEMINI_API_KEY, true );
	assert.equal( providers.TWITTER_API_KEY, false );
	resetEnv();
} );
