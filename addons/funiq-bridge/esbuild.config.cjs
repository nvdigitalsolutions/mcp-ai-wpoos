// @ts-check
const esbuild = require('esbuild');

const isProd = process.argv.includes('--prod');
const isWatch = process.argv.includes('--watch');

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
  minify: isProd,
  sourcemap: !isProd,
  define: {
    'process.env.NODE_ENV': JSON.stringify(isProd ? 'production' : 'development'),
  },
  // All WordPress packages are externals (provided by wp_enqueue_script deps)
  external: [
    '@wordpress/element',
    '@wordpress/components',
    '@wordpress/api-fetch',
    '@wordpress/block-editor',
    '@wordpress/i18n',
    '@wordpress/data',
    'react',
    'react-dom',
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
