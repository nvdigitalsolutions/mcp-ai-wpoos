#!/usr/bin/env node
// ---------------------------------------------------------------------------
// forge-mcp.mjs — the nvoos-astro-forge MCP HTTP server (096 Phase 4 team
// mode). Serves the dual-era Streamable HTTP endpoint at /mcp.
//
//   npx nvoos-forge-mcp                 # http://127.0.0.1:8472/mcp
//   FORGE_MCP_PORT=9000 npx nvoos-forge-mcp
//
// Env: NVOOS_FORGE_MCP_TOKEN (write-tool bearer; unset = writes fail
// closed), NVOOS_FORGE_MCP_HOSTS/ORIGINS, NVOOS_FORGE_MCP_RATE_MAX /
// NVOOS_FORGE_MCP_RATE_WINDOW_MS, NVOOS_FORGE_MODE=router|full,
// NVOOS_FORGE_HOME / NVOOS_FORGE_WORKDIR / NVOOS_FORGE_OPEN_ANALYZE.
// ---------------------------------------------------------------------------

import { createServer } from 'node:http';

import { createForgeMcpNodeHandler } from '../mcp/bff.ts';

const port = Number.parseInt( process.env.FORGE_MCP_PORT ?? '8472', 10 );
const handler = createForgeMcpNodeHandler();

const server = createServer( ( req, res ) => {
	void handler( req, res );
} );

server.listen( port, '127.0.0.1', () => {
	console.log( `[nvoos-astro-forge] MCP endpoint listening at http://127.0.0.1:${ port }/mcp` );
} );
