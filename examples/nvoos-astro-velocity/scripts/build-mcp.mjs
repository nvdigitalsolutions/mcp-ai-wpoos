// ---------------------------------------------------------------------------
// build-mcp.mjs — bundle the MCP HTTP mount into dist/mcp-entry.mjs so
// scripts/serve.mjs (plain Node) can route /mcp without a TS loader.
// Wired into `npm run build` (before `astro build`).
//
// TODO(095): `astro build` empties dist/ after this runs, erasing
// dist/mcp-entry.mjs from production builds — add a post-build sync step
// (sync-dist.mjs) before shipping the /mcp env docs.
// ---------------------------------------------------------------------------

import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..');

try {
	execFileSync(
		process.execPath,
		[
			join(ROOT, 'node_modules', 'esbuild', 'bin', 'esbuild'),
			join(ROOT, 'mcp', 'bff.ts'),
			'--bundle',
			'--platform=node',
			'--format=esm',
			'--target=node20',
			'--outfile=' + join(ROOT, 'dist', 'mcp-entry.mjs'),
			'--external:node:*',
			'--log-level=warning',
		],
		{ stdio: 'inherit' }
	);
} catch (error) {
	console.error('[build-mcp] esbuild failed — is it installed (npm ci)?');
	process.exit(1);
}
console.log('[build-mcp] wrote dist/mcp-entry.mjs');
