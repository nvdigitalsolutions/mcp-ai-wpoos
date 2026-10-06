#!/usr/bin/env node
/**
 * Sync bin/ sources into this package — bin/ is the canonical home.
 *
 * The published package must ship byte-identical copies of the repo's
 * bin/mcp-bridge.js, bin/mcp-bridge-ssh.js, and bin/utils/env-file.js
 * (plus the repo LICENSE). This script is the ONLY editor of the copies:
 * edit the sources in bin/ instead, then run `npm run sync`.
 *
 * Usage:
 *   node sync-from-bin.js            # copy + verify
 *   node sync-from-bin.js --check    # fail (exit 1) on any drift — CI gate
 */

'use strict';

const crypto = require( 'crypto' );
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..', '..' );
const PKG = __dirname;

// Repo source → package destination. Do not reorder without updating the
// header docs; the --check gate depends on every pair being present.
const SOURCES = [
	{ src: path.join( ROOT, 'bin', 'mcp-bridge.js' ), dest: path.join( PKG, 'bin', 'mcp-bridge.js' ) },
	{ src: path.join( ROOT, 'bin', 'mcp-bridge-ssh.js' ), dest: path.join( PKG, 'bin', 'mcp-bridge-ssh.js' ) },
	{ src: path.join( ROOT, 'bin', 'utils', 'env-file.js' ), dest: path.join( PKG, 'bin', 'utils', 'env-file.js' ) },
	{ src: path.join( ROOT, 'LICENSE' ), dest: path.join( PKG, 'LICENSE' ) },
];

const CHECK_ONLY = process.argv.includes( '--check' );

/**
 * SHA-256 of a file's contents.
 *
 * @param {string} file  Absolute file path.
 * @returns {string} Hex digest.
 */
function sha256( file ) {
	return crypto.createHash( 'sha256' ).update( fs.readFileSync( file ) ).digest( 'hex' );
}

/**
 * Human-readable path relative to a base, for log lines.
 *
 * @param {string} base  Base directory.
 * @param {string} file  Target file.
 * @returns {string}
 */
function rel( base, file ) {
	return path.relative( base, file ).split( path.sep ).join( '/' );
}

let drift = 0;

for ( const pair of SOURCES ) {
	if ( ! fs.existsSync( pair.src ) ) {
		process.stderr.write( `[sync] ERROR: source missing: ${ pair.src }\n` );
		process.exit( 1 );
	}

	if ( CHECK_ONLY ) {
		if ( ! fs.existsSync( pair.dest ) || sha256( pair.src ) !== sha256( pair.dest ) ) {
			process.stderr.write(
				`[sync] DRIFT: ${ rel( PKG, pair.dest ) } differs from ${ rel( ROOT, pair.src ) } — run "npm run sync".\n`
			);
			drift += 1;
		}
		continue;
	}

	fs.mkdirSync( path.dirname( pair.dest ), { recursive: true } );
	fs.copyFileSync( pair.src, pair.dest );
	process.stderr.write( `[sync] ${ rel( ROOT, pair.src ) } → ${ rel( PKG, pair.dest ) }\n` );
}

if ( CHECK_ONLY ) {
	if ( drift > 0 ) {
		process.stderr.write( `[sync] ${ drift } file(s) drifted. Do not hand-edit the package copies.\n` );
		process.exit( 1 );
	}
	process.stderr.write( '[sync] OK — package copies are byte-identical to bin/ sources.\n' );
	process.exit( 0 );
}

process.stderr.write( '[sync] Done. Verify with: npm run sync -- --check\n' );
