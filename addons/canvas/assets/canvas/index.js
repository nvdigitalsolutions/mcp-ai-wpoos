'use strict';
/**
 * NV oOS Canvas Addon — canvas module entry point.
 *
 * This file makes the `assets/canvas/` directory loadable as a Node.js module.
 * It provides the same API surface as the `canvas` npm package (v2.x) so that
 * code written against `require('canvas')` works without modification when this
 * addon passes `NVOOS_CANVAS_PATH` to the OCR service process.
 *
 * The native binding (`build/Release/canvas.node`) is platform-specific and is
 * NOT included in the git repository. It is added by the CI build pipeline when
 * producing a platform-specific NV oOS Canvas Addon ZIP.
 */

const path = require( 'path' );
const fs   = require( 'fs' );

const binaryPath = path.join( __dirname, 'build', 'Release', 'canvas.node' );

if ( ! fs.existsSync( binaryPath ) ) {
	throw new Error(
		'NV oOS Canvas Addon: native binary not found at ' + binaryPath + '. ' +
		'Please install the platform-specific build (linux-x64 or linux-arm64) ' +
		'from https://nvdigitalsolutions.com/wpoos#canvas-addon'
	);
}

// Load platform-specific native binding.
const bindings = require( './lib/bindings' );
const Canvas   = require( './lib/canvas' );

/**
 * Create a new Canvas element.
 *
 * @param {number} width  Width in pixels.
 * @param {number} height Height in pixels.
 * @returns {Canvas}
 */
exports.createCanvas = function ( width, height ) {
	return new Canvas( width, height );
};

// Re-export the full binding surface so drop-in replacement is complete.
exports.Canvas              = Canvas;
exports.Image               = bindings.Image;
exports.ImageData           = bindings.ImageData;
exports.CanvasGradient      = bindings.CanvasGradient;
exports.CanvasPattern       = bindings.CanvasPattern;
exports.createImageData     = bindings.createImageData;
exports.registerFont        = bindings.registerFont;
exports.deregisterAllFonts  = bindings.deregisterAllFonts;
exports.parseFont           = require( './lib/parse-font' );
exports.backends            = bindings.backends;
