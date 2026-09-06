/**
 * Multi-tenant site namespacing for filesystem scratch space.
 *
 * Single-tenant mode (SITE_TOKENS unset): every path falls back to
 * os.tmpdir() so existing Docker/volume deployments behave byte-for-byte
 * as before. In strict mode (STRICT_PATHS=1 / STRICT_PDF_PATHS=1) an
 * explicit TEMP_ROOT becomes the allowlisted sandbox root, so deployments
 * whose shared volumes live outside os.tmpdir() can opt into path
 * enforcement without moving mounts (proposal 028, Q5).
 *
 * Multi-tenant mode (SITE_TOKENS set): each site gets its own directory
 * under TEMP_ROOT/sites/<slug>/ so files from one site can never be read or
 * overwritten by another. Caller-supplied paths are resolved and prefix
 * checked against the site namespace (fail-closed).
 */

import fs from 'fs';
import os from 'os';
import path from 'path';

const SLUG_RE = /^[a-z0-9-]{1,32}$/;

/**
 * Scratch groups with their default TTLs (ms). Each group gets its own
 * subdirectory under the site namespace in multi-tenant mode; the env var
 * TEMP_TTL_<GROUP> overrides the default. Single-tenant mode is unaffected
 * (os.tmpdir(), OS-managed).
 */
export const TTL_GROUPS = {
	upload: 7 * 24 * 60 * 60 * 1000,   // user uploads (video/PDF inputs)
	video: 60 * 60 * 1000,             // processed video outputs
	browser: 60 * 60 * 1000,           // screenshots / PDFs
	doc: 24 * 60 * 60 * 1000,          // excel / word / data outputs
	scratch: 24 * 60 * 60 * 1000,      // everything else
};

/**
 * Whether multi-tenant mode is active (SITE_TOKENS env var set).
 *
 * @return {boolean} True in multi-tenant mode.
 */
export function isMultiTenant() {
	return Boolean( process.env.SITE_TOKENS );
}

/**
 * Whether strict path sandboxing is active. Always on in multi-tenant mode;
 * opt-in for single-tenant deployments via STRICT_PATHS or STRICT_PDF_PATHS.
 *
 * @return {boolean} True when caller-supplied paths must stay in the namespace.
 */
export function isStrictPaths() {
	return isMultiTenant() ||
		'1' === process.env.STRICT_PATHS ||
		'1' === process.env.STRICT_PDF_PATHS;
}

/**
 * Validate a site slug.
 *
 * @param {string} slug Site slug.
 * @return {boolean} True when safe for use in a path component.
 */
export function isValidSlug( slug ) {
	return 'string' === typeof slug && SLUG_RE.test( slug );
}

/**
 * Base scratch directory for a site.
 *
 * @param {string} slug Site slug.
 * @return {string} Absolute directory.
 */
export function siteBaseDir( slug ) {
	if ( ! isMultiTenant() ) {
		// Legacy single-tenant deployments use os.tmpdir(). In strict mode an
		// explicit TEMP_ROOT is the allowlisted sandbox root (proposal 028,
		// Q5) — the safe default for shared-volume paths the W6 flip was
		// waiting for.
		if ( isStrictPaths() && process.env.TEMP_ROOT ) {
			return path.resolve( process.env.TEMP_ROOT );
		}
		return os.tmpdir();
	}
	const root = process.env.TEMP_ROOT || path.join( os.tmpdir(), 'mw' );
	return path.join( root, 'sites', slug );
}

/**
 * Effective TTL for a group (env override or default).
 *
 * @param {string} group Group name (key of TTL_GROUPS).
 * @return {number} TTL in milliseconds.
 */
export function groupTtl( group ) {
	const fallback = TTL_GROUPS[ group ] || TTL_GROUPS.scratch;
	const envKey = `TEMP_TTL_${ String( group ).toUpperCase() }`;
	if ( group === 'scratch' && process.env.TEMP_TTL ) {
		const global = Number( process.env.TEMP_TTL );
		return Number.isFinite( global ) && global > 0 ? global : fallback;
	}
	const envValue = Number( process.env[ envKey ] );
	return Number.isFinite( envValue ) && envValue > 0 ? envValue : fallback;
}

/**
 * Directory for a site's scratch group, created lazily. In single-tenant
 * mode returns os.tmpdir() regardless of group (legacy behavior).
 *
 * @param {string} slug  Site slug.
 * @param {string} group Group name (key of TTL_GROUPS).
 * @return {string} Absolute directory (created).
 */
export function siteDirFor( slug, group ) {
	if ( ! isMultiTenant() ) {
		// os.tmpdir() normally; TEMP_ROOT when strict single-tenant mode is
		// active so outputs stay inside the allowlisted root (Q5).
		return siteBaseDir( slug );
	}
	const dir = path.join( siteBaseDir( slug ), TTL_GROUPS[ group ] ? group : 'scratch' );
	fs.mkdirSync( dir, { recursive: true } );
	return dir;
}

/**
 * Upload directory for a site (the 'upload' group), created lazily.
 *
 * @param {string} slug Site slug.
 * @return {string} Absolute directory (created).
 */
export function siteUploadDir( slug ) {
	return siteDirFor( slug, 'upload' );
}

/**
 * Resolve a caller-supplied path against a site's namespace and verify it
 * stays inside. Returns null when the path escapes the namespace, the slug
 * is invalid, or the input is empty.
 *
 * @param {string} slug  Site slug.
 * @param {string} input Raw path from the request.
 * @return {string|null} Resolved path inside the namespace, or null.
 */
