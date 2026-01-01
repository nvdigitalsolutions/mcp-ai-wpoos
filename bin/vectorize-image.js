#!/usr/bin/env node
/**
 * Image Vectorization Script
 *
 * Converts raster images (PNG, JPEG, WebP, GIF) to SVG format using @neplex/vectorizer.
 * This script is called by the WordPress plugin via subprocess execution.
 *
 * Usage:
 *   node vectorize-image.js <input-file> <output-file> [options-json]
 *
 * Arguments:
 *   input-file   - Path to input raster image
 *   output-file  - Path to output SVG file
 *   options-json - Optional JSON string with vectorization options
 *
 * Options (passed as JSON):
 *   colorMode        - 'color' or 'binary' (default: 'color')
 *   colorPrecision   - Color quantization (1-8, default: 6)
 *   filterSpeckle    - Filter out speckles of this size (default: 4)
 *   spliceThreshold  - Splice control (default: 45)
 *   cornerThreshold  - Corner detection (default: 60)
 *   hierarchical     - 'stacked' or 'cutout' (default: 'stacked')
 *   mode             - 'spline', 'polygon', or 'none' (default: 'spline')
 *   layerDifference  - Layer difference (default: 5)
 *   lengthThreshold  - Length threshold (default: 5)
 *   maxIterations    - Max iterations (default: 2)
 *   pathPrecision    - Path precision (default: 5)
 *
 * Exit codes:
 *   0 - Success
 *   1 - Invalid arguments
 *   2 - File read error
 *   3 - Vectorization error
 *   4 - File write error
 *   5 - Missing dependencies
 *
 * @package WP_MCP_AI
 */

const fs = require('fs');
const path = require('path');

/**
 * Main execution function
 */
async function main() {
	// Parse command line arguments
	const args = process.argv.slice(2);
	
	if (args.length < 2) {
		console.error(JSON.stringify({
			success: false,
			error: 'Invalid arguments. Usage: vectorize-image.js <input-file> <output-file> [options-json]',
			code: 'INVALID_ARGS'
		}));
		process.exit(1);
	}

	const inputFile = args[0];
	const outputFile = args[1];
	const optionsJson = args[2] || '{}';

	// Validate input file exists
	if (!fs.existsSync(inputFile)) {
		console.error(JSON.stringify({
			success: false,
			error: `Input file not found: ${inputFile}`,
			code: 'INPUT_NOT_FOUND'
		}));
		process.exit(2);
	}

	// Parse options
	let options = {};
	try {
		options = JSON.parse(optionsJson);
	} catch (error) {
		console.error(JSON.stringify({
			success: false,
			error: `Invalid options JSON: ${error.message}`,
			code: 'INVALID_OPTIONS'
		}));
		process.exit(1);
	}

	// Try to import @neplex/vectorizer from vendor directory
	// Falls back to node_modules if vendor is not available (development)
	let vectorize;
	let vectorizerPath;
	const vendorPath = path.join(__dirname, '..', 'assets', 'js', 'vendor', 'neplex-vectorizer', 'vectorizer');
	
	try {
		// Try vendor directory first (production)
		if (fs.existsSync(path.join(vendorPath, 'index.js'))) {
			const vectorizer = require(vendorPath);
			vectorize = vectorizer.vectorize;
			vectorizerPath = vendorPath;
		} else {
			// Fallback to node_modules (development)
			const vectorizer = require('@neplex/vectorizer');
			vectorize = vectorizer.vectorize;
			vectorizerPath = '@neplex/vectorizer';
		}
	} catch (error) {
		console.error(JSON.stringify({
			success: false,
			error: `Failed to load @neplex/vectorizer: ${error.message}`,
			code: 'MISSING_DEPENDENCY'
		}));
		process.exit(5);
	}

	// Read input file
	let imageData;
	try {
		imageData = fs.readFileSync(inputFile);
	} catch (error) {
		console.error(JSON.stringify({
			success: false,
			error: `Failed to read input file: ${error.message}`,
			code: 'READ_ERROR'
		}));
		process.exit(2);
	}

	// Import enums from vectorizer (use same path as loaded above)
	const vectorizerModule = vectorizerPath.startsWith('@neplex') 
		? require('@neplex/vectorizer')
		: require(vectorizerPath);
	const { ColorMode, Hierarchical, PathSimplifyMode } = vectorizerModule;
	
	// Prepare vectorization options with defaults
	const vectorizeOptions = {
		colorMode: options.colorMode === 'binary' ? ColorMode.Binary : ColorMode.Color,
		colorPrecision: options.colorPrecision || 6,
		filterSpeckle: options.filterSpeckle || 4,
		spliceThreshold: options.spliceThreshold || 45,
		cornerThreshold: options.cornerThreshold || 60,
		hierarchical: options.hierarchical === 'cutout' ? Hierarchical.Cutout : Hierarchical.Stacked,
		mode: options.mode === 'polygon' ? PathSimplifyMode.Polygon : (options.mode === 'none' ? PathSimplifyMode.None : PathSimplifyMode.Spline),
		layerDifference: options.layerDifference || 5,
		lengthThreshold: options.lengthThreshold || 5,
		maxIterations: options.maxIterations || 2,
		pathPrecision: options.pathPrecision || 5
	};

	// Perform vectorization
	let svgData;
	const startTime = Date.now();
	try {
		svgData = await vectorize(imageData, vectorizeOptions);
	} catch (error) {
		console.error(JSON.stringify({
			success: false,
			error: `Vectorization failed: ${error.message}`,
			code: 'VECTORIZE_ERROR'
		}));
		process.exit(3);
	}
	const duration = Date.now() - startTime;

	// Write output file
	try {
		fs.writeFileSync(outputFile, svgData);
	} catch (error) {
		console.error(JSON.stringify({
			success: false,
			error: `Failed to write output file: ${error.message}`,
			code: 'WRITE_ERROR'
		}));
		process.exit(4);
	}

	// Calculate file sizes
	const inputSize = fs.statSync(inputFile).size;
	const outputSize = fs.statSync(outputFile).size;

	// Output success result
	console.log(JSON.stringify({
		success: true,
		input_file: inputFile,
		output_file: outputFile,
		input_size: inputSize,
		output_size: outputSize,
		size_ratio: (outputSize / inputSize).toFixed(2),
		duration_ms: duration,
		options: vectorizeOptions
	}));

	process.exit(0);
}

// Run main function
main().catch((error) => {
	console.error(JSON.stringify({
		success: false,
		error: `Unexpected error: ${error.message}`,
		code: 'UNEXPECTED_ERROR',
		stack: error.stack
	}));
	process.exit(3);
});
