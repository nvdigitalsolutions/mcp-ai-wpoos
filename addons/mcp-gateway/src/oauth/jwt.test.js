/**
 * Unit tests for OAuth access-token validation (real RSA/EC keys, fake JWKS
 * transport, injected clock and cache — no network, no dependencies).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { generateKeyPairSync, sign } from 'crypto';
import {
	looksLikeJwt,
	audienceMatches,
	validateAccessToken,
	fetchJwks,
} from './jwt.js';

/** Base64url-encode a buffer. */
function b64url( input ) {
	return Buffer.from( input ).toString( 'base64url' );
}

/** Build an RS256 keypair + JWKS entry. */
function makeRsaKeys( kid = 'test-key' ) {
	const { publicKey, privateKey } = generateKeyPairSync( 'rsa', { modulusLength: 2048 } );
	const jwk = publicKey.export( { format: 'jwk' } );
	jwk.kid = kid;
	jwk.alg = 'RS256';
	jwk.use = 'sig';
	return { privateKey, jwk };
}

/** Sign a compact JWT (RS256 by default; alg/kid overridable). */
function signJwt( payload, privateKey, header = {} ) {
	const fullHeader = { alg: 'RS256', typ: 'JWT', kid: 'test-key', ...header };
	const encodedHeader = b64url( JSON.stringify( fullHeader ) );
	const encodedPayload = b64url( JSON.stringify( payload ) );
	const signature = sign( 'sha256', Buffer.from( `${ encodedHeader }.${ encodedPayload }` ), privateKey );
	return `${ encodedHeader }.${ encodedPayload }.${ b64url( signature ) }`;
}

const OAUTH_CFG = {
	enabled: true,
	issuer: 'https://auth.example.com/',
	jwksUri: 'https://auth.example.com/.well-known/jwks.json',
	resource: 'https://mcp.nvoos.pro',
};

const NOW = 1_800_000_000_000; // ms epoch.

/** Fake JWKS transport with a call counter. */
function fakeFetch( jwk ) {
	let calls = 0;
	const impl = async () => {
		calls += 1;
		return { ok: true, json: async () => ( { keys: [ jwk ] } ) };
	};
	impl.calls = () => calls;
	return impl;
}

function validPayload( overrides = {} ) {
	return {
		iss: 'https://auth.example.com/',
		sub: 'auth0|user-1',
		aud: 'https://mcp.nvoos.pro',
		exp: Math.floor( NOW / 1000 ) + 3600,
		iat: Math.floor( NOW / 1000 ),
		scope: 'site:read site:demo',
		...overrides,
	};
}

test( 'looksLikeJwt distinguishes JWTs from static keys', () => {
	assert.equal( looksLikeJwt( 'header.payload.sig' ), true );
	assert.equal( looksLikeJwt( 'UQgWrYOdr46QmR3PtUDRt3Je5W92tJWbwMpHN5fVS9D' ), false );
	assert.equal( looksLikeJwt( 'not-a-token' ), false );
	assert.equal( looksLikeJwt( '' ), false );
} );

test( 'audienceMatches accepts the resource, trailing slash, and /mcp variants', () => {
	assert.equal( audienceMatches( 'https://mcp.nvoos.pro', 'https://mcp.nvoos.pro' ), true );
	assert.equal( audienceMatches( 'https://mcp.nvoos.pro/', 'https://mcp.nvoos.pro' ), true );
	assert.equal( audienceMatches( 'https://mcp.nvoos.pro/mcp', 'https://mcp.nvoos.pro' ), true );
	assert.equal( audienceMatches( [ 'other', 'https://mcp.nvoos.pro' ], 'https://mcp.nvoos.pro' ), true );
	assert.equal( audienceMatches( 'https://evil.example', 'https://mcp.nvoos.pro' ), false );
	assert.equal( audienceMatches( [], 'https://mcp.nvoos.pro' ), false );
} );

test( 'a valid token returns claims and parsed scopes', async () => {
	const { privateKey, jwk } = makeRsaKeys();
	const fetchImpl = fakeFetch( jwk );
	const token = signJwt( validPayload(), privateKey );

	const result = await validateAccessToken( token, OAUTH_CFG, {
		now: () => NOW,
		fetchImpl,
		cache: new Map(),
	} );

	assert.equal( result.ok, true );
	assert.equal( result.claims.sub, 'auth0|user-1' );
	assert.deepEqual( result.scopes, [ 'site:read', 'site:demo' ] );
	assert.equal( fetchImpl.calls(), 1 );
} );

test( 'a token signed with a different key fails closed', async () => {
	const { jwk } = makeRsaKeys();
	const { privateKey: evilKey } = makeRsaKeys();
	const token = signJwt( validPayload(), evilKey );

	const result = await validateAccessToken( token, OAUTH_CFG, {
		now: () => NOW,
		fetchImpl: fakeFetch( jwk ),
		cache: new Map(),
	} );

	assert.equal( result.ok, false );
	assert.equal( result.error, 'bad_signature' );
} );

