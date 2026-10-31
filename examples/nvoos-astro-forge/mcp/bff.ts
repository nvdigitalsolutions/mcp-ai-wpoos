// ---------------------------------------------------------------------------
// bff.ts — Streamable HTTP mount for nvoos-astro-forge MCP (096 Phase 4; the
// 095 dual-era pattern). Request pipeline: per-IP rate-limit gate → Host /
// Origin validation → fail-closed write-tool auth → the SDK handler. Write
// calls that pass auth are audit-logged (tool name, IP — never credentials).
// ---------------------------------------------------------------------------

import { createMcpHandler } from '@modelcontextprotocol/server';
import { hostHeaderValidation, originValidation, toNodeHandler } from '@modelcontextprotocol/node';
import type { IncomingMessage, ServerResponse } from 'node:http';

import { authorizeRequest, firstHeaderValue, isWriteToolCall } from './auth.ts';
import { mcpRequestContext } from './context.ts';
import { createRateLimiter } from './ratelimit.ts';
import { buildServer, WRITE_TOOL_NAMES } from './registry.ts';

export interface ForgeMcpEnv {
	NVOOS_FORGE_MCP_TOKEN?: string;
	NVOOS_FORGE_MCP_HOSTS?: string;
	NVOOS_FORGE_MCP_ORIGINS?: string;
	NVOOS_FORGE_MCP_RATE_MAX?: string;
	NVOOS_FORGE_MCP_RATE_WINDOW_MS?: string;
}

function allowedHosts( env: ForgeMcpEnv ): string[] {
	return ( env.NVOOS_FORGE_MCP_HOSTS ?? 'localhost,127.0.0.1,[::1]' ).split( ',' ).map( ( entry ) => entry.trim() ).filter( Boolean );
}

function allowedOrigins( env: ForgeMcpEnv ): string[] {
	return ( env.NVOOS_FORGE_MCP_ORIGINS ?? '' ).split( ',' ).map( ( entry ) => entry.trim() ).filter( Boolean );
}

async function readBody( req: IncomingMessage ): Promise<unknown> {
	if ( req.method !== 'POST' && req.method !== 'PUT' ) {
		return undefined;
	}
	const chunks: Buffer[] = [];
	for await ( const chunk of req ) {
		chunks.push( chunk as Buffer );
	}
	const raw = Buffer.concat( chunks ).toString( 'utf8' );
	if ( ! raw ) {
		return undefined;
	}
	try {
		return JSON.parse( raw );
	} catch {
		return undefined;
	}
}

const DEFAULT_RATE_MAX = 120;
const DEFAULT_RATE_WINDOW_MS = 60000;

function positiveInt( value: string | undefined, fallback: number ): number {
	const parsed = Number.parseInt( value ?? '', 10 );
	return Number.isInteger( parsed ) && parsed > 0 ? parsed : fallback;
}

function toolNameFrom( body: unknown ): string {
	const name = ( body as { params?: { name?: unknown } } | null )?.params?.name;
	return typeof name === 'string' ? name : 'unknown';
}

/**
 * Build the `(req, res) => Promise<void>` handler served at /mcp.
 */
export function createForgeMcpNodeHandler( env: ForgeMcpEnv = process.env ) {
	const effectiveEnv: ForgeMcpEnv = { ...env };
	const handler = createMcpHandler( () => buildServer(), {
		legacy: 'stateless',
		onerror: ( error ) => {
			console.error( '[forge-mcp] handler error:', error.message );
		},
	} );
	const nodeHandler = toNodeHandler( handler );
	const validateHost = hostHeaderValidation( allowedHosts( env ) );
	const validateOrigin = originValidation( allowedOrigins( env ) );
	const limiter = createRateLimiter( {
		max: positiveInt( env.NVOOS_FORGE_MCP_RATE_MAX, DEFAULT_RATE_MAX ),
		windowMs: positiveInt( env.NVOOS_FORGE_MCP_RATE_WINDOW_MS, DEFAULT_RATE_WINDOW_MS ),
	} );
	const contextEnv: Record<string, string | undefined> = { ...process.env, ...env };

	return async ( req: IncomingMessage, res: ServerResponse ): Promise<void> => {
		const ip = req.socket.remoteAddress ?? 'unknown';
		if ( ! limiter.allow( ip ) ) {
			if ( ! res.headersSent ) {
				res.writeHead( 429, { 'content-type': 'application/json', 'cache-control': 'no-store' } );
			}
			res.end( JSON.stringify( { jsonrpc: '2.0', error: { code: -32029, message: 'rate limit exceeded' }, id: null } ) );
			return;
		}
		if ( ! validateHost( req, res ) ) {
			return;
		}
		if ( ! validateOrigin( req, res ) ) {
			return;
		}
		const body = await readBody( req );
		const auth = authorizeRequest( req, body, effectiveEnv, WRITE_TOOL_NAMES );
		if ( ! auth.ok ) {
			if ( ! res.headersSent ) {
				res.writeHead( 401, { 'content-type': 'application/json', 'cache-control': 'no-store' } );
			}
			res.end( JSON.stringify( { jsonrpc: '2.0', error: { code: -32001, message: auth.message ?? 'unauthorized' }, id: null } ) );
			return;
		}
		if ( isWriteToolCall( body, WRITE_TOOL_NAMES ) ) {
			console.log( `[forge-mcp] audit tool=${ toolNameFrom( body ) } via=bearer ip=${ ip }` );
		}
		await mcpRequestContext.run( { env: contextEnv }, () => nodeHandler( req, res, body ) );
	};
}
