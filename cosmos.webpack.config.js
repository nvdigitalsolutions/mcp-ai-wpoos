/**
 * Webpack configuration for React Cosmos TMA Template Builder playground.
 *
 * Cosmos uses this config when `npm run cosmos:tma` is invoked. It is kept
 * intentionally minimal — just enough to transpile JSX and import CSS.
 * Production builds continue to use @wordpress/scripts (wp-scripts build).
 *
 * Packages required (all declared as devDependencies in package.json):
 *   babel-loader, @babel/core, @babel/preset-env, @babel/preset-react,
 *   css-loader, style-loader, react-cosmos, react-cosmos-plugin-webpack
 *
 * @see https://reactcosmos.org/docs/getting-started/webpack
 */

'use strict';

const path = require( 'path' );

module.exports = {
	mode: 'development',

	resolve: {
		extensions: [ '.js', '.jsx', '.ts', '.tsx' ],
		alias: {
			// Map @wordpress/* to their actual packages so Cosmos can resolve them
			// without the full wp-scripts build pipeline.
			'@wordpress/element': path.resolve( __dirname, 'node_modules/@wordpress/element' ),
			'@wordpress/i18n':    path.resolve( __dirname, 'node_modules/@wordpress/i18n' ),
			// Map @dnd-kit packages.
			'@dnd-kit/core':      path.resolve( __dirname, 'node_modules/@dnd-kit/core' ),
			'@dnd-kit/sortable':  path.resolve( __dirname, 'node_modules/@dnd-kit/sortable' ),
			'@dnd-kit/utilities': path.resolve( __dirname, 'node_modules/@dnd-kit/utilities' ),
		},
	},

	module: {
		rules: [
			{
				// Transpile JSX and modern JS via Babel.
				test: /\.[jt]sx?$/,
				exclude: /node_modules/,
				use: {
					loader: 'babel-loader',
					options: {
						presets: [
							[ '@babel/preset-env', { targets: 'last 2 Chrome versions' } ],
							[ '@babel/preset-react', { runtime: 'automatic' } ],
						],
					},
				},
			},
			{
				// Inline CSS imports (e.g. from @dnd-kit).
				test: /\.css$/,
				use: [ 'style-loader', 'css-loader' ],
			},
		],
	},

	// Cosmos injects its own entry point; we just need the module rules above.
	entry: {},
};
