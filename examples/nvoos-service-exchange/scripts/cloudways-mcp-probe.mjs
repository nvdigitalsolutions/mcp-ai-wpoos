#!/usr/bin/env node
/**
 * Cloudways MCP probe (Phase 2 verification supplement).
 *
 * Connects to the user's configured Cloudways MCP server(s) over Streamable
 * HTTP, lists the advertised tools, and (with --call) invokes READ-ONLY
 * tools. Credentials are read from the Zed settings file at runtime and are
 * never printed. No state-changing tool is ever called by this script.
 *
 * Usage:
 *   node scripts/cloudways-mcp-probe.mjs
 *   node scripts/cloudways-mcp-probe.mjs --server cloudways-mushroom
 *   node scripts/cloudways-mcp-probe.mjs --call server_list
 *   node scripts/cloudways-mcp-probe.mjs --call app_list
 *   node scripts/cloudways-mcp-probe.mjs --call app_get --app-id 1234567
 *   node scripts/cloudways-mcp-probe.mjs --call app_credentials --server-id 1536372 --app-id 1234567
 *
 * Safety:
 *  - only an explicit read-only allowlist may be called;
 *  - results are summarised structurally: no passwords, IPs, emails, or
 *    tokens are printed;
 *  - `sys_user` (a folder identifier, not a secret) is printed when present
 *    because it is the value the provisioning step needs.
 */

import fs from 'node:fs';

const SETTINGS_PATH = 'C:/Users/rasta/AppData/Roaming/Zed/settings.json';
const DEFAULT_SERVER = 'cloudways-nvdigital';

const READ_ONLY_TOOLS = new Set( [
	'server_list',
	'server_get',
	'app_list',
	'app_get',
	'app_credentials',
	'service_status',
	'server_settings_get',
	'monitoring_server_summary',
	'monitoring_app_summary',
	'addon_list',
	'list_available_toolsets',
	'get_write_tools',
	'copilot_insights_list',
	'varnish_app_status',
	'cloudflare_get_details',
] );

function stripJsonc( text ) {
	let out = '';
	let inString = false;
	let escaped = false;
	let inLine = false;
	let inBlock = false;

	for ( let i = 0; i < text.length; i += 1 ) {
		const char = text[ i ];
		const next = text[ i + 1 ];
		if ( inLine ) {
			if ( char === '\n' ) {
				inLine = false;
				out += char;
			}
			continue;
		}
		if ( inBlock ) {
			if ( char === '*' && next === '/' ) {
				inBlock = false;
				i += 1;
			}
			continue;
		}
		if ( inString ) {
			out += char;
			if ( escaped ) {
				escaped = false;
			} else if ( char === '\\' ) {
				escaped = true;
			} else if ( char === '"' ) {
				inString = false;
			}
			continue;
		}
		if ( char === '"' ) {
			inString = true;
			out += char;
			continue;
		}
		if ( char === '/' && next === '/' ) {
			inLine = true;
			i += 1;
			continue;
		}
		if ( char === '/' && next === '*' ) {
			inBlock = true;
			i += 1;
			continue;
		}
		out += char;
	}
	return out.replace( /,(\s*[}\]])/g, '$1' );
}

function parseArgs( argv ) {
	const options = { server: DEFAULT_SERVER, calls: [], serverId: null, appId: null, page: null, limit: null };
	for ( let i = 0; i < argv.length; i += 1 ) {
		const arg = argv[ i ];
		if ( arg === '--server' ) {
			options.server = argv[ ++i ];
		} else if ( arg === '--call' ) {
			options.calls.push( argv[ ++i ] );
		} else if ( arg === '--server-id' ) {
			options.serverId = argv[ ++i ];
		} else if ( arg === '--app-id' ) {
			options.appId = argv[ ++i ];
		} else if ( arg === '--page' ) {
			options.page = argv[ ++i ];
		} else if ( arg === '--limit' ) {
			options.limit = argv[ ++i ];
		}
	}
	return options;
}

