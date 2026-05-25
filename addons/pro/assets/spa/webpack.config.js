const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
	...defaultConfig,
	entry: {
		'spa-bundle': './src/index.js',
	},
	output: {
		...defaultConfig.output,
		filename: '[name].js',
	},
};
