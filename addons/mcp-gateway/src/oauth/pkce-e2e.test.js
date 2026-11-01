/**
 * PKCE end-to-end harness — Proposal 099 Phase 0/3.
 *
 * Simulates the ChatGPT native MCP connector's client flow against the
 * gateway, end to end, with a fake in-process authorization server:
 *
 *   resource metadata (RFC 9728)  →  AS metadata (RFC 8414, S256 + DCR
 *   required by OpenAI)  →  dynamic client registration (RFC 7591)  →
 *   authorization code + PKCE S256 (RFC 7636)  →  token exchange  →
 *   namespaced tools/list through the gateway's RFC 9728 validation.
 *
 * Industry requirements pinned 2026-11-01 (OpenAI "Authentication –
 * Plugins" docs): the AS metadata MUST advertise `registration_endpoint`
 * and `code_challenge_methods_supported` containing `S256` — MCP servers
 * whose authorization server omits either are unsupported.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import { createHash, generateKeyPairSync, randomUUID, sign } from 'crypto';
import { createApp } from '../index.js';
import { startTestServer } from '../utils/test-server.js';

const STATIC_KEY = 'key-static-1234567890abcd';
const RESOURCE = 'https://mcp.nvoos.pro';
const AS_ISSUER_PATH = '/oauth';
const REDIRECT_URI = 'http://localhost/chatgpt/callback';

function b64url( input ) {
	return Buffer.from( input ).toString( 'base64url' );
}

/** PKCE S256 challenge from a verifier (RFC 7636 §4.2). */
function pkceChallenge( verifier ) {
	return createHash( 'sha256' ).update( verifier ).digest( 'base64url' );
}

function signJwt( payload, privateKey, header = {} ) {
	const fullHeader = { alg: 'RS256', typ: 'JWT', kid: 'as-key', ...header };
	const encodedHeader = b64url( JSON.stringify( fullHeader ) );
	const encodedPayload = b64url( JSON.stringify( payload ) );
	const signature = sign( 'sha256', Buffer.from( `${ encodedHeader }.${ encodedPayload }` ), privateKey );
	return `${ encodedHeader }.${ encodedPayload }.${ b64url( signature ) }`;
}

function formEncode( fields ) {
	const params = new URLSearchParams();
	for ( const [ key, value ] of Object.entries( fields ) ) {
		if ( Array.isArray( value ) ) {
			// Repeated keys — express.urlencoded (qs) parses them back to arrays.
			for ( const item of value ) {
				params.append( key, String( item ) );
			}
		} else {
			params.append( key, String( value ) );
		}
	}
	return params.toString();
}

/**
 * Fake authorization server: RFC 8414 metadata, JWKS, RFC 7591 dynamic
 * registration, an authorize endpoint that issues codes via a redirect
 * (no browser needed — the test reads the Location header), and a token
 * endpoint enforcing PKCE S256.
 *
 * @param {object} jwk      Public JWK (kid `as-key`).
 * @param {object} keyPair  `{ publicKey, privateKey }`.
 * @param {object} [overrides] Metadata overrides for negative cases.
 */
