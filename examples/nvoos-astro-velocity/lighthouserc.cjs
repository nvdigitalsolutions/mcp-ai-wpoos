// ---------------------------------------------------------------------------
// Lighthouse CI — Proposal 092 Phase 2.6 gate.
//
// Industry-standard budgets + category assertions (GoogleChrome/lighthouse-ci
// pattern). The collect step targets the local BFF + fixture servers, which
// CI boots before running `npm run lhci:ci`; CHROME_PATH is pointed at the
// Playwright-installed Chromium.
// ---------------------------------------------------------------------------

const BASE_URL = process.env.E2E_BASE_URL || 'http://localhost:4321';

module.exports = {
	ci: {
		collect: {
			numberOfRuns: 1,
			url: [
				`${BASE_URL}/`,
				`${BASE_URL}/products/1/aurora-3-seater-sofa/`,
				`${BASE_URL}/categories/1/sofas/`,
			],
			settings: {
				chromeFlags: '--headless --no-sandbox --disable-gpu --disable-dev-shm-usage',
				formFactor: 'mobile',
				throttlingMethod: 'devtools',
			},
		},
		assert: {
			// The industry baseline for an SSR storefront: CWV-friendly perf,
			// strict a11y, and full SEO marks (the JSON-LD + canonical suite).
			assertions: {
				'categories:performance': ['error', { minScore: 0.8 }],
				'categories:accessibility': ['error', { minScore: 0.9 }],
				'categories:best-practices': ['error', { minScore: 0.9 }],
				'categories:seo': ['error', { minScore: 0.95 }],
			},
		},
		upload: {
			target: 'temporary-public-storage',
		},
	},
};
