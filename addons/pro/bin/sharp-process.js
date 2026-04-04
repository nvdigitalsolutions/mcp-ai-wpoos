#!/usr/bin/env node
/**
 * Sharp Image Processing Script
 *
 * Processes images using the Sharp library (pre-packaged in assets/vendor/sharp/).
 * This script is invoked by the WordPress plugin via Node.js subprocess.
 *
 * Usage:
 *   node sharp-process.js <json_params_file> <output_file>
 *
 * Arguments:
 *   json_params_file - Path to a JSON file containing processing parameters
 *   output_file      - Path where the processed image will be written
 *
 * Parameters (JSON file):
 *   source          - Absolute path to the source image file (required)
 *   operation       - One of: "optimize", "resize", "convert", "enhance" (default: "optimize")
 *   quality         - Output quality 1-100 (default: 80)
 *   maintain_aspect - Whether to maintain aspect ratio when resizing (default: true)
 *   width           - Target width in pixels (for resize)
 *   height          - Target height in pixels (for resize)
 *   format          - Target format: "webp", "avif", "jpeg", "png" (for convert)
 *   sharpen         - Apply sharpening filter true/false (for enhance)
 *   blur            - Blur sigma 0.3-1000 (for enhance)
 *   rotate          - Rotation angle in degrees: 0, 90, 180, 270
 *
 * Output (JSON to stdout):
 *   success          - true/false
 *   output_path      - Absolute path to the processed file
 *   original_size    - Original file size in bytes
 *   optimized_size   - Processed file size in bytes
 *   reduction_percent - Percentage size reduction (0 if output is larger)
 *   dimensions       - { width, height } of the output image
 *   format           - Output format string
 *   error            - Error message (on failure)
 *
 * Exit codes:
 *   0 - Success
 *   1 - Invalid arguments
 *   2 - File not found or unreadable
 *   3 - Image processing error
 *   5 - Missing Sharp dependency
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

'use strict';

const fs   = require( 'fs' );
const path = require( 'path' );
const os   = require( 'os' );

/**
 * Resolve the path to the bundled Sharp module.
 *
 * Sharp lives in assets/vendor/sharp relative to the add-on root
 * (two levels up from this script in addons/pro/bin/).
 */
const SHARP_VENDOR_PATH = path.join( __dirname, '..', 'assets', 'vendor', 'sharp', 'lib', 'index.js' );

/**
 * Output a JSON result and exit.
 *
 * @param {Object} result  Result object.
 * @param {number} [code]  Exit code (defaults to 0 for success, 1 for error).
 */
function exit( result, code ) {
	if ( result.success ) {
		process.stdout.write( JSON.stringify( result ) + '\n' );
		process.exit( 0 );
	} else {
		process.stderr.write( JSON.stringify( result ) + '\n' );
		process.exit( typeof code === 'number' ? code : 1 );
	}
}

/**
 * Main processing function.
 */
