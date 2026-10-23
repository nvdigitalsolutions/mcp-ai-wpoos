import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

/**
 * NV oOS Complete Console — Vite shell over the spa-v2 sources.
 *
 * Same host recipe as examples/nvoos-pro-spa-vite (alias onto
 * addons/pro/assets/spa-v2/src — no fork, tracks the plugin SPA), minus the
 * connection screen: this app is cookie-auth-first through the same-origin
 * proxy (scripts/serve.mjs) and boots directly into the admin surface.
 *
 * Env (dev only):
 *   NVOOS_TARGET_SITE — WordPress Complete-bundle site to proxy in dev.
 *   NVOOS_WORKER_URL  — optional Design Stack media worker to proxy at /worker/*.
 */
const spaV2Src = fileURLToPath(
  new URL('../../addons/pro/assets/spa-v2/src', import.meta.url),
);
const repoRoot = fileURLToPath(new URL('../..', import.meta.url));
const envDir = fileURLToPath(new URL('.', import.meta.url));

/** Force every importer onto this app's single dependency tree. */
function nodeModulesAlias(pkg: string): Record<string, string> {
  return { [pkg]: fileURLToPath(new URL(`./node_modules/${pkg}`, import.meta.url)) };
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, envDir, '');
  const targetSite = env.NVOOS_TARGET_SITE || '';
  const workerTarget = env.NVOOS_WORKER_URL || '';

  return {
    plugins: [react()],
    define: {
      // The spa-v2 sources reference process.env.NODE_ENV (axe dev audit gate).
      'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'development'),
    },
    resolve: {
      alias: {
        // The whole SPA, imported from this app as `@nvoos/pro-spa-v2/...`.
        '@nvoos/pro-spa-v2': spaV2Src,
        // spa-v2 reads @wordpress/i18n from window.wp.i18n; ship the drop-in.
        '@wordpress/i18n': fileURLToPath(new URL('./src/shims/wp-i18n.ts', import.meta.url)),
        // A second react/jsx-runtime instance would break hooks; the spa-v2
        // sources have no node_modules of their own outside the monorepo.
        // Directory aliases so subpaths (react-dom/client, react/jsx-runtime,
        // react-router-dom/*) still resolve.
        ...nodeModulesAlias('react'),
        ...nodeModulesAlias('react-dom'),
        ...nodeModulesAlias('react-router-dom'),
        ...nodeModulesAlias('zustand'),
        ...nodeModulesAlias('marked'),
        ...nodeModulesAlias('dompurify'),
        ...nodeModulesAlias('@ai-sdk/react'),
        ...nodeModulesAlias('@ai-sdk/ui-utils'),
        // Knowledge-graph page deps: a second cytoscape instance would break
        // extension registration.
        ...nodeModulesAlias('jquery'),
        ...nodeModulesAlias('cytoscape'),
        ...nodeModulesAlias('cytoscape-fcose'),
      },
    },
    server: {
      port: 5198,
      open: false,
      fs: {
        // The spa-v2 sources live outside this app's root directory.
        allow: [spaV2Src, repoRoot],
      },
      proxy: {
        ...(targetSite
          ? {
              '/wp-json': { target: targetSite, changeOrigin: true, secure: false },
              '/wp-login.php': { target: targetSite, changeOrigin: true, secure: false },
              '/wp-admin': { target: targetSite, changeOrigin: true, secure: false },
              '/wp-includes': { target: targetSite, changeOrigin: true, secure: false },
            }
          : {}),
        ...(workerTarget
          ? {
              '/worker': {
                target: workerTarget,
                changeOrigin: true,
                secure: false,
                rewrite: (path) => path.replace(/^\/worker/, ''),
              },
            }
          : {}),
      },
    },
    build: {
      outDir: 'dist',
      assetsDir: 'assets',
      sourcemap: true,
    },
  };
});
