import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
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
