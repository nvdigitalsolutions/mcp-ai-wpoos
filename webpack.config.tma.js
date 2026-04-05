/**
 * Webpack Configuration – Telegram Mini App (TMA) Builds
 *
 * Consolidated config for all Telegram Mini App React SPAs. Each TMA gets its
 * own entry point and output directory but shares the same @wordpress/scripts
 * defaults (loaders, optimisation) with two critical overrides:
 *
 * 1. The WordPress DependencyExtractionWebpackPlugin is removed so that React
 *    and other packages are **bundled** into the output instead of being
 *    treated as externals pointing at `window.wp.*`.
 *
 * 2. `externals` is set to `{}` to ensure nothing is externalised.
 *
 * TMA source files import directly from `react` / `react-dom` (not from the
 * `@wordpress/element` wrapper) so the bundles are completely standalone and
 * do not depend on WordPress globals at runtime.
 *
 * TMA pages run inside Telegram's WebView, outside of WordPress, so the `wp`
 * global does not exist.
 *
 * Replaces the three individual configs:
 *   webpack.config.tma-builder.js
 *   webpack.config.tma-woo-shop.js
 *   webpack.config.tma-shopify-jewelry.js
 *   webpack.config.tma-shopify-shop.js
 *
 * Usage:
 *   npm run build:tma-builder          → production bundle (tma-template-builder)
 *   npm run build:tma-woo-shop         → production bundle (tma-woo-shop)
 *   npm run build:tma-shopify-jewelry  → production bundle (tma-shopify-jewelry)
 *   npm run build:tma-shopify-shop     → production bundle (tma-shopify-shop)
 *   npm run start:tma-builder          → watch mode (tma-template-builder)
 *   npm run start:tma-woo-shop         → watch mode (tma-woo-shop)
 *   npm run start:tma-shopify-jewelry  → watch mode (tma-shopify-jewelry)
 *   npm run start:tma-shopify-shop     → watch mode (tma-shopify-shop)
 *   npm run build:tma                  → all four bundles in one pass
 *
 * To build only a specific TMA during development, pass the ENTRY env var:
 *   ENTRY=tma-template-builder npm run build:tma
 *
 * Output directories:
 *   addons/pro/build/tma-template-builder/   (tma-template-builder.js/css/asset.php)
 *   addons/pro/build/tma-woo-shop/           (tma-woo-shop.js/css/asset.php)
 *   addons/pro/build/tma-shopify-jewelry/    (tma-shopify-jewelry.js/css/asset.php)
 *   addons/pro/build/tma-shopify-shop/       (tma-shopify-shop.js/css/asset.php)
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

'use strict';

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path          = require( 'path' );

/**
 * Strip the DependencyExtractionWebpackPlugin that @wordpress/scripts adds by
 * default. That plugin externalises every `@wordpress/*` import to the
 * corresponding `window.wp.*` global and emits a `.asset.php` manifest.
 *
 * TMA bundles are standalone SPAs loaded outside WordPress, so those globals
 * do not exist. Removing the plugin causes webpack to bundle React, React DOM,
 * and the thin @wordpress/element wrapper directly into the output file.
 */
const tmaPlugins = ( defaultConfig.plugins || [] ).filter(
	( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
);

/**
 * TMA entry definitions.
 *
 * Each entry maps:
 *   name   → bundle name / asset prefix
 *   entry  → source file (relative to repo root)
 *   output → output directory (relative to repo root)
 */
const TMA_ENTRIES = [
	{
		name:   'tma-template-builder',
		entry:  'src/tma-template-builder/index.jsx',
		output: 'addons/pro/build/tma-template-builder',
	},
	{
		name:   'tma-woo-shop',
		entry:  'src/tma-woo-shop/index.jsx',
		output: 'addons/pro/build/tma-woo-shop',
	},
	{
		name:   'tma-shopify-jewelry',
		entry:  'src/tma-shopify-jewelry/index.jsx',
		output: 'addons/pro/build/tma-shopify-jewelry',
	},
	{
		name:   'tma-shopify-shop',
		entry:  'src/tma-shopify-shop/index.jsx',
		output: 'addons/pro/build/tma-shopify-shop',
	},
];

/**
 * When the ENTRY env var is set, build only the named TMA (e.g., when running
 * individual `npm run build:tma-*` scripts through this file).
 */
const requestedEntry = process.env.ENTRY || null;

const entries = TMA_ENTRIES.filter(
	( tma ) => ! requestedEntry || tma.name === requestedEntry
);

module.exports = entries.map( ( tma ) => ( {
	...defaultConfig,
	entry: {
		[ tma.name ]: path.resolve( __dirname, tma.entry ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, tma.output ),
	},
	plugins: tmaPlugins,

	// Explicitly clear externals so that React, ReactDOM, and @wordpress/*
	// packages are always bundled into the TMA output.  The
	// DependencyExtractionWebpackPlugin is already removed above, but an
	// explicit empty object guards against any other plugin or config layer
	// that might inject externals.
	externals: {},
} ) );
