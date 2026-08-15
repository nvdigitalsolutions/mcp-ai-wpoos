#!/usr/bin/env node
/**
 * Tests for the Hermes WebUI MCP server (bin/hermes-mcp-server.js).
 *
 * Uses an in-process fake WebUI (login + sessions + async chat + approval
 * routes) and drives the server over its stdio channel the same way Zed
 * does — newline-delimited JSON-RPC. No real Hermes infrastructure is
 * touched.
 *
 * Run:  node bin/test-hermes-mcp-server.js
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const http = require( 'node:http' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const readline = require( 'node:readline' );
const { spawn } = require( 'node:child_process' );

const SERVER = path.join( __dirname, 'hermes-mcp-server.js' );
const { readLocalSkills, normalizeContent } = require( './hermes-skill-sync.js' );

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
		// Async chat state: stream_id → active flag, plus the approval gate.
		streams: new Map(),
		nextStreamId: 1,
		pendingApproval: null,
		approvalResponses: [],
		// Skills API state: name → SKILL.md content, plus call logs.
		skillBodies: {},
		saveCalls: [],
		deleteCalls: [],
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
			const url = new URL( req.url, 'http://fake' );
			const sessionId = url.searchParams.get( 'session_id' ) || 's1';
			// Mirrors the live WebUI: everything is nested under `session`.
			return json( 200, {
				session: {
					session_id: sessionId,
					title: 'First',
					messages: [
						{ role: 'user', content: 'hi' },
						{ role: 'assistant', content: 'PONG' },
					],
				},
			} );
		}

		// ── Skills API (mirrors the real WebUI routes) ──
		if ( 'GET' === req.method && '/api/skills' === req.url ) {
			const skills = Object.entries( state.skillBodies ).map( ( [ name ] ) => ( {
				name,
				description: 'd',
				category: null,
				disabled: false,
			} ) );
			return json( 200, { success: true, skills, categories: [], count: skills.length } );
		}

		if ( 'GET' === req.method && req.url.startsWith( '/api/skills/content' ) ) {
			const name = new URL( req.url, 'http://fake' ).searchParams.get( 'name' );
			if ( Object.prototype.hasOwnProperty.call( state.skillBodies, name ) ) {
				return json( 200, { success: true, name, content: state.skillBodies[ name ], linked_files: {} } );
			}
			return json( 200, { success: false, error: `Skill '${ name }' not found.` } );
		}

		if ( 'POST' === req.method && '/api/skills/save' === req.url ) {
			let raw = '';
			req.on( 'data', ( c ) => { raw += c; } );
			req.on( 'end', () => {
				const body = JSON.parse( raw || '{}' );
				state.saveCalls.push( { name: body.name, content: body.content } );
				state.skillBodies[ body.name ] = body.content;
				json( 200, { ok: true, name: body.name } );
			} );
			return;
		}

		if ( 'POST' === req.method && '/api/skills/delete' === req.url ) {
			let raw = '';
			req.on( 'data', ( c ) => { raw += c; } );
			req.on( 'end', () => {
				const body = JSON.parse( raw || '{}' );
				state.deleteCalls.push( { name: body.name } );
				delete state.skillBodies[ body.name ];
				json( 200, { ok: true } );
			} );
			return;
		}

		if ( 'POST' === req.method && '/api/chat/start' === req.url ) {
			let raw = '';
			req.on( 'data', ( c ) => { raw += c; } );
			req.on( 'end', () => {
				state.chatBodies.push( JSON.parse( raw || '{}' ) );
				const streamId = `stream-${ state.nextStreamId++ }`;
				state.streams.set( streamId, true );
				if ( state.chatDelayMs ) {
					// unref: long fake-run timers must not keep the test
					// process alive after the suite finishes.
					setTimeout( () => state.streams.set( streamId, false ), state.chatDelayMs ).unref();
				} else {
					state.streams.set( streamId, false );
				}
				json( 200, { stream_id: streamId } );
			} );
			return;
		}

		if ( 'GET' === req.method && req.url.startsWith( '/api/chat/stream/status' ) ) {
			const streamId = new URL( req.url, 'http://fake' ).searchParams.get( 'stream_id' );
			if ( ! state.streams.has( streamId ) ) {
				return json( 404, { error: 'stream not found' } );
			}
			return json( 200, { active: state.streams.get( streamId ) } );
		}

		if ( 'GET' === req.method && req.url.startsWith( '/api/approval/pending' ) ) {
			return json( 200, {
				pending: !! state.pendingApproval,
				pending_count: state.pendingApproval ? 1 : 0,
			} );
		}

		if ( 'POST' === req.method && '/api/approval/respond' === req.url ) {
			let raw = '';
			req.on( 'data', ( c ) => { raw += c; } );
			req.on( 'end', () => {
				const body = JSON.parse( raw || '{}' );
				state.approvalResponses.push( { session_id: body.session_id, choice: body.choice } );
				state.pendingApproval = null;
				json( 200, { ok: true } );
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

function startServer( port, extraEnv = {} ) {
	const child = spawn( process.execPath, [ SERVER ], {
		env: {
			...process.env,
			HERMES_WEBUI_URL: `http://127.0.0.1:${ port }`,
			HERMES_WEBUI_PASSWORD: 'test-password',
			// Isolate tests from the startup auto-sync (which would otherwise
			// read the real repo .agents/skills tree against the fake WebUI).
			HERMES_SYNC_SKILLS_ON_START: '0',
			...extraEnv,
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

/**
 * Create a temporary skills fixture (alpha + beta) for sync tests.
 *
 * @returns {string} Fixture directory path (caller removes it).
 */
