// ---------------------------------------------------------------------------
// Interactive storefront e2e — Proposal 092 Phase 1 parity checks.
//
// With JavaScript ENABLED: island hydration, View Transitions persistence,
// server-driven filters, promo-code validation and the auth gate.
//
// Hydration note: below-the-fold islands use client:visible — tests scroll
// the element into view and wrap click→assert sequences in toPass() so the
// assertion retries until the island has hydrated (Playwright's documented
// pattern for lazy-hydration races).
// ---------------------------------------------------------------------------

import { expect, test } from '@playwright/test';

test('cart badge persists across View Transitions navigation', async ({ page }) => {
	await page.goto('/products/1/aurora-3-seater-sofa/');

	const addButton = page.getByRole('button', { name: 'Add to cart' });
	await addButton.scrollIntoViewIfNeeded();

	await expect(async () => {
		await addButton.click();
		await expect(page.getByRole('button', { name: 'Added to cart ✓' })).toBeVisible();
	}).toPass();

	// transition:persist keeps the badge state across client-side navigation.
	await page.getByRole('link', { name: 'Categories' }).click();
	await expect(page.getByRole('heading', { level: 1 })).toHaveText(/Categories/);
	await expect(page.locator('.cart-badge__count')).toHaveText('1');
});

test('color filter narrows the grid via server query params', async ({ page }) => {
	await page.goto('/categories/1/sofas/');
	await expect(page.getByText('Aurora 3-Seater Sofa')).toBeVisible();

	// Terracotta (color 4) exists only on Solace Daybed within Sofas.
	await page.locator('input[name="color"][value="4"]').check();

	await expect(page).toHaveURL(/color=4/);
	await expect(page.getByText('Solace Daybed')).toBeVisible();
	await expect(page.getByText('Aurora 3-Seater Sofa')).toHaveCount(0);
});

test('promo-code field validates against the contract (Q5)', async ({ page }) => {
	// The island is client:visible — under parallel local load (Windows box,
	// 2 workers) hydration can lag past the default budgets; give it room
	// rather than racing the clock (Linux CI is unaffected).
	test.setTimeout(90_000);
	await page.goto('/products/1/aurora-3-seater-sofa/');

	const field = page.getByLabel('Promo code');
	await field.scrollIntoViewIfNeeded();
	// Wait for the client:visible island to hydrate before interacting
	// (deterministic signal — the click-retry pattern cannot tell an
	// unhydrated island from a slow response under parallel local load).
	await expect(page.locator('.promo-field[data-hydrated="true"]')).toBeVisible({ timeout: 30_000 });
	await field.fill('SUMMER10');

	const apply = page.getByRole('button', { name: 'Apply' });
	await expect(async () => {
		await apply.click();
		await expect(page.getByRole('status')).toContainText('SUMMER10 applied');
	}).toPass({ timeout: 30_000 });

	await expect(page.getByRole('status')).toContainText('10% off');
});

test('account page offers sign-in and register (Q5)', async ({ page }) => {
	await page.goto('/account/');

	// No session cookie → the gate renders the auth forms (the tablist
	// disambiguates the mode tab from the submit button).
	const tabs = page.getByRole('tablist', { name: 'Account form' });
	await expect(tabs.getByRole('button', { name: 'Sign in' })).toBeVisible();
	await expect(tabs.getByRole('button', { name: 'Create account' })).toBeVisible();
});

test('search returns matches from the contract name.like operator', async ({ page }) => {
	await page.goto('/search/?q=Sofa');
	await expect(page.getByText('Aurora 3-Seater Sofa')).toBeVisible();
	await expect(page.getByText('1 result')).toBeVisible();
});