function loadServerConfig( name ) {
	const settings = JSON.parse( stripJsonc( fs.readFileSync( SETTINGS_PATH, 'utf8' ) ) );
	const config = ( settings.context_servers ?? {} )[ name ];
	if ( ! config ) {
		throw new Error( `MCP server "${ name }" is not configured in ${ SETTINGS_PATH }` );
	}
	const args = Array.isArray( config.args ) ? config.args : [];
	const url = args.find( ( value ) => /^https?:\/\//.test( value ) );
	if ( ! url ) {
		throw new Error( `Server "${ name }" has no HTTP URL in its args` );
	}
	const headers = {};
	for ( let i = 0; i < args.length; i += 1 ) {
		if ( args[ i ] === '--header' && typeof args[ i + 1 ] === 'string' ) {
			const [ key, ...rest ] = args[ i + 1 ].split( ':' );
			headers[ key.trim() ] = rest.join( ':' ).trim();
			i += 1;
		}
	}
	return { url, headers };
}

function parseMcpResponse( text ) {
	if ( ! text ) {
		return null;
	}
	try {
		return JSON.parse( text );
	} catch {
		// Fall through to SSE framing.
	}
	let last = null;
	for ( const line of text.split( /\r?\n/ ) ) {
		if ( ! line.startsWith( 'data:' ) ) {
			continue;
		}
		const payload = line.slice( 5 ).trim();
		if ( ! payload ) {
			continue;
		}
		try {
			last = JSON.parse( payload );
		} catch {
			// ignore non-JSON frames
		}
	}
	return last;
}

class McpHttpClient {
	constructor( { url, headers } ) {
		this.url = url;
		this.headers = { ...headers };
		this.sessionId = null;
		this.nextId = 1;
	}

	async post( payload ) {
		const headers = {
			...this.headers,
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
		};
		if ( this.sessionId ) {
			headers[ 'Mcp-Session-Id' ] = this.sessionId;
		}
		const response = await fetch( this.url, {
			method: 'POST',
			headers,
			body: JSON.stringify( payload ),
			signal: AbortSignal.timeout( 30_000 ),
		} );
		const session = response.headers.get( 'mcp-session-id' );
		if ( session ) {
			this.sessionId = session;
		}
		const text = await response.text();
		const data = parseMcpResponse( text );
		if ( ! response.ok ) {
			const message = data?.error?.message ?? text.slice( 0, 160 );
			throw new Error( `MCP HTTP ${ response.status }: ${ message }` );
		}
		return data;
	}

	async initialize() {
		const result = await this.post( {
			jsonrpc: '2.0',
			id: this.nextId++,
			method: 'initialize',
			params: {
				protocolVersion: '2025-03-26',
				capabilities: {},
				clientInfo: { name: 'nvoos-service-exchange-spike', version: '0.1.0' },
			},
		} );
		await this.post( { jsonrpc: '2.0', method: 'notifications/initialized' } );
		return result;
	}

	async listTools() {
		const result = await this.post( {
			jsonrpc: '2.0',
			id: this.nextId++,
			method: 'tools/list',
			params: {},
		} );
		return result?.result?.tools ?? [];
	}

	async callTool( name, args = {} ) {
		const result = await this.post( {
			jsonrpc: '2.0',
			id: this.nextId++,
			method: 'tools/call',
			params: { name, arguments: args },
		} );
		if ( result?.error ) {
			throw new Error( `Tool ${ name } failed: ${ result.error.message }` );
		}
		return result?.result;
	}
}

function parseToolJson( result ) {
	const content = Array.isArray( result?.content ) ? result.content : [];
	const text = content.map( ( item ) => item?.text ?? '' ).join( '\n' );
	if ( ! text ) {
		return null;
	}
	try {
		return JSON.parse( text );
	} catch {
		return null;
	}
}

function asArray( parsed ) {
	if ( Array.isArray( parsed ) ) {
		return parsed;
	}
	if ( parsed && typeof parsed === 'object' ) {
		for ( const key of [ 'servers', 'apps', 'data', 'items', 'results' ] ) {
			if ( Array.isArray( parsed[ key ] ) ) {
				return parsed[ key ];
			}
		}
	}
	return [];
}

function pick( object, keys ) {
	const out = {};
	for ( const key of keys ) {
		if ( object && Object.prototype.hasOwnProperty.call( object, key ) ) {
			out[ key ] = object[ key ];
		}
	}
	return out;
}

function shapeOf( value, depth = 0 ) {
	if ( value === null ) {
		return 'null';
	}
	if ( Array.isArray( value ) ) {
		return `array(${ value.length })`;
	}
	if ( typeof value !== 'object' ) {
		return typeof value;
	}
	if ( depth > 2 ) {
		return 'object';
	}
	const out = {};
	for ( const [ key, child ] of Object.entries( value ) ) {
		out[ key ] = shapeOf( child, depth + 1 );
	}
	return out;
}

function findSysUser( value, depth = 0 ) {
	if ( ! value || typeof value !== 'object' || depth > 3 ) {
		return null;
	}
	if ( typeof value.sys_user === 'string' && /^[a-z0-9]{3,32}$/i.test( value.sys_user ) ) {
		return value.sys_user;
	}
	for ( const child of Object.values( value ) ) {
		const found = findSysUser( child, depth + 1 );
		if ( found ) {
			return found;
		}
	}
	return null;
}

function summarise( name, result ) {
	if ( ! result ) {
		return 'no result';
	}
	if ( result.isError ) {
		const content = Array.isArray( result.content ) ? result.content : [];
		const text = content
			.map( ( item ) => item?.text ?? '' )
			.join( ' ' )
			.replace( /\s+/g, ' ' )
			.slice( 0, 300 );
		return `tool-error: ${ text }`;
	}
	const parsed = parseToolJson( result );

	switch ( name ) {
		case 'server_get': {
			const server = parsed?.server ?? parsed;
			return JSON.stringify(
				pick( server, [ 'id', 'label', 'public_ip', 'master_user', 'region', 'size', 'state', 'status', 'provider' ] )
			);
		}
		case 'server_list':
		case 'app_list': {
			const items = asArray( parsed );
			const keys =
				name === 'server_list'
					? [ 'id', 'label', 'state', 'status', 'apps', 'app_count', 'region', 'size' ]
					: [ 'id', 'label', 'app_fqdn', 'cname', 'server_id', 'application', 'status' ];
			return JSON.stringify( {
				count: items.length,
				items: items.slice( 0, 12 ).map( ( item ) => pick( item, keys ) ),
			} );
		}
		case 'app_get': {
			const app = parsed?.app ?? parsed;
			const sysUser =
				typeof app?.sys_user === 'string' && /^[a-z0-9]{3,32}$/i.test( app.sys_user )
					? app.sys_user
					: null;
			return JSON.stringify( {
				picked: pick( app, [ 'id', 'label', 'status', 'application', 'cname', 'app_fqdn', 'server_id' ] ),
				sys_user: sysUser,
				has: {
					username: Boolean( app?.username ),
					password: Boolean( app?.password ),
					project: Boolean( app?.project_name ),
				},
			} );
		}
		case 'app_credentials': {
			return JSON.stringify( {
				shape: shapeOf( parsed ),
				sys_user: findSysUser( parsed ),
			} );
		}
		default: {
			if ( Array.isArray( parsed ) ) {
				return `array(${ parsed.length })`;
			}
			if ( parsed && typeof parsed === 'object' ) {
				return `object keys=${ Object.keys( parsed ).join( ',' ) }`;
			}
			return typeof parsed;
		}
	}
}

const options = parseArgs( process.argv.slice( 2 ) );
const { url, headers } = loadServerConfig( options.server );
const client = new McpHttpClient( { url, headers } );

console.log( `server: ${ options.server }` );
console.log( `url: ${ url }` );
console.log( `auth headers: ${ Object.keys( headers ).join( ', ' ) } (values not printed)` );

const init = await client.initialize();
console.log(
	`initialize → protocol=${ init?.result?.protocolVersion ?? '?' } server=${
		init?.result?.serverInfo?.name ?? '?'
	}`
);

const tools = await client.listTools();
console.log( `tools: ${ tools.length }` );

if ( options.calls.length === 0 ) {
	for ( const tool of tools ) {
		const marker = READ_ONLY_TOOLS.has( tool.name ) ? 'ro' : 'rw';
		console.log( `  [${ marker }] ${ tool.name } — ${ ( tool.description ?? '' ).slice( 0, 90 ) }` );
	}
}

for ( const name of options.calls ) {
	if ( ! READ_ONLY_TOOLS.has( name ) ) {
		console.log( `refused: ${ name } is not on the read-only allowlist` );
		continue;
	}
	const tool = tools.find( ( entry ) => entry.name === name );
	if ( tool?.inputSchema ) {
		console.log(
			`schema ${ name }: required=${ JSON.stringify( tool.inputSchema.required ?? [] ) } properties=${ JSON.stringify(
				Object.keys( tool.inputSchema.properties ?? {} )
			) }`
		);
	}

	const args = {};
	if ( options.serverId ) {
		args.server_id = String( options.serverId );
	}
	if ( options.appId ) {
		args.app_id = String( options.appId );
	}
	if ( options.page ) {
		args.page = Number( options.page );
	}
	if ( options.limit ) {
		args.limit = Number( options.limit );
	}
	try {
		const result = await client.callTool( name, args );
		console.log( `call ${ name } → ${ summarise( name, result ) }` );
	} catch ( error ) {
		console.log( `call ${ name } → ERROR: ${ error.message }` );
	}
}
