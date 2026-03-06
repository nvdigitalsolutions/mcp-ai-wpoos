/**
 * Webpack Configuration – Telegram Mini App Template Builder
 *
 * Dedicated config for the TMA Template Builder React app. Kept separate
 * from the root webpack.config.js (which targets the Workflow Builder) so
 * that both can be built independently with predictable chunk names and
 * output paths.
 *
 * Usage:
 *   npm run build:tma-builder   → production bundle
 *   npm run start:tma-builder   → watch mode (development)
 *
 * Output: addons/pro/build/tma-template-builder/
 *   tma-template-builder.js
 *   tma-template-builder.css
 *   tma-template-builder.asset.php   ← WP dependency manifest
 *
 * @package WP_MCP_AI
 * @since   1.1.3
 */

'use strict';

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'tma-template-builder': path.resolve(
			__dirname,
			'src/tma-template-builder/index.jsx'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'addons/pro/build/tma-template-builder' ),
	},
};
