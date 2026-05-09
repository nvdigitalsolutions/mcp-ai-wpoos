import { defineConfig } from 'vitest/config';

export default defineConfig( {
	esbuild: {
		// docs-hub has no tsconfig.json; tell esbuild to use the automatic
		// React 17+ JSX transform so tests can write JSX without importing React.
		jsx: 'automatic',
	},
	test: {
		environment: 'jsdom',
		globals: true,
		setupFiles: [ './src/test-setup.ts' ],
		coverage: {
			provider: 'v8',
			reporter: [ 'text', 'html' ],
		},
	},
} );
