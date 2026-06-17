import { cloudflareTest } from '@cloudflare/vitest-pool-workers';
import { defineConfig } from 'vitest/config';

// Migrated from `defineWorkersConfig` / `defineWorkersProject` (vitest v3 / pool-workers <= 0.15.x)
// to the vitest v4 / pool-workers >= 0.16.x API. The `@cloudflare/vitest-pool-workers/config`
// subpath export was removed in 0.16.0 — configuration now lives in a Vite plugin and the
// `test.poolOptions.workers` block moves directly into `cloudflareTest({ ... })`.
// See `node_modules/@cloudflare/vitest-pool-workers/dist/codemods/vitest-v3-to-v4.mjs`.
export default defineConfig({
	plugins: [
		cloudflareTest({
			wrangler: { configPath: './wrangler.toml' },
			miniflare: {
				compatibilityFlags: ['nodejs_compat'],
				d1Databases: ['NVOOS_DB'],
				kvNamespaces: ['RATE_KV'],
				bindings: {
					WORKER_VERSION: '1.0.0-test',
					DEFAULT_CURRENCY: 'usd',
					MARKUP_RATE: '0.07',
					MIN_TOPUP_USD: '25',
					LOW_BALANCE_THRESHOLD_USD: '2',
					STRIPE_FEE_PERCENT: '0.029',
					STRIPE_FEE_FIXED_USD: '0.30',
					OPENROUTER_API_KEY: 'sk-or-test',
					STRIPE_SECRET_KEY: 'sk_test',
					STRIPE_WEBHOOK_SECRET: 'whsec_test_secret',
					CF_AI_GATEWAY_URL: 'https://gateway.example/v1/acct/gw/openrouter',
				},
			},
		}),
	],
});
