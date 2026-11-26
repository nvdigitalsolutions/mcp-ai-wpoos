// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Token rotation tests against a local fake token endpoint.
 *
 * These cover the two failure modes that break a QuickBooks grant for good:
 * dropping the rotated refresh token, and re-refreshing while a valid access
 * token is still live.
 */

import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { promises as fs } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { IntuitClient } from '../src/intuit.js';

let fake;
let tokenUrl;
let calls = 0;
let lastBody = '';
let lastAuth = '';

before( async () => {
	fake = createServer( ( req, res ) => {
		let body = '';
		req.on( 'data', ( chunk ) => ( body += chunk ) );
		req.on( 'end', () => {
			calls += 1;
			lastBody = body;
			lastAuth = req.headers.authorization || '';

			res.writeHead( 200, { 'Content-Type': 'application/json' } );
			res.end(
				JSON.stringify( {
					access_token: `access-${ calls }`,
					refresh_token: `rotated-${ calls }`,
					expires_in: 3600,
					token_type: 'bearer',
				} )
			);
		} );
	} );

	await new Promise( ( resolve ) => fake.listen( 0, '127.0.0.1', resolve ) );
	tokenUrl = `http://127.0.0.1:${ fake.address().port }/token`;
} );

after( () => new Promise( ( resolve ) => fake.close( resolve ) ) );

/**
 * Build a client config with a unique state file.
 *
 * @return {Object} Config.
 */
function makeConfig() {
	return {
		clientId: 'cid',
		clientSecret: 'secret',
		realmId: '193514562011054',
		refreshToken: 'initial-refresh',
		environment: 'sandbox',
		baseUrl: 'https://sandbox-quickbooks.api.intuit.com/v3',
		stateFile: join( tmpdir(), `qbo-oauth-${ randomUUID() }.json` ),
		tokenUrl,
		inboundToken: 't',
		allowAnonymous: false,
		port: 0,
		serverName: 'test',
		serverVersion: '0.0.0',
	};
}

test( 'refresh exchanges credentials with HTTP Basic and persists the rotation', async () => {
	calls = 0;
	const config = makeConfig();
	const client = new IntuitClient( config );
	await client.init();

	const token = await client.getAccessToken();

	assert.equal( token, 'access-1' );
	assert.equal( calls, 1 );
	assert.equal( lastAuth, `Basic ${ Buffer.from( 'cid:secret' ).toString( 'base64' ) }` );
	assert.match( lastBody, /grant_type=refresh_token/ );
	assert.match( lastBody, /refresh_token=initial-refresh/ );

	const state = JSON.parse( await fs.readFile( config.stateFile, 'utf8' ) );
	assert.equal( state.refresh_token, 'rotated-1' );
} );

test( 'a live access token is reused instead of refreshing again', async () => {
	calls = 0;
	const client = new IntuitClient( makeConfig() );
	await client.init();

	const first = await client.getAccessToken();
	const second = await client.getAccessToken();

	assert.equal( first, second );
	assert.equal( calls, 1 );
} );

test( 'a restart resumes from the rotated refresh token', async () => {
	calls = 0;
	const config = makeConfig();

	const first = new IntuitClient( config );
	await first.init();
	await first.getAccessToken();

	const second = new IntuitClient( config );
	await second.init();
	await second.getAccessToken();

	assert.equal( calls, 2 );
	assert.match( lastBody, /refresh_token=rotated-1/ );
} );

test( 'a non-200 from the token endpoint surfaces invalid_grant guidance', async () => {
	const failing = createServer( ( req, res ) => {
		req.resume();
		req.on( 'end', () => {
			res.writeHead( 400, { 'Content-Type': 'application/json' } );
			res.end( JSON.stringify( { error: 'invalid_grant' } ) );
		} );
	} );

	await new Promise( ( resolve ) => failing.listen( 0, '127.0.0.1', resolve ) );

	try {
		const config = makeConfig();
		config.tokenUrl = `http://127.0.0.1:${ failing.address().port }/token`;

		const client = new IntuitClient( config );
		await client.init();

		await assert.rejects( () => client.getAccessToken(), ( error ) => {
			assert.equal( error.name, 'IntuitError' );
			assert.equal( error.status, 400 );
			assert.match( error.message, /browser sign-in/ );
			return true;
		} );
	} finally {
		await new Promise( ( resolve ) => failing.close( resolve ) );
	}
} );
