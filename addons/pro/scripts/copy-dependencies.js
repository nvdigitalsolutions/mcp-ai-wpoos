#!/usr/bin/env node
/**
 * Copy Pro addon NPM dependencies to vendor directory
 * 
 * This script copies necessary files from node_modules to addons/pro/assets/vendor/
 * so they can be distributed with the plugin (since node_modules is excluded).
 * 
 * Pattern similar to how the base plugin copies @neplex/vectorizer and chart.js
 * to assets/js/vendor/
 * 
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

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

/**
 * Copy a single file
 */
function copyFile(src, dest) {
	const destDir = path.dirname(dest);
	if (!fs.existsSync(destDir)) {
		fs.mkdirSync(destDir, { recursive: true });
	}
	fs.copyFileSync(src, dest);
}

/**
 * Get size of directory or file
 */
function getSize(filePath) {
	if (!fs.existsSync(filePath)) return 0;
	const stats = fs.statSync(filePath);
	if (stats.isDirectory()) {
		let size = 0;
		const files = fs.readdirSync(filePath);
		files.forEach(file => {
			size += getSize(path.join(filePath, file));
		});
		return size;
	}
	return stats.size;
}

function formatSize(bytes) {
	if (bytes < 1024) return bytes + ' B';
	if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
	return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

console.log(`${colors.blue}🚀 Copying Pro addon dependencies to vendor directory...${colors.reset}\n`);

const startTime = Date.now();
const proPath = __dirname.replace('/scripts', '');
const vendorPath = path.join(proPath, 'assets', 'vendor');

// Ensure vendor directory exists
if (!fs.existsSync(vendorPath)) {
	fs.mkdirSync(vendorPath, { recursive: true });
}

// ============================================================================
// CDN-LOADED PACKAGES (Skip copying - loaded from CDN with fallback support)
// ============================================================================
// These packages are loaded from CDN in production to reduce plugin size.
// Fallback copies are kept for offline/intranet installations but marked optional.
// See: includes/class-wp-mcp-ai-pro-cdn-loader.php
//
// To disable CDN loading:
// - define( 'WP_MCP_AI_PRO_DISABLE_CDN', true ) in wp-config.php
// - apply_filters( 'wp_mcp_ai_pro_use_cdn', false )
// - Enable "Disable CDN Loading" in plugin settings
// ============================================================================
const cdnPackages = [
	'chart.js',     // 420KB - Available on jsDelivr/cdnjs
	'katex',        // 3.1MB - Available on jsDelivr/cdnjs (includes fonts)
	'd3',           // 864KB - Available on jsDelivr/cdnjs
	'axios',        // 1.6MB - Available on jsDelivr/cdnjs
	'mathjs',       // 17MB - Available on jsDelivr/cdnjs (browser build)
	'prettier',     // ~500KB - Available on jsDelivr/cdnjs (standalone)
];

// Check if we should skip CDN packages (for offline builds)
const skipCdnPackages = process.env.WP_MCP_AI_BUILD_OFFLINE === 'true' || 
                         process.argv.includes('--include-cdn-packages');

if (skipCdnPackages) {
	console.log(`${colors.yellow}⚠️  Including CDN packages for offline build${colors.reset}\n`);
} else {
	// Clean up CDN packages from vendor directory (they'll be loaded from CDN)
	console.log(`${colors.blue}🧹 Cleaning CDN packages from vendor directory...${colors.reset}`);
	cdnPackages.forEach(pkgName => {
		const pkgVendorPath = path.join(vendorPath, pkgName);
		if (fs.existsSync(pkgVendorPath)) {
			const pkgSize = getSize(pkgVendorPath);
			fs.rmSync(pkgVendorPath, { recursive: true, force: true });
			console.log(`${colors.blue}🗑️  Removed ${pkgName}${colors.reset} → ${formatSize(pkgSize)} (will load from CDN)`);
		}
	});
	console.log('');
}

// Dependencies to copy with their configurations
const dependencies = [
	{
		name: '@turf/turf',
		dirs: [
			{ src: 'dist', dest: 'turf/dist' }, // Include both cjs and esm
		],
		files: [
			{ src: 'package.json', dest: 'turf/package.json' },
		],
	},
	{
		name: 'katex',
		cdnPackage: true, // Loaded from CDN (jsDelivr)
		dirs: [
			{ src: 'dist', dest: 'katex/dist' }, // Includes fonts, CSS, and JS
		],
		files: [
			{ src: 'package.json', dest: 'katex/package.json' },
		],
	},
	{
		name: 'chart.js',
		cdnPackage: true, // Loaded from CDN (jsDelivr)
		files: [
			{ src: 'dist/chart.umd.js', dest: 'chart.js/chart.umd.js' },
			{ src: 'dist/chart.umd.min.js', dest: 'chart.js/chart.umd.min.js' },
			{ src: 'package.json', dest: 'chart.js/package.json' },
		],
	},
	{
		name: 'ics',
		files: [
			{ src: 'dist/index.js', dest: 'ics/index.js' },
			{ src: 'package.json', dest: 'ics/package.json' },
		],
	},
	{
		name: 'sharp',
		dirs: [
			{ src: 'lib', dest: 'sharp/lib' }, // Main library
		],
		files: [
			{ src: 'package.json', dest: 'sharp/package.json' },
		],
	},
	{
		name: 'prettier',
		cdnPackage: true, // Loaded from CDN (jsDelivr)
		files: [
			{ src: 'standalone.js', dest: 'prettier/standalone.js' },
			{ src: 'parser-babel.js', dest: 'prettier/parser-babel.js' },
			{ src: 'parser-typescript.js', dest: 'prettier/parser-typescript.js' },
			{ src: 'parser-html.js', dest: 'prettier/parser-html.js' },
			{ src: 'parser-postcss.js', dest: 'prettier/parser-postcss.js' },
			{ src: 'parser-markdown.js', dest: 'prettier/parser-markdown.js' },
			{ src: 'parser-yaml.js', dest: 'prettier/parser-yaml.js' },
			{ src: 'package.json', dest: 'prettier/package.json' },
		],
	},
	{
		name: 'mjml',
		dirs: [
			{ src: 'lib', dest: 'mjml/lib' }, // MJML library
		],
		files: [
			{ src: 'package.json', dest: 'mjml/package.json' },
		],
	},
	{
		name: 'fluent-ffmpeg',
		files: [
			{ src: 'index.js', dest: 'fluent-ffmpeg/index.js' },
			{ src: 'lib', dest: 'fluent-ffmpeg/lib', isDir: true },
			{ src: 'package.json', dest: 'fluent-ffmpeg/package.json' },
		],
	},
	// ========================================================================
	// NEW PRO TOOLKITS PACKAGES (Phase 2+)
	// ========================================================================
	// E-commerce Toolkit
	{
		name: '@woocommerce/woocommerce-rest-api',
		files: [
			{ src: 'index.js', dest: 'woocommerce-rest-api/index.js' },
			{ src: 'index.mjs', dest: 'woocommerce-rest-api/index.mjs' },
			{ src: 'package.json', dest: 'woocommerce-rest-api/package.json' },
		],
	},
	{
		name: 'stripe',
		dirs: [
			{ src: 'cjs', dest: 'stripe/cjs' },
			{ src: 'esm', dest: 'stripe/esm' },
		],
		files: [
			{ src: 'package.json', dest: 'stripe/package.json' },
		],
	},
	{
		name: 'currency.js',
		files: [
			{ src: 'dist/currency.min.js', dest: 'currency.js/currency.min.js' },
			{ src: 'package.json', dest: 'currency.js/package.json' },
		],
	},
	// Social Media Toolkit
	{
		name: 'twitter-api-v2',
		dirs: [
			{ src: 'dist', dest: 'twitter-api-v2/dist' },
		],
		files: [
			{ src: 'package.json', dest: 'twitter-api-v2/package.json' },
		],
	},
	{
		name: 'axios',
		cdnPackage: true, // Loaded from CDN (jsDelivr)
		dirs: [
			{ src: 'dist', dest: 'axios/dist' },
		],
		files: [
			{ src: 'index.js', dest: 'axios/index.js' },
			{ src: 'package.json', dest: 'axios/package.json' },
		],
	},
	{
		name: 'facebook-nodejs-business-sdk',
		dirs: [
			{ src: 'dist', dest: 'facebook-nodejs-business-sdk/dist' },
		],
		files: [
			{ src: 'package.json', dest: 'facebook-nodejs-business-sdk/package.json' },
		],
	},
	{
		name: 'linkedin-api-client',
		dirs: [
			{ src: 'dist', dest: 'linkedin-api-client/dist' },
		],
		files: [
			{ src: 'package.json', dest: 'linkedin-api-client/package.json' },
		],
	},
	// Analytics Toolkit
	{
		name: 'd3',
		cdnPackage: true, // Loaded from CDN (jsDelivr)
		dirs: [
			{ src: 'dist', dest: 'd3/dist' },
		],
		files: [
			{ src: 'package.json', dest: 'd3/package.json' },
		],
	},
	{
		name: 'mathjs',
		cdnPackage: true, // Loaded from CDN (jsDelivr) - browser build
		dirs: [
			{ src: 'lib', dest: 'mathjs/lib' },
		],
		files: [
			{ src: 'package.json', dest: 'mathjs/package.json' },
		],
	},
	{
		name: 'regression',
		files: [
			{ src: 'dist/regression.min.js', dest: 'regression/regression.min.js' },
			{ src: 'package.json', dest: 'regression/package.json' },
		],
	},
	{
		name: 'fast-csv',
		dirs: [
			{ src: 'build', dest: 'fast-csv/build' },
		],
		files: [
			{ src: 'package.json', dest: 'fast-csv/package.json' },
		],
	},
	// Multilingual Toolkit
	{
		name: 'i18next',
		dirs: [
			{ src: 'dist', dest: 'i18next/dist' },
		],
		files: [
			{ src: 'package.json', dest: 'i18next/package.json' },
		],
	},
	{
		name: 'franc',
		files: [
			{ src: 'index.js', dest: 'franc/index.js' },
			{ src: 'package.json', dest: 'franc/package.json' },
		],
	},
	{
		name: 'google-translate-api-x',
		files: [
			{ src: 'index.cjs', dest: 'google-translate-api-x/index.cjs' },
			{ src: 'lib', dest: 'google-translate-api-x/lib', isDir: true },
			{ src: 'package.json', dest: 'google-translate-api-x/package.json' },
		],
	},
	{
		name: 'iso-639-1',
		files: [
			{ src: 'build/index.js', dest: 'iso-639-1/index.js' },
			{ src: 'package.json', dest: 'iso-639-1/package.json' },
		],
	},
	// Video Production Toolkit
	{
		name: 'ffmpeg-static',
		files: [
			{ src: 'index.js', dest: 'ffmpeg-static/index.js' },
			{ src: 'package.json', dest: 'ffmpeg-static/package.json' },
		],
	},
	{
		name: 'ffprobe-static',
		files: [
			{ src: 'index.js', dest: 'ffprobe-static/index.js' },
			{ src: 'package.json', dest: 'ffprobe-static/package.json' },
		],
	},
	{
		name: 'gif-encoder',
		files: [
			{ src: 'lib', dest: 'gif-encoder/lib', isDir: true },
			{ src: 'package.json', dest: 'gif-encoder/package.json' },
		],
	},
	{
		name: 'video-stitch',
		files: [
			{ src: 'index.js', dest: 'video-stitch/index.js' },
			{ src: 'lib', dest: 'video-stitch/lib', isDir: true },
			{ src: 'package.json', dest: 'video-stitch/package.json' },
		],
	},
	{
		name: 'subtitle',
		dirs: [
			{ src: 'dist', dest: 'subtitle/dist' },
		],
		files: [
			{ src: 'package.json', dest: 'subtitle/package.json' },
		],
	},
	// ========================================================================
	// CRM & EMAIL MARKETING TOOLKIT PACKAGES
	// ========================================================================
	{
		name: 'nodemailer',
		dirs: [
			{ src: 'lib', dest: 'nodemailer/lib' },
		],
		files: [
			{ src: 'package.json', dest: 'nodemailer/package.json' },
		],
	},
	{
		name: 'validator',
		files: [
			{ src: 'index.js', dest: 'validator/index.js' },
			{ src: 'lib', dest: 'validator/lib', isDir: true },
			{ src: 'es', dest: 'validator/es', isDir: true },
			{ src: 'package.json', dest: 'validator/package.json' },
		],
	},
	{
		name: 'email-validator',
		files: [
			{ src: 'index.js', dest: 'email-validator/index.js' },
			{ src: 'package.json', dest: 'email-validator/package.json' },
		],
	},
	{
		name: 'libphonenumber-js',
		files: [
			{ src: 'index.js', dest: 'libphonenumber-js/index.js' },
			{ src: 'min', dest: 'libphonenumber-js/min', isDir: true },
			{ src: 'mobile', dest: 'libphonenumber-js/mobile', isDir: true },
			{ src: 'metadata.min.json', dest: 'libphonenumber-js/metadata.min.json' },
			{ src: 'package.json', dest: 'libphonenumber-js/package.json' },
		],
	},
	{
		name: 'mailparser',
		dirs: [
			{ src: 'lib', dest: 'mailparser/lib' },
		],
		files: [
			{ src: 'package.json', dest: 'mailparser/package.json' },
		],
	},
	{
		name: 'csv-parse',
		dirs: [
			{ src: 'lib', dest: 'csv-parse/lib' },
		],
		files: [
			{ src: 'package.json', dest: 'csv-parse/package.json' },
		],
	},
	{
		name: 'csv-stringify',
		dirs: [
			{ src: 'lib', dest: 'csv-stringify/lib' },
		],
		files: [
			{ src: 'package.json', dest: 'csv-stringify/package.json' },
		],
	},
	{
		name: 'ical-generator',
		dirs: [
			{ src: 'dist', dest: 'ical-generator/dist' },
		],
		files: [
			{ src: 'package.json', dest: 'ical-generator/package.json' },
		],
	},
	// ========================================================================
	// DOCUMENT GENERATION UTILITY PACKAGES
	// ========================================================================
	{
		name: 'pdf-lib',
		dirs: [
			{ src: 'cjs', dest: 'pdf-lib/cjs' },
			{ src: 'es', dest: 'pdf-lib/es' },
		],
		files: [
			{ src: 'package.json', dest: 'pdf-lib/package.json' },
		],
	},
	{
		name: 'pdfkit',
		dirs: [
			{ src: 'js', dest: 'pdfkit/js' },
		],
		files: [
			{ src: 'package.json', dest: 'pdfkit/package.json' },
		],
	},
	{
		name: 'docx',
		dirs: [
			{ src: 'build', dest: 'docx/build' },
		],
		files: [
			{ src: 'package.json', dest: 'docx/package.json' },
		],
	},
	{
		name: 'exceljs',
		dirs: [
			{ src: 'dist', dest: 'exceljs/dist' },
			{ src: 'lib', dest: 'exceljs/lib' },
		],
		files: [
			{ src: 'package.json', dest: 'exceljs/package.json' },
		],
	},
	{
		name: 'puppeteer-core',
		dirs: [
			{ src: 'lib', dest: 'puppeteer-core/lib' },
		],
		files: [
			{ src: 'package.json', dest: 'puppeteer-core/package.json' },
		],
	},
	{
		name: 'qrcode',
		files: [
			{ src: 'build/qrcode.min.js', dest: 'qrcode/qrcode.min.js' },
			{ src: 'package.json', dest: 'qrcode/package.json' },
		],
	},
	{
		name: 'turndown',
		dirs: [
			{ src: 'lib', dest: 'turndown/lib' },
		],
		files: [
			{ src: 'package.json', dest: 'turndown/package.json' },
		],
	},
	{
		name: 'cheerio',
		dirs: [
			{ src: 'dist', dest: 'cheerio/dist' },
		],
		files: [
			{ src: 'package.json', dest: 'cheerio/package.json' },
		],
	},
	{
		name: 'pdf-parse',
		dirs: [
			{ src: 'lib', dest: 'pdf-parse/lib' },
		],
		files: [
			{ src: 'package.json', dest: 'pdf-parse/package.json' },
			{ src: 'index.js', dest: 'pdf-parse/index.js' },
		],
	},
	{
		name: 'node-ensure',
		files: [
			{ src: 'index.js', dest: 'node-ensure/index.js' },
			{ src: 'package.json', dest: 'node-ensure/package.json' },
		],
	},
	// ========================================================================
	// OCR PACKAGES (Document Generation Toolkit - Phase 3)
	// ========================================================================
	{
		name: 'tesseract.js',
		dirs: [
			{ src: 'src', dest: 'tesseract.js/src' },
		],
		files: [
			{ src: 'dist/tesseract.min.js', dest: 'tesseract.js/tesseract.min.js' },
			{ src: 'package.json', dest: 'tesseract.js/package.json' },
		],
	},
	{
		name: 'pdfjs-dist',
		dirs: [
			{ src: 'legacy/build', dest: 'pdfjs-dist/legacy/build' },
			{ src: 'legacy/web', dest: 'pdfjs-dist/legacy/web' },
		],
		files: [
			{ src: 'package.json', dest: 'pdfjs-dist/package.json' },
		],
	},
	{
		name: 'canvas',
		dirs: [
			{ src: 'lib', dest: 'canvas/lib' },
			{ src: 'build', dest: 'canvas/build' },
		],
		files: [
			{ src: 'browser.js', dest: 'canvas/browser.js' },
			{ src: 'package.json', dest: 'canvas/package.json' },
		],
	},
];

let totalCopied = 0;
let totalSize = 0;
let skippedCdn = 0;
let skippedCdnSize = 0;

dependencies.forEach(dep => {
	const depPath = path.join(proPath, 'node_modules', dep.name);
	
	if (!fs.existsSync(depPath)) {
		console.log(`${colors.yellow}⚠️  ${dep.name} not found in node_modules${colors.reset}`);
		return;
	}
	
	// Skip CDN packages unless explicitly included
	if (dep.cdnPackage && !skipCdnPackages) {
		// Calculate size for reporting
		let cdnPackageSize = 0;
		if (dep.dirs) {
			dep.dirs.forEach(dir => {
				const srcPath = path.join(depPath, dir.src);
				if (fs.existsSync(srcPath)) {
					cdnPackageSize += getSize(srcPath);
				}
			});
		}
		if (dep.files) {
			dep.files.forEach(file => {
				const srcPath = path.join(depPath, file.src);
				if (fs.existsSync(srcPath)) {
					const stats = fs.statSync(srcPath);
					if (stats.isDirectory() || file.isDir) {
						cdnPackageSize += getSize(srcPath);
					} else {
						cdnPackageSize += stats.size;
					}
				}
			});
		}
		
		console.log(`${colors.blue}⏭️  ${dep.name}${colors.reset} → ${formatSize(cdnPackageSize)} (CDN-loaded, skipped)`);
		skippedCdn++;
		skippedCdnSize += cdnPackageSize;
		return;
	}
	
	let depSize = 0;
	
	// Copy directories
	if (dep.dirs) {
		dep.dirs.forEach(dir => {
			const srcPath = path.join(depPath, dir.src);
			const destPath = path.join(vendorPath, dir.dest);
			
			if (fs.existsSync(srcPath)) {
				copyDir(srcPath, destPath);
				const size = getSize(destPath);
				depSize += size;
			}
		});
	}
	
	// Copy files
	if (dep.files) {
		dep.files.forEach(file => {
			const srcPath = path.join(depPath, file.src);
			const destPath = path.join(vendorPath, file.dest);
			
			if (fs.existsSync(srcPath)) {
				const stats = fs.statSync(srcPath);
				if (stats.isDirectory() || file.isDir) {
					copyDir(srcPath, destPath);
				} else {
					copyFile(srcPath, destPath);
				}
				const size = getSize(destPath);
				depSize += size;
			}
		});
	}
	
	if (depSize > 0) {
		const cdnLabel = dep.cdnPackage ? ' (offline fallback)' : '';
		console.log(`${colors.green}✅ ${dep.name}${cdnLabel}${colors.reset} → ${formatSize(depSize)}`);
		totalCopied++;
		totalSize += depSize;
	}
});

const endTime = Date.now();
const duration = ((endTime - startTime) / 1000).toFixed(2);

console.log(`\n${colors.green}✅ Copied ${totalCopied} dependencies (${formatSize(totalSize)}) in ${duration}s${colors.reset}`);
if (skippedCdn > 0) {
	console.log(`${colors.blue}⏭️  Skipped ${skippedCdn} CDN packages (${formatSize(skippedCdnSize)} saved)${colors.reset}`);
	console.log(`${colors.blue}💡 CDN packages will load from jsDelivr with automatic fallback${colors.reset}`);
	console.log(`${colors.blue}💡 To include CDN packages: WP_MCP_AI_BUILD_OFFLINE=true npm run build${colors.reset}`);
}

// ============================================================================
// POST-COPY CLEANUP: Remove unnecessary files to reduce plugin size
// ============================================================================
console.log(`\n${colors.blue}🧹 Cleaning up unnecessary files...${colors.reset}\n`);

let cleanupSaved = 0;

// 1. Remove canvas native binaries (~181MB uncompressed, ~50MB compressed)
//    Canvas requires system-level installation, bundling binaries doesn't work
//    We keep the lib files so the cloned repo has the JavaScript code, but users
//    need to run `npm install canvas` to compile native binaries for their platform
const canvasBuildPath = path.join(vendorPath, 'canvas', 'build');
if (fs.existsSync(canvasBuildPath)) {
	const canvasSize = getSize(canvasBuildPath);
	fs.rmSync(canvasBuildPath, { recursive: true, force: true });
	cleanupSaved += canvasSize;
	console.log(`${colors.green}✓ Removed canvas native binaries${colors.reset} → ${formatSize(canvasSize)} saved`);
	console.log(`  ${colors.yellow}Note: Canvas lib files preserved; run 'npm install canvas' for PDF OCR${colors.reset}`);
}

// 2. Remove old pdf.js versions from pdf-parse (keep only v2.0.550)
//    Old versions: v1.9.426, v1.10.88, v1.10.100 (~18MB total)
const pdfParseLibPath = path.join(vendorPath, 'pdf-parse', 'lib', 'pdf.js');
if (fs.existsSync(pdfParseLibPath)) {
	const oldVersions = ['v1.9.426', 'v1.10.88', 'v1.10.100'];
	oldVersions.forEach(version => {
		const versionPath = path.join(pdfParseLibPath, version);
		if (fs.existsSync(versionPath)) {
			const versionSize = getSize(versionPath);
			fs.rmSync(versionPath, { recursive: true, force: true });
			cleanupSaved += versionSize;
			console.log(`${colors.green}✓ Removed pdf-parse ${version}${colors.reset} → ${formatSize(versionSize)} saved`);
		}
	});
}

// 3. Remove source maps from pdfjs-dist (~8MB)
const pdfjsDistPath = path.join(vendorPath, 'pdfjs-dist');
if (fs.existsSync(pdfjsDistPath)) {
	let mapSize = 0;
	const removeMapFiles = (dir) => {
		if (!fs.existsSync(dir)) return;
		const entries = fs.readdirSync(dir, { withFileTypes: true });
		entries.forEach(entry => {
			const fullPath = path.join(dir, entry.name);
			if (entry.isDirectory()) {
				removeMapFiles(fullPath);
			} else if (entry.name.endsWith('.map')) {
				mapSize += getSize(fullPath);
				fs.unlinkSync(fullPath);
			}
		});
	};
	removeMapFiles(pdfjsDistPath);
	if (mapSize > 0) {
		cleanupSaved += mapSize;
		console.log(`${colors.green}✓ Removed pdfjs-dist source maps${colors.reset} → ${formatSize(mapSize)} saved`);
	}
}

console.log(`\n${colors.green}✅ Cleanup complete: ${formatSize(cleanupSaved)} total saved${colors.reset}\n`);

console.log(`${colors.blue}📦 Vendor directory: ${path.relative(process.cwd(), vendorPath)}${colors.reset}`);
