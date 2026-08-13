/**
 * Multi-tenant site namespacing for filesystem scratch space.
 *
 * Single-tenant mode (SITE_TOKENS unset): every path falls back to
 * os.tmpdir() so existing Docker/volume deployments behave byte-for-byte
 * as before.
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
		return os.tmpdir();
	}
	const root = process.env.TEMP_ROOT || path.join( os.tmpdir(), 'mw' );
	return path.join( root, 'sites', slug );
}

/**
 * Upload/scratch directory for a site, created lazily.
 *
 * @param {string} slug Site slug.
 * @return {string} Absolute directory (created).
 */
export function siteUploadDir( slug ) {
	const dir = siteBaseDir( slug );
	fs.mkdirSync( dir, { recursive: true } );
	return dir;
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
 * TTL cleanup of site scratch directories. Files older than ttlMs inside
 * TEMP_ROOT/sites are removed. No-op in single-tenant mode (the OS owns
 * os.tmpdir()). Never throws.
 *
 * @param {number} ttlMs Age threshold in milliseconds.
 */
export function cleanupSiteTemp( ttlMs ) {
	if ( ! isMultiTenant() ) {
		return;
	}
	const root = process.env.TEMP_ROOT || path.join( os.tmpdir(), 'mw' );
	const sitesDir = path.join( root, 'sites' );
	let pruned = 0;
	try {
		if ( ! fs.existsSync( sitesDir ) ) {
			return;
		}
		const cutoff = Date.now() - ttlMs;
		for ( const slug of fs.readdirSync( sitesDir ) ) {
			const dir = path.join( sitesDir, slug );
			try {
				if ( ! fs.statSync( dir ).isDirectory() ) {
					continue;
				}
				for ( const file of fs.readdirSync( dir ) ) {
					const full = path.join( dir, file );
					try {
						const st = fs.statSync( full );
						if ( st.isFile() && st.mtimeMs < cutoff ) {
							fs.unlinkSync( full );
							pruned++;
						}
					} catch {
						// Best effort per file.
					}
				}
			} catch {
				// Best effort per site directory.
			}
		}
	} catch ( err ) {
		console.warn( '[Temp] cleanup error:', err.message );
		return;
	}
	if ( pruned > 0 ) {
		console.log( `[Temp] pruned ${ pruned } file(s) older than ${ ttlMs }ms` );
	}
}
