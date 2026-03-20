/**
 * Webpack Configuration – Shopify Jewelry Telegram Mini App (React SPA)
 *
 * Dedicated config for the Shopify Jewelry Shop Mini App. The compiled bundle
 * is a standalone React SPA loaded inside the Telegram WebView via the
 * `jewelry_shop` TMA template.
 *
 * Usage:
 *   npm run build:tma-shopify-jewelry   → production bundle
 *   npm run start:tma-shopify-jewelry   → watch mode (development)
 *
 * Output: addons/shopify-jewelry-tma/build/tma-shopify-jewelry/
 *   tma-shopify-jewelry.js
 *   tma-shopify-jewelry.css
 *   tma-shopify-jewelry.asset.php   ← WP dependency manifest
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

'use strict';

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path          = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'tma-shopify-jewelry': path.resolve( __dirname, 'src/tma-shopify-jewelry/index.jsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'addons/shopify-jewelry-tma/build/tma-shopify-jewelry' ),
	},
};
