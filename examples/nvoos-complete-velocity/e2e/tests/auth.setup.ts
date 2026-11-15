import { test as setup, expect } from '@playwright/test';
import { fileURLToPath } from 'node:url';

/**
 * Signs in once against the Laravel runtime and saves the session for the
 * smoke project. The account is the seeded `DatabaseSeeder` user; override
 * with E2E_EMAIL / E2E_PASSWORD on a differently-seeded app.
 *
 * The form goes through `submitLaravelLogin()` → POST /api/v1/session/login,
 * so a passing setup also proves the Sanctum stateful-domain configuration
 * and the CSRF-alias handshake between the proxy and the backend.
 */

const AUTH_FILE = fileURLToPath(new URL('../.auth/state.json', import.meta.url));

const EMAIL = process.env.E2E_EMAIL || 'spike@example.com';
const PASSWORD = process.env.E2E_PASSWORD || 'password';

setup('signs in to the Laravel runtime', async ({ page }) => {
  await page.goto('/');

  // The branded sign-in screen, not WordPress' login page.
  await expect(page.getByRole('heading', { name: 'NV oOS Console' })).toBeVisible();

  await page.locator('#nvoos-login-username').fill(EMAIL);
  await page.locator('#nvoos-login-password').fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();

  // Authenticated bootstrap: the dashboard greets the session's user.
  await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible();
  await expect(page.getByText(/Signed in as /)).toBeVisible();

  await page.context().storageState({ path: AUTH_FILE });
});
