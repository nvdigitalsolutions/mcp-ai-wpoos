/**
 * Tests for per-site provider credential resolution (proposal 027, Phase 2a).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {
	getCredential,
	configuredProviders,
	parseSiteProviderKeys,
	isProviderKeysStrict,
	resetProviderKeysCache,
	reloadProviderKeysFromFile,
} from './provider-keys.js';

const originalKeys = process.env.SITE_PROVIDER_KEYS;
const originalStrict = process.env.PROVIDER_KEYS_STRICT;
const originalOpenAI = process.env.OPENAI_API_KEY;
const originalFirefly = process.env.FIREFLY_CLIENT_SECRET;
const originalSiteEnv = process.env.SITE_PROVIDER_KEYS_SITE_A;
const originalFile = process.env.PROVIDER_KEYS_FILE;

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
	if ( originalSiteEnv ) {
		process.env.SITE_PROVIDER_KEYS_SITE_A = originalSiteEnv;
	} else {
		delete process.env.SITE_PROVIDER_KEYS_SITE_A;
	}
	if ( originalFile ) {
		process.env.PROVIDER_KEYS_FILE = originalFile;
	} else {
		delete process.env.PROVIDER_KEYS_FILE;
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

// ── Phase 3: per-site env fallback + provider key file ───────

test( 'SITE_PROVIDER_KEYS_<SLUG> env vars merge over the global JSON (W3)', () => {
	process.env.OPENAI_API_KEY = 'sk-POOL';
	process.env.SITE_PROVIDER_KEYS = '{"site-a":{"openai_api_key":"sk-JSON"}}';
	process.env.SITE_PROVIDER_KEYS_SITE_A = '{"openai_api_key":"sk-ENV"}';

	// The per-site env var wins over the global JSON entry.
	assert.equal( getCredential( 'site-a', 'OPENAI_API_KEY' ), 'sk-ENV' );
	// Pool fallback still applies where neither is set.
	assert.equal( getCredential( 'site-b', 'OPENAI_API_KEY' ), 'sk-POOL' );
	resetEnv();
} );

test( 'PROVIDER_KEYS_FILE loads and hot-replaces the file-sourced map (W5)', () => {
	const filePath = path.join( os.tmpdir(), `provider-keys-test-${ Date.now() }.json` );
	fs.writeFileSync( filePath, '{"site-a":{"openai_api_key":"sk-FILE1"},"site-b":{"gemini_api_key":"AIza-B"}}' );

	process.env.PROVIDER_KEYS_FILE = filePath;
	assert.equal( getCredential( 'site-a', 'OPENAI_API_KEY' ), 'sk-FILE1' );
	assert.equal( getCredential( 'site-b', 'GEMINI_API_KEY' ), 'AIza-B' );

	// Rewrite the file without site-b: reload must REPLACE, not merge.
	fs.writeFileSync( filePath, '{"site-a":{"openai_api_key":"sk-FILE2"}}' );
	assert.equal( reloadProviderKeysFromFile(), true );
	assert.equal( getCredential( 'site-a', 'OPENAI_API_KEY' ), 'sk-FILE2' );
	assert.equal( getCredential( 'site-b', 'GEMINI_API_KEY' ), null );

	// Malformed update is rejected and the previous map stays active.
	fs.writeFileSync( filePath, 'not json' );
	assert.equal( reloadProviderKeysFromFile(), false );
	assert.equal( getCredential( 'site-a', 'OPENAI_API_KEY' ), 'sk-FILE2' );

	fs.rmSync( filePath, { force: true } );
	resetEnv();
} );
