import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

/**
 * Standalone NV oOS Pro SPA — Vite shell.
 *
 * Reuses the spa-v2 React sources (addons/pro/assets/spa-v2/src) via alias
 * instead of forking them, so the standalone app tracks the plugin SPA.
 * The shell's only jobs: build the `window.NVOOS_PRO_SPA` runtime from user
 * input (see src/runtime-config.ts), shim `@wordpress/i18n`, and mount <App/>.
 *
 * Env:
 *   NVOOS_TARGET_SITE  — WordPress site to proxy in dev (cookie-auth mode),
 *                        e.g. http://localhost:8000 or https://example.com
 */
const spaV2Src = fileURLToPath(
  new URL('../../addons/pro/assets/spa-v2/src', import.meta.url),
);
const repoRoot = fileURLToPath(new URL('../..', import.meta.url));

const targetSite = process.env.NVOOS_TARGET_SITE || '';
const workerTarget = process.env.NVOOS_WORKER_URL || '';

export default defineConfig({
  plugins: [react()],
  define: {
    // The spa-v2 sources reference process.env.NODE_ENV (axe dev audit gate).
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'development'),
  },
  resolve: {
    alias: {
      // The whole SPA, imported from this app as `@nvoos/pro-spa-v2/...`.
      '@nvoos/pro-spa-v2': spaV2Src,
      // WordPress loads this from window.wp.i18n; the standalone app ships a
      // tiny drop-in instead (same trick as spa-v2's esbuild plugin).
      '@wordpress/i18n': fileURLToPath(new URL('./src/shims/wp-i18n.ts', import.meta.url)),
      // The spa-v2 sources have their own node_modules in the monorepo — and
      // NONE in the standalone repo. Force every importer (shell + SPA + its
      // deps) onto this app's single dependency tree: a second
      // react/jsx-runtime instance would break hooks, and in the standalone
      // mirror the spa-v2 sources have no node_modules of their own to
      // resolve from. Directory aliases so subpaths (react-dom/client,
      // react/jsx-runtime, react-router-dom/*) still work.
      react: fileURLToPath(new URL('./node_modules/react', import.meta.url)),
      'react-dom': fileURLToPath(new URL('./node_modules/react-dom', import.meta.url)),
      'react-router-dom': fileURLToPath(new URL('./node_modules/react-router-dom', import.meta.url)),
      zustand: fileURLToPath(new URL('./node_modules/zustand', import.meta.url)),
      marked: fileURLToPath(new URL('./node_modules/marked', import.meta.url)),
      dompurify: fileURLToPath(new URL('./node_modules/dompurify', import.meta.url)),
      '@ai-sdk/react': fileURLToPath(new URL('./node_modules/@ai-sdk/react', import.meta.url)),
      '@ai-sdk/ui-utils': fileURLToPath(new URL('./node_modules/@ai-sdk/ui-utils', import.meta.url)),
    },
  },
  server: {
    port: 5199,
    open: false,
    fs: {
      // The spa-v2 sources live outside this app's root directory.
      allow: [spaV2Src, repoRoot],
    },
    proxy: {
      ...(targetSite
        ? {
            // Cookie-auth mode: everything same-origin through the dev server,
            // including wp-login.php so users can log in from the app.
            '/wp-json': { target: targetSite, changeOrigin: true, secure: false, cookieDomainRewrite: '' },
            '/wp-login.php': { target: targetSite, changeOrigin: true, secure: false },
            '/wp-admin': { target: targetSite, changeOrigin: true, secure: false },
          }
        : {}),
      ...(workerTarget
        ? {
            // Media worker: /worker/* → worker /* (dev only; the deployed app
            // talks to the worker origin directly with X-Site-Token).
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
});
