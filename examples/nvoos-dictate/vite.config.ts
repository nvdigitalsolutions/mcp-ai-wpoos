import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Tauri webview build. The Rust core owns all sensitive logic; this webview
// only talks to it over IPC. No remote content, no network calls from here.
export default defineConfig({
  plugins: [react()],
  clearScreen: false,
  server: {
    port: 1420,
    strictPort: true,
  },
  envPrefix: ['VITE_', 'TAURI_'],
  build: {
    target: 'es2021',
    minify: 'esbuild',
    sourcemap: false,
  },
});