function buildAuthorizationServer( jwk, keyPair, overrides = {} ) {
	const app = express();
	app.use( express.urlencoded( { extended: true } ) );

	/** code → { clientId, redirectUri, scope, challenge, resource } */
	const pending = new Map();

	app.get( '/.well-known/oauth-authorization-server', ( req, res ) => {
		const base = `${ req.protocol }://${ req.get( 'host' ) }${ AS_ISSUER_PATH }`;
		res.json( {
			issuer: base,
			authorization_endpoint: `${ base }/authorize`,
			token_endpoint: `${ base }/token`,
			registration_endpoint: `${ base }/register`,
			jwks_uri: `${ req.protocol }://${ req.get( 'host' ) }/.well-known/jwks.json`,
			response_types_supported: [ 'code' ],
			grant_types_supported: [ 'authorization_code' ],
			code_challenge_methods_supported: [ 'S256' ],
			token_endpoint_auth_methods_supported: [ 'none' ],
			scopes_supported: [ 'site:read', 'site:site-a', 'openid' ],
			...overrides,
		} );
	} );

	app.get( '/.well-known/jwks.json', ( _req, res ) => res.json( { keys: [ jwk ] } ) );

	// RFC 7591 §3 — DCR. Public client: no secret (expires_at 0).
	app.post( '/oauth/register', ( req, res ) => {
		const { client_name, redirect_uris, token_endpoint_auth_method, grant_types, response_types } = req.body;
		// A single-item form field arrives as a string; repeated keys as arrays.
		const redirectUris = Array.isArray( redirect_uris ) ? redirect_uris : redirect_uris ? [ redirect_uris ] : [];
		const grantList = Array.isArray( grant_types ) ? grant_types : grant_types ? [ grant_types ] : [];
		const responseList = Array.isArray( response_types ) ? response_types : response_types ? [ response_types ] : [];
		if ( ! redirectUris.length ) {
			return res.status( 400 ).json( { error: 'invalid_redirect_uri' } );
		}
		if ( 'none' !== token_endpoint_auth_method ) {
			return res.status( 400 ).json( { error: 'invalid_client_metadata' } );
		}
		if ( ! grantList.includes( 'authorization_code' ) || ! responseList.includes( 'code' ) ) {
			return res.status( 400 ).json( { error: 'invalid_client_metadata' } );
		}
		res.status( 201 ).json( {
			client_id: `client-${ randomUUID() }`,
			client_id_issued_at: Math.floor( Date.now() / 1000 ),
			client_secret_expires_at: 0,
			client_name,
			redirect_uris,
			token_endpoint_auth_method,
			grant_types,
			response_types,
		} );
	} );

	app.post( '/oauth/authorize', ( req, res ) => {
		const fields = req.body;
		const {
			response_type,
			client_id,
			redirect_uri,
			scope,
			state,
			code_challenge,
			code_challenge_method,
			resource,
		} = fields;
		if ( 'code' !== response_type || ! client_id || ! redirect_uri || ! code_challenge ) {
			return res.status( 400 ).json( { error: 'invalid_request' } );
		}
		// OpenAI requirement: S256 only — plain challenges are refused.
		if ( 'S256' !== code_challenge_method ) {
			return res.status( 400 ).json( { error: 'invalid_request', error_description: 'S256 required' } );
		}
		const code = randomUUID();
		pending.set( code, { clientId: client_id, redirectUri: redirect_uri, scope, challenge: code_challenge, resource } );
		res.redirect( 302, `${ redirect_uri }${ redirect_uri.includes( '?' ) ? '&' : '?' }code=${ code }&state=${ state || '' }` );
	} );

	app.post( '/oauth/token', ( req, res ) => {
		const fields = req.body;
		const { grant_type, code, redirect_uri, client_id, code_verifier } = fields;
		if ( 'authorization_code' !== grant_type ) {
			return res.status( 400 ).json( { error: 'unsupported_grant_type' } );
		}
		const auth = pending.get( code );
		if ( ! auth || auth.clientId !== client_id || auth.redirectUri !== redirect_uri ) {
			return res.status( 400 ).json( { error: 'invalid_grant' } );
		}
		// PKCE S256 verification — a wrong verifier must fail the exchange.
		if ( pkceChallenge( code_verifier || '' ) !== auth.challenge ) {
			return res.status( 400 ).json( { error: 'invalid_grant', error_description: 'PKCE verification failed' } );
		}
		pending.delete( code );
		const now = Math.floor( Date.now() / 1000 );
		const accessToken = signJwt(
			{
				iss: `${ res.app.get( 'asIssuer' ) }`,
				sub: 'chatgpt|demo-user',
				aud: auth.resource || RESOURCE,
				exp: now + 3600,
				iat: now,
				scope: auth.scope,
			},
			keyPair.privateKey
		);
		res.json( { access_token: accessToken, token_type: 'Bearer', expires_in: 3600, scope: auth.scope } );
	} );

	return app;
}