function makeSkillsFixture() {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'hermes-sync-skills-' ) );
	fs.mkdirSync( path.join( dir, 'alpha' ) );
	fs.writeFileSync( path.join( dir, 'alpha', 'SKILL.md' ), '---\nname: alpha\ndescription: A\n---\n\nbody alpha\n' );
	fs.mkdirSync( path.join( dir, 'beta' ) );
	fs.writeFileSync( path.join( dir, 'beta', 'SKILL.md' ), '---\nname: beta\ndescription: B\n---\n\nbody beta\n' );
	return dir;
}

const BETA_CONTENT = '---\nname: beta\ndescription: B\n---\n\nbody beta\n';

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
		'hermes_sync_skills',
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
	assert.strictEqual( payload.status, 'done' );
	assert.ok( payload.stream_id, 'async submit returns a stream handle' );
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
	assert.strictEqual( payload.answer, 'PONG' );
	assert.deepStrictEqual( webui.state.chatBodies[ 0 ], { session_id: 's1', message: 'hi' } );

	await stop( ctx );
} );

test( 'chat resolves the approval gate with the configured choice', async () => {
	const webui = await startFakeWebui();
	webui.state.pendingApproval = { session_id: 's1' };
	webui.state.chatDelayMs = 1500; // Run outlives the first poll round.
	const ctx = { ...startServer( webui.port ), webui };

	const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_chat', arguments: { session_id: 's1', message: 'hi', approval_choice: 'deny' } }, 6 );
	const payload = JSON.parse( res.result.content[ 0 ].text );

	assert.strictEqual( payload.status, 'done' );
	assert.strictEqual( payload.answer, 'PONG' );
	assert.deepStrictEqual( webui.state.approvalResponses, [ { session_id: 's1', choice: 'deny' } ] );
	assert.strictEqual( webui.state.pendingApproval, null, 'gate cleared by the respond call' );

	await stop( ctx );
} );

