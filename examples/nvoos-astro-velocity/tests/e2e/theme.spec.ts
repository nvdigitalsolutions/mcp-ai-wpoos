// theme.spec.ts — ADS theme switching e2e (Proposal 095 Phase 1 gates).
// JS path (ThemeToggle) + no-JS path (data-theme boot) + persistence.
import { expect, test } from '@playwright/test';

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:4321';

test('ThemeToggle cycles light → dark → brand and persists', async ({ page }) => {
	await page.goto(`${BASE}/tokens/`);
	await expect(page.locator('html')).not.toHaveAttribute('data-theme');

	await page.locator('[data-theme-toggle]').click();
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

	await page.locator('[data-theme-toggle]').click();
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'example-brand');

	// Persisted across a reload.
	await page.reload();
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'example-brand');
});

test('no-FOUC boot applies a stored dark theme before paint', async ({ page }) => {
	await page.addInitScript(() => {
		localStorage.setItem('nvoos-theme', 'dark');
	});
	await page.goto(`${BASE}/`);
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
	// The no-FOUC inline script is in the initial HTML head.
	const inline = await page.evaluate(() =>
		Array.from(document.querySelectorAll('script')).some((script) =>
			(script.textContent ?? '').includes('nvoos-theme')
		)
	);
	expect(inline).toBe(true);
});

test('theme persists across client-side navigation (Proposal 104 Defect A)', async ({ page }) => {
	await page.goto(`${BASE}/tokens/`);
	await expect(page.locator('html')).not.toHaveAttribute('data-theme');

	await page.locator('[data-theme-toggle]').click();
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

	// Client-side navigation — the ClientRouter swaps the whole <html> element,
	// so only the boot's astro:after-swap listener can keep the theme alive.
	await page.getByRole('link', { name: 'Categories' }).first().click();
	await expect(page).toHaveURL(/\/categories\//);
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
	await expect(page.locator('html')).toHaveCSS('color-scheme', 'dark');
	await expect(page.locator('[data-theme-label]')).toHaveText('dark');

	// And again — the fix is per-navigation, not once-only.
	await page.getByRole('link', { name: 'Brands' }).first().click();
	await expect(page).toHaveURL(/\/brands\//);
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});

test('toggle continues from the STORED theme after navigation', async ({ page }) => {
	await page.goto(`${BASE}/tokens/`);
	await page.locator('[data-theme-toggle]').click(); // → dark
	await page.getByRole('link', { name: 'Categories' }).first().click();
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

	// One click must advance dark → example-brand (storage is read, not the
	// reset DOM attribute — the Defect A symptom was light → dark).
	await page.locator('[data-theme-toggle]').click();
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'example-brand');
});

test('high-contrast axis persists across navigation and reload', async ({ page }) => {
	await page.goto(`${BASE}/tokens/`);
	await page.locator('[data-contrast-toggle]').click();
	await expect(page.locator('html')).toHaveAttribute('data-contrast', 'high');
	await expect(page.locator('[data-contrast-toggle]')).toHaveAttribute('aria-pressed', 'true');

	await page.getByRole('link', { name: 'Categories' }).first().click();
	await expect(page.locator('html')).toHaveAttribute('data-contrast', 'high');

	await page.reload();
	await expect(page.locator('html')).toHaveAttribute('data-contrast', 'high');
});