/** Fake upstream NV oOS site (site-a). */
function buildFakeSite() {
	const app = express();
	app.use( express.json() );
	app.post( '/wp-json/mcp-ai/v1/mcp', ( req, res ) => {
		const { id, method } = req.body;
		if ( 'tools/list' === method ) {
			return res.json( {
				jsonrpc: '2.0',
				id,
				result: { tools: [ { name: 'site_a_read', description: 'read-only demo tool', inputSchema: { type: 'object' } } ] },
			} );
		}
		return res.json( { jsonrpc: '2.0', id, result: {} } );
	} );
	return app;
}

/** Gateway config: one site, one static key, OAuth wired to the fake AS. */
function buildConfig( site, oauth ) {
	return {
		keys: new Map( [ [ STATIC_KEY, [ 'site-a' ] ] ] ),
		previousKeys: new Map(),
		sites: new Map( [ [ 'site-a', { slug: 'site-a', url: site.baseUrl, token: 'op_site_a.SECRET' } ] ] ),
		oauth,
		errors: [],
	};
}

/**
 * The ChatGPT connector's client steps, in order. Returns the tools/list
 * result or throws a typed precondition error for unsupported AS metadata.
 */
async function chatgptFlow( harness, overrides = {} ) {
	const {
		gatewayUrl,
		asUrl,
		scope = 'site:read site:site-a',
		resource = RESOURCE,
		client = {},
	} = { ...harness, ...overrides };
	// 1. Protected-resource metadata (RFC 9728).
	const rmRes = await fetch( `${ gatewayUrl }/.well-known/oauth-protected-resource` );
	assert.equal( rmRes.status, 200, 'resource metadata must be served once OAuth is configured' );
	const rm = await rmRes.json();
	assert.equal( rm.resource, RESOURCE );

	// 2. Authorization-server metadata (RFC 8414) with the OpenAI mandates.
	const asRes = await fetch( `${ asUrl }/.well-known/oauth-authorization-server` );
	assert.equal( asRes.status, 200 );
	const as = await asRes.json();
	if ( ! as.registration_endpoint ) {
		throw new Error( 'AS metadata omits registration_endpoint — ChatGPT will not connect.' );
	}
	if ( ! Array.isArray( as.code_challenge_methods_supported ) || ! as.code_challenge_methods_supported.includes( 'S256' ) ) {
		throw new Error( 'AS metadata does not advertise S256 — ChatGPT will not connect.' );
	}
	if ( ! as.response_types_supported.includes( 'code' ) ) {
		throw new Error( 'AS does not support the authorization_code response type.' );
	}

	// 3. Dynamic client registration (RFC 7591).
	const dcrRes = await fetch( as.registration_endpoint, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: formEncode( {
			client_name: client.name || 'ChatGPT',
			redirect_uris: client.redirect_uris || [ REDIRECT_URI ],
			token_endpoint_auth_method: 'none',
			grant_types: [ 'authorization_code' ],
			response_types: [ 'code' ],
		} ),
	} );
	assert.equal( dcrRes.status, 201, 'dynamic client registration must succeed' );
	const dcr = await dcrRes.json();
	assert.ok( dcr.client_id, 'registration must issue a client_id' );

	// 4. Authorization code + PKCE S256 (RFC 7636) — browser leg simulated
	//    by reading the redirect Location (no real browser in CI).
	const verifier = randomUUID().replace( /-/g, '' ) + randomUUID().replace( /-/g, '' );
	const challenge = pkceChallenge( verifier );
	const state = randomUUID();
	const authRes = await fetch( as.authorization_endpoint, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: formEncode( {
			response_type: 'code',
			client_id: dcr.client_id,
			redirect_uri: REDIRECT_URI,
			scope,
			state,
			code_challenge: challenge,
			code_challenge_method: 'S256',
			resource,
		} ),
		redirect: 'manual',
	} );
	assert.equal( authRes.status, 302, 'authorize must redirect back with a code' );
	const location = new URL( authRes.headers.get( 'location' ) );
	const code = location.searchParams.get( 'code' );
	assert.ok( code, 'redirect must carry an authorization code' );

	// 5. Token exchange.
	const tokenRes = await fetch( as.token_endpoint, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: formEncode( {
			grant_type: 'authorization_code',
			code,
			redirect_uri: REDIRECT_URI,
			client_id: dcr.client_id,
			code_verifier: verifier,
		} ),
	} );
	assert.equal( tokenRes.status, 200, 'token exchange must succeed' );
	const tokens = await tokenRes.json();
	assert.ok( tokens.access_token, 'token response must carry an access_token' );

	// 6. Use the token at the gateway.
	const mcpRes = await fetch( `${ gatewayUrl }/mcp`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
			Authorization: `Bearer ${ tokens.access_token }`,
		},
		body: JSON.stringify( { jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} } ),
	} );
	return { status: mcpRes.status, body: await mcpRes.json(), headers: mcpRes.headers, accessToken: tokens.access_token };
}

