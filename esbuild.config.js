/**
 * esbuild configuration for WP MCP AI JavaScript files
 * 
 * This provides:
 * - Fast bundling (10-100x faster than webpack)
 * - Minification
 * - Source maps for debugging
 * - ES6+ to ES2015 transpilation
 * - Tree shaking for smaller bundles
 */

const esbuild = require('esbuild');
const path = require('path');
const fs = require('fs');

// Common build options
const commonOptions = {
	bundle: false, // Set to true when we modularize the code
	minify: true,
	sourcemap: true,
	target: ['es2015'], // Compatible with WordPress requirements
	format: 'iife', // Immediately Invoked Function Expression for browser
	logLevel: 'info',
};

// Options for files that need bundling (e.g., chat.js with external libraries)
const bundledOptions = {
	bundle: true,
	minify: true,
	sourcemap: true,
	target: ['es2015'],
	format: 'iife',
	logLevel: 'info',
};

// Build configurations for each file
const builds = [
	{
		entryPoints: ['assets/js/admin-settings.js'],
		outfile: 'assets/js/admin-settings.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/chat.js'],
		outfile: 'assets/js/chat.min.js',
		...bundledOptions, // Use bundled options to include marked, DOMPurify, and ky
	},
	{
		entryPoints: ['assets/js/settings-dashboard.js'],
		outfile: 'assets/js/settings-dashboard.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/user-chats.js'],
		outfile: 'assets/js/user-chats.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/auth0-setup.js'],
		outfile: 'assets/js/auth0-setup.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/mcp-diagnostic.js'],
		outfile: 'assets/js/mcp-diagnostic.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/performance-blocks.js'],
		outfile: 'assets/js/performance-blocks.min.js',
		...commonOptions,
	},
];

// Build all files
async function buildAll() {
	console.log('🚀 Building JavaScript files with esbuild...\n');
	
	const startTime = Date.now();
	const results = [];
	
	for (const config of builds) {
		try {
			const result = await esbuild.build(config);
			const inputFile = config.entryPoints[0];
			const outputFile = config.outfile;
			
			// Get file sizes
			const inputSize = fs.statSync(inputFile).size;
			const outputSize = fs.statSync(outputFile).size;
			const reduction = ((1 - outputSize / inputSize) * 100).toFixed(1);
			
			results.push({
				input: path.basename(inputFile),
				output: path.basename(outputFile),
				inputSize: (inputSize / 1024).toFixed(1) + ' KB',
				outputSize: (outputSize / 1024).toFixed(1) + ' KB',
				reduction: reduction + '%',
			});
			
			console.log(`✅ ${path.basename(inputFile)} → ${path.basename(outputFile)}`);
		} catch (error) {
			console.error(`❌ Error building ${config.entryPoints[0]}:`, error);
			process.exit(1);
		}
	}
	
	const endTime = Date.now();
	const duration = ((endTime - startTime) / 1000).toFixed(2);
	
	console.log('\n📊 Build Summary:');
	console.log('┌─────────────────────────────┬────────────┬─────────────┬────────────┐');
	console.log('│ File                        │ Original   │ Minified    │ Reduction  │');
	console.log('├─────────────────────────────┼────────────┼─────────────┼────────────┤');
	
	results.forEach(r => {
		const file = r.input.padEnd(27);
		const original = r.inputSize.padStart(10);
		const minified = r.outputSize.padStart(11);
		const reduction = r.reduction.padStart(10);
		console.log(`│ ${file} │ ${original} │ ${minified} │ ${reduction} │`);
	});
	
	console.log('└─────────────────────────────┴────────────┴─────────────┴────────────┘');
	console.log(`\n⚡ Build completed in ${duration}s`);
}

// Run the build
buildAll().catch((error) => {
	console.error('Build failed:', error);
	process.exit(1);
});
