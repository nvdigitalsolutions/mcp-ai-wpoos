#!/usr/bin/env node
/**
 * Bundle LangChain.js libraries for browser use
 * 
 * This script bundles @langchain/core, @langchain/community, and langchain
 * from node_modules into browser-compatible IIFE bundles and copies them
 * to assets/js/vendor/ for distribution with the plugin.
 * 
 * LangChain packages are ES modules designed for Node.js, so they need to be
 * bundled with esbuild to work in browser environments.
 * 
 * @package WP_MCP_AI
 * @since 1.2.0
 */

const esbuild = require('esbuild');
const fs = require('fs');
const path = require('path');

// Colors for console output
const colors = {
	reset: '\x1b[0m',
	green: '\x1b[32m',
	yellow: '\x1b[33m',
	red: '\x1b[31m',
	blue: '\x1b[34m',
};

/**
 * Format file size
 */
function formatSize(bytes) {
	if (bytes < 1024) return bytes + ' B';
	if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
	return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

/**
 * Get file size
 */
function getFileSize(filePath) {
	try {
		return fs.statSync(filePath).size;
	} catch {
		return 0;
	}
}

console.log(`${colors.blue}🚀 Bundling LangChain.js libraries for browser...${colors.reset}\n`);

const startTime = Date.now();
const vendorPath = path.join(__dirname, '..', 'assets', 'js', 'vendor');

// Ensure vendor directory exists
if (!fs.existsSync(vendorPath)) {
	fs.mkdirSync(vendorPath, { recursive: true });
}

/**
 * Create entry point files for bundling
 * We create entry files that properly import from the LangChain packages
 * and export everything to window globals for browser use.
 */
const entryPoints = [
	{
		name: 'langchainCore',
		pkg: '@langchain/core',
		content: `
// Import core functionality from @langchain/core
import * as core from '@langchain/core/load';
import * as messages from '@langchain/core/messages';
import * as prompts from '@langchain/core/prompts';
import * as runnables from '@langchain/core/runnables';

// Export to window for browser global access
window.langchainCore = {
	...core,
	messages,
	prompts,
	runnables,
};
`,
		outfile: 'langchain-core.bundle.js',
	},
	{
		name: 'langchain',
		pkg: 'langchain',
		content: `
// Import from langchain package using explicit exports
import * as load from 'langchain/load';
import * as agents from 'langchain/agents';
import * as chains from 'langchain/chains';
import * as memory from 'langchain/memory';

// Export to window for browser global access
window.langchain = {
	load,
	agents,
	chains,
	memory,
};
`,
		outfile: 'langchain.bundle.js',
	},
	{
		name: 'langchainCommunity',
		pkg: '@langchain/community',
		content: `
// Import from @langchain/community using explicit exports
import * as load from '@langchain/community/load';

// Export to window for browser global access
window.langchainCommunity = {
	load,
};
`,
		outfile: 'langchain-community.bundle.js',
	},
];

/**
 * Bundle each library
 */
async function bundleLibraries() {
	const results = [];
	const tempDir = path.join(__dirname, '..', '.tmp-langchain-entries');
	
	// Create temp directory for entry points
	if (!fs.existsSync(tempDir)) {
		fs.mkdirSync(tempDir, { recursive: true });
	}
	
	try {
		for (const entry of entryPoints) {
			// Check if package exists
			const packagePath = path.join(__dirname, '..', 'node_modules', entry.pkg);
			if (!fs.existsSync(packagePath)) {
				console.log(`${colors.yellow}⚠️  ${entry.pkg} not found in node_modules${colors.reset}`);
				continue;
			}
			
			// Create temporary entry file
			const entryFile = path.join(tempDir, `${entry.name}-entry.js`);
			fs.writeFileSync(entryFile, entry.content);
			
			const outPath = path.join(vendorPath, entry.outfile);
			const outPathMin = path.join(vendorPath, entry.outfile.replace('.js', '.min.js'));
			
			try {
				// External dependencies that should not be bundled
				// These are optional peer dependencies for specific integrations
				const external = [
					'couchbase',
					'@gomomento/sdk-core',
					'@upstash/redis',
					'@vercel/kv',
					'@google-cloud/storage',
					'node:os',
					'node:path',
					'node:fs',
					'node:stream',
					'node:crypto',
					'node:http',
					'node:https',
					'node:url',
					'fs',
					'path',
					'os',
					'crypto',
					'stream',
					'http',
					'https',
					'url',
				];
				
				// Bundle (unminified with source map for debugging)
				await esbuild.build({
					entryPoints: [entryFile],
					bundle: true,
					minify: false,
					sourcemap: true,
					platform: 'browser',
					target: ['es2015'],
					format: 'iife',
					external: external,
					outfile: outPath,
					logLevel: 'warning',
				});
				
				// Bundle (minified for production)
				await esbuild.build({
					entryPoints: [entryFile],
					bundle: true,
					minify: true,
					sourcemap: true,
					platform: 'browser',
					target: ['es2015'],
					format: 'iife',
					external: external,
					outfile: outPathMin,
					logLevel: 'warning',
				});
				
				const originalSize = getFileSize(outPath);
				const minifiedSize = getFileSize(outPathMin);
				
				results.push({
					name: entry.name,
					originalSize: formatSize(originalSize),
					minifiedSize: formatSize(minifiedSize),
					reduction: originalSize > 0 ? ((1 - minifiedSize / originalSize) * 100).toFixed(1) + '%' : '0%',
				});
				
				console.log(`${colors.green}✅ ${entry.name}${colors.reset} → ${formatSize(minifiedSize)} (minified)`);
			} catch (error) {
				console.error(`${colors.red}❌ Error bundling ${entry.name}:${colors.reset}`, error.message);
			}
		}
		
		// Clean up temp directory
		fs.rmSync(tempDir, { recursive: true, force: true });
		
		const endTime = Date.now();
		const duration = ((endTime - startTime) / 1000).toFixed(2);
		
		if (results.length > 0) {
			console.log('\n📊 Bundle Summary:');
			console.log('┌──────────────────────┬─────────────┬─────────────┬────────────┐');
			console.log('│ Library              │ Original    │ Minified    │ Reduction  │');
			console.log('├──────────────────────┼─────────────┼─────────────┼────────────┤');
			
			results.forEach(r => {
				const name = r.name.padEnd(20);
				const original = r.originalSize.padStart(11);
				const minified = r.minifiedSize.padStart(11);
				const reduction = r.reduction.padStart(10);
				console.log(`│ ${name} │ ${original} │ ${minified} │ ${reduction} │`);
			});
			
			console.log('└──────────────────────┴─────────────┴─────────────┴────────────┘');
		}
		
		console.log(`\n${colors.green}⚡ Bundling completed in ${duration}s${colors.reset}`);
		console.log(`${colors.blue}📦 Output directory: ${path.relative(process.cwd(), vendorPath)}${colors.reset}`);
	} catch (error) {
		console.error(`${colors.red}❌ Bundling failed:${colors.reset}`, error);
		process.exit(1);
	}
}

// Run the bundling
bundleLibraries().catch((error) => {
	console.error(`${colors.red}❌ Fatal error:${colors.reset}`, error);
	process.exit(1);
});
