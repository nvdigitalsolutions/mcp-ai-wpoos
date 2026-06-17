#!/usr/bin/env node
/**
 * NV oOS Pro — Cornerstone3D Vendor Build Script
 *
 * Downloads and bundles the Cornerstone3D libraries as self-contained ESM
 * modules for local serving, eliminating the runtime CDN dependency.
 *
 * The build mirrors the CDN strategy used by imaging-viewer.js:
 *   - @cornerstonejs/core    → standalone ESM bundle
 *   - @cornerstonejs/tools   → ESM bundle (core is external, resolved via importmap)
 *   - @cornerstonejs/dicom-image-loader → ESM bundle (core + dicom-parser external)
 *   - dicom-parser           → standalone ESM bundle
 *   - xmlbuilder2            → standalone ESM bundle
 *
 * Usage:
 *   cd <repo-root>
 *   node bin/vendor-cornerstone.js
 *
 * Requirements:
 *   - Node.js >= 18
 *   - npm (for installing packages)
 *   - esbuild (installed automatically via npx)
 *
 * Output:
 *   addons/pro/assets/vendor/cornerstone/
 *     ├── cornerstone-core.esm.js
 *     ├── cornerstone-tools.esm.js
 *     ├── cornerstone-dicom-loader.esm.js
 *     ├── dicom-parser.esm.js
 *     ├── xmlbuilder2.esm.js
 *     └── vendor-meta.json
 *
 * @package WP_MCP_AI_Pro
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { execSync } = require( 'child_process' );

// ---------------------------------------------------------------------------
// Configuration — Pinned versions (must match class-wp-mcp-ai-imaging-admin-page.php)
// ---------------------------------------------------------------------------
const PACKAGES = {
	'@cornerstonejs/core': '1.86.1',
	'@cornerstonejs/tools': '1.86.1',
	'@cornerstonejs/dicom-image-loader': '1.86.0',
	'dicom-parser': '1.8.21',
	'xmlbuilder2': '3.0.2',
};

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------
const ROOT_DIR = path.resolve( __dirname, '..' );
const VENDOR_DIR = path.join( ROOT_DIR, 'addons', 'pro', 'assets', 'vendor', 'cornerstone' );
const WORK_DIR = path.join( ROOT_DIR, 'build', '.tmp-cornerstone-vendor' );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function formatSize( bytes ) {
	if ( bytes >= 1024 * 1024 ) {
		return ( bytes / 1024 / 1024 ).toFixed( 1 ) + ' MB';
	}
	return ( bytes / 1024 ).toFixed( 0 ) + ' KB';
}

function run( cmd, opts ) {
	console.log( '  $ ' + cmd );
	return execSync( cmd, { stdio: 'inherit', ...opts } );
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
console.log( '' );
console.log( '=========================================' );
console.log( 'Cornerstone3D Vendor Build' );
console.log( '=========================================' );
console.log( '' );

// Step 1: Create a clean working directory with package.json.
if ( fs.existsSync( WORK_DIR ) ) {
	fs.rmSync( WORK_DIR, { recursive: true, force: true } );
}
fs.mkdirSync( WORK_DIR, { recursive: true } );

const deps = {};
for ( const [ pkg, ver ] of Object.entries( PACKAGES ) ) {
	deps[ pkg ] = ver;
}

const workPkg = {
	name: 'cornerstone-vendor-build',
	version: '1.0.0',
	private: true,
	dependencies: deps,
	devDependencies: {
		esbuild: '^0.21.0',
	},
};

fs.writeFileSync(
	path.join( WORK_DIR, 'package.json' ),
	JSON.stringify( workPkg, null, 2 ) + '\n'
);

console.log( '[1/4] Installing Cornerstone3D packages...' );
run( 'npm install --no-audit --no-fund', { cwd: WORK_DIR } );
console.log( '' );

// Step 2: Create entry point files for esbuild.
console.log( '[2/4] Creating ESM entry points...' );

const entries = [
	{
		name: 'cornerstone-core',
		entry: 'entry-core.js',
		code: 'export * from "@cornerstonejs/core";\n',
		external: [],
	},
	{
		name: 'dicom-parser',
		entry: 'entry-dicom-parser.js',
		code: 'export * from "dicom-parser";\n',
		external: [],
	},
	{
		name: 'xmlbuilder2',
		entry: 'entry-xmlbuilder2.js',
		code: 'export { create } from "xmlbuilder2";\n',
		external: [],
	},
	{
		name: 'cornerstone-tools',
		entry: 'entry-tools.js',
		code: 'export * from "@cornerstonejs/tools";\n',
		external: [ '@cornerstonejs/core' ],
	},
	{
		name: 'cornerstone-dicom-loader',
		entry: 'entry-dicom-loader.js',
		// Statically import `dicom-parser` before re-exporting the dicom-image-loader.
		// This guarantees esbuild traces and inlines dicom-parser into the bundle as
		// a real ESM module — the `import` statement creates a static dependency
		// edge that cannot be elided.  Re-exporting `@cornerstonejs/dicom-image-loader`
		// alone is not sufficient because the loader's published dist is a UMD
		// wrapper that references `dicom-parser` through CommonJS detection
		// branches; some esbuild paths emit a runtime `__require("dicom-parser")`
		// shim for those references which throws
		// "Dynamic require of \"dicom-parser\" is not supported" at runtime.
		// The static import primes the bundler so the dicom-parser module is
		// always present in the output graph, eliminating the CJS shim path.
		code:
			'import "dicom-parser";\n' +
			'export * from "@cornerstonejs/dicom-image-loader";\n',
		// Only @cornerstonejs/core is kept external so tools and dicom-loader
		// share a single core instance via the importmap.  dicom-parser and
		// xmlbuilder2 are CommonJS internally and must NOT be externalised —
		// externalising them causes esbuild to emit the `__require()` shim
		// described above.  Inlining them (~140 KB) keeps the bundle ESM-pure.
		external: [ '@cornerstonejs/core' ],
	},
];

for ( const e of entries ) {
	fs.writeFileSync( path.join( WORK_DIR, e.entry ), e.code );
}
console.log( '  Created ' + entries.length + ' entry points.' );
console.log( '' );

// Step 3: Bundle each entry with esbuild.
console.log( '[3/4] Bundling ESM modules with esbuild...' );
fs.mkdirSync( VENDOR_DIR, { recursive: true } );

const esbuildBin = path.join( WORK_DIR, 'node_modules', '.bin', 'esbuild' );
const results = [];

for ( const e of entries ) {
	const outFile = path.join( VENDOR_DIR, e.name + '.esm.js' );
	const externals = e.external.map( ( x ) => '--external:' + x ).join( ' ' );

	run(
		esbuildBin + ' ' + e.entry +
		' --bundle --format=esm --platform=browser' +
		' --target=es2020' +
		' --minify' +
		( externals ? ' ' + externals : '' ) +
		' --outfile=' + outFile,
		{ cwd: WORK_DIR }
	);

	const size = fs.statSync( outFile ).size;
	results.push( { name: e.name, size } );
	console.log( '  ✅ ' + e.name + '.esm.js (' + formatSize( size ) + ')' );
}
console.log( '' );

// Step 4: Write vendor-meta.json.
console.log( '[4/4] Writing vendor-meta.json...' );

const meta = {
	packages: {},
	built_at: new Date().toISOString(),
	node_version: process.version,
	platform: process.platform,
	arch: process.arch,
};

for ( const [ pkg, ver ] of Object.entries( PACKAGES ) ) {
	meta.packages[ pkg ] = ver;
}

for ( const r of results ) {
	meta[ r.name ] = { file: r.name + '.esm.js', size: r.size };
}

fs.writeFileSync(
	path.join( VENDOR_DIR, 'vendor-meta.json' ),
	JSON.stringify( meta, null, '\t' ) + '\n'
);

// Clean up working directory.
fs.rmSync( WORK_DIR, { recursive: true, force: true } );

console.log( '' );
console.log( '=========================================' );
console.log( 'Cornerstone3D Vendor Build Complete' );
console.log( '=========================================' );
console.log( '' );

const totalSize = results.reduce( ( sum, r ) => sum + r.size, 0 );
console.log( '  Output: ' + VENDOR_DIR );
console.log( '  Files:  ' + results.length + ' ESM bundles' );
console.log( '  Total:  ' + formatSize( totalSize ) );
console.log( '' );
console.log( '  Packages:' );
for ( const [ pkg, ver ] of Object.entries( PACKAGES ) ) {
	console.log( '    ' + pkg + '@' + ver );
}
console.log( '' );
