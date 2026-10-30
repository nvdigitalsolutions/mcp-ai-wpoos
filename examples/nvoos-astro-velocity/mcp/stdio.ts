#!/usr/bin/env node
// ---------------------------------------------------------------------------
// stdio.ts — nvoos-astro-mcp local entry (Proposal 095 §3.3): the coding-
// agent profile (Zed / Claude Code / npx). Same tool registry as the BFF
// Streamable HTTP mount. Runs the connection inside mcpRequestContext so
// tool handlers can read the ambient process env (Proposal 095 hardening).
//
//   npx nvoos-astro-mcp          (or)   node mcp/stdio.ts
// ---------------------------------------------------------------------------

import { StdioServerTransport } from '@modelcontextprotocol/server/stdio';
import { mcpRequestContext } from './context';
import { buildServer } from './server';

const transport = new StdioServerTransport();
await mcpRequestContext.run({ env: process.env as Record<string, string | undefined> }, async () => {
	await buildServer().connect(transport);
});

process.stderr.write('[nvoos-astro-mcp] stdio transport connected\n');
