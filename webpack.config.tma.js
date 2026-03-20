/**
 * Webpack Configuration – Telegram Mini App (TMA) Builds
 *
 * Consolidated config for all Telegram Mini App React SPAs. Each TMA gets its
 * own entry point and output directory but shares the same @wordpress/scripts
 * defaults (loaders, externals, optimisation) to keep bundle behaviour identical
 * to the previous per-file configs.
 *
 * Replaces the three individual configs:
 *   webpack.config.tma-builder.js
 *   webpack.config.tma-woo-shop.js
 *   webpack.config.tma-shopify-jewelry.js
 *
 * Usage:
 *   npm run build:tma-builder          → production bundle (tma-template-builder)
 *   npm run build:tma-woo-shop         → production bundle (tma-woo-shop)
 *   npm run build:tma-shopify-jewelry  → production bundle (tma-shopify-jewelry)
 *   npm run start:tma-builder          → watch mode (tma-template-builder)
 *   npm run start:tma-woo-shop         → watch mode (tma-woo-shop)
 *   npm run start:tma-shopify-jewelry  → watch mode (tma-shopify-jewelry)
 *   npm run build:tma                  → all three bundles in one pass
 *
 * To build only a specific TMA during development, pass the ENTRY env var:
 *   ENTRY=tma-template-builder npm run build:tma
 *
 * Output directories:
 *   addons/pro/build/tma-template-builder/   (tma-template-builder.js/css/asset.php)
 *   addons/pro/build/tma-woo-shop/           (tma-woo-shop.js/css/asset.php)
 *   addons/pro/build/tma-shopify-jewelry/    (tma-shopify-jewelry.js/css/asset.php)
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

'use strict';

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path          = require( 'path' );

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
} ) );
