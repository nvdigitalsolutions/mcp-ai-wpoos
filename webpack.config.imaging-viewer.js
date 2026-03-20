/**
 * Webpack Configuration – Cornerstone3D Imaging Viewer Bundle
 *
 * Produces a self-contained bundle of @cornerstonejs/core, /tools, and
 * /dicom-image-loader that is served as a normal WordPress-enqueued script.
 * This avoids the CDN + ES importmap approach and works reliably without
 * internet access at render time.
 *
 * Intentionally does NOT extend @wordpress/scripts' defaultConfig so that
 * the DependencyExtractionWebpackPlugin (which externalises WP packages such
 * as react and wp-element) is NOT applied.  Cornerstone3D must be fully
 * self-contained in the output bundle.
 *
 * Usage:
 *   npm run build:imaging-viewer   → production build
 *   npm run start:imaging-viewer   → watch mode
 *
 * Output: addons/pro/build/imaging-viewer/
 *   imaging-viewer-bundle.js
 *
 * @package WP_MCP_AI
 * @since   1.1.4
 */

'use strict';

const path    = require( 'path' );
const webpack = require( 'webpack' );

const stubPath = path.resolve( __dirname, 'src/imaging-viewer/stubs/empty.js' );

module.exports = {
	mode: 'production',

	entry: {
		'imaging-viewer-bundle': path.resolve(
			__dirname,
			'src/imaging-viewer/index.js'
		),
	},

	output: {
		path:     path.resolve( __dirname, 'addons/pro/build/imaging-viewer' ),
		filename: '[name].js',
		// No library export — the entry script sets window.nvCs directly.
		clean: false,
	},

	module: {
		rules: [
			{
				// Transpile modern JS for broad browser compatibility.
				test:    /\.js$/,
				exclude: /node_modules/,
				use:     {
					loader:  'babel-loader',
					options: {
						presets: [
							[ '@babel/preset-env', { targets: 'last 2 Chrome versions, last 2 Firefox versions, last 2 Edge versions' } ],
						],
					},
				},
			},
			{
				// Stub out all WASM files — polySeg segmentation is not used.
				test: /\.wasm$/,
				type: 'asset/inline',
				generator: { dataUrl: () => 'data:application/wasm,STUB' },
			},
		],
	},

	plugins: [
		// Redirect ALL @kitware/vtk.js subpath imports to an empty stub.
		// vtk.js is required only by OrientationMarkerTool and 3D volume tools;
		// the Stack Viewport (2D DICOM viewer) does not need it.
		new webpack.NormalModuleReplacementPlugin(
			/@kitware[/\\]vtk\.js/,
			stubPath
		),
		// Redirect polySeg WASM — used only by segmentation tools, not by
		// the basic StackScrollMouseWheel / WindowLevel / Pan / Zoom tools.
		new webpack.NormalModuleReplacementPlugin(
			/@icr[/\\]polyseg-wasm/,
			stubPath
		),
		// Redirect the polySeg web worker converters file which pulls in VTK + WASM.
		// It is only loaded when polySeg segmentation is used (which we don't use).
		new webpack.NormalModuleReplacementPlugin(
			/workers[/\\]polySegConverters/,
			stubPath
		),
		// Also stub registerPolySegWorker which creates `new Worker(new URL(...))`.
		// webpack detects that pattern and generates a separate worker bundle even
		// if the worker file itself is stubbed; stubbing the registration file
		// prevents the worker bundle from being emitted at all.
		new webpack.NormalModuleReplacementPlugin(
			/polySeg[/\\]registerPolySegWorker/,
			stubPath
		),
		// comlink is only used by the polySeg worker — stub it too.
		new webpack.NormalModuleReplacementPlugin(
			/^comlink$/,
			stubPath
		),
		// xmlbuilder2 is transitively required by the VTK.js XML reader.
		new webpack.NormalModuleReplacementPlugin(
			/^xmlbuilder2/,
			stubPath
		),
		// Limit to exactly one chunk so WordPress only needs to enqueue a
		// single script handle and the runtime never fetches additional chunks.
		new webpack.optimize.LimitChunkCountPlugin( { maxChunks: 1 } ),
	],

	resolve: {
		extensions: [ '.js', '.mjs' ],
		// Prefer the ESM entry for better tree-shaking.
		mainFields: [ 'module', 'main' ],
		// Silence webpack 5 "can't resolve Node.js built-in" errors.
		fallback: {
			url:    false,
			path:   false,
			crypto: false,
			buffer: false,
			stream: false,
			fs:     false,
		},
	},

	// Enable asyncWebAssembly so webpack does not hard-error on any .wasm
	// references that escape our stubs.
	experiments: {
		asyncWebAssembly: true,
	},

	// Disable code splitting — produce a single self-contained file so WordPress
	// only needs to enqueue one script handle and no chunk manifests are needed.
	optimization: {
		splitChunks: false,
		runtimeChunk: false,
	},

	// Silence very large asset warnings — the Cornerstone3D bundle is
	// intentionally large (~2 MB) for a medical admin tool.
	performance: {
		hints: false,
	},

	devtool: false,
};
