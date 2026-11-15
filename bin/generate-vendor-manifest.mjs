#!/usr/bin/env node
/**
 * Generate addons/pro/assets/vendor/manifest.json — a small SBOM for the
 * vendored Pro dependency tree.
 *
 * Why: the tree is committed to git and shipped in the plugin zips, but had no
 * inventory. That is how a truncated copy (ics without pipeline.js) sat
 * undetected. The manifest records version, license and runtime for every
 * vendored package so `bin/check-vendor-integrity.mjs` can enforce it.
 *
 * Runtime classification:
 * - sidecar  : served by the Media Worker (source of truth is the PHP
 *              WORKER_ROUTED_PACKAGES constant).
 * - local    : executed on this host; its entry point must exist.
 * - unwired  : vendored but nothing calls it; listed so it stays visible.
 *
 * Usage: node bin/generate-vendor-manifest.mjs [--check]
 *   --check  compare with the committed manifest and exit 1 on drift.
 *
 * @package WP_MCP_AI
 */

import fs from 'node:fs';
import path from 'node:path';
import {
	MANIFEST_PATH,
	VENDOR_DIR,
	WORKER_ROUTES_SOURCE,
	enumeratePackages,
	normaliseLicense,
	readDeclaredEntries,
	readPackageJson,
	readWorkerRoutes,
	resolveEntry,
} from './lib/vendor-inventory.mjs';

/**
 * Packages that are vendored but have no local execution path.
 *
 * Kept explicit rather than inferred: removing a vendored copy is a product
 * decision, so the manifest records the current state instead of guessing.
 */
const RUNTIME_OVERRIDES = {
	mailparser: 'unwired',
};

const root = process.cwd();
const vendorDir = path.join( root, VENDOR_DIR );
const routes = readWorkerRoutes( path.join( root, WORKER_ROUTES_SOURCE ) );
const declared = readDeclaredEntries( path.join( root, WORKER_ROUTES_SOURCE ) );
const packages = enumeratePackages( vendorDir );

const manifest = {
	generated_by: 'bin/generate-vendor-manifest.mjs',
	source_of_truth: {
		worker_routes: `${ WORKER_ROUTES_SOURCE }#WORKER_ROUTED_PACKAGES`,
		vendoring_script: 'addons/pro/scripts/copy-dependencies.js',
	},
	note: 'Versions and licenses are read from each vendored package.json. Runtime is classifed from WORKER_ROUTED_PACKAGES.',
	packages: {},
};

const warnings = [];

for ( const [ name, dir ] of [ ...packages.entries() ].sort( ( a, b ) => a[ 0 ].localeCompare( b[ 0 ] ) ) ) {
	const pkg = readPackageJson( dir );

	let runtime = 'unwired';
	if ( RUNTIME_OVERRIDES[ name ] ) {
		runtime = RUNTIME_OVERRIDES[ name ];
	} else if ( routes.has( name ) ) {
		runtime = 'sidecar';
	} else if ( declared.has( name ) ) {
		runtime = 'local';
	}

	// PHP resolves local packages by an explicit subpath, and that map is the
	// source of truth; package.json `main` does not describe this trimmed tree.
	let entry = null;
	let entrySource = null;
	if ( declared.has( name ) ) {
		entry = declared.get( name );
		entrySource = 'php';
	} else {
		const resolved = resolveEntry( dir, pkg );
		if ( resolved ) {
			entry = resolved;
			entrySource = 'package.json';
		}
	}

	const record = {
		version: pkg?.version || null,
		license: normaliseLicense( pkg ) || null,
		runtime,
	};

	if ( routes.has( name ) ) {
		record.worker_route = routes.get( name );
	}

	if ( entry ) {
		record.entry = entry;
		record.entry_source = entrySource;
	}

	if ( ! pkg ) {
		warnings.push( `${ name }: no package.json at the vendored root (provenance not recorded).` );
		record.note = 'No package.json at the vendored root; version and license unknown.';
	}

	if ( 'local' === runtime && ! entry ) {
		record.note = 'No entry point declared by PHP or package.json.';
	}

	if ( 'unwired' === runtime ) {
		record.note = record.note || 'Vendored but not referenced by PHP or a worker route.';
	}

	manifest.packages[ name ] = record;
}

const serialised = `${ JSON.stringify( manifest, null, 2 ) }\n`;
const manifestFile = path.join( root, MANIFEST_PATH );

if ( process.argv.includes( '--check' ) ) {
	const current = fs.existsSync( manifestFile ) ? fs.readFileSync( manifestFile, 'utf8' ) : '';
	if ( current !== serialised ) {
		console.error( 'Vendor manifest is out of date. Run: node bin/generate-vendor-manifest.mjs' );
		process.exit( 1 );
	}
	console.log( `Vendor manifest is current (${ Object.keys( manifest.packages ).length } packages).` );
	process.exit( 0 );
}

fs.writeFileSync( manifestFile, serialised );
console.log( `Wrote ${ MANIFEST_PATH } (${ Object.keys( manifest.packages ).length } packages).` );

const byRuntime = Object.values( manifest.packages ).reduce( ( acc, p ) => {
	acc[ p.runtime ] = ( acc[ p.runtime ] || 0 ) + 1;
	return acc;
}, {} );
console.log( `Runtime split: ${ JSON.stringify( byRuntime ) }` );

for ( const warning of warnings ) {
	console.warn( `WARN ${ warning }` );
}
