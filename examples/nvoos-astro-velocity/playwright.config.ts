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
	//
	// Proposal 105 note: theme-policy PATCHes the shared fixture policy doc,
	// which would race every other spec's theme assertions — so it runs
	// serial against its OWN fixture + BFF pair (ports 8124/4322) with the
	// policy memo disabled (NVOOS_THEME_POLICY_TTL=0) so a PATCH is visible
	// to the very next SSR render in the same long-lived process.
	projects: [
		{
			name: 'default',
			fullyParallel: true,
			testIgnore: /(design-import|rebrand-loop|theme-policy|content-write|checkout-woo)\.spec\.ts/,
		},
		{
			name: 'stateful-mcp',
			fullyParallel: false,
			workers: 1,
			testMatch: /(design-import|rebrand-loop)\.spec\.ts/,
		},
		{
			name: 'theme-policy',
			fullyParallel: false,
			workers: 1,
			testMatch: /theme-policy\.spec\.ts/,
			use: {
				baseURL: 'http://localhost:4322',
				trace: 'on-first-retry',
			},
		},
		// The content-write spec publishes/deletes posts, which would race
		// every other spec's listing counts — so it runs serial against its
		// OWN fixture + BFF pair (ports 8125/4323), the theme-policy pattern.
		{
			name: 'content-write',
			fullyParallel: false,
			workers: 1,
			testMatch: /content-write\.spec\.ts/,
			use: {
				baseURL: 'http://localhost:4323',
				trace: 'on-first-retry',
			},
		},
		// The checkout-woo spec exercises the 108 server-cart path, which
		// needs the fixture in woo product mode — an isolated pair with
		// NVOOS_FIXTURE_PRODUCTS_SOURCE=woo, the theme-policy pattern.
		{
			name: 'checkout-woo',
			fullyParallel: false,
			workers: 1,
			testMatch: /checkout-woo\.spec\.ts/,
			use: {
				baseURL: 'http://localhost:4324',
				trace: 'on-first-retry',
			},
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
		// The theme-policy spec PATCHes the policy doc, which would race every
		// other spec's theme assertions — so it runs serial against its OWN
		// fixture + BFF pair (ports 8124/4322) with the policy memo disabled
		// (NVOOS_THEME_POLICY_TTL=0) so a PATCH is visible to the very next
		// SSR render in the same long-lived process.
		{
			command: 'node scripts/fixture-server.mjs',
			port: 8124,
			reuseExistingServer: true,
			stdout: 'ignore',
			env: { ...process.env, PORT: '8124' },
		},
		{
			command: 'node scripts/serve.mjs',
			port: 4322,
			reuseExistingServer: true,
			stdout: 'ignore',
			env: {
				...process.env,
				PORT: '4322',
				NVOOS_TARGET_SITE: 'http://localhost:8124',
				NVOOS_THEME_POLICY_TTL: '0',
			},
		},
		// The content-write pair (Proposal 107): isolated so publishing/
		// deleting fixture content can never race the shared listing counts.
		{
			command: 'node scripts/fixture-server.mjs',
			port: 8125,
			reuseExistingServer: true,
			stdout: 'ignore',
			env: { ...process.env, PORT: '8125' },
		},
		{
			command: 'node scripts/serve.mjs',
			port: 4323,
			reuseExistingServer: true,
			stdout: 'ignore',
			env: {
				...process.env,
				PORT: '4323',
				NVOOS_TARGET_SITE: 'http://localhost:8125',
				NVOOS_SSR_API_ORIGIN: 'http://localhost:8125/wp-json/nvoos-commerce/v1',
			},
		},
		// The checkout-woo pair (Proposal 108): isolated so the fixture can
		// serve woo product mode without flipping the shared pair's shop doc.
		{
			command: 'node scripts/fixture-server.mjs',
			port: 8126,
			reuseExistingServer: true,
			stdout: 'ignore',
			env: { ...process.env, PORT: '8126', NVOOS_FIXTURE_PRODUCTS_SOURCE: 'woo' },
		},
		{
			command: 'node scripts/serve.mjs',
			port: 4324,
			reuseExistingServer: true,
			stdout: 'ignore',
			env: {
				...process.env,
				PORT: '4324',
				NVOOS_TARGET_SITE: 'http://localhost:8126',
				NVOOS_SSR_API_ORIGIN: 'http://localhost:8126/wp-json/nvoos-commerce/v1',
			},
		},
	],
});
