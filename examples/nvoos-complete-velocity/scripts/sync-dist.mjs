#!/usr/bin/env node
/**
 * Build the NV oOS Complete Console and stage it into this app's dist/.
 *
 * Unlike the Schedule Anything app (which copies a prebuilt addon bundle),
 * this app IS the Vite host — spa-v2 sources are compiled in via the alias,
 * so the build runs here in the monorepo where the tsconfig paths resolve
 * (building in the mirror would be the TS2307 failure class). dist/ is
 * git-tracked; Velocity needs no build step.
 *
 *   npm run sync:dist   # typecheck + vite build + asset-closure gate
 */

import { cp, rm, mkdir, readFile, readdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const scriptsDir = fileURLToPath(new URL('.', import.meta.url));
const appDir = join(scriptsDir, '..');
const targetDir = join(appDir, 'dist');

console.log(`[sync-dist] building ${appDir} …`);
const result = spawnSync('npm', ['run', 'build'], {
  cwd: appDir,
  stdio: 'inherit',
  shell: process.platform === 'win32',
});
if (result.status !== 0) {
  console.error('[sync-dist] build failed — dist not updated.');
  process.exit(1);
}

// Vite build already wipes outDir (emptyOutDir). Copy nothing; gate below.
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
  // ".css" string literals are ignored.
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

console.log(`[sync-dist] build staged in ${targetDir}`);
console.log(`[sync-dist] asset closure verified (${new Set(referenced).size} referenced assets present).`);
