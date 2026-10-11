/**
 * OAuth integration tests — full gateway app with a fake upstream site, a
 * fake authorization-server JWKS endpoint, and real signed tokens.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import { generateKeyPairSync, sign } from 'crypto';
import { createApp } from '../index.js';
import { startTestServer } from '../utils/test-server.js';

const STATIC_KEY = 'key-static-1234567890abcd';

function b64url( input ) {
	return Buffer.from( input ).toString( 'base64url' );
}

function signJwt( payload, privateKey, header = {} ) {
	const fullHeader = { alg: 'RS256', typ: 'JWT', kid: 'oauth-key', ...header };
	const encodedHeader = b64url( JSON.stringify( fullHeader ) );
	const encodedPayload = b64url( JSON.stringify( payload ) );
	const signature = sign( 'sha256', Buffer.from( `${ encodedHeader }.${ encodedPayload }` ), privateKey );
	return `${ encodedHeader }.${ encodedPayload }.${ b64url( signature ) }`;
}

const NOW = 1_800_000_000_000;

function validPayload( overrides = {} ) {
	return {
		iss: 'https://auth.example.com/',
		sub: 'auth0|user-1',
		aud: 'https://mcp.nvoos.pro',
		exp: Math.floor( NOW / 1000 ) + 3600,
		iat: Math.floor( NOW / 1000 ),
		scope: 'site:read site:site-a',
		...overrides,
	};
}

/** Fake upstream NV oOS site. */
async function startFakeSite() {
	const app = express();
	app.use( express.json() );
	app.post( '/wp-json/mcp-ai/v1/mcp', ( req, res ) => {
		const { id, method } = req.body;
		if ( 'tools/list' === method ) {
			return res.json( {
				jsonrpc: '2.0',
				id,
				result: { tools: [ { name: 'make_site_a', description: 'd', inputSchema: { type: 'object' } } ] },
			} );
		}
		return res.json( { jsonrpc: '2.0', id, result: {} } );
	} );
	const { baseUrl, close } = await startTestServer( app );
	return { baseUrl: `${ baseUrl }/wp-json/mcp-ai/v1/mcp`, close };
}

/** Fake authorization-server JWKS endpoint. */
async function startJwksServer( jwk ) {
	const app = express();
	app.get( '/.well-known/jwks.json', ( _req, res ) => res.json( { keys: [ jwk ] } ) );
	const { baseUrl, close } = await startTestServer( app );
	return { jwksUri: `${ baseUrl }/.well-known/jwks.json`, close };
}

/** Gateway config with one site, one static key, and optional OAuth. */
function buildConfig( { site, oauth } ) {
	return {
		keys: new Map( [ [ STATIC_KEY, [ 'site-a' ] ] ] ),
		previousKeys: new Map(),
		sites: new Map( [ [ 'site-a', { slug: 'site-a', url: site.baseUrl, token: 'op_site_a.SECRET' } ] ] ),
		oauth: oauth || { enabled: false, issuer: '', jwksUri: '', resource: 'https://mcp.nvoos.pro' },
		errors: [],
	};
}

async function post( baseUrl, body, key ) {
	return fetch( `${ baseUrl }/mcp`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			...( key ? { Authorization: `Bearer ${ key }` } : {} ),
		},
		body: JSON.stringify( body ),
	} );
}

test( 'well-known answers 404 while OAuth is not configured', async () => {
	const site = await startFakeSite();
	const { baseUrl, close } = await startTestServer( createApp( { config: buildConfig( { site } ) } ) );

	const res = await fetch( `${ baseUrl }/.well-known/oauth-protected-resource` );
	assert.strictEqual( res.status, 404 );

	await close();
	await site.close();
} );

test( 'well-known serves the RFC 9728 document (root + /mcp variant) when configured', async () => {
	const site = await startFakeSite();
	const { publicKey } = generateKeyPairSync( 'rsa', { modulusLength: 2048 } );
	const jwk = publicKey.export( { format: 'jwk' } );
	jwk.kid = 'oauth-key';
	jwk.alg = 'RS256';
	jwk.use = 'sig';
	const jwks = await startJwksServer( jwk );

	const oauth = {
		enabled: true,
		issuer: 'https://auth.example.com/',
		jwksUri: jwks.jwksUri,
		resource: 'https://mcp.nvoos.pro',
	};
	const { baseUrl, close } = await startTestServer( createApp( { config: buildConfig( { site, oauth } ) } ) );

	const root = await ( await fetch( `${ baseUrl }/.well-known/oauth-protected-resource` ) ).json();
	assert.strictEqual( root.resource, 'https://mcp.nvoos.pro' );
	assert.deepStrictEqual( root.authorization_servers, [ 'https://auth.example.com/' ] );
	assert.deepStrictEqual( root.bearer_methods_supported, [ 'header' ] );
	assert.ok( root.scopes_supported.includes( 'site:read' ) );
	assert.ok( root.scopes_supported.includes( 'site:site-a' ) );
	assert.ok( root.resource_documentation );

	const variant = await ( await fetch( `${ baseUrl }/.well-known/oauth-protected-resource/mcp` ) ).json();
	assert.strictEqual( variant.resource, 'https://mcp.nvoos.pro/mcp' );

	await close();
	await site.close();
	await jwks.close();
} );