export function resolveSitePath( slug, input ) {
	if ( ! input || typeof input !== 'string' || ! isValidSlug( slug ) ) {
		return null;
	}
	// Resolve BOTH sides so the comparison is consistent across platforms
	// (e.g. Windows adds a drive letter to rooted paths on resolve).
	const base = path.resolve( siteBaseDir( slug ) );
	const resolved = path.resolve( input );
	if ( resolved !== base && ! resolved.startsWith( base + path.sep ) ) {
		return null;
	}
	return resolved;
}

/**
 * Guard a caller-supplied output/source path. In strict mode the path must
 * resolve inside the site namespace; otherwise the raw value is returned
 * (legacy single-tenant behavior).
 *
 * @param {string} slug Site slug.
 * @param {string} raw  Raw path from the request body.
 * @return {string|null} Allowed path, or null when raw is absent.
 * @throws {Error} With status=403 when the path escapes the namespace.
 */
export function pathGuard( slug, raw ) {
	if ( ! raw ) {
		return null;
	}
	if ( isStrictPaths() ) {
		const resolved = resolveSitePath( slug, raw );
		if ( ! resolved ) {
			const err = new Error( 'path_not_allowed' );
			err.status = 403;
			throw err;
		}
		return resolved;
	}
	return raw;
}

/**
 * TTL cleanup of site scratch directories. Each group directory is pruned
 * with its own TTL. No-op in single-tenant mode (the OS owns os.tmpdir()).
 * Never throws.
 *
 * @return {Object|null} { files, bytes } pruned, or null when no-op.
 */
export function cleanupSiteTemp() {
	if ( ! isMultiTenant() ) {
		return null;
	}
	const root = process.env.TEMP_ROOT || path.join( os.tmpdir(), 'mw' );
	const sitesDir = path.join( root, 'sites' );
	const pruned = { files: 0, bytes: 0 };
	try {
		if ( ! fs.existsSync( sitesDir ) ) {
			return pruned;
		}
		const now = Date.now();
		for ( const slug of fs.readdirSync( sitesDir ) ) {
			const siteDir = path.join( sitesDir, slug );
			try {
				if ( ! fs.statSync( siteDir ).isDirectory() ) {
					continue;
				}
				for ( const [ group, ttl ] of Object.entries( TTL_GROUPS ) ) {
					const groupDir = path.join( siteDir, group );
					pruneDir( groupDir, now - groupTtl( group ), pruned );
				}
			} catch {
				// Best effort per site directory.
			}
		}
	} catch ( err ) {
		console.warn( '[Temp] cleanup error:', err.message );
		return pruned;
	}
	if ( pruned.files > 0 ) {
		console.log(
			`[Temp] pruned ${ pruned.files } file(s), ${ pruned.bytes } byte(s)`
		);
	}
	return pruned;
}

/**
 * Prune files older than the cutoff inside a directory (flat, non-recursive).
 *
 * @param {string} dir     Directory to prune.
 * @param {number} cutoff  Age threshold (epoch ms).
 * @param {Object} pruned  Accumulator { files, bytes }.
 */
function pruneDir( dir, cutoff, pruned ) {
	let entries;
	try {
		entries = fs.readdirSync( dir );
	} catch {
		return;
	}
	for ( const file of entries ) {
		const full = path.join( dir, file );
		try {
			const st = fs.statSync( full );
			if ( st.isFile() && st.mtimeMs < cutoff ) {
				fs.unlinkSync( full );
				pruned.files++;
				pruned.bytes += st.size;
			}
		} catch {
			// Best effort per file.
		}
	}
}

/**
 * Temp storage stats for /api/health/full: per-site file counts, bytes,
 * and oldest file age, plus totals. No-op in single-tenant mode.
 *
 * @return {Object|null} Stats, or null when no-op.
 */
export function tempStats() {
	if ( ! isMultiTenant() ) {
		return null;
	}
	const root = process.env.TEMP_ROOT || path.join( os.tmpdir(), 'mw' );
	const sitesDir = path.join( root, 'sites' );
	const perSite = {};
	const totals = { files: 0, bytes: 0 };
	try {
		if ( ! fs.existsSync( sitesDir ) ) {
			return { per_site: perSite, totals, oldest_ms: null };
		}
		let oldestMs = null;
		const now = Date.now();
		for ( const slug of fs.readdirSync( sitesDir ) ) {
			const siteDir = path.join( sitesDir, slug );
			let siteFiles = 0;
			let siteBytes = 0;
			try {
				if ( ! fs.statSync( siteDir ).isDirectory() ) {
					continue;
				}
				for ( const group of Object.keys( TTL_GROUPS ) ) {
					const groupDir = path.join( siteDir, group );
					let entries;
					try {
						entries = fs.readdirSync( groupDir );
					} catch {
						continue;
					}
					for ( const file of entries ) {
						try {
							const st = fs.statSync( path.join( groupDir, file ) );
							if ( ! st.isFile() ) {
								continue;
							}
							siteFiles++;
							siteBytes += st.size;
							const age = now - st.mtimeMs;
							if ( null === oldestMs || age > oldestMs ) {
								oldestMs = age;
							}
						} catch {
							// Best effort per file.
						}
					}
				}
			} catch {
				// Best effort per site directory.
			}
			perSite[ slug ] = { files: siteFiles, bytes: siteBytes };
			totals.files += siteFiles;
			totals.bytes += siteBytes;
		}
		return { per_site: perSite, totals, oldest_ms: oldestMs };
	} catch ( err ) {
		console.warn( '[Temp] stats error:', err.message );
		return { per_site: perSite, totals, oldest_ms: null };
	}
}
