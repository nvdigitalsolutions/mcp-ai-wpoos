/**
 * esbuild configuration for bundling Pro addon Node.js scripts
 * 
 * This bundles the document generation scripts (PDF, Word, Excel) with their
 * npm dependencies (pdfkit, docx, exceljs) into standalone scripts that can
 * run without node_modules.
 * 
 * Similar pattern to how @neplex/vectorizer is copied to assets/js/vendor/.
 * 
 * @package WP_MCP_AI
 * @since 1.1.0
 */

const esbuild = require('esbuild');
const path = require('path');
const fs = require('fs');

// Configuration for Node.js script bundling
const nodeScriptOptions = {
	bundle: true,
	platform: 'node',
	target: 'node14', // WordPress typically runs on Node 14+
	format: 'cjs', // CommonJS format for Node.js
	external: ['fs', 'path', 'pdfkit', 'cheerio', 'docx', 'exceljs'], // Don't bundle Node.js built-ins and vendor packages
	minify: false, // Keep readable for debugging
	sourcemap: true,
	logLevel: 'info',
};

// Browser bundle options for orchestration (IIFE for WordPress)
const browserBundleOptions = {
	bundle: true,
	platform: 'browser',
	target: ['es2015'], // Compatible with WordPress requirements
	format: 'iife', // Immediately Invoked Function Expression
	minify: true,
	sourcemap: true,
	logLevel: 'info',
	// Resolve packages from Pro addon's node_modules (for cheerio, turndown, p-queue)
	nodePaths: [path.join(__dirname, 'addons', 'pro', 'node_modules')],
};

// Scripts to bundle
const builds = [
	{
		entryPoints: ['addons/pro/scripts/generate-pdf.js'],
		outfile: 'addons/pro/bin/generate-pdf.bundle.js',
		...nodeScriptOptions,
	},
	{
		entryPoints: ['addons/pro/scripts/generate-word.js'],
		outfile: 'addons/pro/bin/generate-word.bundle.js',
		...nodeScriptOptions,
	},
	{
		entryPoints: ['addons/pro/scripts/generate-excel.js'],
		outfile: 'addons/pro/bin/generate-excel.bundle.js',
		...nodeScriptOptions,
	},
	// Orchestration and research bundles are pre-built and committed
	// Uncomment to rebuild (requires node_modules with p-queue, cheerio, turndown)
	/*
	{
		entryPoints: ['addons/pro/assets/js/orchestration-bundle.js'],
		outfile: 'addons/pro/assets/js/orchestration-bundle.min.js',
		...browserBundleOptions,
		globalName: 'WpMcpAiOrchestrationBundle',
	},
	{
		entryPoints: ['addons/pro/assets/js/research-bundle.js'],
		outfile: 'addons/pro/assets/js/research-bundle.min.js',
		...browserBundleOptions,
		globalName: 'WpMcpAiResearchBundle',
	},
	*/
];

/**
 * Copy directory recursively
 */
function copyDir(src, dest) {
	if (!fs.existsSync(dest)) {
		fs.mkdirSync(dest, { recursive: true });
	}
	const entries = fs.readdirSync(src, { withFileTypes: true });
	for (const entry of entries) {
		const srcPath = path.join(src, entry.name);
		const destPath = path.join(dest, entry.name);
		if (entry.isDirectory()) {
			copyDir(srcPath, destPath);
		} else {
			fs.copyFileSync(srcPath, destPath);
		}
	}
}

// Build all scripts
async function buildAll() {
	console.log('🚀 Bundling Pro addon Node.js scripts...\n');

	const startTime = Date.now();

	// Ensure output directory exists
	const outputDir = path.join(__dirname, 'addons', 'pro', 'bin');
	if (!fs.existsSync(outputDir)) {
		fs.mkdirSync(outputDir, { recursive: true });
	}

	for (const config of builds) {
		try {
			await esbuild.build(config);
			const inputFile = config.entryPoints[0];
			const outputFile = config.outfile;

			const inputSize = fs.statSync(inputFile).size;
			const outputSize = fs.statSync(outputFile).size;

			console.log(`✅ ${path.basename(inputFile)} → ${path.basename(outputFile)}`);
			console.log(`   ${(inputSize / 1024).toFixed(1)} KB → ${(outputSize / 1024).toFixed(1)} KB\n`);
		} catch (error) {
			console.error(`❌ Error building ${config.entryPoints[0]}:`, error);
			process.exit(1);
		}
	}

	// Copy PDFKit data files (fonts, color profiles)
	console.log('📄 Copying PDFKit data files...');
	const pdfkitDataSrc = path.join(__dirname, 'addons', 'pro', 'node_modules', 'pdfkit', 'js', 'data');
	const pdfkitDataDest = path.join(outputDir, 'data');
	if (fs.existsSync(pdfkitDataSrc)) {
		copyDir(pdfkitDataSrc, pdfkitDataDest);
		console.log(`✅ Copied PDFKit data files to ${path.relative(__dirname, pdfkitDataDest)}\n`);
	} else {
		console.warn('⚠️  PDFKit data directory not found, PDF generation may not work\n');
	}

	const endTime = Date.now();
	const duration = ((endTime - startTime) / 1000).toFixed(2);

	console.log(`\n⚡ Bundling completed in ${duration}s`);
}

// Run the build
buildAll().catch((error) => {
	console.error('Build failed:', error);
	process.exit(1);
});