test( 'missing credentials challenge with RFC 9728 metadata when OAuth is enabled', async () => {
	const site = await startFakeSite();
	const { publicKey } = generateKeyPairSync( 'rsa', { modulusLength: 2048 } );
	const jwk = publicKey.export( { format: 'jwk' } );
	jwk.kid = 'oauth-key';
	const jwks = await startJwksServer( jwk );

	const oauth = { enabled: true, issuer: 'https://auth.example.com/', jwksUri: jwks.jwksUri, resource: 'https://mcp.nvoos.pro' };
	const { baseUrl, close } = await startTestServer( createApp( { config: buildConfig( { site, oauth } ) } ) );

	const res = await post( baseUrl, { jsonrpc: '2.0', id: 1, method: 'ping', params: {} } );
	assert.strictEqual( res.status, 401 );
	assert.match( res.headers.get( 'www-authenticate' ), /resource_metadata="https:\/\/mcp\.nvoos\.pro\/\.well-known\/oauth-protected-resource"/ );
	assert.match( res.headers.get( 'www-authenticate' ), /scope="site:read site:site-a"/ );

	await close();
	await site.close();
	await jwks.close();
} );

test( 'static keys keep working alongside OAuth', async () => {
	const site = await startFakeSite();
	const { publicKey } = generateKeyPairSync( 'rsa', { modulusLength: 2048 } );
	const jwk = publicKey.export( { format: 'jwk' } );
	jwk.kid = 'oauth-key';
	const jwks = await startJwksServer( jwk );

	const oauth = { enabled: true, issuer: 'https://auth.example.com/', jwksUri: jwks.jwksUri, resource: 'https://mcp.nvoos.pro' };
	const { baseUrl, close } = await startTestServer( createApp( { config: buildConfig( { site, oauth } ) } ) );

	const body = await (
		await post( baseUrl, { jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} }, STATIC_KEY )
	).json();
	assert.deepStrictEqual( body.result.tools.map( ( t ) => t.name ), [ 'site-a.make_site_a' ] );

	await close();
	await site.close();
	await jwks.close();
} );

test( 'a valid OAuth token is scoped to its site:<slug> grants', async () => {
	const site = await startFakeSite();
	const { publicKey, privateKey } = generateKeyPairSync( 'rsa', { modulusLength: 2048 } );
	const jwk = publicKey.export( { format: 'jwk' } );
	jwk.kid = 'oauth-key';
	jwk.alg = 'RS256';
	jwk.use = 'sig';
	const jwks = await startJwksServer( jwk );

	const oauth = { enabled: true, issuer: 'https://auth.example.com/', jwksUri: jwks.jwksUri, resource: 'https://mcp.nvoos.pro' };
	const { baseUrl, close } = await startTestServer( createApp( { config: buildConfig( { site, oauth } ) } ) );

	const token = signJwt( validPayload(), privateKey );
	const body = await (
		await post( baseUrl, { jsonrpc: '2.0', id: 3, method: 'tools/list', params: {} }, token )
	).json();
	assert.deepStrictEqual( body.result.tools.map( ( t ) => t.name ), [ 'site-a.make_site_a' ] );

	await close();
	await site.close();
	await jwks.close();
} );

test( 'a token without site scopes gets 403 insufficient_scope', async () => {
	const site = await startFakeSite();
	const { publicKey, privateKey } = generateKeyPairSync( 'rsa', { modulusLength: 2048 } );
	const jwk = publicKey.export( { format: 'jwk' } );
	jwk.kid = 'oauth-key';
	jwk.alg = 'RS256';
	jwk.use = 'sig';
	const jwks = await startJwksServer( jwk );

	const oauth = { enabled: true, issuer: 'https://auth.example.com/', jwksUri: jwks.jwksUri, resource: 'https://mcp.nvoos.pro' };
	const { baseUrl, close } = await startTestServer( createApp( { config: buildConfig( { site, oauth } ) } ) );

	const token = signJwt( validPayload( { scope: 'site:read site:elsewhere' } ), privateKey );
	const res = await post( baseUrl, { jsonrpc: '2.0', id: 4, method: 'tools/list', params: {} }, token );
	assert.strictEqual( res.status, 403 );
	assert.match( res.headers.get( 'www-authenticate' ), /error="insufficient_scope"/ );
	assert.deepStrictEqual( ( await res.json() ).error, 'insufficient_scope' );

	await close();
	await site.close();
	await jwks.close();
} );

test( 'a tampered token gets 401 with the RFC 9728 challenge', async () => {
	const site = await startFakeSite();
	const { publicKey, privateKey } = generateKeyPairSync( 'rsa', { modulusLength: 2048 } );
	const jwk = publicKey.export( { format: 'jwk' } );
	jwk.kid = 'oauth-key';
	jwk.alg = 'RS256';
	jwk.use = 'sig';
	const jwks = await startJwksServer( jwk );

	const oauth = { enabled: true, issuer: 'https://auth.example.com/', jwksUri: jwks.jwksUri, resource: 'https://mcp.nvoos.pro' };
	const { baseUrl, close } = await startTestServer( createApp( { config: buildConfig( { site, oauth } ) } ) );

	const good = signJwt( validPayload(), privateKey );
	const parts = good.split( '.' );
	// Same signing key, but the payload claims change — the signature no
	// longer matches, which is exactly what a tampered token looks like.
	const tampered = `${ parts[ 0 ] }.${ b64url( JSON.stringify( validPayload( { sub: 'auth0|evil-user' } ) ) ) }.${ parts[ 2 ] }`;

	const res = await post( baseUrl, { jsonrpc: '2.0', id: 5, method: 'tools/list', params: {} }, tampered );
	assert.strictEqual( res.status, 401 );
	assert.match( res.headers.get( 'www-authenticate' ), /resource_metadata=/ );

	await close();
	await site.close();
	await jwks.close();
} );
