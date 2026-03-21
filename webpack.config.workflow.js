/**
 * WordPress Scripts Webpack Config Extension
 * 
 * Extends default @wordpress/scripts webpack config for workflow builder.
 *
 * @package WP_MCP_AI
 */

const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry,
		'workflow-builder': path.resolve(process.cwd(), 'src/workflow-builder/index.jsx'),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve(process.cwd(), 'build'),
	},
};