async function startHarness( metadataOverrides = {} ) {
	const { publicKey, privateKey } = generateKeyPairSync( 'rsa', { modulusLength: 2048 } );
	const jwk = publicKey.export( { format: 'jwk' } );
	jwk.kid = 'as-key';
	jwk.alg = 'RS256';
	jwk.use = 'sig';

	const as = buildAuthorizationServer( jwk, { publicKey, privateKey }, metadataOverrides );
	const asServer = await startTestServer( as );
	as.set( 'asIssuer', `${ asServer.baseUrl }${ AS_ISSUER_PATH }` );

	const siteServer = await startTestServer( buildFakeSite() );
	const gateway = createApp( {
		config: buildConfig(
			{ baseUrl: `${ siteServer.baseUrl }/wp-json/mcp-ai/v1/mcp` },
			{
				enabled: true,
				issuer: `${ asServer.baseUrl }${ AS_ISSUER_PATH }`,
				jwksUri: `${ asServer.baseUrl }/.well-known/jwks.json`,
				resource: RESOURCE,
			}
		),
	} );
	const gatewayServer = await startTestServer( gateway );

	return {
		gatewayUrl: gatewayServer.baseUrl,
		asUrl: asServer.baseUrl,
		close: async () => {
			await gatewayServer.close();
			await siteServer.close();
			await asServer.close();
		},
	};
}

test( 'PKCE flow end-to-end: discovery → DCR → code → token → namespaced tools/list', async () => {
	const harness = await startHarness();
	try {
		const { status, body } = await chatgptFlow( harness );
		assert.equal( status, 200 );
		assert.deepEqual( body.result.tools.map( ( t ) => t.name ), [ 'site-a.site_a_read' ] );
		assert.ok( ! body.result._meta?.gateway?.errors, 'no gateway errors expected' );
	} finally {
		await harness.close();
	}
} );

test( 'the flow refuses AS metadata that omits S256 (OpenAI: servers are unsupported)', async () => {
	const harness = await startHarness( { code_challenge_methods_supported: [] } );
	try {
		await assert.rejects(
			chatgptFlow( harness ),
			/does not advertise S256/,
			'metadata without S256 must fail the flow before DCR'
		);
	} finally {
		await harness.close();
	}
} );

test( 'the flow refuses AS metadata without a registration endpoint (OpenAI: DCR required)', async () => {
	const harness = await startHarness( { registration_endpoint: undefined } );
	try {
		await assert.rejects( chatgptFlow( harness ), /omits registration_endpoint/ );
	} finally {
		await harness.close();
	}
} );

