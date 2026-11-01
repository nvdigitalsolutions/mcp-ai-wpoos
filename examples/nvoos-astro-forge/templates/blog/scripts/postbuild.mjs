// ---------------------------------------------------------------------------
// postbuild.mjs — materialize the host-agnostic 301 map (Proposal 096 §0).
//
// Astro ignores underscore-prefixed files in src/pages/ (the partial
// convention), so the redirect document is emitted as /redirects.json and
// this script converts it into `dist/_redirects` — the publish-root format
// understood by Netlify, Cloudflare Pages, and self-hosted static servers.
// Runs after `astro build` (wired into the template's build script).
// ---------------------------------------------------------------------------

import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const distDir = resolve(process.cwd(), 'dist');
const mapPath = resolve(distDir, 'redirects.json');
const targetPath = resolve(distDir, '_redirects');

if (!existsSync(mapPath)) {
	console.log('[forge-postbuild] no redirects.json — skipping _redirects.');
	process.exit(0);
}

try {
	const entries = JSON.parse(readFileSync(mapPath, 'utf8'));
	const lines = entries.map((entry) => `${entry.from}\t${entry.to}\t${entry.status}`).join('\n');
	writeFileSync(targetPath, `${lines}\n`, 'utf8');
	console.log(`[forge-postbuild] wrote ${entries.length} redirect(s) to dist/_redirects.`);
} catch (error) {
	console.error(`[forge-postbuild] failed: ${error instanceof Error ? error.message : error}`);
	process.exit(1);
}
