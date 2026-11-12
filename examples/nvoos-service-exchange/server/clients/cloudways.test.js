import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createCloudwaysClient } from './cloudways.js';

function config( overrides = {} ) {
	return {
		mode: 'live',
		cloudways: {
			email: 'ops@example.test',
			apiKey: 'api-key',
			baseUrl: 'https://api.cloudways.test/v2',
			legacyBaseUrl: 'https://api.cloudways.test/v1',
			defaultServerId: '42',
			appTimeoutMs: 60_000,
			appPollMs: 1_000,
			timeoutMs: 5_000,
			retries: 0,
			...overrides,
		},
	};
}

function jsonResponse( status, body ) {
	return {
		ok: status >= 200 && status < 300,
		status,
		headers: new Headers(),
		text: async () => ( typeof body === 'string' ? body : JSON.stringify( body ) ),
	};
}

function oauth( url ) {
	return url.endsWith( '/oauth/access_token' );
}

test( 'oauth token is fetched once and cached', async () => {
	let oauthCalls = 0;
	const fetchImpl = async ( url ) => {
		if ( oauth( url ) ) {
			oauthCalls += 1;
			return jsonResponse( 200, { access_token: 'token-1', expires_in: 3600 } );
		}
		if ( url.endsWith( '/servers' ) ) {
			return jsonResponse( 200, { servers: [ { id: 1 } ] } );
		}
		throw new Error( `Unexpected URL ${ url }` );
	};

	const client = createCloudwaysClient( { config: config(), fetchImpl } );
	await client.listServers();
	await client.listServers();
	assert.equal( oauthCalls, 1 );
} );

test( 'authentication failure surfaces a provider error with the API message', async () => {
	const fetchImpl = async () =>
		jsonResponse( 403, {
			error: 'invalid_credentials',
			error_description: 'The user credentials were incorrect.',
		} );
	const client = createCloudwaysClient( { config: config(), fetchImpl } );
	await assert.rejects(
		() => client.listServers(),
		( error ) =>
			error.code === 'provider_error' &&
			error.status === 502 &&
			/user credentials were incorrect/.test( error.message )
	);
} );

test( 'listServers prefers /servers and falls back to /server on 404', async () => {
	const seen = [];
	const fetchImpl = async ( url ) => {
		if ( oauth( url ) ) {
			return jsonResponse( 200, { access_token: 't', expires_in: 3600 } );
		}
		seen.push( url );
		if ( url.endsWith( '/servers' ) ) {
			return jsonResponse( 404, { error: 'not_found' } );
		}
		return jsonResponse( 200, { servers: [ { id: 2 } ] } );
	};
	const client = createCloudwaysClient( { config: config(), fetchImpl } );
	const servers = await client.listServers();
	assert.deepEqual( servers, [ { id: 2 } ] );
	assert.ok( seen.some( ( url ) => url.endsWith( '/servers' ) ) );
	assert.ok( seen.some( ( url ) => url.endsWith( '/server' ) ) );
} );

test( 'ensureApp returns the existing app without creating a duplicate', async () => {
	const calls = [];
	const fetchImpl = async ( url, options ) => {
		if ( oauth( url ) ) {
			return jsonResponse( 200, { access_token: 't', expires_in: 3600 } );
		}
		if ( url.includes( '/server/42/apps' ) ) {
			return jsonResponse( 200, { apps: [ { id: 7, label: 'MCP Server - clinic-ops' } ] } );
		}
		calls.push( { url, method: options.method } );
		return jsonResponse( 200, { app: { id: 8 } } );
	};

	const client = createCloudwaysClient( { config: config(), fetchImpl } );
	const app = await client.ensureApp( { serverId: 42, label: 'MCP Server - clinic-ops' } );
	assert.equal( app.id, 7 );
	assert.equal( calls.length, 0 );
} );

test( 'ensureApp creates the app with the verified v2 body', async () => {
	const posts = [];
	const fetchImpl = async ( url, options ) => {
		if ( oauth( url ) ) {
			return jsonResponse( 200, { access_token: 't', expires_in: 3600 } );
		}
		if ( url.includes( '/server/42/apps' ) ) {
			return jsonResponse( 200, { apps: [] } );
		}
		if ( url.endsWith( '/app' ) ) {
			posts.push( { url, method: options.method, body: JSON.parse( options.body ) } );
			return jsonResponse( 200, { app: { id: 9, label: 'MCP Server - clinic-ops' } } );
		}
		if ( url.endsWith( '/app/9' ) ) {
			return jsonResponse( 200, {
				app: { id: 9, status: 'running', cname: 'clinic.example.test', sys_user: 'abcdef12' },
			} );
		}
		throw new Error( `Unexpected URL ${ url }` );
	};

	const client = createCloudwaysClient( { config: config(), fetchImpl } );
	const app = await client.ensureApp( {
		serverId: 42,
		label: 'MCP Server - clinic-ops',
		projectName: 'nvoos-estates',
	} );
	assert.equal( app.id, 9 );
	assert.equal( posts.length, 1 );
	assert.equal( posts[ 0 ].method, 'POST' );
	assert.equal( posts[ 0 ].url, 'https://api.cloudways.test/v2/app' );
	assert.deepEqual( posts[ 0 ].body, {
		server_id: '42',
		application: 'wordpress',
		app_label: 'MCP Server - clinic-ops',
		project_name: 'nvoos-estates',
	} );
	assert.equal( app.sys_user, 'abcdef12' );
} );

