const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
	...defaultConfig,
	entry: {
		'spa-bundle': './src/index.js',
		'inline-assistant': './src/components/chat/InlineAssistant.jsx',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve(__dirname, 'dist'),
		filename: '[name].js',
	},
};