test( 'chat with approval_choice ask leaves the gate pending', async () => {
	const webui = await startFakeWebui();
	webui.state.pendingApproval = { session_id: 's1' };
	webui.state.chatDelayMs = 60000; // Stream stays active — only 'ask' stops the loop.
	const ctx = { ...startServer( webui.port ), webui };

	const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_chat', arguments: { session_id: 's1', message: 'hi', approval_choice: 'ask' } }, 7 );
	const payload = JSON.parse( res.result.content[ 0 ].text );

	assert.strictEqual( payload.status, 'needs_approval' );
	assert.strictEqual( payload.answer, null );
	assert.strictEqual( payload.pending_count, 1 );
	assert.deepStrictEqual( webui.state.approvalResponses, [], 'ask mode must not answer the gate' );

	await stop( ctx );
} );

test( 'chat returns still_running when the run outlives the wait budget', async () => {
	const webui = await startFakeWebui();
	webui.state.chatDelayMs = 100000; // Stream stays active far past the budget.
	const ctx = { ...startServer( webui.port ), webui };

	const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_chat', arguments: { session_id: 's1', message: 'hi', timeout_ms: 1500 } }, 8 );
	const payload = JSON.parse( res.result.content[ 0 ].text );

	assert.strictEqual( payload.status, 'still_running' );
	assert.strictEqual( payload.answer, null );
	assert.ok( payload.stream_id, 'stream handle lets a follow-up call re-check' );

	await stop( ctx );
} );

test( 'chat rejects an invalid approval_choice', async () => {
	const webui = await startFakeWebui();
	const ctx = { ...startServer( webui.port ), webui };

	const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_chat', arguments: { session_id: 's1', message: 'hi', approval_choice: 'sometimes' } }, 9 );
	assert.strictEqual( res.error.code, -32603 );
	assert.ok( res.error.message.includes( 'Invalid approval_choice' ) );

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

test( 'config comes from the env file when process env is empty', async () => {
	// Regression: config used to be read at module scope, before
	// loadEnvFile() ran — env-file-only launches (how Zed spawns us) failed.
	const webui = await startFakeWebui();
	const envFile = path.join( os.tmpdir(), `hermes-mcp-test-${ process.pid }.env` );
	fs.writeFileSync( envFile, [
		`HERMES_WEBUI_URL=http://127.0.0.1:${ webui.port }`,
		'HERMES_WEBUI_PASSWORD=test-password',
	].join( '\n' ) );

	const child = spawn( process.execPath, [ SERVER ], {
		env: {
			...process.env,
			MCP_AI_ENV_FILE: envFile,
			// No HERMES_* vars in process env — file must supply them.
		},
		stdio: [ 'pipe', 'pipe', 'pipe' ],
		windowsHide: true,
	} );
	const lines = [];
	readline.createInterface( { input: child.stdout } ).on( 'line', ( l ) => lines.push( l ) );
	const exit = new Promise( ( resolve ) => child.once( 'exit', ( code, signal ) => resolve( { code, signal } ) ) );

	try {
		const init = await rpc( child, lines, 'initialize', { protocolVersion: '2024-11-05', capabilities: {}, clientInfo: { name: 'zed', version: '0.224.0' } }, 1 );
		assert.strictEqual( init.result.serverInfo.name, 'hermes-webui' );

		const list = await rpc( child, lines, 'tools/call', { name: 'hermes_list_sessions', arguments: {} }, 2 );
		assert.strictEqual( JSON.parse( list.result.content[ 0 ].text ).count, 2 );
		assert.strictEqual( webui.state.loginCount, 1, 'password came from the env file' );
	} finally {
		child.stdin.end();
		await exit;
		fs.unlinkSync( envFile );
		await webui.close();
	}
} );

test( 'unknown tool returns a JSON-RPC error envelope', async () => {
	const webui = await startFakeWebui();
	const ctx = { ...startServer( webui.port ), webui };

	const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'nope', arguments: {} }, 8 );
	assert.strictEqual( res.error.code, -32603 );
	assert.ok( res.error.message.includes( 'Unknown tool' ) );

	await stop( ctx );
} );

