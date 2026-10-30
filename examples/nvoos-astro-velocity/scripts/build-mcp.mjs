// ---------------------------------------------------------------------------
// build-mcp.mjs — bundle the MCP HTTP mount into dist/mcp-entry.mjs so
// scripts/serve.mjs (plain Node) can route /mcp without a TS loader.
// Wired into `npm run build` (before `astro build`).
//
// Uses esbuild's JavaScript API instead of spawning `node` against
// node_modules/esbuild/bin/esbuild: esbuild's install fallback can replace
// that file with the native platform binary (an ELF executable on Linux),
// which node cannot parse — the API resolves the platform binary itself.
//
// TODO(095): `astro build` empties dist/ after this runs, erasing
// dist/mcp-entry.mjs from production builds — add a post-build sync step
// (sync-dist.mjs) before shipping the /mcp env docs.
// ---------------------------------------------------------------------------

import { build } from 'esbuild';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..');
const OUT_FILE = join(ROOT, 'dist', 'mcp-entry.mjs');

mkdirSync(join(ROOT, 'dist'), { recursive: true });

try {
	await build({
		entryPoints: [join(ROOT, 'mcp', 'bff.ts')],
		bundle: true,
		platform: 'node',
		format: 'esm',
		target: 'node20',
		outfile: OUT_FILE,
		external: ['node:*'],
		logLevel: 'warning',
	});
} catch (error) {
	console.error(`[build-mcp] esbuild failed: ${error instanceof Error ? error.message : error}`);
	process.exit(1);
}
console.log('[build-mcp] wrote dist/mcp-entry.mjs');
