import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.ts',
  timeout: 30_000,
  use: {
    // The webview UI is driven through the Vite dev server; Tauri IPC calls
    // are mocked via the spec below (no Rust backend needed for UI logic).
    baseURL: 'http://localhost:1420',
  },
  reporter: [['list']],
});
