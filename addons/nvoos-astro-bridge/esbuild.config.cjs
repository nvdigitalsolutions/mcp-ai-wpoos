// @ts-check
const esbuild = require('esbuild');

const isProd = process.argv.includes('--prod');
const isWatch = process.argv.includes('--watch');
const isStandalone = process.argv.includes('--standalone');

/**
 * WordPress packages are provided by the wp_enqueue_script dependency chain
 * as `wp.*` globals (wp.element, wp.components, ...). esbuild's plain
 * `external` option would emit `require('@wordpress/element')` in the IIFE,
 * which throws at runtime ("Dynamic require is not supported") — observed in
 * the field on nvoos.pro (2026-10-29). Map externals to the wp.* globals
 * instead so the bundle stays require-free.
 *
 * The standalone build (--standalone) does NOT externalize anything: it
 * bundles @wordpress/* and React from node_modules so the SPA runs on the
 * storefront's /manage surface with no wp-admin script chain.
 */
const wpExternalGlobals = {
	'@wordpress/element': 'wp.element',
	'@wordpress/components': 'wp.components',
	'@wordpress/api-fetch': 'wp.apiFetch',
	'@wordpress/i18n': 'wp.i18n',
	react: 'window.React',
	'react-dom': 'window.ReactDOM',
};

/**
 * The automatic JSX runtime must come from WP's React, NOT a bundled
 * react/jsx-runtime copy. A second React copy renders elements with a
 * foreign $$typeof — React error #31 in the admin SPA. Use the PURE
 * window.React.createElement: the @wordpress/element wrapper reads React
 * owner internals that are unset in this context and throws
 * ("Cannot read properties of undefined (reading
 * 'recentlyCreatedOwnerStacks')").
 */
const wpJsxRuntimeShim = `
module.exports = {
	jsx: window.React.createElement,
	jsxs: window.React.createElement,
	jsxDEV: window.React.createElement,
	Fragment: window.React.Fragment,
};`;

/** @type {import('esbuild').BuildOptions} */
const config = {
	entryPoints: isStandalone
		? { 'astro-admin': 'src/standalone.tsx' }
		: ['src/index.tsx'],
	bundle: true,
	...(isStandalone
		? { outdir: 'build/standalone' }
		: { outfile: 'build/index.js' }),
	format: 'iife',
	globalName: 'AstroAdmin',
	target: 'es2020',
	platform: 'browser',
	jsx: 'automatic',
	// The wp-admin build piggybacks WP's React via @wordpress/element's
	// shimmed runtime; the standalone build bundles its own React and uses
	// react/jsx-runtime directly (@wordpress/element exports no jsx-runtime).
	jsxImportSource: isStandalone ? 'react' : '@wordpress/element',
	minify: isProd,
	sourcemap: !isProd,
	loader: isStandalone ? { '.css': 'css' } : undefined,
	define: {
		'process.env.NODE_ENV': JSON.stringify(isProd ? 'production' : 'development'),
	},
	plugins: isStandalone
		? []
		: [
				{
					name: 'wp-external-globals',
					setup(build) {
						build.onResolve(
							{ filter: /^(?:@wordpress\/(?:element(?:\/jsx-runtime)?|components|api-fetch|i18n)|react|react-dom|react\/(?:jsx-runtime|jsx-dev-runtime))$/ },
							(args) => ({
								path: args.path,
								namespace: 'wp-ext',
							})
						);
						build.onLoad({ filter: /.*/, namespace: 'wp-ext' }, (args) => ({
							contents: /\/jsx-runtime$|\/jsx-dev-runtime$/.test(args.path)
								? wpJsxRuntimeShim
								: `module.exports = ${wpExternalGlobals[args.path]};`,
						}));
					},
				},
			],
};

async function build() {
	const label = isStandalone ? 'standalone' : 'wp-admin';
	if (isWatch) {
		const ctx = await esbuild.context(config);
		await ctx.watch();
		console.log(`[astro-admin] Watching (${label}) for changes...`);
	} else {
		await esbuild.build(config);
		console.log(`[astro-admin] Build complete (${label}).`);
	}
}

build().catch((err) => {
	console.error(err);
	process.exit(1);
});
