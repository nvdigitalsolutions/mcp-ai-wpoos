// ---------------------------------------------------------------------------
// handshake.test.ts — MCP Streamable HTTP conformance over a real node:http
// socket (096 Phase 4 exit criteria; the 095 pattern), covering BOTH wire
// eras the endpoint serves: modern (2026-07-28 — server/discover, the
// params._meta envelope, SEP-2243 headers, resultType stamp) and legacy
// (2025-era claim-less initialize over SSE), plus ladder rejections.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { createForgeMcpNodeHandler } from '../../mcp/bff.ts';

const PORT = 4397;
let server: Server;

beforeAll( async () => {
	const handler = createForgeMcpNodeHandler( {} );
	server = createServer( ( req, res ) => {
		void handler( req, res );
	} );
	await new Promise<void>( ( resolve ) => server.listen( PORT, '127.0.0.1', resolve ) );
} );

afterAll( async () => {
	await new Promise<void>( ( resolve ) => server.close( () => resolve() ) );
} );

interface JsonRpcResult {
	jsonrpc: string;
	id: number | null;
	result?: {
		protocolVersion?: string;
		supportedVersions?: string[];
		resultType?: string;
		tools?: Array<{ name: string; annotations?: Record<string, boolean> }>;
		content?: Array<{ type: string; text: string }>;
	};
	error?: { code: number; message: string };
}

async function rpc( body: unknown, headers: Record<string, string> = {} ): Promise<{ status: number; message: JsonRpcResult; contentType: string }> {
	const response = await fetch( `http://127.0.0.1:${ PORT }/mcp`, {
		method: 'POST',
		headers: {
			accept: 'application/json, text/event-stream',
			'content-type': 'application/json',
			...headers,
		},
		body: JSON.stringify( body ),
	} );
	const contentType = response.headers.get( 'content-type' ) ?? '';
	if ( contentType.includes( 'text/event-stream' ) ) {
		const raw = await response.text();
		const data = raw
			.split( '\n' )
			.filter( ( line ) => line.startsWith( 'data: ' ) )
			.map( ( line ) => line.slice( 6 ) )
			.join( '' );
		return { status: response.status, message: JSON.parse( data ) as JsonRpcResult, contentType };
	}
	return { status: response.status, message: ( await response.json() ) as JsonRpcResult, contentType };
}

describe( 'forge MCP — dual-era handshake', () => {
	it( 'serves server/discover with the modern revision list', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 1,
				method: 'server/discover',
				params: {
					_meta: {
						'io.modelcontextprotocol/protocolVersion': '2026-07-28',
						'io.modelcontextprotocol/clientCapabilities': {},
					},
				},
			},
			{ 'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': 'server/discover' }
		);
		expect( status ).toBe( 200 );
		expect( message.result?.supportedVersions ).toContain( '2026-07-28' );
		expect( message.result?.resultType ).toBe( 'complete' );
	} );

	it( 'serves the 2025-era claim-less initialize over SSE (stateless fallback)', async () => {
		const { status, message, contentType } = await rpc( {
			jsonrpc: '2.0',
			id: 2,
			method: 'initialize',
			params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'probe', version: '1.0' } },
		} );
		expect( status ).toBe( 200 );
		expect( contentType ).toContain( 'text/event-stream' );
		expect( message.result?.protocolVersion ).toBeTruthy();
	} );

	it( 'rejects a legacy initialize sent with a modern protocol header (ladder boundary)', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 3,
				method: 'initialize',
				params: { protocolVersion: '2025-06-18', capabilities: {} },
			},
			{ 'MCP-Protocol-Version': '2026-07-28' }
		);
		expect( status ).toBe( 400 );
		expect( message.error?.code ).toBe( -32020 );
	} );

	it( 'lists tools over the modern wire with SEP-2243 headers', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 4,
				method: 'tools/list',
				params: {
					_meta: {
						'io.modelcontextprotocol/protocolVersion': '2026-07-28',
						'io.modelcontextprotocol/clientCapabilities': {},
					},
				},
			},
			{ 'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': 'tools/list' }
		);
		expect( status ).toBe( 200 );
		const names = ( message.result?.tools ?? [] ).map( ( tool ) => tool.name );
		expect( names ).toContain( 'forge_help' );
		expect( names ).toContain( 'setup_wizard' );
		expect( names ).toContain( 'site_analyze' );
		// Every listed tool declares explicit annotations.
		for ( const tool of message.result?.tools ?? [] ) {
			expect( tool.annotations, `tool ${ tool.name } lacks annotations` ).toBeDefined();
		}
	} );

	it( 'calls a read tool over the modern wire with all three headers', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 5,
				method: 'tools/call',
				params: {
					name: 'forge_status',
					arguments: {},
					_meta: {
						'io.modelcontextprotocol/protocolVersion': '2026-07-28',
						'io.modelcontextprotocol/clientCapabilities': {},
					},
				},
			},
			{ 'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': 'tools/call', 'Mcp-Name': 'forge_status' }
		);
		expect( status ).toBe( 200 );
		expect( message.result?.content?.[ 0 ]?.text ).toContain( 'Forge' );
	} );

	it( 'rejects a modern tools/call missing the Mcp-Name header (ladder rejection)', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 6,
				method: 'tools/call',
				params: {
					name: 'forge_status',
					arguments: {},
					_meta: {
						'io.modelcontextprotocol/protocolVersion': '2026-07-28',
						'io.modelcontextprotocol/clientCapabilities': {},
					},
				},
			},
			{ 'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': 'tools/call' }
		);
		expect( status ).toBe( 400 );
	} );
} );
