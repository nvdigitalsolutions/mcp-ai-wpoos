import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createGatewayClient, parseMcpPayload } from './gateway.js';

function config( mode = 'live' ) {
	return {
		mode,
		gateway: {
			baseUrl: 'https://gateway.test',
			adminKey: '',
			timeoutMs: 5_000,
		},
	};
}

function jsonResponse( status, body, headers = {} ) {
	return {
		ok: status >= 200 && status < 300,
		status,
		headers: new Headers( headers ),
		text: async () => ( typeof body === 'string' ? body : JSON.stringify( body ) ),
	};
}

test( 'parses JSON and SSE MCP payloads', () => {
	assert.deepEqual( parseMcpPayload( { result: 1 } ), { result: 1 } );
	const sse = 'event: message\ndata: {"result":{"tools":[]}}\n\n';
	assert.deepEqual( parseMcpPayload( sse ), { result: { tools: [] } } );
	assert.equal( parseMcpPayload( 'garbage' ), null );
} );

test( 'verification ladder passes end-to-end', async () => {
	const fetchImpl = async ( url, options ) => {
		if ( url.endsWith( '/health' ) ) {
			return jsonResponse( 200, { status: 'ok', version: '0.3.1' } );
		}
		if ( url.endsWith( '/health/full' ) ) {
			return jsonResponse( 200, { sites: { 'clinic-ops': 'ok' } } );
		}
		if ( url.endsWith( '/mcp' ) ) {
			assert.equal( options.headers.Authorization, 'Bearer key-123' );
			const body = JSON.parse( options.body );
			if ( body.method === 'tools/list' ) {
				return jsonResponse( 200, {
					jsonrpc: '2.0',
					id: 1,
					result: { tools: [ { name: 'clinic-ops.get_environment_status' } ] },
				} );
			}
			if ( body.method === 'tools/call' ) {
				assert.equal( body.params.name, 'clinic-ops.get_environment_status' );
				return jsonResponse( 200, { jsonrpc: '2.0', id: 2, result: { content: [] } } );
			}
		}
		throw new Error( `Unexpected URL ${ url }` );
	};

	const client = createGatewayClient( { config: config(), fetchImpl } );
	const result = await client.verify( {
		slug: 'clinic-ops',
		apiKey: 'key-123',
		tool: 'get_environment_status',
	} );
	assert.equal( result.ok, true );
	assert.deepEqual( result.namespacedTools, [ 'clinic-ops.get_environment_status' ] );
	assert.equal( result.steps.toolsCall.ok, true );
} );

test( 'fails when the site reports an upstream error in _meta', async () => {
	const fetchImpl = async ( url ) => {
		if ( url.endsWith( '/health' ) ) {
			return jsonResponse( 200, { version: '0.3.1' } );
		}
		if ( url.endsWith( '/health/full' ) ) {
			return jsonResponse( 200, { sites: { 'clinic-ops': 'degraded' } } );
		}
		return jsonResponse( 200, {
			result: { tools: [ { name: 'clinic-ops.tool' } ] },
			_meta: { gateway: { errors: { 'clinic-ops': 'upstream error (401)' } } },
		} );
	};

	const client = createGatewayClient( { config: config(), fetchImpl } );
	const result = await client.verify( { slug: 'clinic-ops', apiKey: 'k' } );
	assert.equal( result.ok, false );
	assert.equal( result.steps.toolsList.ok, false );
	assert.equal( result.steps.healthFull.ok, false );
} );

test( 'mock mode passes with a deterministic tool list', async () => {
	const client = createGatewayClient( { config: config( 'mock' ) } );
	const result = await client.verify( { slug: 'clinic-ops', apiKey: 'k' } );
	assert.equal( result.ok, true );
	assert.ok( result.namespacedTools[ 0 ].startsWith( 'clinic-ops.' ) );
} );
