import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

/**
 * Vitest config — NV oOS Complete Console host.
 * Mirrors the app's Vite alias map so tests resolve spa-v2 imports the same
 * way the build does.
 */
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@nvoos/pro-spa-v2': fileURLToPath(
        new URL('../../addons/pro/assets/spa-v2/src', import.meta.url),
      ),
    },
  },
  test: {
    environment: 'jsdom',
    globals: false,
    setupFiles: ['./src/test-setup.ts'],
    include: ['src/**/__tests__/**/*.test.{ts,tsx}'],
  },
});
