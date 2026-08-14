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
 *   POST /api/chat         → synchronous chat (agent run, returns {answer})
 *
 * Environment variables (also read from MCP_AI_ENV_FILE, default
 * ~/.nvoos-bridge.env; explicit process env wins):
 *
 *   HERMES_WEBUI_URL        Base URL of the WebUI (required), e.g.
 *                           https://your-box.example.com:9610
 *   HERMES_WEBUI_PASSWORD   WebUI password (required; keep it in the env
 *                           file rather than Zed's settings.json)
 *   HERMES_SESSION_ID       Optional default session for hermes_chat
 *   HERMES_CHAT_TIMEOUT     Chat request timeout in ms (default 300000 —
 *                           agent runs with tools can take minutes)
 *   HERMES_WEBUI_INSECURE   Set to 1 to skip TLS verification (self-signed)
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
 *                            answer (optionally in a specific session)
 *   hermes_session_detail  — full detail for one session
 */

'use strict';

const http = require( 'http' );
const https = require( 'https' );
const readline = require( 'readline' );
const { loadEnvFile } = require( './utils/env-file.js' );

const LOG = '[hermes-mcp]';
const SERVER_NAME = 'hermes-webui';
const SERVER_VERSION = '1.0.0';
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

const BASE_URL = ( process.env.HERMES_WEBUI_URL || '' ).replace( /\/+$/, '' );
const PASSWORD = process.env.HERMES_WEBUI_PASSWORD || '';
const DEFAULT_SESSION_ID = process.env.HERMES_SESSION_ID || '';
const INSECURE = '1' === process.env.HERMES_WEBUI_INSECURE;

const timeoutRaw = parseInt( process.env.HERMES_CHAT_TIMEOUT || '', 10 );
const CHAT_TIMEOUT_MS = Number.isFinite( timeoutRaw ) && timeoutRaw >= 1000 ? timeoutRaw : 300000;

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
			let raw = '';
			res.on( 'data', ( chunk ) => { raw += chunk; } );
			res.on( 'end', () => {
				const trimmed = raw.trim();
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
 * Send a synchronous chat message.
 *
 * @param {object} args  {message, session_id?, model?}.
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

	const body = { session_id: sessionId, message };
	if ( args.model ) {
		body.model = String( args.model );
	}

	const { statusCode, data } = await authedRequest( 'POST', '/api/chat', body, CHAT_TIMEOUT_MS );
	if ( 200 !== statusCode || ! data ) {
		throw new Error( `POST /api/chat failed (HTTP ${ statusCode }): ${ JSON.stringify( data ) }` );
	}
	if ( data.error ) {
		throw new Error( `Hermes error: ${ data.error }` );
	}
	return {
		session_id: sessionId,
		status: data.status || 'done',
		answer: data.answer || '',
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
		description: 'Send a message to the Hermes agent and wait for its answer. Optionally target a specific session by id (defaults to HERMES_SESSION_ID or the newest session).',
		inputSchema: {
			type: 'object',
			properties: {
				message: { type: 'string', description: 'The message to send.' },
				session_id: { type: 'string', description: 'Optional session id.' },
				model: { type: 'string', description: 'Optional model override.' },
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
	throw new Error( `Unknown tool: ${ name }` );
}

// ── Main loop ────────────────────────────────────────────────────────────────

function write( obj ) {
	process.stdout.write( JSON.stringify( obj ) + '\n' );
}

function main() {
	loadEnvFile( log );

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

module.exports = { request, login, authedRequest, toolListSessions, toolChat, toolSessionDetail, TOOLS, main };
