const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
	...defaultConfig,
	entry: {
		'spa-bundle': './src/index.js',
		'inline-assistant': './src/components/chat/InlineAssistant.jsx',
	},
	output: {
		...defaultConfig.output,
		filename: '[name].js',
	},
};
