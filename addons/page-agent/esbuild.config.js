/**
 * esbuild configuration for the NV oOS Page Agent addon.
 *
 * Bundles the page-agent npm package (ESM) into a self-contained IIFE
 * that exposes `window.PageAgent` for WordPress use. The bridge script
 * is a separate IIFE that wraps around the PageAgent global.
 *
 * This produces two output files:
 *   1. page-agent.bundle.js — The page-agent library as IIFE (production: .min.js)
 *   2. page-agent-bridge.js — The NV oOS bridge (production: .min.js)
 *
 * Usage:
 *   node esbuild.config.js              (development build)
 *   node esbuild.config.js --prod       (production build)
 *
 * @since 0.1.0
 */

const esbuild = require( 'esbuild' );
const path    = require( 'path' );

const isProd = process.argv.includes( '--prod' ) || process.argv.includes( '--production' );

const outdir = path.resolve( __dirname, 'assets/js' );

/**
 * Build 1: Bundle the page-agent library as an IIFE.
 */
async function buildPageAgent() {
	const config = {
		entryPoints: [ 'page-agent' ],
		bundle:      true,
		minify:      isProd,
		// Always emit external source maps (F-CMP-04): production bundles must
		// ship a sibling .map file so minified output remains reviewable.
		sourcemap:   'external',
		target:      [ 'es2020' ],
		format:      'iife',
		globalName:  'PageAgent',
		outfile:     path.resolve(
			outdir,
			isProd ? 'page-agent.bundle.min.js' : 'page-agent.bundle.js'
		),
		define: {
			'process.env.NODE_ENV': isProd ? '"production"' : '"development"',
		},
	};

	try {
		const result = await esbuild.build( config );
		if ( result.warnings.length > 0 ) {
			console.warn( '⚠ page-agent bundle warnings:', result.warnings );
		}
		console.log(
			isProd
				? '✅ page-agent.bundle.min.js built (production).'
				: '✅ page-agent.bundle.js built (development).'
		);
	} catch ( error ) {
		console.error( '❌ page-agent bundle failed:', error );
		process.exit( 1 );
	}
}

/**
 * Build 2: Bundle the NV oOS bridge as an IIFE.
 */
async function buildBridge() {
	const config = {
		entryPoints: [ path.resolve( __dirname, 'src/page-agent-bridge.js' ) ],
		bundle:      true,
		minify:      isProd,
		// Always emit external source maps (F-CMP-04): production bundles must
		// ship a sibling .map file so minified output remains reviewable.
		sourcemap:   'external',
		target:      [ 'es2020' ],
		format:      'iife',
		outfile:     path.resolve(
			outdir,
			isProd ? 'page-agent-bridge.min.js' : 'page-agent-bridge.js'
		),
		external: [
			// The page-agent global is provided by page-agent.bundle.js loaded first.
		],
		define: {
			'process.env.NODE_ENV': isProd ? '"production"' : '"development"',
		},
	};

	try {
		const result = await esbuild.build( config );
		if ( result.warnings.length > 0 ) {
			console.warn( '⚠ bridge bundle warnings:', result.warnings );
		}
		console.log(
			isProd
				? '✅ page-agent-bridge.min.js built (production).'
				: '✅ page-agent-bridge.js built (development).'
		);
	} catch ( error ) {
		console.error( '❌ bridge bundle failed:', error );
		process.exit( 1 );
	}
}

( async function () {
	await buildPageAgent();
	await buildBridge();
	console.log( isProd ? '🎉 Production build complete.' : '🎉 Development build complete.' );
} )();
