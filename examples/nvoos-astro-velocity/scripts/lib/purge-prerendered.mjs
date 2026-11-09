// ---------------------------------------------------------------------------
// purge-prerendered.mjs — remove orphaned prerendered HTML from the SSR client
// output at boot.
//
// WHY: this app is fully SSR (`output: 'server'`), so a correct build emits
// ZERO `.html` files under `dist/client`. But Velocity deploys the mirror
// INCREMENTALLY — it never deletes files that disappeared from the repo. An
// orphaned prerendered page therefore survives every deploy and is served as a
// static file, SHADOWING the SSR route (`@astrojs/node` serves `dist/client`
// before falling through to the renderer).
//
// The live symptom (2026-11-09): `dist/client/products/1/aurora-3-seater-sofa/
// index.html` from the Proposal 092 prerender era (31 Oct) kept being served
// for nine days. Consequences:
//   - the PDP showed build-time fixture content instead of the live catalog;
//   - its inline scripts came from that old build, so a ClientRouter
//     navigation into it hit the document's hash-based CSP with hashes it
//     could never contain (`client="visible"` bootstraps) — a CSP error storm
//     that survived restarts AND redeploys;
//   - the page referenced a chunk (`GalleryLightbox.0OTO-7vP.js`) that only
//     that stale build knew about.
//
// Purging at boot is self-healing: no redeploy logic depends on Velocity
// cleaning up, and the invariant is trivially true for a correct build.
// ---------------------------------------------------------------------------

import { readdirSync, rmSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Recursively delete `*.html` files under `clientDir`.
 *
 * @param {string} clientDir Absolute path to the built client directory.
 * @returns {string[]} The paths removed (empty when the dir is clean/absent).
 */
export function purgePrerenderedHtml(clientDir) {
	const removed = [];

	let entries;
	try {
		entries = readdirSync(clientDir);
	} catch {
		// No client dir (dev tree, or a build that emitted none) — nothing to do.
		return removed;
	}

	for (const entry of entries) {
		const full = join(clientDir, entry);

		let stat;
		try {
			stat = statSync(full);
		} catch {
			continue; // vanished between readdir and stat
		}

		if (stat.isDirectory()) {
			removed.push(...purgePrerenderedHtml(full));
			continue;
		}

		if (!entry.endsWith('.html')) {
			continue;
		}

		try {
			rmSync(full);
			removed.push(full);
		} catch {
			// Leave it: a file we cannot remove is reported by the caller's log.
		}
	}

	return removed;
}
