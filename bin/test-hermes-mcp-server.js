#!/usr/bin/env node
/**
 * Tests for the Hermes WebUI MCP server (bin/hermes-mcp-server.js).
 *
 * Uses an in-process fake WebUI (login + sessions + chat routes) and drives
 * the server over its stdio channel the same way Zed does — newline-delimited
 * JSON-RPC. No real Hermes infrastructure is touched.
 *
 * Run:  node bin/test-hermes-mcp-server.js
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const http = require( 'node:http' );
const path = require( 'node:path' );
const readline = require( 'node:readline' );
const { spawn } = require( 'node:child_process' );

const SERVER = path.join( __dirname, 'hermes-mcp-server.js' );

// ── Fake WebUI ───────────────────────────────────────────────────────────────

/**
 * Start a fake Hermes WebUI.
 *
 * Cookie validity is `session-v<loginCount>`; bumping `state.validCookie`
 * simulates session expiry and forces the MCP server to re-login.
 *
 * @returns {Promise<{port:number, state:object, close:Function}>}
 */
function startFakeWebui() {
	const state = {
		loginCount: 0,
		validCookie: 'session-v0',
		chatBodies: [],
		lastCookie: null,
		expireAfterRequests: Infinity,
		requestCount: 0,
		chatDelayMs: 0,
	};

	const server = http.createServer( ( req, res ) => {
		const cookieHeader = String( req.headers.cookie || '' );
		state.lastCookie = cookieHeader;
		state.requestCount += 1;

		const json = ( code, payload, headers = {} ) => {
			res.writeHead( code, { 'Content-Type': 'application/json', ...headers } );
			res.end( JSON.stringify( payload ) );
		};

		if ( 'POST' === req.method && '/api/auth/login' === req.url ) {
			let raw = '';
			req.on( 'data', ( c ) => { raw += c; } );
			req.on( 'end', () => {
				const body = JSON.parse( raw || '{}' );
				if ( 'test-password' !== body.password ) {
					return json( 403, { ok: false } );
				}
				state.loginCount += 1;
				state.validCookie = `session-v${ state.loginCount }`;
				json( 200, { ok: true }, { 'Set-Cookie': `${ state.validCookie }; Path=/; HttpOnly` } );
			} );
			return;
		}

		if ( cookieHeader !== state.validCookie ) {
			return json( 401, { error: 'unauthorized' } );
		}
		if ( state.requestCount > state.expireAfterRequests ) {
			state.validCookie = 'session-v999';
			return json( 401, { error: 'session expired' } );
		}

		if ( 'GET' === req.method && '/api/sessions' === req.url ) {
			return json( 200, {
				sessions: [
					{ session_id: 's1', title: 'First', model: 'm', model_provider: 'p', message_count: 3, workspace: '/w', updated_at: 1 },
					{ session_id: 's2', title: 'Second', model: 'm', model_provider: 'p', message_count: 9, workspace: '/w', updated_at: 2 },
				],
			} );
		}

		if ( 'GET' === req.method && req.url.startsWith( '/api/session' ) ) {
			return json( 200, { session_id: 's1', title: 'First' } );
		}

		if ( 'POST' === req.method && '/api/chat' === req.url ) {
			let raw = '';
			req.on( 'data', ( c ) => { raw += c; } );
			req.on( 'end', () => {
				state.chatBodies.push( JSON.parse( raw || '{}' ) );
				const send = () => json( 200, { answer: 'PONG', status: 'done' } );
				if ( state.chatDelayMs ) {
					setTimeout( send, state.chatDelayMs );
				} else {
					send();
				}
			} );
			return;
		}

		json( 404, { error: 'not found' } );
	} );

	return new Promise( ( resolve ) => {
		server.listen( 0, '127.0.0.1', () => resolve( {
			port: server.address().port,
			state,
			close: () => new Promise( ( r ) => server.close( r ) ),
		} ) );
	} );
}

// ── MCP client helpers ───────────────────────────────────────────────────────

function startServer( port ) {
	const child = spawn( process.execPath, [ SERVER ], {
		env: {
			...process.env,
			HERMES_WEBUI_URL: `http://127.0.0.1:${ port }`,
			HERMES_WEBUI_PASSWORD: 'test-password',
		},
		stdio: [ 'pipe', 'pipe', 'pipe' ],
		windowsHide: true,
	} );
	const lines = [];
	readline.createInterface( { input: child.stdout } ).on( 'line', ( l ) => lines.push( l ) );
	const exit = new Promise( ( resolve ) => child.once( 'exit', ( code, signal ) => resolve( { code, signal } ) ) );
	return { child, lines, exit };
}

async function rpc( child, lines, method, params, id ) {
	const sendId = undefined === id ? Math.floor( Math.random() * 10000 ) + 100 : id;
	child.stdin.write( JSON.stringify( { jsonrpc: '2.0', method, params, id: sendId } ) + '\n' );
	const deadline = Date.now() + 15000;
	while ( Date.now() < deadline ) {
		const hit = lines.find( ( l ) => l.includes( `"id":${ sendId }` ) );
		if ( hit ) {
			return JSON.parse( hit );
		}
		await new Promise( ( r ) => setTimeout( r, 25 ) );
	}
	throw new Error( `Timed out waiting for ${ method } (id ${ sendId }).` );
}

