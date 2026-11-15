/**
 * OpenAI domain-verification challenge tests — exact-token plain-text
 * contract, 404 while unset, no caching.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createApp } from '../index.js';
import { startTestServer } from '../utils/test-server.js';

const KEY = 'key-1234567890abcdef';

function makeConfig( challengeToken = '' ) {
	return {
		keys: new Map( [ [ KEY, [ 'site-a' ] ] ] ),
		previousKeys: new Map(),
		sites: new Map( [
			[ 'site-a', { slug: 'site-a', url: 'https://a.example.com/wp-json/mcp-ai/v1/mcp', token: 'op_a.SECRET' } ],
		] ),
		errors: [],
		openaiChallengeToken: challengeToken,
	};
}

test( 'challenge route answers 404 while the token is unset', async () => {
	const { baseUrl, close } = await startTestServer( createApp( { config: makeConfig() } ) );
	const res = await fetch( `${ baseUrl }/.well-known/openai-apps-challenge` );
	assert.strictEqual( res.status, 404 );
	const body = await res.json();
	assert.strictEqual( body.error, 'challenge_not_configured' );
	await close();
} );

test( 'challenge route serves the exact token as plain text with no-store headers', async () => {
	const token = 'oa-challenge-token-abc123';
	const { baseUrl, close } = await startTestServer( createApp( { config: makeConfig( token ) } ) );
	const res = await fetch( `${ baseUrl }/.well-known/openai-apps-challenge` );
	assert.strictEqual( res.status, 200 );
	assert.match( res.headers.get( 'content-type' ) || '', /^text\/plain/ );
	assert.strictEqual( res.headers.get( 'cache-control' ), 'no-store' );
	// Exact body — no JSON wrapper, no trailing newline (the portal
	// compares the raw token byte-for-byte).
	assert.strictEqual( await res.text(), token );
	await close();
} );
