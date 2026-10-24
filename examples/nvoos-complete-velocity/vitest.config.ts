import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

/**
 * Vitest config — NV oOS Complete Console host.
 * Mirrors the app's Vite alias map so tests resolve spa-v2 imports the same
 * way the build does — including forcing spa-v2 sources onto this app's
 * single dependency tree (a second react/zustand instance from the repo root
 * node_modules would break hooks and store identity in tests).
 */
function nodeModulesAlias(pkg: string): Record<string, string> {
  return { [pkg]: fileURLToPath(new URL(`./node_modules/${pkg}`, import.meta.url)) };
}

// The app root + the monorepo root: runtime-config.ts imports the media-
// studio/document-editor island bundles via `?url` (Proposal 087), which
// live outside this app's root — without the fs allow entry vitest denies
// the import with "Denied ID ... ?url".
const appRoot = fileURLToPath(new URL('.', import.meta.url));
const repoRoot = fileURLToPath(new URL('../..', import.meta.url));

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@nvoos/pro-spa-v2': fileURLToPath(
        new URL('../../addons/pro/assets/spa-v2/src', import.meta.url),
      ),
      // spa-v2 reads @wordpress/i18n from window.wp.i18n; ship the drop-in.
      '@wordpress/i18n': fileURLToPath(new URL('./src/shims/wp-i18n.ts', import.meta.url)),
      ...nodeModulesAlias('react'),
      ...nodeModulesAlias('react-dom'),
      ...nodeModulesAlias('react-router-dom'),
      ...nodeModulesAlias('zustand'),
      ...nodeModulesAlias('marked'),
      ...nodeModulesAlias('dompurify'),
      ...nodeModulesAlias('@ai-sdk/react'),
      ...nodeModulesAlias('@ai-sdk/ui-utils'),
      ...nodeModulesAlias('jquery'),
      ...nodeModulesAlias('cytoscape'),
      ...nodeModulesAlias('cytoscape-fcose'),
    },
  },
  test: {
    environment: 'jsdom',
    globals: false,
    setupFiles: ['./src/test-setup.ts'],
    include: ['src/**/__tests__/**/*.test.{ts,tsx}'],
  },
  server: {
    fs: {
      allow: [appRoot, repoRoot],
    },
  },
});