test( 'issuer, audience, and expiry mismatches are each rejected', async () => {
	const { privateKey, jwk } = makeRsaKeys();
	const deps = { now: () => NOW, fetchImpl: fakeFetch( jwk ) };

	const cases = [
		{ payload: validPayload( { iss: 'https://evil.example/' } ), error: 'invalid_issuer' },
		{ payload: validPayload( { aud: 'https://evil.example' } ), error: 'invalid_audience' },
		{ payload: validPayload( { exp: Math.floor( NOW / 1000 ) - 120 } ), error: 'expired_token' },
		{ payload: validPayload( { nbf: Math.floor( NOW / 1000 ) + 3600 } ), error: 'invalid_token' },
	];
	for ( const { payload, error } of cases ) {
		const result = await validateAccessToken( signJwt( payload, privateKey ), OAUTH_CFG, {
			...deps,
			cache: new Map(),
		} );
		assert.equal( result.error, error, `${ error } expected for ${ JSON.stringify( payload ).slice( 0, 80 ) }` );
	}
} );

test( 'a token with no kid or an allowlist-excluded alg is rejected', async () => {
	const { privateKey, jwk } = makeRsaKeys();
	const deps = { now: () => NOW, fetchImpl: fakeFetch( jwk ) };

	const noKid = await validateAccessToken(
		signJwt( validPayload(), privateKey, { kid: '' } ),
		OAUTH_CFG,
		{ ...deps, cache: new Map() }
	);
	assert.equal( noKid.error, 'invalid_token' );

	const algNone = await validateAccessToken(
		signJwt( validPayload(), privateKey, { alg: 'none', kid: 'test-key' } ),
		OAUTH_CFG,
		{ ...deps, cache: new Map() }
	);
	assert.equal( algNone.error, 'invalid_token' );
} );

test( 'an unknown kid refetches once, then fails closed', async () => {
	const { jwk } = makeRsaKeys( 'other-key' );
	const { privateKey } = makeRsaKeys( 'missing-key' );
	const fetchImpl = fakeFetch( jwk );
	const token = signJwt( validPayload(), privateKey, { kid: 'missing-key' } );

	// Pre-warm the cache with a key set that predates the rotation: it is
	// fresh but lacks the token's kid, so the validator must refetch once.
	const cache = new Map();
	cache.set( OAUTH_CFG.jwksUri, { fetchedAt: NOW, keys: new Map( [ [ 'old-key', jwk ] ] ) } );

	const result = await validateAccessToken( token, OAUTH_CFG, {
		now: () => NOW,
		fetchImpl,
		cache,
	} );

	assert.equal( result.error, 'unknown_kid' );
	assert.equal( fetchImpl.calls(), 2, 'one stale-cache fetch + one rotation refetch' );
} );

test( 'a failing JWKS transport fails closed', async () => {
	const { privateKey, jwk } = makeRsaKeys();
	const token = signJwt( validPayload(), privateKey );

	const result = await validateAccessToken( token, OAUTH_CFG, {
		now: () => NOW,
		fetchImpl: async () => {
			throw new Error( 'network down' );
		},
		cache: new Map(),
	} );

	assert.equal( result.error, 'unknown_kid' );
} );

test( 'the JWKS cache serves repeat validations without refetching', async () => {
	const { privateKey, jwk } = makeRsaKeys();
	const fetchImpl = fakeFetch( jwk );
	const cache = new Map();
	const deps = { now: () => NOW, fetchImpl, cache };

	const first = await validateAccessToken( signJwt( validPayload(), privateKey ), OAUTH_CFG, deps );
	const second = await validateAccessToken( signJwt( validPayload(), privateKey ), OAUTH_CFG, deps );

	assert.equal( first.ok, true );
	assert.equal( second.ok, true );
	assert.equal( fetchImpl.calls(), 1 );
} );

test( 'a stale cache entry refetches', async () => {
	const { privateKey, jwk } = makeRsaKeys();
	const fetchImpl = fakeFetch( jwk );
	const cache = new Map();
	const deps = { now: () => NOW, fetchImpl, cache, ttlMs: 1000 };

	await validateAccessToken( signJwt( validPayload(), privateKey ), OAUTH_CFG, deps );
	await validateAccessToken( signJwt( validPayload(), privateKey ), OAUTH_CFG, {
		...deps,
		now: () => NOW + 2000,
	} );

	assert.equal( fetchImpl.calls(), 2 );
} );

test( 'ES256 tokens verify against an EC JWK', async () => {
	const { publicKey, privateKey } = generateKeyPairSync( 'ec', { namedCurve: 'P-256' } );
	const jwk = publicKey.export( { format: 'jwk' } );
	jwk.kid = 'ec-key';
	jwk.alg = 'ES256';
	jwk.use = 'sig';

	const encodedHeader = b64url( JSON.stringify( { alg: 'ES256', typ: 'JWT', kid: 'ec-key' } ) );
	const encodedPayload = b64url( JSON.stringify( validPayload() ) );
	const signature = sign( 'sha256', Buffer.from( `${ encodedHeader }.${ encodedPayload }` ), privateKey );
	const token = `${ encodedHeader }.${ encodedPayload }.${ b64url( signature ) }`;

	const result = await validateAccessToken( token, OAUTH_CFG, {
		now: () => NOW,
		fetchImpl: fakeFetch( jwk ),
		cache: new Map(),
	} );

	assert.equal( result.ok, true );
} );

test( 'validateAccessToken is inert when OAuth is not configured', async () => {
	const result = await validateAccessToken( 'anything', { enabled: false }, { now: () => NOW } );
	assert.equal( result.error, 'oauth_disabled' );
} );

test( 'fetchJwks rejects malformed documents', async () => {
	assert.equal( await fetchJwks( 'https://a/jwks', async () => ( { ok: true, json: async () => ( { nope: 1 } ) } ) ), null );
	assert.equal(
		await fetchJwks( 'https://a/jwks', async () => ( { ok: false, json: async () => ( {} ) } ) ),
		null
	);
} );
