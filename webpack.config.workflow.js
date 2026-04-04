/**
 * WordPress Scripts Webpack Config – Pro Workflow Builder
 *
 * Extends default @wordpress/scripts webpack config so the output bundle is
 * named `workflow-builder.*` (matching what the PHP loader class expects) and
 * is placed directly in the Pro addon build directory.
 *
 * Usage:
 *   npm run build:workflow   → production bundle
 *   npm run start:workflow   → watch mode
 *
 * Output: addons/pro/build/workflow-builder/workflow-builder.{js,css,asset.php}
 *
 * @package WP_MCP_AI
 */

'use strict';

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path          = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'workflow-builder': path.resolve( __dirname, 'src/workflow-builder/index.jsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'addons/pro/build/workflow-builder' ),
	},
};
