// ---------------------------------------------------------------------------
// mcp-build.mjs — bundle the MCP HTTP mount (mcp/bff.ts) into
// dist/mcp-entry.mjs so scripts/serve.mjs (plain Node) can route /mcp
// without a TS loader.
//
// Shared by both call sites:
//   - scripts/build-mcp.mjs (npm run build:mcp) — dev/manual builds.
//   - scripts/lib/mcp-build-integration.mjs — Astro `astro:build:done`
//     hook, which rebuilds the bundle AFTER `astro build` rewrites dist/.
//     (astro build wipes dist/, so the old pre-build position in the npm
//     chain was erased from every production build — /mcp 404ed on the
//     mirror. Incident 2026-10-30.)
//
// Uses esbuild's JavaScript API instead of spawning `node` against
// node_modules/esbuild/bin/esbuild: esbuild's install fallback can replace
// that file with the native platform binary (an ELF executable on Linux),
// which node cannot parse — the API resolves the platform binary itself.
// ---------------------------------------------------------------------------

import { build } from 'esbuild';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
// scripts/lib/ → app root is two levels up (the tokens-build gotcha: one
// `..` silently resolves relative to scripts/).
const ROOT = join(HERE, '..', '..');
const OUT_FILE = join(ROOT, 'dist', 'mcp-entry.mjs');

/**
 * Bundle mcp/bff.ts → dist/mcp-entry.mjs.
 *
 * @throws {Error} When esbuild fails — callers fail the build rather than
 * ship a tree whose /mcp route silently falls through to the 404 handler.
 */
export async function buildMcpEntry() {
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
		const message = error instanceof Error ? error.message : String(error);
		throw new Error(`[build-mcp] esbuild failed: ${message}`);
	}

	console.log('[build-mcp] wrote dist/mcp-entry.mjs');
}
