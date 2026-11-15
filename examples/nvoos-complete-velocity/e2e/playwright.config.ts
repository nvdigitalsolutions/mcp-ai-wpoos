import { defineConfig, devices } from '@playwright/test';
import { fileURLToPath } from 'node:url';

/**
 * Playwright config for the Laravel-runtime T0 console smoke (Proposal 090-B,
 * Phase 1 task 1.7 / gate G1).
 *
 * The smoke runs against the **built** console artifact, not the Vite dev
 * server: `npm run build:e2e` produces a Laravel-profile bundle in
 * `.e2e-dist/` and `scripts/serve.mjs` serves it behind the same-origin proxy
 * the deployment uses. The committed `dist/` stays a WordPress build, so the
 * smoke never rewrites it.
 *
 * Prerequisites (see e2e/README.md):
 *   - the Laravel stack is up (`docker compose -f docker/docker-compose.laravel.yml up -d`)
 *   - the console origin is a Sanctum stateful domain
 *
 * Env:
 *   E2E_BACKEND_URL    Laravel origin behind the proxy (default http://127.0.0.1:8001)
 *   E2E_CONSOLE_PORT   proxy port (default 4318, deliberately not serve.mjs's 4181)
 *   E2E_EMAIL / E2E_PASSWORD   seeded account (default spike@example.com / password)
 *   E2E_SKIP_BUILD=1   reuse an existing .e2e-dist/ (skips the Vite build)
 */

const APP_DIR = fileURLToPath(new URL('..', import.meta.url));
const AUTH_FILE = fileURLToPath(new URL('./.auth/state.json', import.meta.url));

const PORT = Number(process.env.E2E_CONSOLE_PORT || 4318);
const BACKEND = (process.env.E2E_BACKEND_URL || 'http://127.0.0.1:8001').replace(/\/+$/, '');
const SKIP_BUILD = process.env.E2E_SKIP_BUILD === '1';

export default defineConfig({
  testDir: './tests',
  timeout: 90_000,
  expect: { timeout: 15_000 },

  // One backend session, one proxy: serial keeps the request-log assertions
  // honest and avoids six parallel logins racing the session store.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,

  reporter: [
    ['list'],
    ['html', { outputFolder: fileURLToPath(new URL('./playwright-report', import.meta.url)), open: 'never' }],
  ],
  outputDir: fileURLToPath(new URL('./test-results', import.meta.url)),

  use: {
    baseURL: `http://localhost:${PORT}`,
    trace: 'retain-on-failure',
    video: 'off',
    screenshot: 'only-on-failure',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },

  projects: [
    // Signs in once and writes the cookie/localStorage state the smoke reuses.
    { name: 'auth', testMatch: /auth\.setup\.ts/ },
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1440, height: 900 },
        storageState: AUTH_FILE,
      },
      dependencies: ['auth'],
    },
  ],

  webServer: {
    // Build the Laravel-profile artifact, then serve it through the proxy the
    // deployment uses. `NVOOS_BACKEND=laravel` must match the build's
    // VITE_NVOOS_BACKEND — runtime-config.ts asserts the pair at boot.
    command: SKIP_BUILD ? 'node scripts/serve.mjs' : 'npm run build:e2e && node scripts/serve.mjs',
    cwd: APP_DIR,
    url: `http://localhost:${PORT}/`,
    // Never reuse: a stray server on this port would be a different artifact
    // and the zero-WordPress-requests gate would fail for the wrong reason.
    reuseExistingServer: false,
    timeout: 240_000,
    env: {
      PORT: String(PORT),
      NVOOS_BACKEND: 'laravel',
      NVOOS_TARGET_SITE: BACKEND,
      NVOOS_CONSOLE_ROOT: '.e2e-dist',
    },
    stdout: 'pipe',
    stderr: 'pipe',
  },
});
