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

import { cp, rm, mkdir } from 'node:fs/promises';
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

console.log(`[sync-dist] copied ${sourceDir} → ${targetDir}`);