async function stop( ctx ) {
	ctx.child.stdin.end();
	await ctx.exit;
	await ctx.webui.close();
}

// ── Tests ────────────────────────────────────────────────────────────────────

test( 'initialize + tools/list handshake', async () => {
	const webui = await startFakeWebui();
	const ctx = { ...startServer( webui.port ), webui };

	const init = await rpc( ctx.child, ctx.lines, 'initialize', { protocolVersion: '2024-11-05', capabilities: {}, clientInfo: { name: 'zed', version: '0.224.0' } }, 1 );
	assert.strictEqual( init.result.serverInfo.name, 'hermes-webui' );
	assert.strictEqual( init.result.protocolVersion, '2024-11-05' );

	const list = await rpc( ctx.child, ctx.lines, 'tools/list', {}, 2 );
	assert.deepStrictEqual( list.result.tools.map( ( t ) => t.name ), [
		'hermes_list_sessions',
		'hermes_chat',
		'hermes_session_detail',
	] );

	await stop( ctx );
} );

test( 'list sessions logs in once and returns trimmed rows', async () => {
	const webui = await startFakeWebui();
	const ctx = { ...startServer( webui.port ), webui };

	const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_list_sessions', arguments: {} }, 3 );
	const payload = JSON.parse( res.result.content[ 0 ].text );

	assert.strictEqual( payload.count, 2 );
	assert.strictEqual( payload.sessions[ 0 ].session_id, 's1' );
	assert.ok( payload.sessions[ 0 ].title, 'rows are trimmed but carry title' );
	assert.strictEqual( webui.state.loginCount, 1, 'one login for the first authed request' );
	assert.ok( webui.state.lastCookie.includes( 'session-v1' ), 'cookie sent on subsequent request' );

	await stop( ctx );
} );

test( 'chat targets explicit session and returns the answer', async () => {
	const webui = await startFakeWebui();
	const ctx = { ...startServer( webui.port ), webui };

	const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_chat', arguments: { session_id: 's2', message: 'hi' } }, 4 );
	const payload = JSON.parse( res.result.content[ 0 ].text );

	assert.strictEqual( payload.answer, 'PONG' );
	assert.strictEqual( payload.session_id, 's2' );
	assert.deepStrictEqual( webui.state.chatBodies[ 0 ], { session_id: 's2', message: 'hi' } );

	await stop( ctx );
} );

test( 'chat falls back to the newest session when none is given', async () => {
	const webui = await startFakeWebui();
	const ctx = { ...startServer( webui.port ), webui };

	const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_chat', arguments: { message: 'hi' } }, 5 );
	const payload = JSON.parse( res.result.content[ 0 ].text );

	assert.strictEqual( payload.session_id, 's1', 'first listed session is used' );
	assert.deepStrictEqual( webui.state.chatBodies[ 0 ], { session_id: 's1', message: 'hi' } );

	await stop( ctx );
} );

test( 'expired session cookie triggers exactly one re-login', async () => {
	const webui = await startFakeWebui();
	const ctx = { ...startServer( webui.port ), webui };

	// First call establishes the session (login #1).
	await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_list_sessions', arguments: {} }, 6 );
	assert.strictEqual( webui.state.loginCount, 1 );

	// Invalidate the cookie server-side, then call again: expect 401 → re-login → success.
	webui.state.validCookie = 'session-v999';
	const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_list_sessions', arguments: {} }, 7 );
	assert.strictEqual( JSON.parse( res.result.content[ 0 ].text ).count, 2 );
	assert.strictEqual( webui.state.loginCount, 2, 'exactly one re-login after expiry' );

	await stop( ctx );
} );

test( 'unknown tool returns a JSON-RPC error envelope', async () => {
	const webui = await startFakeWebui();
	const ctx = { ...startServer( webui.port ), webui };

	const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'nope', arguments: {} }, 8 );
	assert.strictEqual( res.error.code, -32603 );
	assert.ok( res.error.message.includes( 'Unknown tool' ) );

	await stop( ctx );
} );

test( 'in-flight request completes even when stdin closes right after', async () => {
	const webui = await startFakeWebui();
	webui.state.chatDelayMs = 400; // Response arrives well after stdin EOF.
	const ctx = { ...startServer( webui.port ), webui };

	try {
		ctx.child.stdin.write( JSON.stringify( {
			jsonrpc: '2.0',
			method: 'tools/call',
			params: { name: 'hermes_chat', arguments: { message: 'hi', session_id: 's1' } },
			id: 9,
		} ) + '\n' );
		ctx.child.stdin.end(); // Half-close: no further requests coming.

		const deadline = Date.now() + 10000;
		let response;
		while ( Date.now() < deadline && ! response ) {
			response = ctx.lines.find( ( l ) => l.includes( '"id":9' ) );
			if ( ! response ) {
				await new Promise( ( r ) => setTimeout( r, 25 ) );
			}
		}
		assert.ok( response, 'final response must be written after stdin EOF' );
		const parsed = JSON.parse( response );
		assert.strictEqual( JSON.parse( parsed.result.content[ 0 ].text ).answer, 'PONG' );

		const { code } = await ctx.exit;
		assert.strictEqual( code, 0 );
	} finally {
		await ctx.webui.close();
	}
} );
