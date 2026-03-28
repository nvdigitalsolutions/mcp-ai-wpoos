#!/usr/bin/env node
/**
 * NV oOS Canvas Addon — Build Script
 *
 * Copies canvas native binaries from node_modules into the addon's
 * assets/canvas/build/Release/ directory so the plugin can be packaged
 * as a platform-specific ZIP for distribution.
 *
 * Usage:
 *   # From the addons/canvas/ directory:
 *   npm install          # install canvas@2 for current platform
 *   node scripts/copy-canvas.js
 *
 * Environment variables:
 *   CANVAS_SRC_DIR  Override the source canvas directory
 *                   (defaults to ../../addons/pro/node_modules/canvas or
 *                    ./node_modules/canvas)
 *
 * The script also writes a platform.json metadata file alongside the plugin
 * root so the PHP class can report the correct platform label in the admin UI.
 *
 * Requirements:
 *   - Node.js >= 18.17.0
 *   - canvas@2 installed (npm install canvas@2) — canvas@3 requires Node >= 20.9.0
 */

'use strict';

const fs   = require( 'fs' );
const path = require( 'path' );

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------
const pluginRoot  = path.resolve( __dirname, '..' );
const vendorDest  = path.join( pluginRoot, 'assets', 'canvas' );

// Locate canvas source — prefer the addon's own node_modules, then the pro
// addon's node_modules (in development the pro addon installs canvas).
const candidateSrc = [
	process.env.CANVAS_SRC_DIR,
	path.join( pluginRoot, 'node_modules', 'canvas' ),
	path.join( pluginRoot, '..', 'pro', 'node_modules', 'canvas' ),
].filter( Boolean );

let canvasSrc = null;
for ( const candidate of candidateSrc ) {
	if ( fs.existsSync( path.join( candidate, 'package.json' ) ) ) {
		canvasSrc = candidate;
		break;
	}
}

if ( ! canvasSrc ) {
	console.error( '❌  canvas package not found. Run: npm install canvas@2' );
	console.error( '   Searched:' );
	candidateSrc.forEach( ( p ) => console.error( '     ' + p ) );
	process.exit( 1 );
}

console.log( '🔍  Canvas source: ' + canvasSrc );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function copyDir( src, dest ) {
	if ( ! fs.existsSync( src ) ) {
		return;
	}
	fs.mkdirSync( dest, { recursive: true } );
	for ( const entry of fs.readdirSync( src, { withFileTypes: true } ) ) {
		const srcPath  = path.join( src, entry.name );
		const destPath = path.join( dest, entry.name );
		if ( entry.isDirectory() ) {
			copyDir( srcPath, destPath );
		} else {
			fs.copyFileSync( srcPath, destPath );
		}
	}
}

function formatSize( bytes ) {
	if ( bytes >= 1024 * 1024 ) {
		return ( bytes / 1024 / 1024 ).toFixed( 1 ) + ' MB';
	}
	return ( bytes / 1024 ).toFixed( 0 ) + ' KB';
}

function getDirSize( dir ) {
	if ( ! fs.existsSync( dir ) ) {
		return 0;
	}
	let total = 0;
	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		const p = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			total += getDirSize( p );
		} else {
			total += fs.statSync( p ).size;
		}
	}
	return total;
}

// ---------------------------------------------------------------------------
// Read canvas version and native binary info
// ---------------------------------------------------------------------------
const canvasPkg     = JSON.parse( fs.readFileSync( path.join( canvasSrc, 'package.json' ), 'utf8' ) );
const canvasVersion = canvasPkg.version;
const buildRelease  = path.join( canvasSrc, 'build', 'Release' );

if ( ! fs.existsSync( buildRelease ) ) {
	console.error( '❌  Canvas native binaries not found at: ' + buildRelease );
	console.error( '   Canvas must be compiled for this platform.' );
	console.error( '   Run: npm install canvas@2' );
	process.exit( 1 );
}

// Find the compiled binary.
const binaryFile = path.join( buildRelease, 'canvas.node' );
if ( ! fs.existsSync( binaryFile ) ) {
	console.error( '❌  canvas.node not found in: ' + buildRelease );
	process.exit( 1 );
}

const binarySize = fs.statSync( binaryFile ).size;
console.log( '✅  Found canvas.node (' + formatSize( binarySize ) + ')' );

// ---------------------------------------------------------------------------
// Copy native binaries into the addon
// ---------------------------------------------------------------------------
const destBuildRelease = path.join( vendorDest, 'build', 'Release' );
fs.mkdirSync( destBuildRelease, { recursive: true } );

// Remove old .gitkeep placeholder if present.
const gitkeep = path.join( destBuildRelease, '.gitkeep' );
if ( fs.existsSync( gitkeep ) ) {
	fs.unlinkSync( gitkeep );
}

// Copy ALL files from build/Release (includes canvas.node and any .so libs).
copyDir( buildRelease, destBuildRelease );

const totalBinSize = getDirSize( destBuildRelease );
console.log( '📦  Copied native binaries → ' + formatSize( totalBinSize ) );

// ---------------------------------------------------------------------------
// Write platform.json metadata file (used by PHP NV_oOS_Canvas class)
// ---------------------------------------------------------------------------
const nodeMajorVersion = process.version.replace( /^v/, '' ).split( '.' )[ 0 ]; // major only
const platform    = process.platform;
const arch        = process.arch;

const platformMeta = {
	platform      : platform,
	arch          : arch,
	node_version  : nodeMajorVersion,
	canvas_version: canvasVersion,
	built_at      : new Date().toISOString(),
};

fs.writeFileSync(
	path.join( pluginRoot, 'platform.json' ),
	JSON.stringify( platformMeta, null, '\t' ) + '\n'
);

console.log( '📝  Written platform.json: ' + platform + '-' + arch + ' Node ' + nodeMajorVersion );
console.log( '🎉  Canvas addon build complete!' );
console.log( '' );
console.log( '    Canvas version : ' + canvasVersion );
console.log( '    Platform       : ' + platform + '-' + arch );
console.log( '    Binary size    : ' + formatSize( totalBinSize ) );
console.log( '' );
console.log( '    The addon directory is ready to be packaged as a ZIP.' );
