#!/usr/bin/env node
/**
 * Sync a local Funiq PWA build into this Velocity app's dist/ (dev-only).
 *
 * The PWA is a PURCHASED, Envato-licensed bundle — it never lives in the
 * public monorepo (Proposal 085 §3.2 / D2). This script copies a LOCAL
 * build out of the private `nvoos-funiq-pwa` checkout so the app can be
 * smoke-run before a sync:
 *
 *   FUNIQ_PWA_DIST=/path/to/nvoos-funiq-pwa/dist npm run sync:dist
 *
 * In CI the same copy happens inside the sync workflow (which clones the
 * private repo with a read token and injects dist/ into the mirror tree) —
 * this script is EXCLUDED from the mirror and never runs there.
 *
 * The copy is clean (target wiped first) so removed/hash-rotated chunks
 * never linger, and the asset-closure gate from the SA/console apps is
 * applied before the copy is accepted.
 */

import { cp, existsSync, mkdir, readFile, readdir, rm } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const scriptsDir = dirname(fileURLToPath(import.meta.url));
const targetDir = join(scriptsDir, '..', 'dist');

const sourceDir = process.env.FUNIQ_PWA_DIST || process.argv[2];

if (!sourceDir) {
	console.error(
		'[sync-dist] no PWA build given — pass FUNIQ_PWA_DIST (or the dist path as argv[2]) pointing at the private nvoos-funiq-pwa build output.'
	);
	process.exit(1);
}

if (!existsSync(join(sourceDir, 'index.html'))) {
	console.error(`[sync-dist] ${sourceDir} has no index.html — is it a built PWA dist?`);
	process.exit(1);
}

await rm(targetDir, { recursive: true, force: true });
await mkdir(targetDir, { recursive: true });
await cp(sourceDir, targetDir, { recursive: true, force: true });

// Asset-closure gate: every asset referenced by index.html (and every CSS
// chunk referenced by the JS bundles' lazy-dep maps) must exist in the
// copied dist. A missing hashed asset must fail the sync instead of being
// committed — the #7125 snapshot shipped index.html without its hashed CSS
// and broke the app with a browser MIME-refusal.
const assetsDir = join(targetDir, 'assets');
const referenced = [
	...(await readFile(join(targetDir, 'index.html'), 'utf8')).matchAll(
		/(?:src|href)="\/assets\/([^"]+)"/g
	),
].map((m) => m[1]);
if (existsSync(assetsDir)) {
	const chunks = (await readdir(assetsDir)).filter((name) => name.endsWith('.js'));
	for (const chunk of chunks) {
		const source = await readFile(join(assetsDir, chunk), 'utf8');
		for (const match of source.matchAll(/"assets\/([A-Za-z0-9_.-]+\.css)"/g)) {
			referenced.push(match[1]);
		}
	}
}
const missing = [...new Set(referenced)].filter(
	(name) => !existsSync(join(assetsDir, name))
);
if (missing.length > 0) {
	console.error(`[sync-dist] bundle references missing assets: ${missing.join(', ')}`);
	process.exit(1);
}

console.log(`[sync-dist] copied ${sourceDir} → ${targetDir}`);
console.log(
	`[sync-dist] asset closure verified (${new Set(referenced).size} referenced assets present).`
);
