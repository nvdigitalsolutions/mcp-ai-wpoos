// Smoke test: the webview renders and switches tabs without a Rust backend.
// (Tauri IPC is unavailable in plain Playwright; the app must degrade to
// "loading" states instead of crashing — which is what this asserts.)

import { expect, test } from '@playwright/test';

test('renders shell and navigates tabs', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('.brand')).toContainText('NV oOS Dictation');
  await page.getByRole('button', { name: 'History' }).click();
  await expect(page.getByPlaceholder('Search transcripts…')).toBeVisible();
  await page.getByRole('button', { name: 'Meetings' }).click();
  await expect(page.getByText('Meeting transcription')).toBeVisible();
  await page.getByRole('button', { name: 'Settings' }).click();
  await expect(page.getByRole('button', { name: 'Engine & Models' })).toBeVisible();
});

test('overlay renders idle state', async ({ page }) => {
  await page.goto('/?window=overlay');
  await expect(page.locator('.overlay-capsule')).toHaveAttribute('data-state', 'idle');
  await expect(page.locator('.overlay-text')).toContainText('idle');
});
