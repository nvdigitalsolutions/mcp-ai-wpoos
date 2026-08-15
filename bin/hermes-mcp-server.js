#!/usr/bin/env node
/**
 * Hermes WebUI MCP Server — exposes the Hermes Agent WebUI as MCP tools.
 *
 * Zed spawns this as a stdio context server (newline-delimited JSON-RPC, per
 * the MCP stdio transport). Tool calls are translated into the Hermes WebUI
 * REST API (https://<host>:9610) using password login + session cookie:
 *
 *   POST /api/auth/login   → session cookie (re-login handled automatically)
 *   GET  /api/sessions     → session list
 *   POST /api/chat/start   → async chat submit (returns {stream_id} right
 *                            away; the run keeps going server-side even if
 *                            this bridge or its MCP client disconnects)
 *   GET  /api/chat/stream/status → poll the run (returns {active})
 *   GET  /api/approval/pending   → check the approval gate
 *   POST /api/approval/respond   → answer the approval gate
 *   GET  /api/session      → session detail (answer pull after the run)
 *
 * Environment variables (also read from MCP_AI_ENV_FILE, default
 * ~/.nvoos-bridge.env; explicit process env wins):
 *
 *   HERMES_WEBUI_URL        Base URL of the WebUI (required), e.g.
 *                           https://your-box.example.com:9610
 *   HERMES_WEBUI_PASSWORD   WebUI password (required; keep it in the env
 *                           file rather than Zed's settings.json)
 *   HERMES_SESSION_ID       Optional default session for hermes_chat
 *   HERMES_CHAT_TIMEOUT     Total wait budget for hermes_chat in ms
 *                           (default 300000 — agent runs with tools can
 *                           take minutes)
 *   HERMES_APPROVAL_MODE     Default approval handling while a chat turn is
 *                           parked on the approval gate: once | session |
 *                           always | deny | ask (default once). Per-call
 *                           override via hermes_chat's approval_choice.
 *   HERMES_WEBUI_INSECURE   Set to 1 to skip TLS verification (self-signed)
 *   HERMES_SYNC_SKILLS_ON_START
 *                           Sync .agents/skills/ to the agent after the MCP
 *                           handshake (default 1; set 0 to disable)
 *   HERMES_SYNC_SKILLS_DIR  Override the local skills directory (default
 *                           .agents/skills in the repo root)
 *
 * Example Zed context server entry (Settings → AI → MCP Servers → Add Local
 * Server, or settings.json):
 *   {
 *     "command": "node",
 *     "args": ["bin/hermes-mcp-server.js"]
 *     // env vars come from ~/.nvoos-bridge.env — no secrets in settings.json
 *   }
 *
 * Tools exposed:
 *   hermes_list_sessions   — list agent sessions (id, title, model, counts)
 *   hermes_chat            — send a message to the agent and wait for the
 *                            answer via async submit + poll (optionally in a
 *                            specific session; approval_choice for the
 *                            pending-approval gate)
 *   hermes_session_detail  — full detail for one session
 *   hermes_sync_skills     — sync .agents/skills/ to the agent's skills via
 *                            the WebUI skills API (also runs once after the
 *                            MCP handshake unless HERMES_SYNC_SKILLS_ON_START=0)
 */

'use strict';

const http = require( 'http' );
const https = require( 'https' );
const readline = require( 'readline' );
const { loadEnvFile } = require( './utils/env-file.js' );
const { syncSkillsToWebui, DEFAULT_SKILLS_DIR } = require( './hermes-skill-sync.js' );

const LOG = '[hermes-mcp]';
const SERVER_NAME = 'hermes-webui';
const SERVER_VERSION = '1.2.0';
const PROTOCOL_VERSION = '2024-11-05';

/**
 * Log to stderr (stdout is reserved for MCP messages).
 *
 * @param {string} msg  Message.
 */
function log( msg ) {
	process.stderr.write( `${ LOG } ${ msg }\n` );
}

// ── Configuration ────────────────────────────────────────────────────────────
//
// Read lazily via readConfig() AFTER loadEnvFile() has populated the
// environment — reading at module scope would capture empty values for
// env-file-only launches (the exact way Zed spawns this server).

let BASE_URL = '';
let PASSWORD = '';
let DEFAULT_SESSION_ID = '';
let INSECURE = false;
let CHAT_TIMEOUT_MS = 300000;
let APPROVAL_MODE = 'once';

