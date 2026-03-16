/**
 * Webpack Configuration – WooCommerce Telegram Mini App (React SPA)
 *
 * Dedicated config for the enhanced WooCommerce Shopping Mini App. The
 * compiled bundle is a standalone React SPA loaded inside the Telegram
 * WebView via the `woo_shop` TMA template.
 *
 * Usage:
 *   npm run build:tma-woo-shop   → production bundle
 *   npm run start:tma-woo-shop   → watch mode (development)
 *
 * Output: addons/pro/build/tma-woo-shop/
 *   tma-woo-shop.js
 *   tma-woo-shop.css
 *   tma-woo-shop.asset.php   ← WP dependency manifest
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

'use strict';

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'tma-woo-shop': path.resolve( __dirname, 'src/tma-woo-shop/index.jsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'addons/pro/build/tma-woo-shop' ),
	},
};
