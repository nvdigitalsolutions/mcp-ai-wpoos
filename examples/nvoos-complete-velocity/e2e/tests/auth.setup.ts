// SPDX-License-Identifier: LicenseRef-Proprietary
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
 *
 * A multi-estate account is asked which estate to work in before the shell
 * mounts (149 §21) — `ResolveApiTenant` answers 409 `tenant_required` to a
 * scoped request that carries no selector, so the console cannot silently
 * pick one. The choice is persisted in localStorage, which `storageState`
 * carries into the smoke project.
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

  // Boot resolves to either the dashboard (single estate) or the picker.
  await expect(
    page
      .getByRole('heading', { name: 'Dashboard', level: 1 })
      .or(page.getByRole('heading', { name: 'Choose an estate' })),
  ).toBeVisible({ timeout: 30_000 });

  if (await page.getByRole('heading', { name: 'Choose an estate' }).isVisible()) {
    // The list is ordered by estate name server-side; the first option is a
    // deterministic choice without hard-coding a seeded estate name.
    await page
      .getByRole('list', { name: 'Choose an estate' })
      .getByRole('button')
      .first()
      .click();
  }

  // Authenticated bootstrap: the dashboard greets the session's user.
  await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible();
  await expect(page.getByText(/Signed in as /)).toBeVisible();
  await page.context().storageState({ path: AUTH_FILE });
});
