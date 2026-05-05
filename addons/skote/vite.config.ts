import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// Vite config for the NV oOS Skote addon.
//
// Output shape mirrors the @wordpress/scripts convention so the PHP enqueue
// can pick up `dist/index.js`, `dist/index.css`, and `dist/index.asset.php`.
//
// `bin/generate-asset-php.js` runs after `vite build` and converts the Vite
// manifest into the asset.php file consumed by `class-nvoos-skote-assets.php`.

export default defineConfig({
	plugins: [react()],
	root: path.resolve(__dirname),
	build: {
		outDir: 'dist',
		emptyOutDir: true,
		manifest: true,
		sourcemap: true,
		rollupOptions: {
			input: path.resolve(__dirname, 'src/index.tsx'),
			output: {
				entryFileNames: 'index.js',
				chunkFileNames: 'chunks/[name]-[hash].js',
				assetFileNames: (asset) => {
					if (asset.name && asset.name.endsWith('.css')) {
						return 'index.css';
					}
					return 'assets/[name]-[hash][extname]';
				},
			},
		},
	},
	server: {
		port: 5173,
		proxy: {
			'/wp-json': {
				target: 'http://localhost:8000',
				changeOrigin: true,
			},
		},
	},
});
