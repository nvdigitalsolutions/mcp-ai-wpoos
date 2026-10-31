// ---------------------------------------------------------------------------
// auth.test.ts — the fail-closed write-tool gate over a real socket: no
// token = 401 -32001 on write tools, read tools pass unauthenticated, and a
// configured bearer token admits writes.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterEach, describe, expect, it } from 'vitest';

import { createForgeMcpNodeHandler } from '../../mcp/bff.ts';

let server: Server | undefined;

async function startServer( env: Record<string, string | undefined> ): Promise<void> {
	const handler = createForgeMcpNodeHandler( env );
	server = createServer( ( req, res ) => {
		void handler( req, res );
	} );
	await new Promise<void>( ( resolve ) => server?.listen( 4398, '127.0.0.1', resolve ) );
}

afterEach( async () => {
	if ( server ) {
		await new Promise<void>( ( resolve ) => server?.close( () => resolve() ) );
		server = undefined;
	}
} );

async function callTool( name: string, authorization?: string ): Promise<{ status: number; body: { error?: { code: number; message: string } } }> {
	const response = await fetch( `http://127.0.0.1:4398/mcp`, {
		method: 'POST',
		headers: {
			accept: 'application/json, text/event-stream',
			'content-type': 'application/json',
			'MCP-Protocol-Version': '2026-07-28',
			'Mcp-Method': 'tools/call',
			'Mcp-Name': name,
			...( authorization ? { authorization } : {} ),
		},
		body: JSON.stringify( {
			jsonrpc: '2.0',
			id: 1,
			method: 'tools/call',
			params: {
				name,
				arguments: {},
				_meta: {
					'io.modelcontextprotocol/protocolVersion': '2026-07-28',
					'io.modelcontextprotocol/clientCapabilities': {},
				},
			},
		} ),
	} );
	return { status: response.status, body: ( await response.json() ) as { error?: { code: number; message: string } } };
}

describe( 'forge MCP — write-tool auth gate', () => {
	it( 'denies write tools fail-closed when no token is configured', async () => {
		await startServer( {} );
		const { status, body } = await callTool( 'site_remove', 'Bearer anything' );
		expect( status ).toBe( 401 );
		expect( body.error?.code ).toBe( -32001 );
	} );

	it( 'admits read tools without credentials', async () => {
		await startServer( {} );
		const { status } = await callTool( 'forge_status' );
		expect( status ).toBe( 200 );
	} );

	it( 'admits write tools with the configured bearer token', async () => {
		await startServer( { NVOOS_FORGE_MCP_TOKEN: 'correct-horse' } );
		// site_remove will fail in-tool (unknown site) but must pass the gate.
		const { status, body } = await callTool( 'site_remove', 'Bearer correct-horse' );
		expect( status ).toBe( 200 );
		expect( body.error ).toBeUndefined();
	} );

	it( 'rejects a wrong bearer token with a constant-time compare', async () => {
		await startServer( { NVOOS_FORGE_MCP_TOKEN: 'correct-horse' } );
		const { status, body } = await callTool( 'site_remove', 'Bearer wrong-horse' );
		expect( status ).toBe( 401 );
		expect( body.error?.code ).toBe( -32001 );
	} );
} );
