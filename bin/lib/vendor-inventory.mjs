/**
 * Shared inventory helpers for the vendored Pro dependency tree.
 *
 * Standards this encodes:
 * - WordPress Plugin Guidelines: every bundled library must be GPL-compatible.
 * - OSSF npm best practices: pin exact versions and record provenance.
 * - "Vendored code must be complete": a local package's declared entry point
 *   has to exist and parse, not merely have a marker file on disk.
 *
 * @package WP_MCP_AI
 */

import fs from 'node:fs';
import path from 'node:path';

/** Licenses accepted for a GPL-3.0-or-later WordPress plugin. */
export const GPL_COMPATIBLE_LICENSES = new Set( [
	'MIT',
	'MIT-0',
	'ISC',
	'BSD-2-Clause',
	'BSD-3-Clause',
	'0BSD',
	'Apache-2.0',
	'MPL-2.0',
	'CC0-1.0',
	'Unlicense',
	'WTFPL',
	'Zlib',
	'Artistic-2.0',
	'Python-2.0',
	'BlueOak-1.0.0',
	'GPL-2.0',
	'GPL-2.0-or-later',
	'GPL-3.0',
	'GPL-3.0-or-later',
	'LGPL-2.1',
	'LGPL-3.0',
] );

/** Root-relative location of the vendor tree. */
export const VENDOR_DIR = 'addons/pro/assets/vendor';

/** Root-relative location of the manifest. */
export const MANIFEST_PATH = `${ VENDOR_DIR }/manifest.json`;

/** Root-relative source of truth for sidecar-served packages. */
export const WORKER_ROUTES_SOURCE = 'includes/admin/class-wp-mcp-ai-pro-settings.php';

/**
 * Enumerate vendored package directories.
 *
 * Scoped directories (`@scope/name`) are expanded one level so the manifest is
 * keyed by package name, matching npm's own naming.
 *
 * @param {string} vendorDir Absolute vendor directory.
 * @return {Map<string, string>} Package name => absolute directory.
 */
export function enumeratePackages( vendorDir ) {
	const packages = new Map();

	if ( ! fs.existsSync( vendorDir ) ) {
		return packages;
	}

	for ( const entry of fs.readdirSync( vendorDir, { withFileTypes: true } ) ) {
		if ( ! entry.isDirectory() || 'node_modules' === entry.name ) {
			continue;
		}

		const abs = path.join( vendorDir, entry.name );

		if ( entry.name.startsWith( '@' ) ) {
			for ( const child of fs.readdirSync( abs, { withFileTypes: true } ) ) {
				if ( child.isDirectory() ) {
					packages.set( `${ entry.name }/${ child.name }`, path.join( abs, child.name ) );
				}
			}
			continue;
		}

		packages.set( entry.name, abs );
	}

	return packages;
}

/**
 * Read the vendored package.json for a directory, if present.
 *
 * @param {string} dir Absolute package directory.
 * @return {object|null} Parsed manifest or null.
 */
export function readPackageJson( dir ) {
	const file = path.join( dir, 'package.json' );
	if ( ! fs.existsSync( file ) ) {
		return null;
	}

	try {
		return JSON.parse( fs.readFileSync( file, 'utf8' ) );
	} catch {
		return null;
	}
}

/**
 * Normalise a package.json license field to a single SPDX-ish identifier.
 *
 * @param {object|null} pkg Parsed package.json.
 * @return {string} License identifier, or '' when absent.
 */
export function normaliseLicense( pkg ) {
	if ( ! pkg ) {
		return '';
	}

	if ( typeof pkg.license === 'string' ) {
		return pkg.license.trim();
	}

	if ( pkg.license && typeof pkg.license === 'object' && pkg.license.type ) {
		return String( pkg.license.type ).trim();
	}

	if ( Array.isArray( pkg.licenses ) && pkg.licenses.length ) {
		const first = pkg.licenses[ 0 ];
		if ( typeof first === 'string' ) {
			return first.trim();
		}
		if ( first && first.type ) {
			return String( first.type ).trim();
		}
	}

	return '';
}

/**
 * Resolve the first existing entry point declared by a package.json.
 *
 * @param {string}      dir Absolute package directory.
 * @param {object|null} pkg Parsed package.json.
 * @return {string|null} Path relative to the package root, or null.
 */
