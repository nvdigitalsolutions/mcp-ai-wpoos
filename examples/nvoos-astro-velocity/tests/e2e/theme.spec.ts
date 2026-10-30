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
