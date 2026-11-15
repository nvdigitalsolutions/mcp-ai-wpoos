// variants.spec.ts — Proposal 106 P1 e2e gates (variable products).
//
// Product 3 (Halo Lounge Chair) carries 3 Size variants on the single-doc
// route only. Covers: sold-out option disabled, selection updates the price
// + narrows the JSON-LD offers, the cart line stores the variant identity,
// and the selection survives a client-side navigation (Defect A class).
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const PDP = '/products/3/halo-lounge-chair/';

/** The styled option labels wrap hidden radio inputs (pointer-events: none) —
 *  click the LABEL like a visitor, then assert the radio state. */
const chooseOption = (page: import('@playwright/test').Page, name: string) =>
	page.locator('label.variant-option').filter({ hasText: name }).first().click();

test('variant selector renders groups with the sold-out option disabled', async ({ page }) => {
	await page.goto(PDP);
	await expect(page.locator('[data-variant-root="3"][data-hydrated="true"]')).toBeVisible({
		timeout: 30_000,
	});

	const group = page.getByRole('group', { name: 'Size' });
	await expect(group).toBeVisible();
	await expect(group.getByRole('radio', { name: 'Compact' })).toBeEnabled();
	await expect(group.getByRole('radio', { name: 'Standard (sold out)' })).toBeDisabled();
	await expect(group.getByRole('radio', { name: 'Large' })).toBeEnabled();
});

test('selecting a variant updates the price and narrows the JSON-LD offers', async ({ page }) => {
	await page.goto(PDP);
	await expect(page.locator('[data-variant-root="3"][data-hydrated="true"]')).toBeVisible({
		timeout: 30_000,
	});

	// The initial HTML ships the full Offer[] (crawlable, pre-hydration).
	const initial = await page.request.get(PDP);
	const initialHtml = await initial.text();
	expect(initialHtml).toContain('"sku":"HLC-COMP-OAK"');
	expect(initialHtml).toContain('"sku":"HLC-STD-OAK"');
	expect(initialHtml).toContain('"sku":"HLC-LRG-OAK"');

	// Sold out stays unselectable.
	await expect(
		page.getByRole('radio', { name: 'Standard (sold out)' })
	).toBeDisabled();

	await chooseOption(page, 'Large');
	await expect(page.locator('[data-variant-price]')).toHaveText('$849.00');

	// The Product JSON-LD block now carries only the selected offer.
	await expect(async () => {
		const blocks = await page
			.locator('script[type="application/ld+json"]')
			.allTextContents();
		const productBlock = blocks.find((block) => block.includes('"@type":"Product"')) ?? '';
		expect(productBlock).toContain('"sku":"HLC-LRG-OAK"');
		expect(productBlock).not.toContain('"sku":"HLC-COMP-OAK"');
	}).toPass();
});

test('the selected variant lands in the cart line with variant identity', async ({ page }) => {
	await page.goto(PDP);
	await expect(page.locator('[data-variant-root="3"][data-hydrated="true"]')).toBeVisible({
		timeout: 30_000,
	});

	await chooseOption(page, 'Large');
	await expect(page.locator('[data-variant-price]')).toHaveText('$849.00');

	const addButton = page.getByRole('button', { name: 'Add to cart' });
	await addButton.scrollIntoViewIfNeeded();
	await expect(async () => {
		await addButton.click();
		await expect(page.getByRole('button', { name: 'Added to cart ✓' })).toBeVisible();
	}).toPass();

	await expect(page.locator('.cart-badge__count')).toHaveText('1');

	await page.goto('/cart/');
	await expect(page.locator('.cart__line-name')).toHaveText('Halo Lounge Chair');
	await expect(page.locator('.cart__line-variant')).toHaveText('Large');
});

test('selection survives client-side navigation back to the PDP', async ({ page }) => {
	await page.goto(PDP);
	await expect(page.locator('[data-variant-root="3"][data-hydrated="true"]')).toBeVisible({
		timeout: 30_000,
	});

	await chooseOption(page, 'Large');
	await expect(page.locator('[data-variant-price]')).toHaveText('$849.00');

	// Client-side navigation away (ClientRouter swaps the whole <html>).
	await page.getByRole('link', { name: 'Products' }).click();
	await expect(page.getByRole('heading', { level: 1 })).toHaveText(/All products/);

	// And back THROUGH a client-side navigation — the restored selection
	// must re-apply on the freshly mounted island (Defect A discipline).
	await page.getByRole('link', { name: /Halo Lounge Chair/ }).click();
	await expect(page).toHaveURL(/\/products\/3\/halo-lounge-chair\/$/);
	await expect(page.locator('[data-variant-root="3"][data-hydrated="true"]')).toBeVisible({
		timeout: 30_000,
	});
	await expect(page.getByRole('radio', { name: 'Large' })).toBeChecked();
	await expect(page.locator('[data-variant-price]')).toHaveText('$849.00');
});

test('axe: the variant PDP is WCAG-clean', async ({ page }) => {
	await page.goto(PDP);
	const results = await new AxeBuilder({ page }).analyze();
	expect(
		results.violations,
		`axe violations: ${JSON.stringify(results.violations, null, 2)}`
	).toEqual([]);
});
