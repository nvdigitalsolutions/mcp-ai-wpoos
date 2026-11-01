#!/usr/bin/env node
// ---------------------------------------------------------------------------
// stdio.ts — nvoos-astro-forge MCP local entry (096 Phase 4; the 095 stdio
// pattern): the coding-agent profile (Zed / Claude Code / npx). Same tool
// registry as the Streamable HTTP mount. Write tools still require the
// bearer token — agents must set NVOOS_FORGE_MCP_TOKEN (or the env
// NVOOS_FORGE_MCP_TOKEN is unset and writes fail closed, which is the point).
// ---------------------------------------------------------------------------

import { StdioServerTransport } from '@modelcontextprotocol/server/stdio';
import { mcpRequestContext } from './context.ts';
import { buildServer } from './registry.ts';

const transport = new StdioServerTransport();
await mcpRequestContext.run( { env: process.env as Record<string, string | undefined> }, async () => {
	await buildServer().connect( transport );
} );

process.stderr.write( '[nvoos-astro-forge] stdio transport connected\n' );
