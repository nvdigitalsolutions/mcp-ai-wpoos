#!/usr/bin/env node
// ---------------------------------------------------------------------------
// sync-manage-assets.mjs — build (when stale) and copy the Astro CMS
// standalone bundle into public/manage-assets/, which the /manage page
// loads.
//
// The CMS SPA source lives in addons/nvoos-astro-bridge/src (shared with the
// backend plugin's wp-admin build) and is MIT-licensed
// (addons/nvoos-astro-bridge/src/LICENSE, owner decision Q8) — the carve-out
// that makes copying the standalone build into this MIT-licensed app legal.
// The bridge's PROPRIETARY PHP plugin side never flows through this script.
// The standalone esbuild target bundles
// @wordpress/* (GPL-2.0-or-later) + React (MIT) so the SPA runs on the
// storefront origin with no backend admin script chain; attribution lives in
// the app's NOTICE. The CI grep gate only scans src/, so the
// built bytes in public/ are exempt — and the bundle contains no brand
// strings anyway (zero-`funiq` check runs in CI).
//
// Run as part of `npm run build` (package.json) and by build-hermetic.mjs.
// ---------------------------------------------------------------------------

import { cpSync, existsSync, mkdirSync, readdirSync, statSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..');
const BRIDGE = resolve(ROOT, '..', '..', 'addons', 'nvoos-astro-bridge');
const BRIDGE_SRC = join(BRIDGE, 'src');
const BRIDGE_OUT = join(BRIDGE, 'build', 'standalone');
const DEST = join(ROOT, 'public', 'manage-assets');

/** Newest mtime under a directory (recursive). */
function newestMtime(dir, latest = 0) {
	for (const entry of readdirSync(dir)) {
		const full = join(dir, entry);
		const st = statSync(full);
		if (st.isDirectory()) {
			latest = newestMtime(full, latest);
		} else {
			latest = Math.max(latest, st.mtimeMs);
		}
	}
	return latest;
}

function bridgeBuildNeeded() {
	const js = join(BRIDGE_OUT, 'astro-admin.js');
	const css = join(BRIDGE_OUT, 'astro-admin.css');
	if (!existsSync(js) || !existsSync(css)) {
		return true;
	}
	const bundleTime = Math.max(statSync(js).mtimeMs, statSync(css).mtimeMs);
	if (existsSync(BRIDGE_SRC)) {
		return newestMtime(BRIDGE_SRC) > bundleTime;
	}
	return false;
}

if (!existsSync(join(BRIDGE, 'package.json'))) {
	console.error('[sync-manage-assets] bridge addon not found at', BRIDGE);
	process.exit(1);
}

if (bridgeBuildNeeded()) {
	console.log('[sync-manage-assets] building the Astro CMS standalone bundle...');
	const build = spawnSync('npm', ['run', 'build:standalone'], {
		cwd: BRIDGE,
		stdio: 'inherit',
		shell: process.platform === 'win32',
	});
	if (build.status !== 0) {
		console.error('[sync-manage-assets] bridge standalone build failed.');
		process.exit(build.status ?? 1);
	}
}

mkdirSync(DEST, { recursive: true });
cpSync(BRIDGE_OUT, DEST, { recursive: true });
console.log(`[sync-manage-assets] copied ${BRIDGE_OUT} -> ${DEST}`);
