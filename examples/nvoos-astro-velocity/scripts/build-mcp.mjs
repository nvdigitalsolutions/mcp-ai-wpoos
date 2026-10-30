// ---------------------------------------------------------------------------
// build-mcp.mjs — CLI wrapper (npm run build:mcp) around
// scripts/lib/mcp-build.mjs for dev/manual builds.
//
// Production builds get the same bundle from the `astro:build:done`
// integration hook (scripts/lib/mcp-build-integration.mjs), which runs
// after `astro build` has rewritten dist/ — the old npm-chain position ran
// BEFORE astro build and was wiped by astro's dist/ clean, so /mcp 404ed in
// every production build (incident 2026-10-30).
// ---------------------------------------------------------------------------

import { buildMcpEntry } from './lib/mcp-build.mjs';

buildMcpEntry().catch((error) => {
	console.error(error.message);
	process.exit(1);
});
