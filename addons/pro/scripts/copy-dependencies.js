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

// Dependencies to copy with their configurations
const dependencies = [
	{
		name: '@turf/turf',
		files: [
			{ src: 'dist/turf.min.js', dest: 'turf/turf.min.js' },
			{ src: 'package.json', dest: 'turf/package.json' },
		],
	},
	{
		name: 'katex',
		dirs: [
			{ src: 'dist', dest: 'katex/dist' }, // Includes fonts, CSS, and JS
		],
		files: [
			{ src: 'package.json', dest: 'katex/package.json' },
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
];

let totalCopied = 0;
let totalSize = 0;

dependencies.forEach(dep => {
	const depPath = path.join(proPath, 'node_modules', dep.name);
	
	if (!fs.existsSync(depPath)) {
		console.log(`${colors.yellow}⚠️  ${dep.name} not found in node_modules${colors.reset}`);
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
		console.log(`${colors.green}✅ ${dep.name}${colors.reset} → ${formatSize(depSize)}`);
		totalCopied++;
		totalSize += depSize;
	}
});

const endTime = Date.now();
const duration = ((endTime - startTime) / 1000).toFixed(2);

console.log(`\n${colors.green}✅ Copied ${totalCopied} dependencies (${formatSize(totalSize)}) in ${duration}s${colors.reset}`);
console.log(`${colors.blue}📦 Vendor directory: ${path.relative(process.cwd(), vendorPath)}${colors.reset}`);
