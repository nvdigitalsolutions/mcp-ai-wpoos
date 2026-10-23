#!/usr/bin/env node
/**
 * Sync the built Schedule Anything SPA into this Velocity app's dist/.
 *
 * The SPA is built inside the addon (addons/schedule-anything-spa, Vite out
 * dir assets/dist). This app ships the built bundle only — no sources, no
 * dependencies — so Velocity needs no build step. Run this whenever the SPA
 * changes:
 *
 *   npm run sync:dist          # copy the existing addon build
 *   npm run sync:dist:build    # rebuild the addon first, then copy
 *
 * The copy is clean (target wiped first) so removed/hash-rotated chunks never
 * linger. dist/ is git-tracked in this app folder, so the result lands in the
 * deploy tree for Velocity.
 */

import { cp, rm, mkdir, readFile, readdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const buildFirst = process.argv.includes('--build');

const scriptsDir = fileURLToPath(new URL('.', import.meta.url));
const appDir = join(scriptsDir, '..');
const addonDir = join(scriptsDir, '..', '..', '..', 'addons', 'schedule-anything-spa');
const sourceDir = join(addonDir, 'assets', 'dist');
const targetDir = join(appDir, 'dist');

if (buildFirst) {
  console.log(`[sync-dist] building ${addonDir} …`);
  const result = spawnSync('npm', ['run', 'build'], { cwd: addonDir, stdio: 'inherit', shell: process.platform === 'win32' });
  if (result.status !== 0) {
    console.error('[sync-dist] addon build failed — dist not synced.');
    process.exit(1);
  }
}

await rm(targetDir, { recursive: true, force: true });
await mkdir(targetDir, { recursive: true });
await cp(sourceDir, targetDir, { recursive: true, force: true });

// Asset-closure gate: every asset referenced by index.html and every CSS
// chunk referenced by the JS bundles must exist in the copied dist. A
// missing hashed CSS (e.g. the PR #7125 snapshot that shipped HTML for
// /assets/index-*.css and broke the app with a browser MIME-refusal) must
// fail the sync instead of being committed.
const assetsDir = join(targetDir, 'assets');
const referenced = [
  ...(await readFile(join(targetDir, 'index.html'), 'utf8')).matchAll(
    /(?:src|href)="\/assets\/([^"]+)"/g,
  ),
].map((m) => m[1]);
const chunks = (await readdir(assetsDir)).filter((name) => name.endsWith('.js'));
for (const chunk of chunks) {
  const source = await readFile(join(assetsDir, chunk), 'utf8');
  // Vite emits lazy CSS deps inside __vite__mapDeps arrays as quoted
  // "assets/<name>" entries — match only that shape so unrelated
  // ".css" string literals (e.g. error messages) are ignored.
  for (const match of source.matchAll(/"assets\/([A-Za-z0-9_.-]+\.css)"/g)) {
    referenced.push(match[1]);
  }
}
const missing = [...new Set(referenced)].filter(
  (name) => !existsSync(join(assetsDir, name)),
);
if (missing.length > 0) {
  console.error(`[sync-dist] bundle references missing assets: ${missing.join(', ')}`);
  process.exit(1);
}

console.log(`[sync-dist] copied ${sourceDir} → ${targetDir}`);
console.log(`[sync-dist] asset closure verified (${new Set(referenced).size} referenced assets present).`);
