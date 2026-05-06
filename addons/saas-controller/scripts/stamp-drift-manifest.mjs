#!/usr/bin/env node
/**
 * Stamp worker/drift-manifest.json with the build-time fingerprint of
 * worker/dist/index.js.
 *
 * Phase 5d wired the runtime fallback (Apply records the Cloudflare-returned
 * etag + body sha256 into the `nvoos_saas_controller_deployed_worker` option),
 * but the manifest itself shipped with `expected_sha256` / `expected_etag` =
 * null. This script — invoked from `npm run build:worker` — populates the
 * sha256 + version + built_at fields so a fresh release candidate has a
 * pinned fingerprint before any Apply has run.
 *
 * `expected_etag` is intentionally left null: Cloudflare's etag is only
 * known after a successful PUT /workers/scripts/{name} round-trip, so the
 * Apply engine remains the source of truth for that field.
 *
 * Usage:
 *   node scripts/stamp-drift-manifest.mjs                  # default paths
 *   node scripts/stamp-drift-manifest.mjs --check          # verify only
 *
 * Exits non-zero if the dist file is missing (so CI fails loudly rather
 * than shipping an unstamped manifest).
 */

import { createHash } from 'node:crypto';
import { readFile, writeFile, stat } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname( fileURLToPath( import.meta.url ) );
const ADDON_ROOT = resolve( __dirname, '..' );
const MANIFEST_PATH = resolve( ADDON_ROOT, 'worker/drift-manifest.json' );
const PACKAGE_PATH = resolve( ADDON_ROOT, 'package.json' );
const DIST_PATH_DEFAULT = 'worker/dist/index.js';

const args = new Set( process.argv.slice( 2 ) );
const checkOnly = args.has( '--check' );

async function main() {
	const pkgRaw = await readFile( PACKAGE_PATH, 'utf8' );
	const pkg = JSON.parse( pkgRaw );
	const version =
		typeof pkg.version === 'string' && pkg.version.length > 0
			? pkg.version
			: null;

	const manifestRaw = await readFile( MANIFEST_PATH, 'utf8' );
	const manifest = JSON.parse( manifestRaw );
	const distRel =
		typeof manifest.worker_dist_path === 'string' &&
		manifest.worker_dist_path.length > 0
			? manifest.worker_dist_path
			: DIST_PATH_DEFAULT;
	const distAbs = resolve( ADDON_ROOT, distRel );

	let distStat;
	try {
		distStat = await stat( distAbs );
	} catch ( err ) {
		console.error(
			`[stamp-drift-manifest] Worker dist not found at ${ distRel } — run \`npm run build:worker\` first.`
		);
		process.exit( 1 );
	}

	if ( ! distStat.isFile() ) {
		console.error(
			`[stamp-drift-manifest] Worker dist path ${ distRel } is not a regular file.`
		);
		process.exit( 1 );
	}

	const distBuf = await readFile( distAbs );
	const sha256 = createHash( 'sha256' ).update( distBuf ).digest( 'hex' );
	const builtAt = new Date().toISOString();

	if ( checkOnly ) {
		const ok =
			manifest.expected_sha256 === sha256 &&
			manifest.version === version;
		if ( ! ok ) {
			console.error(
				`[stamp-drift-manifest] Manifest is stale.\n  expected_sha256: ${ manifest.expected_sha256 } (manifest) vs ${ sha256 } (dist)\n  version:         ${ manifest.version } (manifest) vs ${ version } (package.json)`
			);
			process.exit( 1 );
		}
		console.log(
			`[stamp-drift-manifest] OK — manifest matches dist (sha256=${ sha256.slice(
				0,
				12
			) }…, version=${ version }).`
		);
		return;
	}

	// Preserve "$comment" if present so the on-disk file stays
	// human-readable for reviewers.
	const ordered = {
		...( typeof manifest.$comment === 'string'
			? { $comment: manifest.$comment }
			: {} ),
		expected_sha256: sha256,
		// expected_etag stays whatever the manifest currently holds (null
		// for fresh releases; populated only by a release-time post-deploy
		// step, never by the build).
		expected_etag:
			typeof manifest.expected_etag === 'string' &&
			manifest.expected_etag.length > 0
				? manifest.expected_etag
				: null,
		version,
		built_at: builtAt,
		worker_dist_path: distRel,
	};

	await writeFile(
		MANIFEST_PATH,
		JSON.stringify( ordered, null, 2 ) + '\n',
		'utf8'
	);

	console.log(
		`[stamp-drift-manifest] Stamped worker/drift-manifest.json — sha256=${ sha256.slice(
			0,
			12
		) }…, version=${ version }, built_at=${ builtAt }.`
	);
}

main().catch( ( err ) => {
	console.error( '[stamp-drift-manifest] Failed:', err );
	process.exit( 1 );
} );
