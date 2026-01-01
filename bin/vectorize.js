#!/usr/bin/env node
/**
 * SVG Vectorization Script
 * 
 * Converts raster images to SVG using @neplex/vectorizer.
 * This script is called by the PHP architectural drawing tool.
 * 
 * Usage: node vectorize.js <input-image-path> <output-svg-path> [options-json]
 * 
 * @package WP_MCP_AI_Pro
 */

const fs = require('fs');
const path = require('path');
const { vectorize } = require('@neplex/vectorizer');

/**
 * Main vectorization function.
 */
async function main() {
	const args = process.argv.slice(2);
	
	if (args.length < 2) {
		console.error('Usage: node vectorize.js <input-image-path> <output-svg-path> [options-json]');
		process.exit(1);
	}
	
	const inputPath = args[0];
	const outputPath = args[1];
	const optionsJson = args[2] || '{}';
	
	try {
		// Parse options
		const options = JSON.parse(optionsJson);
		
		// Default options optimized for architectural drawings
		const vectorizeOptions = {
			// Color mode: 'color' for full color, 'binary' for black/white
			colorMode: options.colorMode || 'color',
			
			// Color precision (1-8). Higher = more colors, larger file
			colorPrecision: options.colorPrecision || 6,
			
			// Filter speckles smaller than this many pixels
			filterSpeckle: options.filterSpeckle || 4,
			
			// Corner threshold (0-180 degrees). Lower = sharper corners
			cornerThreshold: options.cornerThreshold || 60,
			
			// Length threshold for segment simplification
			lengthThreshold: options.lengthThreshold || 4.0,
			
			// Max iterations for curve fitting
			maxIterations: options.maxIterations || 10,
			
			// Splice threshold for path simplification
			spliceThreshold: options.spliceThreshold || 45,
			
			// Path precision (number of decimal places)
			pathPrecision: options.pathPrecision || 8,
			
			// Layer mode: 'stacked' or 'cutout'
			mode: options.mode || 'stacked',
			
			...options
		};
		
		// Check if input file exists
		if (!fs.existsSync(inputPath)) {
			throw new Error(`Input file not found: ${inputPath}`);
		}
		
		// Read input image
		const imageBuffer = fs.readFileSync(inputPath);
		
		// Vectorize the image
		console.error('Starting vectorization...');
		const svgString = await vectorize(imageBuffer, vectorizeOptions);
		
		// Write output SVG
		fs.writeFileSync(outputPath, svgString);
		
		// Output success JSON to stdout
		console.log(JSON.stringify({
			success: true,
			inputPath: inputPath,
			outputPath: outputPath,
			svgSize: svgString.length,
			message: 'Vectorization completed successfully'
		}));
		
		process.exit(0);
	} catch (error) {
		// Output error JSON to stdout
		console.log(JSON.stringify({
			success: false,
			error: error.message,
			stack: error.stack
		}));
		
		process.exit(1);
	}
}

// Run the script
main().catch(error => {
	console.log(JSON.stringify({
		success: false,
		error: error.message,
		stack: error.stack
	}));
	process.exit(1);
});
