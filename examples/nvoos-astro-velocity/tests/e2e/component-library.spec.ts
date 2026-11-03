// component-library.spec.ts — the Q6 evaluation gates: the adapted Incluud
// components (accordion/tabs/breadcrumbs) and the Zag islands (dialog/
// combobox) must pass axe-core and the keyboard contracts, or the adoption
// answer is "no".
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test('gallery page is axe-clean (both approaches)', async ({ page }) => {
	await page.goto('/components/');
	await expect(page.getByRole('heading', { level: 1 })).toHaveText('Component library evaluation');
	const results = await new AxeBuilder({ page }).analyze();
	expect(
		results.violations,
		`axe violations: ${JSON.stringify(results.violations, null, 2)}`
	).toEqual([]);
});

test('accordion opens via keyboard on the native summary', async ({ page }) => {
	await page.goto('/components/');

	const first = page.locator('details.ui-accordion-wrapper').first();
	const summary = first.locator('summary');
	await expect(summary).toBeVisible();

	// Native details behavior: Enter on the summary toggles open.
	await summary.focus();
	await summary.press('Enter');
	await expect(first).toHaveAttribute('open', '');
	await expect(first.locator('.ui-accordion-content')).toBeVisible();

	await summary.press('Enter');
	await expect(first).not.toHaveAttribute('open', '');
});

test('tabs follow the WAI-ARIA keyboard contract (arrows + Home/End)', async ({ page }) => {
	await page.goto('/components/');

	const tablist = page.getByRole('tablist');
	const tabs = tablist.getByRole('tab');
	await expect(tabs).toHaveCount(3);
	await expect(tabs.first()).toHaveAttribute('aria-selected', 'true');

	// ArrowRight moves selection and focus to the next tab.
	await tabs.first().focus();
	await tabs.first().press('ArrowRight');
	await expect(tabs.nth(1)).toBeFocused();
	await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
	await expect(page.locator('#panel-content')).toHaveAttribute('aria-hidden', 'false');

	// End jumps to the last tab.
	await tabs.nth(1).press('End');
	await expect(tabs.nth(2)).toHaveAttribute('aria-selected', 'true');

	// Home jumps back to the first.
	await tabs.nth(2).press('Home');
	await expect(tabs.first()).toHaveAttribute('aria-selected', 'true');
});

test('Zag dialog traps focus and closes on Escape', async ({ page }) => {
	await page.goto('/components/');

	const trigger = page.getByRole('button', { name: 'Open dialog' });
	// Wait for island hydration — under full-suite load an interaction can
	// land before React attaches its listeners (the 107-session race).
	await expect(trigger).toHaveAttribute('data-hydrated', '');
	await trigger.click();

	const dialog = page.getByRole('dialog', { name: 'Zag dialog demo' });
	await expect(dialog).toBeVisible();
	await expect(dialog).toHaveAttribute('aria-modal', 'true');

	// Focus starts inside the dialog (on the close button, the first
	// focusable element in our markup).
	await expect(page.getByRole('button', { name: 'Close' })).toBeFocused();

	// Escape closes and returns focus to the trigger.
	await page.keyboard.press('Escape');
	await expect(dialog).toBeHidden();
	await expect(trigger).toBeFocused();
});

test('Zag combobox filters and selects via keyboard', async ({ page }) => {
	await page.goto('/components/');

	const input = page.getByRole('combobox', { name: 'Wood' });
	// Wait for island hydration first — see the dialog test.
	await expect(page.locator('.ui-combobox')).toHaveAttribute('data-hydrated', '');
	await input.fill('oa');

	// Wait for the filtered collection to commit before the keyboard
	// sequence (the re-filter is a React state round-trip).
	const option = page.getByRole('option', { name: 'Oak' });
	await expect(option).toBeVisible();
	await input.press('ArrowDown');
	// The highlight assertion makes the selection deterministic under
	// parallel suite load — Enter selects whatever is highlighted.
	await expect(option).toHaveAttribute('data-highlighted', '');
	await input.press('Enter');
	await expect(input).toHaveValue('Oak');
});