const APPROVAL_CHOICES = new Set( [ 'once', 'session', 'always', 'deny', 'ask' ] );
const STREAM_POLL_INTERVAL_MS = 4000;
const STREAM_POLL_REQUEST_TIMEOUT_MS = 20000;

/**
 * setTimeout as a promise — used by the chat poll loop.
 *
 * @param {number} ms  Milliseconds.
 * @returns {Promise<void>}
 */
function sleep( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

/**
 * (Re-)read all configuration from the environment.
 */
function readConfig() {
	BASE_URL = ( process.env.HERMES_WEBUI_URL || '' ).replace( /\/+$/, '' );
	PASSWORD = process.env.HERMES_WEBUI_PASSWORD || '';
	DEFAULT_SESSION_ID = process.env.HERMES_SESSION_ID || '';
	INSECURE = '1' === process.env.HERMES_WEBUI_INSECURE;
	const timeoutRaw = parseInt( process.env.HERMES_CHAT_TIMEOUT || '', 10 );
	CHAT_TIMEOUT_MS = Number.isFinite( timeoutRaw ) && timeoutRaw >= 1000 ? timeoutRaw : 300000;
	const approvalMode = String( process.env.HERMES_APPROVAL_MODE || 'once' ).trim().toLowerCase();
	APPROVAL_MODE = APPROVAL_CHOICES.has( approvalMode ) ? approvalMode : 'once';
}

/**
 * Snapshot of the current runtime configuration (used by the standalone
 * sync CLI, bin/sync-skills-to-hermes.js, to reuse this module's HTTP/auth).
 *
 * @returns {object} {baseUrl, password, defaultSessionId, insecure, chatTimeoutMs, approvalMode}.
 */
function getConfig() {
	return {
		baseUrl: BASE_URL,
		password: PASSWORD,
		defaultSessionId: DEFAULT_SESSION_ID,
		insecure: INSECURE,
		chatTimeoutMs: CHAT_TIMEOUT_MS,
		approvalMode: APPROVAL_MODE,
	};
}

let cookie = null;

// ── HTTP helper ──────────────────────────────────────────────────────────────

/**
 * Perform an HTTP request against the WebUI.
 *
 * @param {string}  method     HTTP method.
 * @param {string}  route      URL path (may include query string).
 * @param {object|null} body   JSON body or null.
 * @param {number}  timeoutMs  Request timeout.
 * @returns {Promise<{statusCode:number, data:object|null}>}
 */
function request( method, route, body, timeoutMs ) {
	return new Promise( ( resolve, reject ) => {
		const parsed = new URL( BASE_URL + route );
		const isHttps = 'https:' === parsed.protocol;
		const lib = isHttps ? https : http;

		const headers = { 'Accept': 'application/json' };
		let payload = null;
		if ( null !== body ) {
			payload = JSON.stringify( body );
			headers[ 'Content-Type' ] = 'application/json';
			headers[ 'Content-Length' ] = Buffer.byteLength( payload );
		}
		if ( cookie ) {
			headers[ 'Cookie' ] = cookie;
		}

		const req = lib.request( {
			hostname: parsed.hostname,
			port: parsed.port || ( isHttps ? 443 : 80 ),
			path: parsed.pathname + parsed.search,
			method,
			headers,
			rejectUnauthorized: ! INSECURE,
		}, ( res ) => {
			const setCookie = res.headers[ 'set-cookie' ];
			if ( setCookie && setCookie.length ) {
				cookie = String( setCookie[ 0 ] ).split( ';' )[ 0 ];
			}
			const chunks = [];
			res.on( 'data', ( chunk ) => { chunks.push( chunk ); } );
			res.on( 'end', () => {
				// Join bytes BEFORE decoding: a multi-byte UTF-8 sequence can
				// straddle two TCP chunks, and per-chunk toString('utf8') would
				// corrupt it (e.g. box-drawing chars in SKILL.md diagrams).
				const trimmed = Buffer.concat( chunks ).toString( 'utf8' ).trim();
				if ( '' === trimmed ) {
					resolve( { statusCode: res.statusCode, data: null } );
					return;
				}
				try {
					resolve( { statusCode: res.statusCode, data: JSON.parse( trimmed ) } );
				} catch ( e ) {
					reject( new Error( `Non-JSON response (${ res.statusCode }): ${ trimmed.slice( 0, 200 ) }` ) );
				}
			} );
		} );

		req.setTimeout( timeoutMs, () => {
			req.destroy( new Error( `Request timed out after ${ timeoutMs }ms` ) );
		} );
		req.on( 'error', reject );
		if ( null !== payload ) {
			req.write( payload );
		}
		req.end();
	} );
}

// ── Auth ─────────────────────────────────────────────────────────────────────

let loginInFlight = null;

/**
 * Log in with the WebUI password, storing the session cookie. Concurrent
 * callers share a single login attempt — the WebUI rate-limits logins, so
 * parallel first tool calls must not each trigger one.
 *
 * @returns {Promise<void>}
 */
function login() {
	if ( ! loginInFlight ) {
		loginInFlight = ( async () => {
			try {
				const { statusCode, data } = await request( 'POST', '/api/auth/login', { password: PASSWORD }, 20000 );
				if ( 200 !== statusCode || ! data || true !== data.ok ) {
					throw new Error( `WebUI login failed (HTTP ${ statusCode }): ${ JSON.stringify( data ) }` );
				}
				log( 'logged in to WebUI' );
			} finally {
				loginInFlight = null;
			}
		} )();
	}
	return loginInFlight;
}

/**
 * Run a request, transparently re-authenticating once if the session cookie
 * expired (the WebUI session TTL is 1 hour).
 *
 * @param {string}  method     HTTP method.
 * @param {string}  route      URL path.
 * @param {object|null} body   JSON body or null.
 * @param {number}  timeoutMs  Request timeout.
 * @returns {Promise<{statusCode:number, data:object|null}>}
 */
async function authedRequest( method, route, body, timeoutMs ) {
	const result = await request( method, route, body, timeoutMs );
	if ( 401 === result.statusCode || 403 === result.statusCode || 302 === result.statusCode ) {
		await login();
		return request( method, route, body, timeoutMs );
	}
	return result;
}

// ── Tool implementations ─────────────────────────────────────────────────────

/**
 * List sessions.
 *
 * @returns {Promise<object>} Tool result payload.
 */
async function toolListSessions() {
	const { statusCode, data } = await authedRequest( 'GET', '/api/sessions', null, 30000 );
	if ( 200 !== statusCode ) {
		throw new Error( `GET /api/sessions failed (HTTP ${ statusCode })` );
	}
	const sessions = ( ( data && data.sessions ) || [] ).map( ( s ) => ( {
		session_id: s.session_id,
		title: s.title,
		model: s.model,
		provider: s.model_provider,
		message_count: s.message_count,
		workspace: s.workspace,
		updated_at: s.updated_at,
	} ) );
	return { count: sessions.length, sessions };
}

/**
 * Coerce a message `content` field (plain string or parts array) to text.
 *
 * @param {*} content  Content as stored by the WebUI.
 * @returns {string} Trimmed text ('' when empty or unrecognized).
 */
function contentToText( content ) {
	if ( 'string' === typeof content ) {
		return content.trim();
	}
	if ( Array.isArray( content ) ) {
		return content
			.map( ( part ) => {
				if ( 'string' === typeof part ) {
					return part;
				}
				if ( part && 'string' === typeof part.text ) {
					return part.text;
				}
				return '';
			} )
			.join( '' )
			.trim();
	}
	return '';
}

/**
 * Extract the newest assistant message from a /api/session payload.
 *
 * @param {object|null} data  Session detail payload.
 * @returns {string} Answer text ('' when none).
 */
function extractLastAssistantText( data ) {
	const messages = ( data && Array.isArray( data.messages ) && data.messages ) || [];
	let last = '';
	for ( const msg of messages ) {
		if ( ! msg || 'assistant' !== msg.role ) {
			continue;
		}
		const text = contentToText( msg.content );
		if ( text ) {
			last = text;
		}
	}
	return last;
}

/**
 * Send a chat message and wait for the agent's answer.
 *
 * Submits via POST /api/chat/start (async — returns a stream_id right away)
 * and then polls /api/chat/stream/status. Every poll is a short-lived HTTP
 * request, so the bridge never holds one connection open for the whole agent
 * run: if the MCP client (or this process) dies mid-run, the run continues
 * server-side and the answer can be re-read from the session later. The
 * approval gate is checked each round and answered with the configured
 * choice unless it is 'ask'.
 *
 * @param {object} args  {message, session_id?, model?, approval_choice?, timeout_ms?}.
 * @returns {Promise<object>} Tool result payload.
 */
async function toolChat( args ) {
	const message = String( args.message || '' ).trim();
	if ( ! message ) {
		throw new Error( 'hermes_chat requires a non-empty "message" argument.' );
	}

	const requestedSession = String( args.session_id || DEFAULT_SESSION_ID || '' ).trim();
	let sessionId = requestedSession;
	if ( ! sessionId ) {
		const list = await toolListSessions();
		const first = ( list.sessions || [] )[ 0 ];
		if ( ! first ) {
			throw new Error( 'No sessions exist yet — pass a session_id or create one in the WebUI first.' );
		}
		sessionId = first.session_id;
	}

	// Validate the call shape before submitting: an invalid approval_choice
	// must not inject a message into the conversation and start a run.
	const timeoutRaw = parseInt( args.timeout_ms, 10 );
	const budgetMs = Number.isFinite( timeoutRaw ) && timeoutRaw >= 1000 ? timeoutRaw : CHAT_TIMEOUT_MS;
	const approvalChoice = String( args.approval_choice || APPROVAL_MODE ).trim().toLowerCase();
	if ( ! APPROVAL_CHOICES.has( approvalChoice ) ) {
		throw new Error( `Invalid approval_choice '${ approvalChoice }' — use once, session, always, deny, or ask.` );
	}

	const body = { session_id: sessionId, message };
	if ( args.model ) {
		body.model = String( args.model );
	}

	// 1. Submit the run asynchronously — returns immediately.
	const start = await authedRequest( 'POST', '/api/chat/start', body, 20000 );
	if ( 200 !== start.statusCode || ! start.data ) {
		throw new Error( `POST /api/chat/start failed (HTTP ${ start.statusCode }): ${ JSON.stringify( start.data ) }` );
	}
	if ( start.data.error ) {
		throw new Error( `Hermes error: ${ start.data.error }` );
	}
	const streamId = String( start.data.stream_id || '' );
	if ( ! streamId ) {
		throw new Error( 'POST /api/chat/start returned no stream_id.' );
	}
	const deadline = Date.now() + budgetMs;
	let finished = false;

	// 2. Poll until the run finishes (or the wait budget runs out).
	while ( Date.now() < deadline ) {
		const st = await authedRequest(
			'GET',
			`/api/chat/stream/status?stream_id=${ encodeURIComponent( streamId ) }`,
			null,
			STREAM_POLL_REQUEST_TIMEOUT_MS
		);
		if ( 200 === st.statusCode && st.data && false === st.data.active ) {
			finished = true;
			break;
		}
		if ( 404 === st.statusCode ) {
			// Stream record cleaned up server-side — the run is over.
			finished = true;
			break;
		}
		if ( 200 !== st.statusCode ) {
			log( `stream status HTTP ${ st.statusCode } — will retry` );
		}

		// 3. A run parked on the approval gate stays active forever; answer
		//    it (unless the caller wants to handle it themselves).
		const pend = await authedRequest(
			'GET',
			`/api/approval/pending?session_id=${ encodeURIComponent( sessionId ) }`,
			null,
			15000
		);
		if ( pend.data && pend.data.pending ) {
			if ( 'ask' === approvalChoice ) {
				return {
					session_id: sessionId,
					stream_id: streamId,
					status: 'needs_approval',
					pending_count: pend.data.pending_count,
					answer: null,
				};
			}
			try {
				await authedRequest(
					'POST',
					'/api/approval/respond',
					{ session_id: sessionId, choice: approvalChoice },
					15000
				);
			} catch ( e ) {
				log( `approval respond failed: ${ e.message } — will retry` );
			}
		}

		await sleep( STREAM_POLL_INTERVAL_MS );
	}

	if ( ! finished ) {
		// Wait budget exhausted — the run keeps going server-side. Surface
		// the stream handle so a follow-up call can pick up the answer.
		return {
			session_id: sessionId,
			stream_id: streamId,
			status: 'still_running',
			answer: null,
		};
	}

	// 4. Pull the answer from the session tail.
	let answer = '';
	try {
		const detail = await authedRequest(
			'GET',
			`/api/session?session_id=${ encodeURIComponent( sessionId ) }&msg_limit=10`,
			null,
			30000
		);
		answer = extractLastAssistantText( detail.data );
	} catch ( e ) {
		log( `session answer pull failed: ${ e.message }` );
	}

	return {
		session_id: sessionId,
		stream_id: streamId,
		status: 'done',
		answer,
	};
}

/**
 * Fetch one session's detail.
 *
 * @param {object} args  {session_id}.
 * @returns {Promise<object>} Tool result payload.
 */
async function toolSessionDetail( args ) {
	const sessionId = String( args.session_id || '' ).trim();
	if ( ! sessionId ) {
		throw new Error( 'hermes_session_detail requires a "session_id" argument.' );
	}
	const { statusCode, data } = await authedRequest(
		'GET',
		`/api/session?session_id=${ encodeURIComponent( sessionId ) }`,
		null,
		30000
	);
	if ( 200 !== statusCode || ! data ) {
		throw new Error( `GET /api/session failed (HTTP ${ statusCode }): ${ JSON.stringify( data ) }` );
	}
	return data;
}

/**
 * Sync the repo's .agents/skills/ tree to the Hermes agent.
 *
 * @param {object} args  {names?, remove_missing?}.
 * @returns {Promise<object>} Tool result payload.
 */
async function toolSyncSkills( args ) {
	const skillsDir = process.env.HERMES_SYNC_SKILLS_DIR || DEFAULT_SKILLS_DIR;
	let names = null;
	if ( Array.isArray( args.names ) ) {
		names = args.names.map( ( n ) => String( n ).trim() ).filter( Boolean );
	}
	return syncSkillsToWebui( {
		authedRequest,
		skillsDir,
		names,
		removeMissing: !! args.remove_missing,
		log,
	} );
}

// ── MCP surface ──────────────────────────────────────────────────────────────

const TOOLS = [
	{
		name: 'hermes_list_sessions',
		description: 'List Hermes agent sessions (id, title, model, message count, workspace).',
		inputSchema: {
			type: 'object',
			properties: {},
		},
	},
	{
		name: 'hermes_chat',
		description: 'Send a message to the Hermes agent and wait for its answer. The run is submitted asynchronously and polled, so it keeps running server-side even if the MCP client disconnects. Optionally target a specific session by id (defaults to HERMES_SESSION_ID or the newest session).',
		inputSchema: {
			type: 'object',
			properties: {
				message: { type: 'string', description: 'The message to send.' },
				session_id: { type: 'string', description: 'Optional session id.' },
				model: { type: 'string', description: 'Optional model override.' },
				approval_choice: {
					type: 'string',
					enum: [ 'once', 'session', 'always', 'deny', 'ask' ],
					description: 'How to answer the pending-approval gate if the run parks on it: once, session, always, deny, or ask (leave it pending and return status needs_approval). Defaults to HERMES_APPROVAL_MODE (once).',
				},
				timeout_ms: {
					type: 'integer',
					description: 'Maximum wait for the answer in ms (default 300000). If the budget expires, the run continues server-side and the tool returns status still_running with the stream_id.',
				},
			},
			required: [ 'message' ],
		},
	},
	{
		name: 'hermes_session_detail',
		description: 'Fetch full detail for one Hermes session.',
		inputSchema: {
			type: 'object',
			properties: {
				session_id: { type: 'string', description: 'Session id.' },
			},
			required: [ 'session_id' ],
		},
	},
	{
		name: 'hermes_sync_skills',
		description: 'Sync skills from the repo .agents/skills/ directory to the Hermes agent via the WebUI skills API. Uploads new or changed SKILL.md files and skips unchanged ones; optionally removes remote skills that no longer exist locally.',
		inputSchema: {
			type: 'object',
			properties: {
				names: {
					type: 'array',
					items: { type: 'string' },
					description: 'Optional: only sync these skill names.',
				},
				remove_missing: {
					type: 'boolean',
					description: 'Optional: delete remote skills absent from .agents/skills (default false — the agent may have self-authored skills).',
				},
			},
		},
	},
];

/**
 * Dispatch a tools/call request.
 *
 * @param {object} params  {name, arguments}.
 * @returns {Promise<object>} MCP result.
 */
async function dispatchToolCall( params ) {
	const name = params && params.name;
	const args = ( params && params.arguments ) || {};

	if ( 'hermes_list_sessions' === name ) {
		return { content: [ { type: 'text', text: JSON.stringify( await toolListSessions(), null, 2 ) } ] };
	}
	if ( 'hermes_chat' === name ) {
		return { content: [ { type: 'text', text: JSON.stringify( await toolChat( args ), null, 2 ) } ] };
	}
	if ( 'hermes_session_detail' === name ) {
		return { content: [ { type: 'text', text: JSON.stringify( await toolSessionDetail( args ), null, 2 ) } ] };
	}
	if ( 'hermes_sync_skills' === name ) {
		return { content: [ { type: 'text', text: JSON.stringify( await toolSyncSkills( args ), null, 2 ) } ] };
	}
	throw new Error( `Unknown tool: ${ name }` );
}

// ── Main loop ────────────────────────────────────────────────────────────────

function write( obj ) {
	process.stdout.write( JSON.stringify( obj ) + '\n' );
}

function main() {
	loadEnvFile( log );
	readConfig();

	if ( ! BASE_URL ) {
		log( 'ERROR: HERMES_WEBUI_URL is not set.' );
		process.exit( 1 );
	}
	if ( ! PASSWORD ) {
		log( 'ERROR: HERMES_WEBUI_PASSWORD is not set.' );
		process.exit( 1 );
	}

	log( `Hermes WebUI MCP server ready — ${ BASE_URL }` );

	const rl = readline.createInterface( { input: process.stdin, crlfDelay: Infinity } );

	// Track in-flight requests: stdin EOF must not kill a request that is
	// still awaiting its HTTP response (some clients half-close stdin right
	// after writing the final request).
	let inFlight = 0;

	// One-shot guard: the startup skill sync runs once per server lifetime.
	let startupSyncStarted = false;

	/**
	 * Refresh the Hermes agent's skills from .agents/skills/ once, right after
	 * the MCP handshake. Fire-and-forget: results go to stderr only, so the
	 * protocol stream stays clean. The in-flight counter keeps the process
	 * alive until the sync drains.
	 *
	 * Opt out with HERMES_SYNC_SKILLS_ON_START=0.
	 */
	function maybeStartupSync() {
		if ( startupSyncStarted ) {
			return;
		}
		startupSyncStarted = true;
		if ( '0' === process.env.HERMES_SYNC_SKILLS_ON_START ) {
			return;
		}

		const skillsDir = process.env.HERMES_SYNC_SKILLS_DIR || DEFAULT_SKILLS_DIR;
		log( `syncing ${ skillsDir } → Hermes (startup)` );
		inFlight += 1;
		( async () => {
			try {
				const summary = await syncSkillsToWebui( {
					authedRequest,
					skillsDir,
					names: null,
					removeMissing: false,
					log,
				} );
				log(
					`startup skill sync complete — ${ summary.synced.length } uploaded, ` +
					`${ summary.unchanged.length } unchanged, ${ summary.remote_count } remote`
				);
			} catch ( e ) {
				log( `startup skill sync failed: ${ e.message }` );
			} finally {
				inFlight -= 1;
			}
		} )();
	}

	rl.on( 'line', async ( line ) => {
		const trimmed = line.trim();
		if ( ! trimmed ) {
			return;
		}

		let msg;
		try {
			msg = JSON.parse( trimmed );
		} catch ( e ) {
			write( { jsonrpc: '2.0', id: null, error: { code: -32700, message: `Parse error: ${ e.message }` } } );
			return;
		}

		const isNotification = undefined === msg.id || null === msg.id;
		const respond = ( payload ) => {
			if ( ! isNotification ) {
				write( { jsonrpc: '2.0', id: msg.id, ...payload } );
			}
		};

		inFlight += 1;
		try {
			switch ( msg.method ) {
				case 'initialize':
					respond( {
						result: {
							protocolVersion: PROTOCOL_VERSION,
							capabilities: { tools: { listChanged: false } },
							serverInfo: { name: SERVER_NAME, version: SERVER_VERSION },
						},
					} );
					maybeStartupSync();
					return;

				case 'tools/list':
					respond( { result: { tools: TOOLS } } );
					return;

				case 'tools/call':
					respond( { result: await dispatchToolCall( msg.params ) } );
					return;

				case 'ping':
					respond( { result: {} } );
					return;

				case 'notifications/initialized':
				case 'notifications/cancelled':
					return;

				default:
					respond( { error: { code: -32601, message: `Method not found: ${ msg.method }` } } );
			}
		} catch ( e ) {
			log( `tool error: ${ e.message }` );
			respond( { error: { code: -32603, message: e.message } } );
		} finally {
			inFlight -= 1;
		}
	} );

	rl.on( 'close', () => {
		log( 'stdin closed, draining in-flight requests.' );
		const drain = () => {
			if ( inFlight > 0 ) {
				setTimeout( drain, 50 );
				return;
			}
			process.exit( 0 );
		};
		drain();
	} );
}

if ( require.main === module ) {
	main();
}

module.exports = { request, login, authedRequest, readConfig, getConfig, toolListSessions, toolChat, toolSessionDetail, toolSyncSkills, TOOLS, main };
