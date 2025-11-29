/**
 * esbuild configuration for WP MCP AI JavaScript files
 *
 * This provides:
 * - Fast bundling (10-100x faster than webpack)
 * - Minification
 * - Source maps for debugging
 * - ES6+ to ES2015 transpilation
 * - Tree shaking for smaller bundles
 *
 * Chat Bundle Optimization:
 * The chat-bundle.js entry point bundles all chat-related services into a single
 * optimized file, reducing HTTP requests from 11+ files to just 1 file.
 *
 * Bundled modules include:
 * - sse-service.js (Server-Sent Events)
 * - job-event-bus.js (event coordination)
 * - cron-status-service.js (async job status)
 * - chat-storage-service.js (localStorage)
 * - chat-clipboard-service.js (copy functionality)
 * - chat-markdown-service.js (markdown rendering)
 * - chat-ui-utilities-service.js (DOM helpers)
 * - chat-audio-service.js (TTS/transcription)
 * - chat.js (main chat application)
 */

const esbuild = require('esbuild');
const path = require('path');
const fs = require('fs');

// Common build options for unbundled files (minify only)
const commonOptions = {
	bundle: false,
	minify: true,
	sourcemap: true,
	target: ['es2015'], // Compatible with WordPress requirements
	format: 'iife', // Immediately Invoked Function Expression for browser
	logLevel: 'info',
};

// Bundled build options (for chat-bundle)
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
	// Bundled chat build (combines all chat services into single file)
	{
		entryPoints: ['assets/js/chat-bundle.js'],
		outfile: 'assets/js/chat-bundle.min.js',
		...bundledOptions,
	},
	// Keep individual chat.min.js for backward compatibility (unbundled)
	{
		entryPoints: ['assets/js/chat.js'],
		outfile: 'assets/js/chat.min.js',
		...commonOptions,
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

	// List of all files bundled together in chat-bundle.js
	const bundledFiles = [
		'assets/js/sse-service.js',
		'assets/js/job-event-bus.js',
		'assets/js/cron-status-service.js',
		'assets/js/chat-storage-service.js',
		'assets/js/chat-clipboard-service.js',
		'assets/js/chat-markdown-service.js',
		'assets/js/chat-ui-utilities-service.js',
		'assets/js/chat-audio-service.js',
		'assets/js/chat.js',
	];

	for (const config of builds) {
		try {
			await esbuild.build(config);
			const inputFile = config.entryPoints[0];
			const outputFile = config.outfile;

			// For bundled builds, calculate combined input size
			let inputSize;
			const isBundled = config.bundle === true;

			if (isBundled && inputFile.includes('chat-bundle')) {
				// Sum up all bundled file sizes
				inputSize = bundledFiles.reduce((total, file) => {
					try {
						return total + fs.statSync(file).size;
					} catch {
						return total;
					}
				}, 0);
			} else {
				inputSize = fs.statSync(inputFile).size;
			}

			const outputSize = fs.statSync(outputFile).size;
			const reduction = inputSize > 0 ? ((1 - outputSize / inputSize) * 100).toFixed(1) : '0.0';

			results.push({
				input: path.basename(inputFile),
				output: path.basename(outputFile),
				inputSize: (inputSize / 1024).toFixed(1) + ' KB',
				outputSize: (outputSize / 1024).toFixed(1) + ' KB',
				reduction: reduction + '%',
				bundled: isBundled,
			});

			if (isBundled) {
				console.log(`✅ ${path.basename(inputFile)} → ${path.basename(outputFile)} (bundled: ${bundledFiles.length} files)`);
			} else {
				console.log(`✅ ${path.basename(inputFile)} → ${path.basename(outputFile)}`);
			}
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