export function resolveEntry( dir, pkg ) {
	if ( ! pkg ) {
		return null;
	}

	const candidates = [];
	const push = ( value ) => {
		if ( typeof value === 'string' && value ) {
			candidates.push( value );
		}
	};

	push( pkg.main );
	push( pkg.module );
	push( pkg.browser );

	const exportsField = pkg.exports;
	if ( typeof exportsField === 'string' ) {
		push( exportsField );
	} else if ( exportsField && typeof exportsField === 'object' ) {
		for ( const key of [ '.', 'default', 'require', 'import', 'node' ] ) {
			const value = exportsField[ key ];
			if ( typeof value === 'string' ) {
				push( value );
			} else if ( value && typeof value === 'object' ) {
				push( value.default );
				push( value.require );
				push( value.import );
			}
		}
		for ( const value of Object.values( exportsField ) ) {
			if ( typeof value === 'string' ) {
				push( value );
			}
		}
	}

	for ( const candidate of candidates ) {
		const rel = candidate.replace( /^\.\//, '' );
		if ( fs.existsSync( path.join( dir, rel ) ) ) {
			return rel;
		}
	}

	return null;
}

/**
 * Parse WORKER_ROUTED_PACKAGES from the settings class.
 *
 * Keeps the sidecar classification in a single source of truth instead of
 * duplicating the list in the manifest tooling.
 *
 * @param {string} phpFile Absolute path to the settings file.
 * @return {Map<string, string>} Package name => worker API route.
 */
export function readWorkerRoutes( phpFile ) {
	const routes = new Map();

	if ( ! fs.existsSync( phpFile ) ) {
		return routes;
	}

	const source = fs.readFileSync( phpFile, 'utf8' );
	const block = source.match( /WORKER_ROUTED_PACKAGES\s*=\s*array\(([\s\S]*?)\);/ );
	if ( ! block ) {
		return routes;
	}

	const entry = /'([^']+)'\s*=>\s*'([^']+)'/g;
	let match;
	while ( ( match = entry.exec( block[ 1 ] ) ) !== null ) {
		routes.set( match[ 1 ], match[ 2 ] );
	}

	return routes;
}

/**
 * Parse the local runtime entry points declared by PHP ($pro_vendor_packages).
 *
 * This is the authoritative map for packages the PHP side resolves by an
 * explicit subpath (e.g. csv-parse/lib/index.js), which package.json `main`
 * does not describe in this trimmed tree.
 *
 * @param {string} phpFile Absolute path to the settings file.
 * @return {Map<string, string>} Package name => declared relative entry path.
 */
export function readDeclaredEntries( phpFile ) {
	const entries = new Map();

	if ( ! fs.existsSync( phpFile ) ) {
		return entries;
	}

	const source = fs.readFileSync( phpFile, 'utf8' );
	const block = source.match( /pro_vendor_packages\s*=\s*array\(([\s\S]*?)\);/ );
	if ( ! block ) {
		return entries;
	}

	const entry = /'([^']+)'\s*=>\s*(?:false|'([^']*)')/g;
	let match;
	while ( ( match = entry.exec( block[ 1 ] ) ) !== null ) {
		if ( match[ 2 ] ) {
			entries.set( match[ 1 ], match[ 2 ] );
		}
	}

	return entries;
}

/**
 * Resolve a relative require()/import specifier against the filesystem.
 *
 * @param {string} fromFile Absolute path of the requiring file.
 * @param {string} spec     Relative specifier.
 * @return {string|null} Absolute resolved path, or null.
 */
export function resolveRelativeSpecifier( fromFile, spec ) {
	const base = path.resolve( path.dirname( fromFile ), spec );

	for ( const ext of [ '', '.js', '.cjs', '.mjs', '.json', '.node' ] ) {
		const candidate = base + ext;
		try {
			if ( fs.statSync( candidate ).isFile() ) {
				return candidate;
			}
		} catch {
			// Not present; keep trying.
		}
	}

	try {
		if ( fs.statSync( base ).isDirectory() ) {
			const pkgFile = path.join( base, 'package.json' );
			if ( fs.existsSync( pkgFile ) ) {
				const main = JSON.parse( fs.readFileSync( pkgFile, 'utf8' ) ).main;
				if ( main ) {
					const candidate = path.join( base, main );
					if ( fs.existsSync( candidate ) ) {
						return candidate;
					}
				}
			}
			for ( const indexFile of [ 'index.js', 'index.cjs', 'index.mjs', 'index.json' ] ) {
				const candidate = path.join( base, indexFile );
				if ( fs.existsSync( candidate ) ) {
					return candidate;
				}
			}
		}
	} catch {
		// Not a directory.
	}

	return null;
}

/**
 * Collect relative require()/import specifiers that cannot be resolved.
 *
 * This is what catches a truncated multi-file copy: ics/index.js requires
 * ./pipeline, which the old single-file copy never shipped.
 *
 * @param {string} entryFile Absolute entry file.
 * @param {object} [limits]  Traversal limits.
 * @return {Array<{from: string, spec: string}>} Unresolved specifiers.
 */
export function findUnresolvedRelativeRequires( entryFile, limits = {} ) {
	const maxFiles = limits.maxFiles || 200;
	const maxDepth = limits.maxDepth ?? 3;

	const seen = new Set();
	const missing = [];
	const queue = [ [ entryFile, 0 ] ];

	while ( queue.length && seen.size < maxFiles ) {
		const [ file, depth ] = queue.shift();
		if ( seen.has( file ) ) {
			continue;
		}
		seen.add( file );

		if ( ! /\.(js|cjs|mjs)$/.test( file ) ) {
			continue;
		}

		let source;
		try {
			source = fs.readFileSync( file, 'utf8' );
		} catch {
			continue;
		}

		const specifiers = new Set();
		const pattern = /(?:require\s*\(\s*|from\s*|import\s*\(\s*)['"](\.[^'"]+)['"]/g;
		let match;
		while ( ( match = pattern.exec( source ) ) !== null ) {
			specifiers.add( match[ 1 ] );
		}

		for ( const spec of specifiers ) {
			const resolved = resolveRelativeSpecifier( file, spec );
			if ( ! resolved ) {
				missing.push( { from: path.relative( process.cwd(), file ), spec } );
			} else if ( depth < maxDepth ) {
				queue.push( [ resolved, depth + 1 ] );
			}
		}
	}

	return missing;
}
