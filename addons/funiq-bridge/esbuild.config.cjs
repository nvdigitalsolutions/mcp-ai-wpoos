// @ts-check
const esbuild = require('esbuild');

const isProd = process.argv.includes('--prod');
const isWatch = process.argv.includes('--watch');

/**
 * WordPress packages are provided by the wp_enqueue_script dependency chain
 * as `wp.*` globals (wp.element, wp.components, ...). esbuild's plain
 * `external` option would emit `require('@wordpress/element')` in the IIFE,
 * which throws at runtime ("Dynamic require is not supported") — observed in
 * the field on nvoos.pro (2026-10-29). Map externals to the wp.* globals
 * instead so the bundle stays require-free.
 */
const wpExternalGlobals = {
	'@wordpress/element': 'wp.element',
	'@wordpress/components': 'wp.components',
	'@wordpress/api-fetch': 'wp.apiFetch',
	'@wordpress/block-editor': 'wp.blockEditor',
	'@wordpress/i18n': 'wp.i18n',
	'@wordpress/data': 'wp.data',
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
  entryPoints: ['src/index.tsx'],
  bundle: true,
  outfile: 'build/index.js',
  format: 'iife',
  globalName: 'FuniqAdmin',
  target: 'es2020',
  platform: 'browser',
  jsx: 'automatic',
  jsxImportSource: '@wordpress/element',
  minify: isProd,
  sourcemap: !isProd,
  define: {
    'process.env.NODE_ENV': JSON.stringify(isProd ? 'production' : 'development'),
  },
  plugins: [
    {
      name: 'wp-external-globals',
      setup(build) {
        build.onResolve({ filter: /^(?:@wordpress\/(?:element(?:\/jsx-runtime)?|components|api-fetch|block-editor|i18n|data)|react|react-dom|react\/(?:jsx-runtime|jsx-dev-runtime))$/ }, (args) => ({
          path: args.path,
          namespace: 'wp-ext',
        }));
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
  if (isWatch) {
    const ctx = await esbuild.context(config);
    await ctx.watch();
    console.log('[funiq-bridge] Watching for changes...');
  } else {
    await esbuild.build(config);
    console.log('[funiq-bridge] Build complete.');
  }
}

build().catch((err) => {
  console.error(err);
  process.exit(1);
});
