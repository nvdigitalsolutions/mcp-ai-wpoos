/**
 * esbuild configuration for the NV oOS Docs Hub addon.
 *
 * Produces:
 *   assets/dist/docs-hub.js   — IIFE bundle (React, all dependencies)
 *   assets/dist/docs-hub.css  — Extracted stylesheet
 *
 * Usage:
 *   node esbuild.config.js            (development, watch mode off)
 *   node esbuild.config.js --watch    (development, watch mode on)
 *   node esbuild.config.js --prod     (production, minified)
 *
 * @since 1.0.0
 */

'use strict';

const esbuild = require( 'esbuild' );
const path    = require( 'path' );
const fs      = require( 'fs' );

const args    = process.argv.slice( 2 );
const isProd  = args.includes( '--prod' );
const isWatch = args.includes( '--watch' );

const outDir = path.resolve( __dirname, 'assets', 'dist' );

// Ensure output directory exists.
fs.mkdirSync( outDir, { recursive: true } );

/** @type {import('esbuild').BuildOptions} */
const buildOptions = {
	entryPoints: [ path.resolve( __dirname, 'src', 'index.tsx' ) ],
	bundle:      true,
	outfile:     path.join( outDir, 'docs-hub.js' ),
	format:      'iife',
	globalName:  'NVoOSDocsHub',
	platform:    'browser',
	target:      [ 'es2017', 'chrome80', 'firefox78', 'safari15' ],
	jsx:         'automatic',

	// Extract CSS to a separate file.
	loader: {
		'.css': 'css',
		'.ts':  'ts',
		'.tsx': 'tsx',
	},

	// Mark WordPress globals as external (available via wp.* on the page).
	external: [],

	define: {
		'process.env.NODE_ENV': isProd ? '"production"' : '"development"',
	},

	minify:      isProd,
	sourcemap:   ! isProd,
	treeShaking: true,

	// wp.org Guideline 4: the shipped minified bundles must document where
	// their human-readable source lives and how they were built. The banners
	// are preserved in the minified output so the files are self-describing.
	banner:      {
		js: [
			'/*! NV oOS Docs Hub — bundled frontend (React documentation SPA).',
			' * This file is generated — do not edit directly.',
			' * Source: https://github.com/nvdigitalsolutions/nvoos-docs-hub/tree/main/src',
			' * Build:  npm install && npm run build (node esbuild.config.js --prod)',
			' * License: GPLv3 or later (https://www.gnu.org/licenses/gpl-3.0.html). */',
		].join( '\n' ),
		css: [
			'/*! NV oOS Docs Hub — bundled frontend styles.',
			' * This file is generated — do not edit directly.',
			' * Source: https://github.com/nvdigitalsolutions/nvoos-docs-hub/tree/main/src/styles',
			' * Build:  npm install && npm run build (node esbuild.config.js --prod)',
			' * License: GPLv3 or later (https://www.gnu.org/licenses/gpl-3.0.html). */',
		].join( '\n' ),
	},

	plugins: [
		{
			name: 'css-extract',
			setup( build ) {
				// esbuild writes CSS to <outfile>.css automatically when bundling CSS
				// with `loader: { '.css': 'css' }`. We just need to ensure the output
				// directory exists (done above) and rename if necessary.
				build.onEnd( () => {
					const defaultCss = path.join( outDir, 'docs-hub.css' );
					if ( ! fs.existsSync( defaultCss ) ) {
						// Try alternate name produced by esbuild.
						const altCss = path.join( outDir, 'index.css' );
						if ( fs.existsSync( altCss ) ) {
							fs.renameSync( altCss, defaultCss );
						}
					}
				} );
			},
		},
	],

	logLevel: 'info',
};

if ( isWatch ) {
	esbuild.context( buildOptions ).then( ( ctx ) => {
		ctx.watch();
		console.log( '[docs-hub] Watching for changes…' );
	} );
} else {
	esbuild.build( buildOptions ).catch( () => process.exit( 1 ) );
}