test( 'concurrent first tool calls share one login', async () => {
	const webui = await startFakeWebui();
	const ctx = { ...startServer( webui.port ), webui };

	// Fire two requests back-to-back on a fresh session: both see 401 and
	// race to log in. The serialized login must collapse them into one.
	const p1 = rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_list_sessions', arguments: {} }, 10 );
	const p2 = rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_list_sessions', arguments: {} }, 11 );
	const [ r1, r2 ] = await Promise.all( [ p1, p2 ] );

	assert.strictEqual( JSON.parse( r1.result.content[ 0 ].text ).count, 2 );
	assert.strictEqual( JSON.parse( r2.result.content[ 0 ].text ).count, 2 );
	assert.strictEqual( webui.state.loginCount, 1, 'parallel first calls must trigger a single login' );

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

// ── Skill-sync engine (pure functions) ──────────────────────────────────────

test( 'readLocalSkills parses skill dirs and reports extra files', () => {
	const dir = makeSkillsFixture();
	fs.writeFileSync( path.join( dir, 'alpha', 'reference.md' ), '# ref' );
	fs.mkdirSync( path.join( dir, 'no-md' ) ); // No SKILL.md — must be ignored.
	try {
		const skills = readLocalSkills( dir );
		assert.deepStrictEqual( skills.map( ( s ) => s.name ), [ 'alpha', 'beta' ] );
		assert.deepStrictEqual( skills[ 0 ].extraFiles, [ 'reference.md' ] );
		assert.ok( skills[ 1 ].content.includes( 'body beta' ) );
	} finally {
		fs.rmSync( dir, { recursive: true, force: true } );
	}
} );

test( 'readLocalSkills rejects a missing directory', () => {
	assert.throws( () => readLocalSkills( path.join( os.tmpdir(), 'hermes-sync-nope-' + process.pid ) ), /not found/ );
} );

test( 'normalizeContent flattens CRLF to LF', () => {
	assert.strictEqual( normalizeContent( 'a\r\nb\r\n' ), 'a\nb\n' );
	assert.strictEqual( normalizeContent( 'a\nb\n' ), 'a\nb\n' );
} );

// ── hermes_sync_skills MCP tool ──────────────────────────────────────────────

test( 'sync uploads missing skills and skips unchanged ones', async () => {
	const webui = await startFakeWebui();
	const skillsDir = makeSkillsFixture();
	const ctx = { ...startServer( webui.port, { HERMES_SYNC_SKILLS_DIR: skillsDir } ), webui };

	try {
		// Pre-seed remote with beta (identical content) and gamma (remote-only).
		webui.state.skillBodies.beta = BETA_CONTENT;
		webui.state.skillBodies.gamma = 'body gamma';

		const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_sync_skills', arguments: {} }, 30 );
		const payload = JSON.parse( res.result.content[ 0 ].text );

		assert.deepStrictEqual( payload.synced, [ 'alpha' ], 'only the missing skill is uploaded' );
		assert.deepStrictEqual( payload.unchanged, [ 'beta' ] );
		assert.deepStrictEqual( payload.removed, [], 'no removal by default' );
		assert.strictEqual( payload.remote_count, 2, 'remote seeds: beta + gamma' );
		assert.strictEqual( webui.state.saveCalls.length, 1 );
		assert.strictEqual( webui.state.saveCalls[ 0 ].name, 'alpha' );
		assert.ok( 'gamma' in webui.state.skillBodies, 'remote-only skill survives a default sync' );
	} finally {
		await stop( ctx );
		fs.rmSync( skillsDir, { recursive: true, force: true } );
	}
} );

test( 'sync updates a skill whose remote content differs', async () => {
	const webui = await startFakeWebui();
	const skillsDir = makeSkillsFixture();
	const ctx = { ...startServer( webui.port, { HERMES_SYNC_SKILLS_DIR: skillsDir } ), webui };

	try {
		webui.state.skillBodies.beta = 'stale remote body';

		const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_sync_skills', arguments: {} }, 31 );
		const payload = JSON.parse( res.result.content[ 0 ].text );

		assert.deepStrictEqual( payload.synced.sort(), [ 'alpha', 'beta' ] );
		assert.strictEqual( webui.state.skillBodies.beta, BETA_CONTENT );
	} finally {
		await stop( ctx );
		fs.rmSync( skillsDir, { recursive: true, force: true } );
	}
} );

test( 'sync respects the names filter', async () => {
	const webui = await startFakeWebui();
	const skillsDir = makeSkillsFixture();
	const ctx = { ...startServer( webui.port, { HERMES_SYNC_SKILLS_DIR: skillsDir } ), webui };

	try {
		const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_sync_skills', arguments: { names: [ 'beta' ] } }, 32 );
		const payload = JSON.parse( res.result.content[ 0 ].text );

		assert.strictEqual( payload.local_count, 1 );
		assert.deepStrictEqual( payload.synced, [ 'beta' ] );
		assert.strictEqual( webui.state.saveCalls.length, 1 );
		assert.strictEqual( webui.state.saveCalls[ 0 ].name, 'beta' );
	} finally {
		await stop( ctx );
		fs.rmSync( skillsDir, { recursive: true, force: true } );
	}
} );

test( 'sync with remove_missing prunes remote-only skills, names-scoped', async () => {
	const webui = await startFakeWebui();
	const skillsDir = makeSkillsFixture();
	const ctx = { ...startServer( webui.port, { HERMES_SYNC_SKILLS_DIR: skillsDir } ), webui };

	try {
		webui.state.skillBodies.gamma = 'body gamma';

		const res = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_sync_skills', arguments: { remove_missing: true } }, 33 );
		const payload = JSON.parse( res.result.content[ 0 ].text );

		assert.deepStrictEqual( payload.removed, [ 'gamma' ] );
		assert.deepStrictEqual( webui.state.deleteCalls.map( ( d ) => d.name ), [ 'gamma' ] );
		assert.ok( ! ( 'gamma' in webui.state.skillBodies ) );

		// With a names filter, out-of-scope remote skills are left alone.
		webui.state.skillBodies.gamma = 'body gamma';
		const res2 = await rpc( ctx.child, ctx.lines, 'tools/call', { name: 'hermes_sync_skills', arguments: { names: [ 'alpha' ], remove_missing: true } }, 34 );
		const payload2 = JSON.parse( res2.result.content[ 0 ].text );

		assert.deepStrictEqual( payload2.removed, [] );
		assert.ok( 'gamma' in webui.state.skillBodies, 'names-scoped remove_missing must not touch other skills' );
	} finally {
		await stop( ctx );
		fs.rmSync( skillsDir, { recursive: true, force: true } );
	}
} );

test( 'startup auto-sync uploads repo skills right after initialize', async () => {
	const webui = await startFakeWebui();
	const skillsDir = makeSkillsFixture();

	const child = spawn( process.execPath, [ SERVER ], {
		env: {
			...process.env,
			HERMES_WEBUI_URL: `http://127.0.0.1:${ webui.port }`,
			HERMES_WEBUI_PASSWORD: 'test-password',
			HERMES_SYNC_SKILLS_DIR: skillsDir,
			HERMES_SYNC_SKILLS_ON_START: '1',
		},
		stdio: [ 'pipe', 'pipe', 'pipe' ],
		windowsHide: true,
	} );
	const lines = [];
	readline.createInterface( { input: child.stdout } ).on( 'line', ( l ) => lines.push( l ) );
	const exit = new Promise( ( resolve ) => child.once( 'exit', ( code ) => resolve( code ) ) );

	try {
		await rpc( child, lines, 'initialize', { protocolVersion: '2024-11-05', capabilities: {}, clientInfo: { name: 'zed', version: '0.224.0' } }, 1 );

		const deadline = Date.now() + 10000;
		while ( Date.now() < deadline && webui.state.saveCalls.length < 2 ) {
			await new Promise( ( r ) => setTimeout( r, 25 ) );
		}
		assert.deepStrictEqual(
			webui.state.saveCalls.map( ( s ) => s.name ).sort(),
			[ 'alpha', 'beta' ],
			'startup sync uploads every fixture skill'
		);
	} finally {
		child.stdin.end();
		await exit;
		fs.rmSync( skillsDir, { recursive: true, force: true } );
		await webui.close();
	}
} );

test( 'standalone CLI syncs skills and exits 0', async () => {
	const webui = await startFakeWebui();
	const skillsDir = makeSkillsFixture();
	// Empty env file so the real ~/.nvoos-bridge.env can never leak in.
	const envFile = path.join( os.tmpdir(), `hermes-cli-test-${ process.pid }.env` );
	fs.writeFileSync( envFile, '' );

	const child = spawn( process.execPath, [ path.join( __dirname, 'sync-skills-to-hermes.js' ), '--json', `--dir=${ skillsDir }` ], {
		env: {
			...process.env,
			HERMES_WEBUI_URL: `http://127.0.0.1:${ webui.port }`,
			HERMES_WEBUI_PASSWORD: 'test-password',
			MCP_AI_ENV_FILE: envFile,
		},
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		windowsHide: true,
	} );
	let stdout = '';
	let stderr = '';
	child.stdout.on( 'data', ( c ) => { stdout += c; } );
	child.stderr.on( 'data', ( c ) => { stderr += c; } );
	const exit = new Promise( ( resolve ) => child.once( 'exit', ( code ) => resolve( code ) ) );

	try {
		const code = await exit;
		assert.strictEqual( code, 0, `CLI failed (${ code }): ${ stderr }` );
		const summary = JSON.parse( stdout.trim() );
		assert.deepStrictEqual( summary.synced.sort(), [ 'alpha', 'beta' ] );
		assert.strictEqual( webui.state.saveCalls.length, 2 );
	} finally {
		fs.rmSync( skillsDir, { recursive: true, force: true } );
		fs.unlinkSync( envFile );
		await webui.close();
	}
} );

// ── Response decoding (in-process module reuse) ──────────────────────────────

test( 'response chunks are joined before UTF-8 decoding', async () => {
	// Regression: the request() helper used to decode every TCP chunk on its
	// own, so a multi-byte character split across two chunks came back as
	// mojibake — which made the skill sync re-upload unchanged skills forever.
	const { readConfig, authedRequest } = require( './hermes-mcp-server.js' );
	const body = JSON.stringify( { content: 'x├───┤y' } );
	const rawBuf = Buffer.from( body, 'utf8' );

	const fake = http.createServer( ( req, res ) => {
		res.writeHead( 200, { 'Content-Type': 'application/json' } );
		// Split inside the box-drawing sequence so the multi-byte run straddles the boundary.
		const cut = rawBuf.indexOf( Buffer.from( '├─' ) ) + 1;
		res.write( rawBuf.subarray( 0, cut ) );
		setTimeout( () => {
			res.write( rawBuf.subarray( cut ) );
			res.end();
		}, 10 );
	} );
	await new Promise( ( r ) => fake.listen( 0, '127.0.0.1', r ) );

	const prevUrl = process.env.HERMES_WEBUI_URL;
	const prevPw = process.env.HERMES_WEBUI_PASSWORD;
	try {
		process.env.HERMES_WEBUI_URL = `http://127.0.0.1:${ fake.address().port }`;
		process.env.HERMES_WEBUI_PASSWORD = 'test-password';
		readConfig();

		const { statusCode, data } = await authedRequest( 'GET', '/api/x', null, 30000 );
		assert.strictEqual( statusCode, 200 );
		assert.strictEqual( data.content, 'x├───┤y', 'multi-byte content must round-trip intact' );
	} finally {
		if ( undefined === prevUrl ) {
			delete process.env.HERMES_WEBUI_URL;
		} else {
			process.env.HERMES_WEBUI_URL = prevUrl;
		}
		if ( undefined === prevPw ) {
			delete process.env.HERMES_WEBUI_PASSWORD;
		} else {
			process.env.HERMES_WEBUI_PASSWORD = prevPw;
		}
		readConfig();
		await new Promise( ( r ) => fake.close( r ) );
	}
} );
