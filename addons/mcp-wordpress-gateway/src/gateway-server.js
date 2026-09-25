/**
 * Native Streamable HTTP MCP server built on mcp-wordpress internals.
 *
 * Constructs the upstream McpServer, registers all of mcp-wordpress's tools
 * onto it (filtered by the gateway tool policy at registration time), and
 * serves stateless Streamable HTTP requests through the SDK's transport.
 * Single process — no child processes, no stdio, no gateway binary.
 * (Deep imports are safe: the upstream package is pinned to an exact
 * version in package.json and the pin is CI-gated.)
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { ServerConfiguration } from 'mcp-wordpress/dist/config/ServerConfiguration.js';
import { ToolRegistry } from 'mcp-wordpress/dist/server/ToolRegistry.js';
import { isAllowed, isDenied } from './tool-policy.js';

const GATEWAY_VERSION = '0.1.0';

/**
 * Build the MCP server with upstream tools registered under the policy.
 *
 * Policy is applied at registration, so tools/list truthfully reflects it
 * and blocked tools cannot be invoked at all (the SDK rejects unknown
 * tool names for tools/call).
 *
 * @param {object} config Loaded config (see src/config.js).
 * @returns {Promise<{ server: McpServer, siteCount: number, skipped: string[] }>}
 */
export async function buildGatewayServer( config ) {
	const serverConfig = ServerConfiguration.getInstance();
	const { clients } = await serverConfig.loadClientConfigurations();

	const server = new McpServer( {
		name: 'mcp-wordpress-gateway',
		version: GATEWAY_VERSION,
	} );

	const registry = new ToolRegistry( server, clients );
	const registerTool = registry.registerTool.bind( registry );
	const skipped = [];

	// Filter at registration: every upstream tool funnels through
	// registerTool() before the cached tools/list snapshot is built.
	registry.registerTool = ( tool ) => {
		const name = tool?.name;

		if ( typeof name === 'string' && ( ! isAllowed( config.allow, name ) || isDenied( config.deny, name ) ) ) {
			skipped.push( name );
			if ( config.logLevel !== 'none' ) {
				console.log( JSON.stringify( { event: 'tool_skipped', tool: name } ) );
			}
			return;
		}

		return registerTool( tool );
	};

	registry.registerAllTools();

	return { server, siteCount: clients.size, skipped };
}

/**
 * Handle one MCP request (stateless Streamable HTTP).
 *
 * @param {object} server     Connected McpServer.
 * @param {import('express').Request}  req         Express request.
 * @param {import('express').Response} res         Express response.
 * @param {object|undefined}  parsedBody  Parsed JSON-RPC body.
 * @returns {Promise<void>}
 */
export async function handleMcpRequest( server, req, res, parsedBody ) {
	const transport = new StreamableHTTPServerTransport( {
		sessionIdGenerator: undefined, // Stateless: 2026-07-28 independent requests.
		enableJsonResponse: true, // tools/list and errors return plain JSON.
	} );

	await server.connect( transport );
	await transport.handleRequest( req, res, parsedBody ?? {} );

	res.on( 'close', () => {
		// Detach this request's transport; the shared McpServer stays live.
		transport.close().catch( () => {} );
	} );
}
