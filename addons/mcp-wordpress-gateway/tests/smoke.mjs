/**
 * End-to-end smoke test: boots the real gateway (native Streamable HTTP
 * server on mcp-wordpress internals) as a child process, then exercises
 * health, auth rejection, initialize, policy-filtered tools/list, and a
 * blocked tools/call. Uses dummy WordPress credentials — tool registration
 * is local and needs no network.
 *
 * Not part of `npm test` (needs the real dependency chain). Run with:
 * npm run smoke
 */

import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const TOKEN = 's'.repeat( 40 );
const PORT = 4567;

const env = {
	...process.env,
	MCP_GATEWAY_TOKEN: TOKEN,
	PORT: String( PORT ),
	MCP_TOOLS_ALLOW: 'wp_list_*',
	MCP_TOOLS_DENY: 'wp_delete_*',
	LOG_LEVEL: 'info',
	WORDPRESS_SITE_URL: 'https://example.com',
	WORDPRESS_USERNAME: 'admin',
	WORDPRESS_APP_PASSWORD: 'xxxx xxxx xxxx xxxx xxxx xxxx',
	WORDPRESS_AUTH_METHOD: 'app-password',
};

const child = spawn( process.execPath, [ join( ROOT, 'src', 'index.js' ) ], { env, stdio: [ 'ignore', 'inherit', 'inherit' ] } );

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

async function waitForHealth( attempts = 30 ) {
	for ( let i = 0; i < attempts; i += 1 ) {
		try {
			const response = await fetch( `http://127.0.0.1:${ PORT }/healthz` );
			if ( response.status === 200 ) {
				return;
			}
		} catch {
			// Not up yet.
		}
		await sleep( 1000 );
	}
	throw new Error( 'Gateway did not become healthy within 30s.' );
}

function assert( condition, message ) {
	if ( ! condition ) {
		throw new Error( message );
	}
}

async function rpc( method, params, headers = {} ) {
	const response = await fetch( `http://127.0.0.1:${ PORT }/mcp`, {
		method: 'POST',
		headers: {
			'content-type': 'application/json',
			accept: 'application/json, text/event-stream',
			'X-MCP-Token': TOKEN,
			...headers,
		},
		body: JSON.stringify( { jsonrpc: '2.0', id: 1, method, params } ),
	} );

	return { status: response.status, body: await response.json().catch( () => null ) };
}

try {
	await waitForHealth();

	// 1) Auth rejection without a token.
	const noToken = await fetch( `http://127.0.0.1:${ PORT }/mcp`, {
		method: 'POST',
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify( { jsonrpc: '2.0', id: 1, method: 'tools/list' } ),
	} );
	assert( noToken.status === 401, `Expected 401 without token, got ${ noToken.status }.` );

	// 2) Initialize handshake (stateless — no session id expected).
	const init = await rpc( 'initialize', {
		protocolVersion: '2026-07-28',
		capabilities: {},
		clientInfo: { name: 'smoke', version: '1.0.0' },
	} );
	assert( init.status === 200, `initialize failed: ${ init.status } ${ JSON.stringify( init.body ) }` );
	assert( init.body?.result?.protocolVersion, 'initialize returned no negotiated protocol version.' );
	console.log( `SMOKE: negotiated protocol version ${ init.body.result.protocolVersion }.` );

	// 3) Authenticated tools/list with policy applied.
	const list = await rpc( 'tools/list', {} );
	assert( list.status === 200, `tools/list failed: ${ list.status }` );

	const tools = list.body?.result?.tools ?? [];
	assert( tools.length > 0, 'tools/list returned zero tools.' );
	assert(
		tools.every( ( tool ) => tool.name.startsWith( 'wp_list_' ) ),
		'Allow policy was not applied to the real tools/list response.'
	);
	assert(
		tools.every( ( tool ) => ! tool.name.startsWith( 'wp_delete_' ) ),
		'Deny policy was not applied to the real tools/list response.'
	);
	console.log( `SMOKE OK: ${ tools.length } tools visible under allow=wp_list_*, deny=wp_delete_*.` );
	console.log( `Sample tools: ${ tools.slice( 0, 5 ).map( ( tool ) => tool.name ).join( ', ' ) }` );

	// 4) A blocked tool call must be rejected by the server itself.
	const blocked = await rpc( 'tools/call', { name: 'wp_delete_post', arguments: {} } );
	const rejected = Boolean( blocked.body?.error ) || Boolean( blocked.body?.result?.isError );
	assert( rejected, `Blocked tool call was not rejected: ${ JSON.stringify( blocked.body ) }` );
	console.log(
		`SMOKE OK: denied tool call rejected (${ blocked.body?.error?.message ?? blocked.body?.result?.content?.[ 0 ]?.text ?? 'unknown' }).`
	);
} finally {
	child.kill( 'SIGTERM' );
	await sleep( 2000 );
	if ( ! child.killed ) {
		child.kill( 'SIGKILL' );
	}
}
