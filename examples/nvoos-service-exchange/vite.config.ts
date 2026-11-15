import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

/**
 * Service Exchange dev server. The control-plane API runs on 4182
 * (`npm run serve`); Vite proxies /api so the wizard is same-origin in dev.
 */
export default defineConfig( {
	plugins: [ react() ],
	server: {
		port: 5199,
		open: false,
		proxy: {
			'/api': {
				target: 'http://127.0.0.1:4182',
				changeOrigin: false,
			},
		},
	},
	build: {
		sourcemap: false,
	},
} );
