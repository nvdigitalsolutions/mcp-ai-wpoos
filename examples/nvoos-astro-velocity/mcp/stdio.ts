#!/usr/bin/env node
// ---------------------------------------------------------------------------
// stdio.ts — nvoos-astro-mcp local entry (Proposal 095 §3.3): the coding-
// agent profile (Zed / Claude Code / npx). Same tool registry as the BFF
// Streamable HTTP mount.
//
//   npx nvoos-astro-mcp          (or)   node mcp/stdio.ts
// ---------------------------------------------------------------------------

import { StdioServerTransport } from '@modelcontextprotocol/server/stdio';
import { buildServer } from './server';

const transport = new StdioServerTransport();
await buildServer().connect(transport);

process.stderr.write('[nvoos-astro-mcp] stdio transport connected\n');
