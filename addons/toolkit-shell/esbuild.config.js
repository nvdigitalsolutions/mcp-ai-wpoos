/**
 * esbuild configuration for the NV oOS Toolkit Shell addon.
 *
 * Produces:
 *   assets/dist/toolkit-shell.js   — IIFE bundle
 *   assets/dist/toolkit-shell.css  — Extracted stylesheet
 *
 * @since 0.1.0
 */

'use strict';

const esbuild = require( 'esbuild' );
const path    = require( 'path' );
const fs      = require( 'fs' );

const args    = process.argv.slice( 2 );
const isProd  = args.includes( '--prod' );
const isWatch = args.includes( '--watch' );

const outDir = path.resolve( __dirname, 'assets', 'dist' );
fs.mkdirSync( outDir, { recursive: true } );

/** @type {import('esbuild').BuildOptions} */
const buildOptions = {
	entryPoints: [ path.resolve( __dirname, 'src', 'index.tsx' ) ],
	bundle:      true,
	outfile:     path.join( outDir, 'toolkit-shell.js' ),
	format:      'iife',
	globalName:  'NVoOSToolkitShell',
	platform:    'browser',
	target:      [ 'es2017', 'chrome80', 'firefox78', 'safari13' ],
	jsx:         'automatic',
	loader:      { '.css': 'css', '.ts': 'ts', '.tsx': 'tsx' },
	define:      { 'process.env.NODE_ENV': isProd ? '"production"' : '"development"' },
	minify:      isProd,
	sourcemap:   ! isProd,
	treeShaking: true,
	plugins:     [
		{
			name: 'css-extract',
			setup( build ) {
				build.onEnd( () => {
					const def = path.join( outDir, 'toolkit-shell.css' );
					const alt = path.join( outDir, 'index.css' );
					if ( ! fs.existsSync( def ) && fs.existsSync( alt ) ) {
						fs.renameSync( alt, def );
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
		console.log( '[toolkit-shell] Watching for changes…' );
	} );
} else {
	esbuild.build( buildOptions ).catch( () => process.exit( 1 ) );
}
