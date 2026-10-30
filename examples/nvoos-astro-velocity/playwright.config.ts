// Playwright e2e — Proposal 092 Phase 2.5 gates.
//
// Boots its own fixture contract server + BFF (same pair smoke.mjs uses),
// so the suite is fully hermetic. Run via `npm run test:e2e` (CI installs
// the browser with `npx playwright install --with-deps chromium`).
import { defineConfig } from '@playwright/test';

export default defineConfig({
	testDir: './tests/e2e',
	fullyParallel: true,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
	use: {
		baseURL: 'http://localhost:4321',
		trace: 'on-first-retry',
	},
	webServer: [
		{
			command: 'node scripts/fixture-server.mjs',
			port: 8123,
			// Reuse when already running (CI boots them for build/smoke/LHCI);
			// start them otherwise (local runs).
			reuseExistingServer: true,
			stdout: 'ignore',
		},
		{
			command: 'node scripts/serve.mjs',
			port: 4321,
			reuseExistingServer: true,
			stdout: 'ignore',
			env: {
				...process.env,
				NVOOS_TARGET_SITE: 'http://localhost:8123',
				// The MCP manager-session gate verifies cookies against the
				// contract auth surface (fixture /auth/me).
				NVOOS_SSR_API_ORIGIN: 'http://localhost:8123/wp-json/nvoos-commerce/v1',
			},
		},
	],
});