test( 'a wrong PKCE verifier fails the token exchange', async () => {
	const { publicKey, privateKey } = generateKeyPairSync( 'rsa', { modulusLength: 2048 } );
	const jwk = publicKey.export( { format: 'jwk' } );
	jwk.kid = 'as-key';
	jwk.alg = 'RS256';
	jwk.use = 'sig';

	const as = buildAuthorizationServer( jwk, { publicKey, privateKey } );
	const asServer = await startTestServer( as );
	as.set( 'asIssuer', `${ asServer.baseUrl }${ AS_ISSUER_PATH }` );
	const siteServer = await startTestServer( buildFakeSite() );
	const gateway = createApp( {
		config: buildConfig(
			{ baseUrl: `${ siteServer.baseUrl }/wp-json/mcp-ai/v1/mcp` },
			{ enabled: true, issuer: `${ asServer.baseUrl }${ AS_ISSUER_PATH }`, jwksUri: `${ asServer.baseUrl }/.well-known/jwks.json`, resource: RESOURCE }
		),
	} );
	const gatewayServer = await startTestServer( gateway );

	try {
		// Register + authorize with one verifier…
		const dcr = await (
			await fetch( `${ asServer.baseUrl }/oauth/register`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: formEncode( {
					client_name: 'ChatGPT',
					redirect_uris: [ REDIRECT_URI ],
					token_endpoint_auth_method: 'none',
					grant_types: [ 'authorization_code' ],
					response_types: [ 'code' ],
				} ),
			} )
		).json();
		const verifier = 'correct-verifier-value-0123456789';
		const authRes = await fetch( `${ asServer.baseUrl }${ AS_ISSUER_PATH }/authorize`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: formEncode( {
				response_type: 'code',
				client_id: dcr.client_id,
				redirect_uri: REDIRECT_URI,
				scope: 'site:read site:site-a',
				state: 's',
				code_challenge: pkceChallenge( verifier ),
				code_challenge_method: 'S256',
				resource: RESOURCE,
			} ),
			redirect: 'manual',
		} );
		const code = new URL( authRes.headers.get( 'location' ) ).searchParams.get( 'code' );

		// …but exchange with a different one.
		const tokenRes = await fetch( `${ asServer.baseUrl }${ AS_ISSUER_PATH }/token`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: formEncode( {
				grant_type: 'authorization_code',
				code,
				redirect_uri: REDIRECT_URI,
				client_id: dcr.client_id,
				code_verifier: 'a-different-wrong-verifier-000000',
			} ),
		} );
		assert.equal( tokenRes.status, 400 );
		assert.equal( ( await tokenRes.json() ).error, 'invalid_grant' );
	} finally {
		await gatewayServer.close();
		await siteServer.close();
		await asServer.close();
	}
} );

test( 'a token minted for the wrong resource audience is rejected 401 with the RFC 9728 challenge', async () => {
	const harness = await startHarness();
	try {
		const { status, body, headers } = await chatgptFlow( harness, { resource: 'https://other.example.com' } );
		assert.equal( status, 401 );
		assert.match( body.message, /invalid_audience/ );
		assert.match( headers.get( 'www-authenticate' ) || '', /resource_metadata/ );
	} finally {
		await harness.close();
	}
} );

test( 'a token granting no site:<slug> scope is rejected 403 insufficient_scope', async () => {
	const harness = await startHarness();
	try {
		const { status, body, headers } = await chatgptFlow( harness, { scope: 'site:read' } );
		assert.equal( status, 403 );
		assert.equal( body.error, 'insufficient_scope' );
		assert.match( headers.get( 'www-authenticate' ) || '', /insufficient_scope/ );
	} finally {
		await harness.close();
	}
} );

test( 'static keys keep working alongside the OAuth flow (dual mode)', async () => {
	const harness = await startHarness();
	try {
		const res = await fetch( `${ harness.gatewayUrl }/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				Authorization: `Bearer ${ STATIC_KEY }`,
			},
			body: JSON.stringify( { jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} } ),
		} );
		assert.equal( res.status, 200 );
		const body = await res.json();
		assert.deepEqual( body.result.tools.map( ( t ) => t.name ), [ 'site-a.site_a_read' ] );
	} finally {
		await harness.close();
	}
} );
