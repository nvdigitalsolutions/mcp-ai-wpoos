import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  // esbuild >= 0.27.7 refuses to lower destructuring when a build target
  // includes Safari < 14.1 / iOS < 14.5 (evanw/esbuild#4436). Vite's default
  // 'modules' target includes safari14, which makes production builds fail.
  // Vite 7.3.3+ sets this automatically (vitejs/vite#22346); declare it here
  // for Vite 6 to restore the pre-esbuild-0.27.7 behavior.
  esbuild: {
    supported: {
      destructuring: true,
    },
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 3000,
    proxy: {
      '/wp-json': {
        target: 'https://scheduleanything.local',
        changeOrigin: true,
        secure: false,
      },
    },
  },
  build: {
    outDir: 'assets/dist',
    assetsDir: 'assets',
    sourcemap: true,
  },
});
