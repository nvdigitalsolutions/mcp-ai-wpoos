// Playwright e2e — Proposal 092 Phase 2.5 gates.
//
// Boots its own fixture contract server + BFF (same pair smoke.mjs uses),
// so the suite is fully hermetic. Run via `npm run test:e2e` (CI installs
// the browser with `npx playwright install --with-deps chromium`).
import { defineConfig } from '@playwright/test';

export default defineConfig({
	testDir: './tests/e2e',
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
	use: {
		baseURL: 'http://localhost:4321',
		trace: 'on-first-retry',
	},
	// Proposal 104 note: design-import + rebrand-loop mutate the shared
	// in-process MCP token state (apply/rollback). They run in their own
	// single-worker, serial project so parallel theme/specs cannot interleave
	// with their assertions. Everything else stays fully parallel.
	projects: [
		{
			name: 'default',
			fullyParallel: true,
			testIgnore: /(design-import|rebrand-loop)\.spec\.ts/,
		},
		{
			name: 'stateful-mcp',
			fullyParallel: false,
			workers: 1,
			testMatch: /(design-import|rebrand-loop)\.spec\.ts/,
		},
	],
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
				// The design-import e2e analyzes a loopback design source —
				// private-host fetches are opt-in and dev-mount only (Proposal 098).
				NVOOS_MCP_FETCH_ALLOW_PRIVATE: '1',
			},
		},
	],
});