async function main() {
	const args = process.argv.slice( 2 );

	if ( args.length < 2 ) {
		exit( {
			success: false,
			error:   'Usage: sharp-process.js <json_params_file> <output_file>',
			code:    'INVALID_ARGS',
		}, 1 );
	}

	const paramsFile = args[ 0 ];
	const outputFile = args[ 1 ];

	// Read and parse params.
	if ( ! fs.existsSync( paramsFile ) ) {
		exit( {
			success: false,
			error:   `Params file not found: ${ paramsFile }`,
			code:    'PARAMS_NOT_FOUND',
		}, 2 );
	}

	let params;
	try {
		params = JSON.parse( fs.readFileSync( paramsFile, 'utf8' ) );
	} catch ( err ) {
		exit( {
			success: false,
			error:   `Failed to parse params JSON: ${ err.message }`,
			code:    'INVALID_JSON',
		}, 1 );
	}

	const sourceFile = params.source || '';
	if ( ! sourceFile ) {
		exit( {
			success: false,
			error:   'Missing required parameter: source',
			code:    'MISSING_SOURCE',
		}, 1 );
	}

	if ( ! fs.existsSync( sourceFile ) ) {
		exit( {
			success: false,
			error:   `Source file not found: ${ sourceFile }`,
			code:    'SOURCE_NOT_FOUND',
		}, 2 );
	}

	// Load Sharp from the bundled vendor directory.
	let sharp;
	try {
		if ( fs.existsSync( SHARP_VENDOR_PATH ) ) {
			sharp = require( SHARP_VENDOR_PATH );
		} else {
			// Development fallback: look in node_modules.
			sharp = require( 'sharp' );
		}
	} catch ( err ) {
		exit( {
			success: false,
			error:   `Failed to load Sharp library: ${ err.message }. Ensure Sharp is installed in assets/vendor/sharp/.`,
			code:    'MISSING_DEPENDENCY',
		}, 5 );
	}

	const operation      = params.operation || 'optimize';
	const quality        = Math.max( 1, Math.min( 100, parseInt( params.quality, 10 ) || 80 ) );
	const maintainAspect = params.maintain_aspect !== false;

	// Record original file size.
	const originalSize = fs.statSync( sourceFile ).size;

	// Build the Sharp pipeline.
	let pipeline = sharp( sourceFile );

	// Apply rotation if specified.
	if ( params.rotate && params.rotate !== 0 ) {
		pipeline = pipeline.rotate( parseInt( params.rotate, 10 ) || 0 );
	}

	// Operation-specific processing.
	if ( 'resize' === operation ) {
		const width  = params.width  ? parseInt( params.width, 10 )  : null;
		const height = params.height ? parseInt( params.height, 10 ) : null;

		if ( width || height ) {
			pipeline = pipeline.resize( {
				width:  width  || undefined,
				height: height || undefined,
				fit:    maintainAspect ? 'inside' : 'fill',
				withoutEnlargement: true,
			} );
		}
	} else if ( 'enhance' === operation ) {
		if ( params.sharpen ) {
			pipeline = pipeline.sharpen();
		}
		if ( params.blur && parseFloat( params.blur ) >= 0.3 ) {
			pipeline = pipeline.blur( parseFloat( params.blur ) );
		}
	}

	// Determine output format and apply quality settings.
	let outputFormat = null;

	if ( 'convert' === operation && params.format ) {
		outputFormat = params.format.toLowerCase();
	} else {
		// For optimize, preserve the source format.
		try {
			const metadata = await sharp( sourceFile ).metadata();
			outputFormat   = metadata.format || null;
		} catch ( _err ) {
			// If metadata fails, let Sharp infer from the source extension.
			outputFormat = null;
		}
	}

	// Apply format-specific quality options.
	if ( outputFormat ) {
		switch ( outputFormat ) {
			case 'jpeg':
			case 'jpg':
				pipeline = pipeline.jpeg( { quality, mozjpeg: true } );
				outputFormat = 'jpeg';
				break;
			case 'webp':
				pipeline = pipeline.webp( { quality } );
				break;
			case 'avif':
				pipeline = pipeline.avif( { quality } );
				break;
			case 'png':
				// PNG uses compression level 0-9; map quality (1-100) to compression (9-0).
				pipeline = pipeline.png( { compressionLevel: Math.round( ( 100 - quality ) / 100 * 9 ) } );
				break;
			default:
				// For unsupported formats, write with default settings.
				break;
		}
	}

	// Process and write the output file.
	let info;
	try {
		info = await pipeline.toFile( outputFile );
	} catch ( err ) {
		exit( {
			success: false,
			error:   `Image processing failed: ${ err.message }`,
			code:    'PROCESSING_ERROR',
		}, 3 );
	}

	const optimizedSize     = fs.existsSync( outputFile ) ? fs.statSync( outputFile ).size : 0;
	const reductionPercent  = originalSize > 0 && optimizedSize < originalSize
		? Math.round( ( 1 - optimizedSize / originalSize ) * 100 )
		: 0;

	exit( {
		success:           true,
		output_path:       outputFile,
		original_size:     originalSize,
		optimized_size:    optimizedSize,
		reduction_percent: reductionPercent,
		dimensions:        {
			width:  info.width  || 0,
			height: info.height || 0,
		},
		format:            info.format || outputFormat || '',
	} );
}

main().catch( ( err ) => {
	process.stderr.write( JSON.stringify( {
		success: false,
		error:   `Unexpected error: ${ err.message }`,
		code:    'UNEXPECTED_ERROR',
	} ) + '\n' );
	process.exit( 1 );
} );
