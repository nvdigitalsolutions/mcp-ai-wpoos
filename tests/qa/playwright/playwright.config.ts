import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for NV oOS E2E QA suite.
 *
 * Run modes:
 *   Docker:  BASE_URL=http://wordpress:80  (internal Docker network)
 *   Local:   BASE_URL=http://localhost:8000 (default)
 *
 * See https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
  testDir: './tests',
  timeout: 60_000, // 60s per test
  expect: {
    timeout: 15_000, // 15s per assertion
  },
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: [
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ['list'],
    ['junit', { outputFile: 'test-results/junit.xml' }],
  ],
  outputDir: process.env.CI ? '/artifacts/videos' : 'test-results',

  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8000',
    trace: process.env.CI ? 'retain-on-failure' : 'on',
    video: process.env.CI ? 'retain-on-failure' : 'off',
    screenshot: 'only-on-failure',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },

  projects: [
    // ── Chromium (primary browser for CI) ──
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1440, height: 900 },
      },
    },

    // ── Firefox (cross-browser parity) ──
    {
      name: 'firefox',
      use: {
        ...devices['Desktop Firefox'],
        viewport: { width: 1440, height: 900 },
      },
    },

    // ── WebKit (Safari parity) ──
    {
      name: 'webkit',
      use: {
        ...devices['Desktop Safari'],
        viewport: { width: 1440, height: 900 },
      },
    },

    // ── Mobile Chrome ──
    {
      name: 'mobile-chrome',
      use: {
        ...devices['Pixel 7'],
      },
    },
  ],

  // ── Web server (only for local dev — Docker provides its own) ──
  // Uncomment when running Playwright locally without Docker:
  // webServer: {
  //   command: 'docker compose up -d',
  //   url: 'http://localhost:8000',
  //   reuseExistingServer: !process.env.CI,
  //   cwd: '../../..',
  //   timeout: 120_000,
  // },
});
