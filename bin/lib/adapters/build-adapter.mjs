/**
 * Build System Adapter
 *
 * Generates an esbuild.config.cjs that matches the blueprint §5 pattern
 * (IIFE bundle, wp.i18n external, CSS extraction, committed artifacts).
 * Converts from webpack / vite / CRA / next.js originals.
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {object} BuildAdapterOptions
 * @property {string} slug           Addon slug (kebab-case).
 * @property {string} addonDir       Addon root directory.
 * @property {string} entryFile      Relative path to the entry file (post-mount-adapter).
 * @property {string} techStack      Tech stack info from analyzer.
 * @property {'vite'|'webpack'|'next'|'rsbuild'|'parcel'|'unknown'} bundler
 * @property {boolean} [dryRun]
 */

/**
 * Apply the build adapter — generate esbuild.config.cjs.
 *
 * @param {BuildAdapterOptions} options
 * @returns {{generated: boolean, configFile: string, warnings: string[]}}
 */
export function applyBuildAdapter( options ) {
	const { slug, addonDir, entryFile, techStack, bundler, dryRun = false } = options;
	const upperSnake   = slug.replace( /-/g, '_' ).toUpperCase();
	const globalName   = `NVoOS${ toPascalCase( slug ) }`;
	const entryAbs     = path.resolve( addonDir, 'src', entryFile || 'index.tsx' );
	const entryRel     = path.relative( addonDir, entryAbs ).replace( /\\/g, '/' );
	const configPath   = path.resolve( addonDir, 'esbuild.config.cjs' );
	const warnings     = [];
	const hasTypeScript = fs.existsSync( path.resolve( addonDir, 'tsconfig.json' ) );

	const config = generateEsbuildConfig( {
		slug,
		upperSnake,
		globalName,
		entryRel,
		hasTypeScript,
		bundler,
	} );

	if ( dryRun ) {
		return {
			generated: true,
			configFile: configPath,
			warnings: [ 'Dry run — config not written' ],
		};
	}

	fs.writeFileSync( configPath, config, 'utf-8' );

	// Also ensure tsconfig.json exists if using TypeScript.
	if ( hasTypeScript && ! fs.existsSync( path.resolve( addonDir, 'tsconfig.json' ) ) ) {
		const tsconfig = generateTsconfig( slug );
		fs.writeFileSync( path.resolve( addonDir, 'tsconfig.json' ), tsconfig, 'utf-8' );
	}

	return {
		generated: true,
		configFile: configPath,
		warnings,
	};
}

function generateEsbuildConfig( { slug, upperSnake, globalName, entryRel, hasTypeScript, bundler } ) {
	const loaderMap = hasTypeScript
		? `\t\tloader:      { '.css': 'css', '.ts': 'ts', '.tsx': 'tsx' },`
		: `\t\tloader:      { '.css': 'css' },`;

	return `/**
 * NV oOS ${ toTitle( slug ) } — esbuild configuration.
 *
 * Produces:
 *   assets/dist/${ slug }.js   — IIFE bundle
 *   assets/dist/${ slug }.css  — Extracted stylesheet
 *
 * Converted from ${ bundler } by bin/lib/adapters/build-adapter.mjs.
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

/** Map @wordpress/i18n to window.wp.i18n (loaded via wp-i18n script handle). */
const wpI18nPlugin = {
	name: 'wp-i18n-external',
	setup( build ) {
		build.onResolve( { filter: /^@wordpress\\/i18n$/ }, ( args ) => ( {
			path:      args.path,
			namespace: 'wp-i18n-ns',
		} ) );
		build.onLoad( { filter: /.*/, namespace: 'wp-i18n-ns' }, () => ( {
			contents: 'module.exports = window.wp.i18n;',
			loader:   'js',
		} ) );
	},
};

/** @type {import('esbuild').BuildOptions} */
const buildOptions = {
	entryPoints: [ path.resolve( __dirname, '${ entryRel }' ) ],
	bundle:      true,
	outfile:     path.join( outDir, '${ slug }.js' ),
	format:      'iife',
	globalName:  '${ globalName }',
	platform:    'browser',
	target:      [ 'es2017', 'chrome80', 'firefox78', 'safari15' ],
	jsx:         'automatic',
${ loaderMap }
	define:      { 'process.env.NODE_ENV': isProd ? '"production"' : '"development"' },
	minify:      isProd,
	sourcemap:   ! isProd,
	treeShaking: true,
	external:    [ '@wordpress/i18n' ],
	plugins:     [
		wpI18nPlugin,
		{
			name: 'css-extract',
			setup( build ) {
				build.onEnd( () => {
					const def = path.join( outDir, '${ slug }.css' );
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
		console.log( '[${ slug }] Watching for changes…' );
	} );
} else {
	esbuild.build( buildOptions ).catch( () => process.exit( 1 ) );
}
`;
}

function generateTsconfig( slug ) {
	return `{
  "compilerOptions": {
    "target": "ES2017",
    "lib": ["ES2017", "DOM", "DOM.Iterable"],
    "module": "ESNext",
    "moduleResolution": "bundler",
    "jsx": "react-jsx",
    "strict": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true,
    "resolveJsonModule": true,
    "declaration": false,
    "outDir": "./dist",
    "rootDir": "./src",
    "baseUrl": "./src",
    "paths": {
      "@/*": ["./*"]
    }
  },
  "include": ["src/**/*.ts", "src/**/*.tsx"],
  "exclude": ["node_modules", "assets/dist", "tests"]
}
`;
}

function toPascalCase( slug ) {
	return slug
		.split( '-' )
		.map( w => w.charAt( 0 ).toUpperCase() + w.slice( 1 ) )
		.join( '' );
}

function toTitle( slug ) {
	return slug
		.split( '-' )
		.map( w => w.charAt( 0 ).toUpperCase() + w.slice( 1 ) )
		.join( ' ' );
}