test( 'ensureApp hydrates the created app from GET /app/{id}', async () => {
	const fetchImpl = async ( url ) => {
		if ( oauth( url ) ) {
			return jsonResponse( 200, { access_token: 't', expires_in: 3600 } );
		}
		if ( url.includes( '/server/42/apps' ) ) {
			return jsonResponse( 200, { apps: [] } );
		}
		if ( url.endsWith( '/app' ) ) {
			return jsonResponse( 200, { app: { id: 9 } } );
		}
		if ( url.endsWith( '/app/9' ) ) {
			return jsonResponse( 200, {
				app: { id: 9, status: 'running', cname: 'clinic.example.test', sys_user: 'abcdef12' },
			} );
		}
		throw new Error( `Unexpected URL ${ url }` );
	};

	const client = createCloudwaysClient( { config: config(), fetchImpl } );
	const app = await client.ensureApp( { serverId: 42, label: 'MCP Server - clinic-ops' } );
	assert.equal( app.sys_user, 'abcdef12' );
	assert.equal( app.status, 'running' );
	assert.equal( client.siteUrlFromApp( app ), 'https://clinic.example.test' );
} );

test( 'getApp unwraps the app envelope and siteUrlFromApp prefers cname', async () => {
	const fetchImpl = async ( url ) => {
		if ( oauth( url ) ) {
			return jsonResponse( 200, { access_token: 't', expires_in: 3600 } );
		}
		return jsonResponse( 200, {
			app: { id: 7, status: 'running', cname: 'clinic.example.test', app_fqdn: 'ignored.test' },
		} );
	};
	const client = createCloudwaysClient( { config: config(), fetchImpl } );
	const app = await client.getApp( 7 );
	assert.equal( app.status, 'running' );
	assert.equal( client.siteUrlFromApp( app ), 'https://clinic.example.test' );
	assert.equal( client.siteUrlFromApp( { app_fqdn: 'https://fallback.test' } ), 'https://fallback.test' );
	assert.equal( client.siteUrlFromApp( null ), null );
} );

test( 'waitForApp polls until running', async () => {
	let clock = 0;
	let polls = 0;
	const fetchImpl = async ( url ) => {
		if ( oauth( url ) ) {
			return jsonResponse( 200, { access_token: 't', expires_in: 3600 } );
		}
		polls += 1;
		return jsonResponse( 200, {
			app: { id: 7, status: polls < 2 ? 'provisioning' : 'running' },
		} );
	};
	const client = createCloudwaysClient( {
		config: config(),
		fetchImpl,
		now: () => clock,
		sleep: async ( ms ) => {
			clock += ms;
		},
	} );
	const app = await client.waitForApp( { appId: 7, timeoutMs: 10_000, intervalMs: 1_000 } );
	assert.equal( app.status, 'running' );
	assert.equal( polls, 2 );
} );

test( 'waitForApp fails on a terminal status', async () => {
	let clock = 0;
	const fetchImpl = async ( url ) => {
		if ( oauth( url ) ) {
			return jsonResponse( 200, { access_token: 't', expires_in: 3600 } );
		}
		return jsonResponse( 200, { app: { id: 7, status: 'failed' } } );
	};
	const client = createCloudwaysClient( {
		config: config(),
		fetchImpl,
		now: () => clock,
		sleep: async ( ms ) => {
			clock += ms;
		},
	} );
	await assert.rejects(
		() => client.waitForApp( { appId: 7, timeoutMs: 10_000, intervalMs: 1_000 } ),
		( error ) => error.code === 'provider_error' && /status "failed"/.test( error.message )
	);
} );

test( 'waitForApp times out', async () => {
	let clock = 0;
	const fetchImpl = async ( url ) => {
		if ( oauth( url ) ) {
			return jsonResponse( 200, { access_token: 't', expires_in: 3600 } );
		}
		return jsonResponse( 200, { app: { id: 7, status: 'provisioning' } } );
	};
	const client = createCloudwaysClient( {
		config: config(),
		fetchImpl,
		now: () => clock,
		sleep: async ( ms ) => {
			clock += ms;
		},
	} );
	await assert.rejects(
		() => client.waitForApp( { appId: 7, timeoutMs: 3_000, intervalMs: 1_000 } ),
		( error ) => error.code === 'provider_error' && /did not reach "running"/.test( error.message )
	);
} );

test( 'purgeCache posts string ids to the verified route', async () => {
	const posts = [];
	const fetchImpl = async ( url, options ) => {
		if ( oauth( url ) ) {
			return jsonResponse( 200, { access_token: 't', expires_in: 3600 } );
		}
		posts.push( { url, body: JSON.parse( options.body ) } );
		return jsonResponse( 200, { ok: true } );
	};
	const client = createCloudwaysClient( { config: config(), fetchImpl } );
	await client.purgeCache( { serverId: 42, appId: 7 } );
	assert.equal( posts[ 0 ].url, 'https://api.cloudways.test/v2/app/cache/purge' );
	assert.deepEqual( posts[ 0 ].body, { server_id: '42', app_id: '7' } );
} );

test( 'mock mode never calls the network', async () => {
	const fetchImpl = async () => {
		throw new Error( 'network must not be used in mock mode' );
	};
	const client = createCloudwaysClient( { config: { ...config(), mode: 'mock' }, fetchImpl } );
	const app = await client.ensureApp( { serverId: 1, label: 'Mock' } );
	assert.match( app.id, /^mock-/ );
	assert.equal( app.status, 'running' );
	assert.deepEqual( await client.listServers(), [ { id: 1, label: 'Mock Server', apps: 0 } ] );
} );
