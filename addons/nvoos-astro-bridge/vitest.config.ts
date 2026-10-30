import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		environment: 'jsdom',
		setupFiles: ['./src/test-setup.ts'],
		include: ['src/**/__tests__/**/*.test.{ts,tsx}'],
		restoreMocks: true,
	},
});
