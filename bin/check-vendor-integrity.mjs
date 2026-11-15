#!/usr/bin/env node
/**
 * Verify the vendored Pro dependency tree against its manifest.
 *
 * Hard failures (exit 1):
 * - manifest missing or unparsable;
 * - a vendored package directory is not in the manifest, or vice versa;
 * - a package classified `runtime: local` has no entry point, or its entry
 *   does not exist / does not parse (the ics-without-pipeline.js defect);
 * - a `runtime: sidecar` package records no worker route.
 *
 * Advisory (exit 0 unless --strict):
 * - declared runtime dependencies that cannot be resolved from the copy;
 * - licenses outside the GPL-compatible set (WordPress.org requirement).
 *
 * Usage:
 *   node bin/check-vendor-integrity.mjs [--strict] [--verbose]
 *     [--vendor-dir <dir>] [--manifest <file>]
 *
 * @package WP_MCP_AI
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {
	GPL_COMPATIBLE_LICENSES,
	MANIFEST_PATH,
	VENDOR_DIR,
	enumeratePackages,
	findUnresolvedRelativeRequires,
	normaliseLicense,
	readPackageJson,
} from './lib/vendor-inventory.mjs';

const args = process.argv.slice( 2 );
const getFlag = ( name ) => args.includes( name );
const getOption = ( name, fallback ) => {
	const index = args.indexOf( name );
	return index !== -1 && args[ index + 1 ] ? args[ index + 1 ] : fallback;
};

const strict = getFlag( '--strict' );
const verbose = getFlag( '--verbose' );

const root = process.cwd();
const vendorDir = path.resolve( root, getOption( '--vendor-dir', VENDOR_DIR ) );
const manifestFile = path.resolve( root, getOption( '--manifest', MANIFEST_PATH ) );

const failures = [];
const warnings = [];
const fail = ( message ) => failures.push( message );
const warn = ( message ) => warnings.push( message );

/**
 * Report whether a JS file parses as CommonJS or ESM.
 *
 * @param {string} file Absolute path.
 * @return {boolean} True when either dialect parses.
 */
function parses( file ) {
	const attempts = [
		[ '--check', file ],
		[ '--experimental-default-type=module', '--check', file ],
	];

	for ( const attempt of attempts ) {
		try {
			execFileSync( process.execPath, attempt, { stdio: 'pipe' } );
			return true;
		} catch ( error ) {
			// Syntax errors report a non-zero exit; try the next dialect.
		}
	}

	return false;
}

// ---- manifest -----------------------------------------------------------------
if ( ! fs.existsSync( manifestFile ) ) {
	console.error( `FAIL manifest not found: ${ manifestFile }` );
	console.error( 'Run: node bin/generate-vendor-manifest.mjs' );
	process.exit( 1 );
}

let manifest;
try {
	manifest = JSON.parse( fs.readFileSync( manifestFile, 'utf8' ) );
} catch ( error ) {
	console.error( `FAIL manifest is not valid JSON: ${ error.message }` );
	process.exit( 1 );
}

const declared = manifest.packages || {};
const actual = enumeratePackages( vendorDir );

// ---- bijection ----------------------------------------------------------------
for ( const name of actual.keys() ) {
	if ( ! Object.prototype.hasOwnProperty.call( declared, name ) ) {
		fail( `${ name }: vendored directory is not recorded in the manifest (run the generator).` );
	}
}
for ( const name of Object.keys( declared ) ) {
	if ( ! actual.has( name ) ) {
		fail( `${ name }: manifest entry has no vendored directory (stale entry).` );
	}
}

// ---- per-package rules --------------------------------------------------------
for ( const [ name, record ] of Object.entries( declared ) ) {
	const dir = actual.get( name );
	if ( ! dir ) {
		continue; // already reported by the bijection check
	}

	const runtime = record.runtime || 'local';

	if ( 'sidecar' === runtime && ! record.worker_route ) {
		fail( `${ name }: runtime=sidecar but no worker_route recorded.` );
	}

	if ( 'local' === runtime ) {
		if ( ! record.entry ) {
			fail( `${ name }: runtime=local but no entry point is recorded (truncated copy?).` );
		} else {
			// PHP declares entries relative to the vendor root; package.json
			// entries are relative to the package directory.
			const entryFile =
				'php' === record.entry_source ? path.join( vendorDir, record.entry ) : path.join( dir, record.entry );
			if ( ! fs.existsSync( entryFile ) ) {
				fail( `${ name }: declared entry "${ record.entry }" is missing from the vendored copy.` );
			} else if ( ! parses( entryFile ) ) {
				fail( `${ name }: entry "${ record.entry }" does not parse as JavaScript.` );
			} else {
				// A truncated multi-file copy loads its entry fine but breaks on a
				// relative require (ics without pipeline.js). Resolve them statically.
				const unresolved = findUnresolvedRelativeRequires( entryFile );
				for ( const { from, spec } of unresolved.slice( 0, 10 ) ) {
					fail( `${ name }: ${ from } requires "${ spec }" which is missing from the copy.` );
				}
				if ( unresolved.length > 10 ) {
					fail( `${ name }: ${ unresolved.length - 10 } further unresolved relative require(s).` );
				}
			}
		}

		// Dependency closure is advisory: much of the tree predates this gate.
		const pkg = readPackageJson( dir );
		const deps = Object.keys( pkg?.dependencies || {} );
		const missing = deps.filter( ( dep ) => {
			const local = path.join( dir, 'node_modules', dep );
			const hoisted = path.join( vendorDir, dep );
			return ! fs.existsSync( local ) && ! fs.existsSync( hoisted );
		} );
		if ( missing.length ) {
			const preview = missing.slice( 0, 5 ).join( ', ' ) + ( missing.length > 5 ? ', …' : '' );
			warn( `${ name }: ${ missing.length } unresolved runtime dependency/ies (${ preview }).` );
		}
	}

	// ---- license -----------------------------------------------------------
	const license = record.license || normaliseLicense( readPackageJson( dir ) );
	if ( ! license ) {
		( strict ? fail : warn )(
			`${ name }: license not recorded — WordPress.org requires bundled code to be GPL-compatible.`
		);
	} else if ( ! GPL_COMPATIBLE_LICENSES.has( license ) ) {
		( strict ? fail : warn )(
			`${ name }: license "${ license }" is not in the GPL-compatible allowlist — confirm before distribution.`
		);
	}
}

// ---- report -------------------------------------------------------------------
const byRuntime = Object.values( declared ).reduce( ( acc, p ) => {
	const key = p.runtime || 'local';
	acc[ key ] = ( acc[ key ] || 0 ) + 1;
	return acc;
}, {} );

console.log(
	`Vendored packages: ${ actual.size } on disk, ${ Object.keys( declared ).length } in manifest ` +
		`(${ JSON.stringify( byRuntime ) }).`
);

if ( warnings.length ) {
	console.log( `\n${ warnings.length } advisory warning(s):` );
	for ( const message of warnings.slice( 0, verbose ? warnings.length : 15 ) ) {
		console.log( `  WARN ${ message }` );
	}
	if ( ! verbose && warnings.length > 15 ) {
		console.log( `  … ${ warnings.length - 15 } more (re-run with --verbose).` );
	}
}

if ( failures.length ) {
	console.log( `\n${ failures.length } failure(s):` );
	for ( const message of failures ) {
		console.log( `  FAIL ${ message }` );
	}
	console.log( '\nVendor integrity check FAILED.' );
	process.exit( 1 );
}

console.log( '\nVendor integrity check passed.' );
