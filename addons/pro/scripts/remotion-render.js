#!/usr/bin/env node
/**
 * Remotion render script — pre-bundled for plugin distribution.
 *
 * Uses @remotion/bundler (webpack) to bundle the composition, then
 * @remotion/renderer (puppeteer) to render it to video.  Both packages
 * are bundled into bin/remotion-render.bundle.js by esbuild so that end
 * users never need to run `npm install remotion …` themselves.
 *
 * Usage:
 *   node remotion-render.bundle.js '<json>'
 *
 * JSON fields:
 *   indexFile        string  — absolute path to the composition index.js
 *   nodeModulesPath  string  — absolute path to node_modules for resolution
 *   compositionId    string  — Remotion composition ID to render
 *   outputFile       string  — absolute output path (.mp4 / .webm / .gif)
 *   codec            string  — h264 | vp8 | gif
 *   fps              number  — frames per second (e.g. 30)
 *   durationInFrames number  — total frame count (e.g. 150)
 *   width            number  — video width in pixels
 *   height           number  — video height in pixels
 *
 * Exit codes:
 *   0  — success  (stdout: JSON { success: true, outputFile })
 *   1  — failure  (stdout: JSON { success: false, error: '…' })
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

'use strict';

const path = require( 'path' );
const fs   = require( 'fs' );
const os   = require( 'os' );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Write a JSON result to stdout and exit.
 *
 * @param {boolean} success
 * @param {Object}  extra
 */
function done( success, extra = {} ) {
	process.stdout.write( JSON.stringify( { success, ...extra } ) + '\n' );
	process.exit( success ? 0 : 1 );
}

/**
 * Recursively delete a directory.
 *
 * @param {string} dir
 */
function rimraf( dir ) {
	if ( ! fs.existsSync( dir ) ) return;
	fs.rmSync( dir, { recursive: true, force: true } );
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

( async () => {
	// Parse arguments.
	const rawArg = process.argv[ 2 ];
	if ( ! rawArg ) {
		done( false, { error: 'No JSON argument provided to remotion-render.bundle.js.' } );
	}

	let input;
	try {
		input = JSON.parse( rawArg );
	} catch ( e ) {
		done( false, { error: 'Invalid JSON argument: ' + e.message } );
	}

	const {
		indexFile,
		nodeModulesPath,
		compositionId,
		outputFile,
		codec        = 'h264',
		fps          = 30,
		durationInFrames = 150,
		width        = 1920,
		height       = 1080,
	} = input;

	if ( ! indexFile || ! fs.existsSync( indexFile ) ) {
		done( false, { error: 'indexFile does not exist: ' + indexFile } );
	}
	if ( ! outputFile ) {
		done( false, { error: 'outputFile is required.' } );
	}

	// Resolve node_modules for Remotion + React.
	const resolvedNodeModules = nodeModulesPath && fs.existsSync( nodeModulesPath )
		? nodeModulesPath
		: path.join( __dirname, '..', 'node_modules' );

	// Temp directory for the webpack bundle.
	const bundleDir = fs.mkdtempSync( path.join( os.tmpdir(), 'wp-remotion-' ) );

	try {
		// ------------------------------------------------------------------
		// Step 1 — bundle the composition with @remotion/bundler (webpack).
		// ------------------------------------------------------------------
		const { bundle } = require( '@remotion/bundler' );

		// Set NODE_PATH so webpack can resolve `remotion`, `react`, etc.
		const origNodePath = process.env.NODE_PATH || '';
		process.env.NODE_PATH = resolvedNodeModules
			+ path.delimiter
			+ origNodePath;

		const bundleLocation = await bundle( {
			entryPoint:  indexFile,
			outDir:      bundleDir,
			// Silence noisy webpack progress output.
			onProgress: () => {},
		} );

		process.env.NODE_PATH = origNodePath;

		// ------------------------------------------------------------------
		// Step 2 — render with @remotion/renderer (puppeteer).
		// ------------------------------------------------------------------
		const { selectComposition, renderMedia } = require( '@remotion/renderer' );

		const serveUrl = 'file://' + bundleLocation;

		const composition = await selectComposition( {
			serveUrl,
			id: compositionId,
			inputProps: {},
		} );

		// Override timing from caller so it matches what PHP requested.
		composition.durationInFrames = durationInFrames;
		composition.fps              = fps;
		composition.width            = width;
		composition.height           = height;

		await renderMedia( {
			composition,
			serveUrl,
			codec,
			outputLocation: outputFile,
		} );

		const stat = fs.statSync( outputFile );

		done( true, {
			outputFile,
			file_size: stat.size,
		} );
	} catch ( err ) {
		done( false, { error: err.message || String( err ) } );
	} finally {
		rimraf( bundleDir );
	}
} )();
